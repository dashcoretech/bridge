<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Telemetry;

use Dashcore\Bridge\Models\ErrorGroup;
use Dashcore\Bridge\Models\RouteStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes what went wrong and what was slow into this hour's buckets.
 *
 * Every method here is called from an event listener on a live request, which
 * sets the rule the whole class obeys: **recording must never be able to break
 * the thing it is recording.** An observability layer that throws during an
 * incident converts a handled error into an unhandled one, and does it at
 * exactly the moment the app is least able to cope. So every entry point is
 * wrapped, failures are swallowed, and the worst case is a missing row.
 *
 * The counters are moved with an atomic `increment`, not read-modify-write.
 * Two workers failing on the same line in the same second is the normal case
 * for this table, and it is the one case where losing a count would mean
 * under-reporting an incident in progress.
 */
final class Recorder
{
    public function __construct(private readonly Config $config) {}

    /**
     * Record a failure.
     *
     * `$throwable` is optional because not everything worth seeing is an
     * exception: a `Log::error()` with no exception attached is often the more
     * deliberate signal of the two, since somebody chose to write it.
     */
    public function error(string $level, ?string $message, ?Throwable $throwable = null, ?string $context = null): void
    {
        if (! $this->config->enabled()) {
            return;
        }

        try {
            $file = Redactor::path($throwable?->getFile());
            $line = $throwable?->getLine();
            $exception = $throwable !== null ? $throwable::class : null;

            // Class, file and line — never the message. Two failures of the
            // same code that differ only in which record they name are one
            // problem; fingerprinting the message files them as thousands and
            // makes the report useless precisely when it matters.
            $fingerprint = hash('sha256', implode('|', [$exception ?? 'log', $file ?? '', (string) $line, $level]));

            $now = Carbon::now();
            $window = $this->window($now);

            $this->bucket(
                fn () => ErrorGroup::query()->create([
                    'fingerprint' => $fingerprint,
                    'window_start' => $window,
                    'level' => $level,
                    'exception' => $exception,
                    'message' => Redactor::message($message),
                    'file' => $file,
                    'line' => $line,
                    'context' => $context ?? $this->currentContext(),
                    'count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]),
                fn () => ErrorGroup::query()
                    ->where('fingerprint', $fingerprint)
                    ->where('window_start', $window)
                    ->update([
                        'count' => DB::raw('"count" + 1'),
                        'last_seen_at' => $now,
                    ]),
            );
        } catch (Throwable) {
            // Recording a failure must never itself fail loudly. A dropped row
            // costs one line of a report; an exception here costs the request.
        }
    }

    /** Record one finished request against its route's bucket for this hour. */
    public function request(string $method, string $route, int $durationMs, int $status): void
    {
        if (! $this->config->enabled()) {
            return;
        }

        try {
            $window = $this->window(Carbon::now());
            $slow = $durationMs >= $this->config->slowRequestMs() ? 1 : 0;
            $failed = $status >= 500 ? 1 : 0;

            $this->bucket(
                fn () => RouteStat::query()->create([
                    'method' => $method,
                    'route' => $route,
                    'window_start' => $window,
                    'count' => 1,
                    'slow_count' => $slow,
                    'total_ms' => $durationMs,
                    'max_ms' => $durationMs,
                    'error_count' => $failed,
                ]),
                fn () => RouteStat::query()
                    ->where('method', $method)
                    ->where('route', $route)
                    ->where('window_start', $window)
                    ->update([
                        'count' => DB::raw('"count" + 1'),
                        'slow_count' => DB::raw('slow_count + '.$slow),
                        'total_ms' => DB::raw('total_ms + '.$durationMs),
                        'error_count' => DB::raw('error_count + '.$failed),
                        'max_ms' => DB::raw('CASE WHEN max_ms < '.$durationMs.' THEN '.$durationMs.' ELSE max_ms END'),
                    ]),
            );
        } catch (Throwable) {
            // As above: never at the cost of the request.
        }
    }

    /**
     * Create the bucket, or add to it if someone else got there first.
     *
     * Insert-then-catch rather than select-then-insert, because the race
     * between two workers is the common case here, not the rare one — and the
     * unique index is the only thing that can actually settle it. A `SELECT`
     * first would be wrong under exactly the concurrency this table is for.
     */
    private function bucket(callable $create, callable $increment): void
    {
        try {
            $create();
        } catch (Throwable) {
            $increment();
        }
    }

    /** The start of the hour a moment falls in. */
    private function window(Carbon $at): Carbon
    {
        return $at->copy()->startOfHour();
    }

    /**
     * Where the app was when this happened: a route pattern, or the artisan
     * command that was running.
     *
     * The pattern and not the URL. `/leads/{lead}` says which code was
     * involved; `/leads/412` says that and also who, and this report has no
     * business carrying the second.
     */
    private function currentContext(): ?string
    {
        if (app()->runningInConsole()) {
            $command = $_SERVER['argv'][1] ?? null;

            return is_string($command) ? 'artisan '.$command : 'console';
        }

        try {
            $route = request()->route();
        } catch (Throwable) {
            return null;
        }

        return $route?->uri() !== null ? '/'.ltrim($route->uri(), '/') : null;
    }
}
