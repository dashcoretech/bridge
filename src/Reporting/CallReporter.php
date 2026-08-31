<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Reporting;

use Dashcore\Bridge\Facades\Bridge;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Models\BridgeCall;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Ships this app's inbound call log to the control plane.
 *
 * Every app already records who called it. Nobody else does — a signed call
 * from travis to finance is between those two, and the hub never learns of it.
 * So the hub's own log answers "who called me", which looks like fleet traffic
 * and is not: on a twelve-app fleet it is fifteen callers and every row a ping.
 *
 * Each app reporting its own inbound calls is what makes the graph knowable,
 * and it is the only arrangement that can: the two ends of a call are the only
 * parties to it.
 *
 * What travels is the envelope — caller, method, path, ability, outcome,
 * status, duration. Never a request body, never a response. The hub is
 * building a map of which doors were opened, not a copy of what went through
 * them.
 */
class CallReporter
{
    /** Bounded so a long-unreported app cannot post a ten-megabyte body. */
    public const BATCH = 500;

    public function __construct(
        private readonly IdentityResolver $identity,
    ) {}

    public function pending(int $limit = self::BATCH): Collection
    {
        return BridgeCall::query()
            ->whereNull('reported_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array{sent: int, skipped: string|null}
     */
    public function report(int $limit = self::BATCH): array
    {
        if (! $this->identity->isComplete()) {
            return ['sent' => 0, 'skipped' => 'This app has no bridge identity yet, so it cannot sign a report.'];
        }

        $calls = $this->pending($limit);

        if ($calls->isEmpty()) {
            return ['sent' => 0, 'skipped' => null];
        }

        $control = (string) config('bridge.control_app', 'api');

        try {
            $response = Bridge::to($control)->timeout(20)->post('/call-reports', [
                'calls' => $calls->map(fn (BridgeCall $call) => [
                    // The app's own row id, so the hub can dedupe a retry
                    // rather than counting the same call twice. A report that
                    // times out after the hub committed is the normal case,
                    // not the exotic one.
                    'remote_id' => $call->id,
                    'caller_app' => $call->caller_app,
                    'method' => $call->method,
                    'path' => $call->path,
                    'ability' => $call->ability,
                    'outcome' => $call->outcome,
                    'status' => $call->status,
                    'duration_ms' => $call->duration_ms,
                    'occurred_at' => $call->created_at?->toIso8601String(),
                ])->values()->all(),
            ]);
        } catch (Throwable $e) {
            return ['sent' => 0, 'skipped' => 'Could not reach the control plane: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['sent' => 0, 'skipped' => 'The control plane refused the report: HTTP '.$response->status()];
        }

        // Marked only after the hub has it. The reverse order would lose calls
        // whenever a report failed, and a gap in an audit trail is worse than
        // a duplicate the hub already knows how to drop.
        BridgeCall::query()->whereIn('id', $calls->pluck('id'))->update(['reported_at' => now()]);

        return ['sent' => $calls->count(), 'skipped' => null];
    }
}
