<?php

use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Models\ErrorGroup;
use Dashcore\Bridge\Models\RouteStat;
use Dashcore\Bridge\Telemetry\Recorder;
use Dashcore\Bridge\Telemetry\Redactor;
use Dashcore\Bridge\Telemetry\TelemetryReporter;
use Dashcore\Bridge\Testing\InteractsWithBridge;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

uses(InteractsWithBridge::class);

beforeEach(function () {
    config()->set('bridge.key_id', 'receiver-test');
    config()->set('bridge.private_key', Keypair::generate()->privateKey);
    config()->set('services.fleet.health', 'health');

    $this->registerBridgePeer('health');
});

function fakeCollector(int $status = 200): void
{
    Http::fake(['*' => Http::response($status === 200 ? ['accepted' => true] : ['message' => 'nope'], $status)]);
}

// ─── Recording ──────────────────────────────────────────────────────────────

it('groups repeats of the same failure into one row with a count', function () {
    // The whole point of bucketing: an app in a retry loop must not be able to
    // turn its own incident into a second one in the database.
    $boom = new RuntimeException('Could not reach the payments API');

    foreach (range(1, 50) as $ignored) {
        app(Recorder::class)->error('error', $boom->getMessage(), $boom);
    }

    expect(ErrorGroup::query()->count())->toBe(1)
        ->and(ErrorGroup::query()->first()->count)->toBe(50);
});

it('files two different failures separately', function () {
    app(Recorder::class)->error('error', 'one', new RuntimeException('one'));
    app(Recorder::class)->error('error', 'two', new LogicException('two'));

    expect(ErrorGroup::query()->count())->toBe(2);
});

it('files the same code failing on different records as one problem', function () {
    // Fingerprinted on class, file and line — never the message. Otherwise a
    // loop over a thousand records files a thousand problems, and the report
    // becomes useless exactly when it matters most.
    $throw = fn (string $id) => new RuntimeException("Customer {$id} has no billing account");

    app(Recorder::class)->error('error', 'Customer 412 has no billing account', $throw('412'));
    app(Recorder::class)->error('error', 'Customer 998 has no billing account', $throw('998'));

    expect(ErrorGroup::query()->count())->toBe(1)
        ->and(ErrorGroup::query()->first()->count)->toBe(2);
});

it('records nothing when telemetry is switched off', function () {
    config()->set('bridge.telemetry.enabled', false);

    app(Recorder::class)->error('error', 'boom', new RuntimeException('boom'));
    app(Recorder::class)->request('GET', '/leads/{lead}', 50, 200);

    expect(ErrorGroup::query()->count())->toBe(0)
        ->and(RouteStat::query()->count())->toBe(0);
});

it('accumulates route timings into one bucket per route per hour', function () {
    app(Recorder::class)->request('GET', '/leads/{lead}', 100, 200);
    app(Recorder::class)->request('GET', '/leads/{lead}', 300, 200);
    app(Recorder::class)->request('GET', '/leads/{lead}', 2000, 500);

    $stat = RouteStat::query()->sole();

    expect($stat->count)->toBe(3)
        ->and($stat->max_ms)->toBe(2000)
        ->and($stat->meanMs())->toBe(800)
        // One over the default 1000ms threshold.
        ->and($stat->slow_count)->toBe(1)
        // And one 5xx.
        ->and($stat->error_count)->toBe(1);
});

it('keeps methods apart on the same path', function () {
    app(Recorder::class)->request('GET', '/leads', 10, 200);
    app(Recorder::class)->request('POST', '/leads', 10, 201);

    expect(RouteStat::query()->count())->toBe(2);
});

it('never lets a logged error escape the recorder', function () {
    // Recording a failure must not be able to turn a handled error into an
    // unhandled one. A dropped row costs a line of a report; an exception here
    // costs the request, at the moment the app can least afford it.
    //
    // The table going missing stands in for every way the write can fail —
    // a migration mid-deploy, a full disk, a database refusing connections.
    Schema::drop('bridge_error_groups');
    Schema::drop('bridge_route_stats');

    expect(fn () => app(Recorder::class)->error('error', 'boom', new RuntimeException('boom')))
        ->not->toThrow(Exception::class);

    expect(fn () => app(Recorder::class)->request('GET', '/x', 10, 200))
        ->not->toThrow(Exception::class);
});

/**
 * The caller's transaction survives a bucket collision.
 *
 * This is the regression that a SQLite-only suite cannot see. On PostgreSQL a
 * failed statement aborts the enclosing transaction — every later query
 * returns 25P02 until someone rolls back — so catching the unique violation is
 * not enough on its own: by then the caller's transaction is already dead, and
 * the recorder has broken the request it was only supposed to watch. It
 * reached an app's suite before anyone noticed, and turned 29 unrelated tests
 * red.
 *
 * Asserting the surrounding transaction still works keeps the guarantee
 * checkable on either driver.
 */
it('leaves the caller\'s transaction usable after a bucket collision', function () {
    $boom = new RuntimeException('twice');

    DB::transaction(function () use ($boom) {
        // The second of these collides on (fingerprint, window_start).
        app(Recorder::class)->error('error', 'twice', $boom);
        app(Recorder::class)->error('error', 'twice', $boom);

        // The caller carries on. Under the bug this throws instead.
        expect(ErrorGroup::query()->count())->toBe(1);
    });

    expect(ErrorGroup::query()->first()->count)->toBe(2);
});

/**
 * Recording is not a domain change, and must not announce itself as one.
 *
 * An app is entitled to listen to `eloquent.*` — `marketing` does, filing
 * every model write as an activity event. Creating these rows through Eloquent
 * put this package's bookkeeping into another app's record of what its users
 * did, and broke two of its tests by leaving three rows in a table an
 * assertion expected to be empty.
 */
it('writes its rows without firing model events', function () {
    $fired = [];

    // Only the events that claim something changed. `booting`/`booted` fire
    // whenever a model class is first touched — including by the assertions
    // below — and say nothing about a write.
    Event::listen('eloquent.*', function (string $name) use (&$fired) {
        $persistence = ['creating', 'created', 'saving', 'saved', 'updating', 'updated', 'deleting', 'deleted'];

        foreach ($persistence as $verb) {
            if (str_starts_with($name, "eloquent.{$verb}:")) {
                $fired[] = $name;
            }
        }
    });

    app(Recorder::class)->error('error', 'boom', new RuntimeException('boom'));
    app(Recorder::class)->request('GET', '/leads/{lead}', 40, 200);

    // The rows are there; the app's event stream never heard about them.
    expect(ErrorGroup::query()->count())->toBe(1)
        ->and(RouteStat::query()->count())->toBe(1)
        ->and($fired)->toBe([]);
});

/**
 * Recording an error must not be able to record itself, forever.
 *
 * A query listener that logs is the cleanest reproduction: the insert fires a
 * query event, the event logs, the log re-enters the recorder before the first
 * call has returned, and that writes again. There is no natural bottom —
 * `dcos` hit it at a hundred and seventy thousand stack frames and half a
 * gigabyte before PHP gave up, and it was `dcos`'s own suite that caught this.
 *
 * Reaching the assertion at all is most of the point: without the guard this
 * test does not fail, it exhausts memory and takes the run with it.
 */
it('does not recurse when writing a row itself causes logging', function () {
    DB::listen(function () {
        Log::error('logged from inside a query');
    });

    Log::error('primary', ['exception' => new RuntimeException('primary')]);

    // The outer call wrote its row; the nested ones were dropped rather than
    // looping. One row, and the process survived.
    expect(ErrorGroup::query()->count())->toBe(1);
});

it('keeps recording after a write fails', function () {
    // The guard is cleared in a `finally`. Without that, the first failure
    // switches recording off for the rest of the process — an app would go
    // quiet at exactly the moment it started having problems.
    Schema::rename('bridge_error_groups', 'bridge_error_groups_hidden');
    app(Recorder::class)->error('error', 'while the table was missing', new RuntimeException('x'));
    Schema::rename('bridge_error_groups_hidden', 'bridge_error_groups');

    app(Recorder::class)->error('error', 'after', new RuntimeException('y'));

    expect(ErrorGroup::query()->count())->toBe(1);
});

it('writes without the connection announcing the query', function () {
    // One layer below the model-event rule, and the same principle: an app is
    // entitled to watch its own database and assume what it sees is its own
    // business. `dcos` logs from a query listener, so every telemetry insert
    // produced a log entry that then had to be written somewhere — the
    // recorder generating the traffic it exists to describe.
    $seen = [];

    DB::listen(function ($query) use (&$seen) {
        $seen[] = $query->sql;
    });

    app(Recorder::class)->error('error', 'boom', new RuntimeException('boom'));

    $telemetry = array_filter($seen, fn (string $sql) => str_contains($sql, 'bridge_error_groups'));

    expect(ErrorGroup::query()->count())->toBe(1)
        ->and($telemetry)->toBe([]);
});

it('gives the connection its dispatcher back afterwards', function () {
    // Leaving it unset would be far worse than the problem: the app would lose
    // query logging entirely from its first error onward, and silently.
    $before = DB::connection()->getEventDispatcher();

    app(Recorder::class)->error('error', 'boom', new RuntimeException('boom'));

    expect(DB::connection()->getEventDispatcher())->toBe($before);
});

// ─── Redaction ──────────────────────────────────────────────────────────────

it('strips the things a message should not carry across the fleet', function (string $raw, string $absent) {
    expect(Redactor::message($raw))->not->toContain($absent);
})->with([
    'an email address' => ['No account for dana@northwind.example', 'dana@northwind.example'],
    // Deliberately not shaped like any real vendor's key prefix: a fixture
    // that trips a secret scanner is a fixture that stops the repo pushing.
    'a bearer token' => ['Rejected: zzfake0000TOKEN0000example', 'zzfake0000TOKEN0000example'],
    'a labelled secret' => ['Failed with password=hunter2 supplied', 'hunter2'],
    'a card number' => ['Declined 4111 1111 1111 1111', '4111 1111 1111 1111'],
    'SQL bindings' => ['insert failed (SQL: insert into users (email) values (dana@x.example))', 'dana@x.example'],
]);

it('caps a message so nothing substantial rides along inside it', function () {
    expect(Redactor::message(str_repeat('a', 5000)))
        ->toHaveLength(Redactor::MAX_LENGTH);
});

it('reports a path relative to the app rather than naming the host', function () {
    expect(Redactor::path(base_path('app/Models/User.php')))->toBe('app/Models/User.php')
        ->and(Redactor::path(null))->toBeNull();
});

// ─── The log hook ───────────────────────────────────────────────────────────

it('captures an error written to the log, with no app-side wiring', function () {
    // Laravel's own exception handler reports through the logger, so listening
    // here catches unhandled exceptions and deliberate Log::error() calls
    // alike — without any of the thirteen apps changing a line.
    Log::error('The nightly sync failed', ['exception' => new RuntimeException('timeout')]);

    expect(ErrorGroup::query()->count())->toBe(1)
        ->and(ErrorGroup::query()->first()->exception)->toBe(RuntimeException::class);
});

it('ignores warnings and below', function () {
    // A monitor that reports warnings with the same weight as a 500 trains its
    // reader to ignore it.
    Log::warning('Disk is getting full');
    Log::info('Ran the thing');

    expect(ErrorGroup::query()->count())->toBe(0);
});

// ─── Reporting ──────────────────────────────────────────────────────────────

it('ships only windows that have closed', function () {
    fakeCollector();

    // This hour is still being written to; reporting a partial count as a
    // final one is worse than reporting it an hour later.
    app(Recorder::class)->error('error', 'now', new RuntimeException('now'));

    ErrorGroup::query()->create([
        'fingerprint' => 'old', 'window_start' => now()->subHours(2)->startOfHour(),
        'level' => 'error', 'exception' => 'RuntimeException', 'message' => 'earlier',
        'count' => 3, 'first_seen_at' => now()->subHours(2), 'last_seen_at' => now()->subHours(2),
    ]);

    expect(app(TelemetryReporter::class)->report())
        ->toMatchArray(['errors' => 1, 'routes' => 0, 'skipped' => null]);
});

it('marks a bucket reported only after the collector has it', function () {
    fakeCollector(500);

    $group = ErrorGroup::query()->create([
        'fingerprint' => 'x', 'window_start' => now()->subHour()->startOfHour(),
        'level' => 'error', 'message' => 'boom', 'count' => 1,
        'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour(),
    ]);

    expect(app(TelemetryReporter::class)->report()['errors'])->toBe(0)
        ->and($group->fresh()->reported_at)->toBeNull();
});

it('does not send the same bucket twice', function () {
    fakeCollector();

    ErrorGroup::query()->create([
        'fingerprint' => 'x', 'window_start' => now()->subHour()->startOfHour(),
        'level' => 'error', 'message' => 'boom', 'count' => 1,
        'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour(),
    ]);

    app(TelemetryReporter::class)->report();

    expect(app(TelemetryReporter::class)->report())->toMatchArray(['errors' => 0]);
});

it('says so rather than failing when the collector is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('no route to host'));

    ErrorGroup::query()->create([
        'fingerprint' => 'x', 'window_start' => now()->subHour()->startOfHour(),
        'level' => 'error', 'message' => 'boom', 'count' => 1,
        'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour(),
    ]);

    expect(app(TelemetryReporter::class)->report()['skipped'])->toContain('Could not reach the collector');
});

it('prunes reported buckets past the retention window but keeps unreported ones', function () {
    // The local copy exists to survive a collector that is down, not to be an
    // archive. An unreported bucket is still owed to somebody.
    ErrorGroup::query()->create([
        'fingerprint' => 'sent', 'window_start' => now()->subDays(30), 'level' => 'error',
        'message' => 'old', 'count' => 1, 'first_seen_at' => now()->subDays(30),
        'last_seen_at' => now()->subDays(30), 'reported_at' => now()->subDays(30),
    ]);

    ErrorGroup::query()->create([
        'fingerprint' => 'unsent', 'window_start' => now()->subDays(30), 'level' => 'error',
        'message' => 'old', 'count' => 1, 'first_seen_at' => now()->subDays(30),
        'last_seen_at' => now()->subDays(30),
    ]);

    expect(app(TelemetryReporter::class)->prune())->toBe(1)
        ->and(ErrorGroup::query()->pluck('fingerprint')->all())->toBe(['unsent']);
});
