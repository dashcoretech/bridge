<?php

use Dashcore\Bridge\Models\BridgeCall;
use Dashcore\Bridge\Reporting\CallReporter;
use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Testing\InteractsWithBridge;
use Illuminate\Support\Facades\Http;

uses(InteractsWithBridge::class);

function loggedCall(array $overrides = []): BridgeCall
{
    return BridgeCall::create([
        'caller_app' => 'executiveos',
        'key_id' => 'executiveos-2026-08',
        'method' => 'GET',
        'path' => '/api/bridge/v1/snapshot',
        'ability' => 'finance.read',
        'outcome' => 'ok',
        'status' => 200,
        'duration_ms' => 42,
        'created_at' => now()->subMinutes(5),
        ...$overrides,
    ]);
}

beforeEach(function () {
    // This app needs an identity of its own: the reporter signs the report,
    // and the base TestCase only configures an app id.
    config()->set('bridge.key_id', 'receiver-test');
    config()->set('bridge.private_key', Keypair::generate()->privateKey);

    // A signed call to the hub is what the reporter makes; the hub's answer is
    // the only part these tests need to control.
    $this->registerBridgePeer('api');
});

/** Laravel keeps the first matching stub, so each test registers exactly one. */
function fakeHub(int $status = 200): void
{
    Http::fake(['*' => Http::response($status === 200 ? ['accepted' => true] : ['message' => 'nope'], $status)]);
}

it('sends calls that have not been reported', function () {
    fakeHub();
    loggedCall();
    loggedCall();

    expect(app(CallReporter::class)->report())->toMatchArray(['sent' => 2, 'skipped' => null]);
});

it('marks a call reported only after the hub has it', function () {
    // The reverse order loses calls whenever a report fails, and a gap in an
    // audit trail is worse than a duplicate the hub knows how to drop.
    fakeHub(500);

    $call = loggedCall();

    expect(app(CallReporter::class)->report()['sent'])->toBe(0)
        ->and($call->fresh()->reported_at)->toBeNull();
});

it('does not send the same call twice', function () {
    fakeHub();
    loggedCall();

    app(CallReporter::class)->report();

    expect(app(CallReporter::class)->report())->toMatchArray(['sent' => 0]);
});

it('sends the call envelope and never a body', function () {
    fakeHub();
    // The hub is building a map of which doors were opened, not a copy of what
    // went through them.
    loggedCall();

    app(CallReporter::class)->report();

    Http::assertSent(function ($request) {
        $call = $request->data()['calls'][0];

        expect(array_keys($call))->toEqualCanonicalizing([
            'remote_id', 'caller_app', 'method', 'path',
            'ability', 'outcome', 'status', 'duration_ms', 'occurred_at',
        ]);

        return true;
    });
});

it('sends its own row id so the hub can drop a retry', function () {
    fakeHub();
    $call = loggedCall();

    app(CallReporter::class)->report();

    Http::assertSent(fn ($request) => $request->data()['calls'][0]['remote_id'] === $call->id);
});

it('reports nothing when there is nothing new', function () {
    fakeHub();
    expect(app(CallReporter::class)->report())->toMatchArray(['sent' => 0, 'skipped' => null]);
});

it('bounds a batch so a long-silent app cannot post everything at once', function () {
    fakeHub();
    foreach (range(1, 5) as $ignored) {
        loggedCall();
    }

    expect(app(CallReporter::class)->report(limit: 2)['sent'])->toBe(2)
        ->and(app(CallReporter::class)->pending()->count())->toBe(3);
});

it('says so rather than failing when the app has no identity', function () {
    fakeHub();
    config()->set('bridge.app_id', null);
    config()->set('bridge.private_key', null);

    loggedCall();

    $result = app(CallReporter::class)->report();

    expect($result['sent'])->toBe(0)
        ->and($result['skipped'])->toContain('no bridge identity');
});

it('runs from the console', function () {
    fakeHub();
    loggedCall();

    $this->artisan('bridge:report-calls')
        ->expectsOutputToContain('Reported 1 call')
        ->assertSuccessful();
});
