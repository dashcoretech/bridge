<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Telemetry;

use Dashcore\Bridge\Facades\Bridge;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Models\ErrorGroup;
use Dashcore\Bridge\Models\RouteStat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ships this app's closed error and route buckets to the collector.
 *
 * The same arrangement as `CallReporter`, for the same reason: no app can see
 * more than its own half of the fleet, so each one reports what only it knows
 * and the collector assembles the picture. What differs is the destination —
 * call reports go to the control plane, which owns "who may call whom", while
 * these go to `health`, which owns "is anything wrong".
 *
 * **Only closed windows are sent.** The current hour is still being written
 * to, and shipping it would either report a partial count as a final one or
 * require the collector to handle the same bucket arriving repeatedly with a
 * rising number. An hour that has ended will never change again, which makes
 * it a fact rather than a reading.
 *
 * What travels is the shape of the failure, never the failure's contents:
 * exception class, redacted message, file and line, route pattern, and counts.
 * See `Redactor` for what is stripped and why the stripping happens on write
 * rather than here.
 */
final class TelemetryReporter
{
    /** Bounded so an app that has not reported in a week cannot post a novel. */
    public const BATCH = 200;

    public function __construct(
        private readonly IdentityResolver $identity,
        private readonly Config $config,
    ) {}

    /**
     * @return array{errors: int, routes: int, skipped: string|null}
     */
    public function report(int $limit = self::BATCH): array
    {
        $nothing = ['errors' => 0, 'routes' => 0];

        if (! $this->config->enabled()) {
            return [...$nothing, 'skipped' => 'Telemetry is switched off in this app\'s config.'];
        }

        if (! $this->identity->isComplete()) {
            return [...$nothing, 'skipped' => 'This app has no bridge identity yet, so it cannot sign a report.'];
        }

        $closed = Carbon::now()->startOfHour();

        $errors = $this->unreported(ErrorGroup::query(), $closed, $limit);
        $routes = $this->unreported(RouteStat::query(), $closed, $limit);

        if ($errors->isEmpty() && $routes->isEmpty()) {
            return [...$nothing, 'skipped' => null];
        }

        try {
            $response = Bridge::to($this->collector())->timeout(20)->post('/telemetry', [
                'errors' => $errors->map(fn (ErrorGroup $group) => [
                    // The app's own row id, so the collector can dedupe a
                    // retry rather than double-counting. A report that times
                    // out after the collector committed is the normal case.
                    'remote_id' => $group->id,
                    'fingerprint' => $group->fingerprint,
                    'level' => $group->level,
                    'exception' => $group->exception,
                    'message' => $group->message,
                    'file' => $group->file,
                    'line' => $group->line,
                    'context' => $group->context,
                    'count' => $group->count,
                    'window_start' => $group->window_start?->toIso8601String(),
                    'first_seen_at' => $group->first_seen_at?->toIso8601String(),
                    'last_seen_at' => $group->last_seen_at?->toIso8601String(),
                ])->values()->all(),

                'routes' => $routes->map(fn (RouteStat $stat) => [
                    'remote_id' => $stat->id,
                    'method' => $stat->method,
                    'route' => $stat->route,
                    'count' => $stat->count,
                    'slow_count' => $stat->slow_count,
                    'error_count' => $stat->error_count,
                    'mean_ms' => $stat->meanMs(),
                    'max_ms' => $stat->max_ms,
                    'window_start' => $stat->window_start?->toIso8601String(),
                ])->values()->all(),

                // So the collector can say "slow" in the reporting app's own
                // terms rather than imposing one threshold on thirteen apps
                // with very different jobs.
                'slow_request_ms' => $this->config->slowRequestMs(),
            ]);
        } catch (Throwable $e) {
            return [...$nothing, 'skipped' => 'Could not reach the collector: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return [...$nothing, 'skipped' => 'The collector refused the report: HTTP '.$response->status()];
        }

        // Marked only once the collector has it. The reverse order loses a
        // window whenever a report fails, and a gap is worse than a duplicate
        // the collector already knows how to drop.
        $now = Carbon::now();
        ErrorGroup::query()->whereIn('id', $errors->pluck('id'))->update(['reported_at' => $now]);
        RouteStat::query()->whereIn('id', $routes->pluck('id'))->update(['reported_at' => $now]);

        return ['errors' => $errors->count(), 'routes' => $routes->count(), 'skipped' => null];
    }

    /**
     * Delete buckets that have been reported and are past the retention
     * window.
     *
     * The local copy exists to survive a collector that is down, not to be an
     * archive — `health` holds the history. Kept for a few days so a report
     * that failed all weekend still has something to send.
     */
    public function prune(): int
    {
        $before = Carbon::now()->subDays($this->config->retentionDays());

        return ErrorGroup::query()->whereNotNull('reported_at')->where('window_start', '<', $before)->delete()
            + RouteStat::query()->whereNotNull('reported_at')->where('window_start', '<', $before)->delete();
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $query
     * @return Collection<int, T>
     */
    private function unreported($query, Carbon $closed, int $limit): Collection
    {
        return $query
            ->whereNull('reported_at')
            ->where('window_start', '<', $closed)
            ->orderBy('window_start')
            ->limit($limit)
            ->get();
    }

    /**
     * The collector's slug in this environment.
     *
     * `config('bridge.telemetry.collector')` names the app key; the peer's
     * actual id differs per install, so it is resolved the way every other
     * peer is rather than being written as a hostname.
     */
    private function collector(): string
    {
        $key = $this->config->collector();

        return (string) (config("services.fleet.{$key}") ?: $key);
    }
}
