<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Http\Controllers;

use Dashcore\Bridge\Health\HealthReport;
use Illuminate\Http\JsonResponse;

/**
 * The default `bridge.health` surface.
 *
 * Serves the same shape an app writing its own health endpoint already
 * serves — verdict plus a 24-hour job digest — so a peer reading the fleet
 * does not have to know which apps rolled their own.
 *
 * Aggregates only. The status, the counts and a sentence cross the bridge;
 * the individual check details stay behind it, because a health read is a
 * fleet-observability call and not a remote diagnostic session.
 */
class HealthController
{
    public function __invoke(HealthReport $health): JsonResponse
    {
        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'verdict' => $health->verdict(),
            'jobs_last_24h' => $health->jobsLastDay(),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
