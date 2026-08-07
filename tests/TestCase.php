<?php

namespace Dashcore\Bridge\Tests;

use Dashcore\Bridge\BridgeServiceProvider;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [BridgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Peers and their keys come from config in tests; the manifest
        // resolver is exercised against a live control plane, not here.
        $app['config']->set('bridge.driver', 'config');
        $app['config']->set('bridge.app_id', 'receiver');
    }

    /**
     * A route carrying an ability the test peers are never granted, so the
     * scope layer is the only thing that could refuse it.
     */
    protected function defineRoutes($router): void
    {
        Route::middleware(['bridge.auth', 'bridge.scope:vault.read'])
            ->get('/api/bridge/v1/vault', fn () => response()->json(['ok' => true]))
            ->name('test.vault');
    }
}
