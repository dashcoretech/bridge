<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Telemetry;

/**
 * The telemetry settings, read through one object rather than through
 * `config()` calls scattered across the listeners.
 *
 * Worth a class for one reason: `enabled()` is checked on every request and
 * every logged error, so it is on the hot path of an app that may be having a
 * bad day. Nothing here does work; it reads already-loaded config.
 */
final class Config
{
    public function enabled(): bool
    {
        return (bool) config('bridge.telemetry.enabled', true);
    }

    /**
     * Whether to also watch ordinary requests, not just failures.
     *
     * Separable from `enabled` because the two have very different costs. Error
     * capture writes only when something is already wrong; request capture
     * touches a row on every single request, and an app that is mostly a
     * high-volume webhook receiver may reasonably want the first and not the
     * second.
     */
    public function watchesRequests(): bool
    {
        return $this->enabled() && (bool) config('bridge.telemetry.requests', true);
    }

    /** Past this, a request is worth counting as slow. */
    public function slowRequestMs(): int
    {
        return (int) config('bridge.telemetry.slow_request_ms', 1000);
    }

    /**
     * The app that collects this. `health` by default: it already owns the
     * question "is anything wrong", and it is the one place in the fleet where
     * an operator is looking when something is.
     */
    public function collector(): string
    {
        return (string) config('bridge.telemetry.collector', 'health');
    }

    /** How long a shipped bucket is kept locally before being pruned. */
    public function retentionDays(): int
    {
        return (int) config('bridge.telemetry.retention_days', 7);
    }

    /**
     * Paths never worth a row.
     *
     * Health checks and the telemetry ingest itself. The second matters: the
     * collector recording its own collection endpoint means every report
     * creates traffic that the next report has to describe, and the table
     * never settles.
     *
     * @return list<string>
     */
    public function ignoredPaths(): array
    {
        return (array) config('bridge.telemetry.ignore', [
            'api/bridge/v1/ping',
            'api/bridge/v1/health',
            'api/bridge/v1/telemetry',
            'up',
        ]);
    }
}
