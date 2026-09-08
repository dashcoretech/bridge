<?php

namespace Dashcore\Bridge;

use Dashcore\Bridge\Client\BridgeManager;
use Dashcore\Bridge\Console\CheckContractsCommand;
use Dashcore\Bridge\Console\ConnectCommand;
use Dashcore\Bridge\Console\DemoRefreshCommand;
use Dashcore\Bridge\Console\DoctorCommand;
use Dashcore\Bridge\Console\InstallCommand;
use Dashcore\Bridge\Console\KeysGenerateCommand;
use Dashcore\Bridge\Console\KeysRotateCommand;
use Dashcore\Bridge\Console\ReportCallsCommand;
use Dashcore\Bridge\Console\ReportTelemetryCommand;
use Dashcore\Bridge\Http\Middleware\EnsureBridgeScope;
use Dashcore\Bridge\Http\Middleware\VerifyBridgeRequest;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Keys\ConfigKeysetResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Dashcore\Bridge\Keys\ManifestKeysetResolver;
use Dashcore\Bridge\Telemetry\Config as TelemetryConfig;
use Dashcore\Bridge\Telemetry\Listeners as TelemetryListeners;
use Dashcore\Bridge\Telemetry\Recorder;
use Illuminate\Support\ServiceProvider;

class BridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/bridge.php', 'bridge');

        $this->app->singleton(KeysetResolver::class, fn ($app) => match ($app['config']->get('bridge.driver', 'config')) {
            'control' => new ManifestKeysetResolver,
            default => new ConfigKeysetResolver,
        });
        $this->app->singleton(BridgeManager::class);
        $this->app->singleton(IdentityResolver::class);

        $this->app->singleton(TelemetryConfig::class);
        $this->app->singleton(Recorder::class);
        $this->app->singleton(TelemetryListeners::class);
    }

    public function boot(): void
    {
        $router = $this->app['router'];
        $router->aliasMiddleware('bridge.auth', VerifyBridgeRequest::class);
        $router->aliasMiddleware('bridge.scope', EnsureBridgeScope::class);

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/bridge.php');

        // Attached unconditionally; the listeners themselves check whether
        // telemetry is on. Deciding here instead would read config during
        // provider boot, before an app has had a chance to set it — and would
        // make the switch a restart rather than a setting.
        $this->app->make(TelemetryListeners::class)->subscribe($this->app['events']);

        $this->publishes([
            __DIR__.'/../config/bridge.php' => config_path('bridge.php'),
        ], 'bridge-config');

        if ($this->app->runningInConsole()) {
            $this->commands([KeysGenerateCommand::class, KeysRotateCommand::class, InstallCommand::class, ConnectCommand::class, DoctorCommand::class, DemoRefreshCommand::class, ReportCallsCommand::class, CheckContractsCommand::class, ReportTelemetryCommand::class]);
        }
    }
}
