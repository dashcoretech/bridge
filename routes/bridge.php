<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api/bridge/v1')
    ->middleware(['bridge.auth', 'bridge.scope:bridge.ping'])
    ->get('ping', function () {
        return response()->json([
            'pong' => true,
            'app' => config('bridge.app_id'),
            'time' => now()->toIso8601ZuluString(),
        ], 200, ['Cache-Control' => 'no-store']);
    })
    ->name('bridge.ping');
