<?php

use Dashcore\Bridge\BridgeVersion;
use Dashcore\Bridge\Http\Controllers\HealthController;
use Dashcore\Bridge\Identity\IdentityResolver;
use Illuminate\Support\Facades\Route;

Route::prefix('api/bridge/v1')
    ->middleware(['bridge.auth', 'bridge.scope:bridge.ping'])
    ->get('ping', function () {
        return response()->json([
            'pong' => true,
            'app' => app(IdentityResolver::class)->appId(),
            'version' => BridgeVersion::version(),
            'environment' => app()->environment(),
            'time' => now()->toIso8601ZuluString(),
        ], 200, ['Cache-Control' => 'no-store']);
    })
    ->name('bridge.ping');

// The default health surface. An app with a richer notion of healthy sets
// `bridge.health.enabled` to false and serves its own route at this path —
// the toggle exists so the two never register the same URI and leave which
// one answers up to provider ordering.
if (config('bridge.health.enabled', true)) {
    Route::prefix('api/bridge/v1')
        ->middleware(['bridge.auth', 'bridge.scope:bridge.health'])
        ->get('health', HealthController::class)
        ->name('bridge.health');
}
