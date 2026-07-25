<?php

namespace Dashcore\Bridge;

use Dashcore\Bridge\Client\BridgeManager;
use Dashcore\Bridge\Console\KeysGenerateCommand;
use Dashcore\Bridge\Http\Middleware\EnsureBridgeScope;
use Dashcore\Bridge\Http\Middleware\VerifyBridgeRequest;
use Dashcore\Bridge\Keys\ConfigKeysetResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        $this->app->singleton(KeysetResolver::class, ConfigKeysetResolver::class);
        $this->app->singleton(BridgeManager::class);
    }

    public function boot(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('bridge.auth', VerifyBridgeRequest::class);
        $router->aliasMiddleware('bridge.scope', EnsureBridgeScope::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/bridge.php');

        $this->publishes([
            __DIR__.'/../config/bridge.php' => config_path('bridge.php'),
        ], 'bridge-config');

        if ($this->app->runningInConsole()) {
            $this->commands([KeysGenerateCommand::class]);
        }
    }
}
