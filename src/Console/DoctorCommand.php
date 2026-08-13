<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Client\BridgeManager;
use Dashcore\Bridge\Exceptions\BridgeException;
use Dashcore\Bridge\Identity\Environment;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Keys\KeysetResolver;
use Dashcore\Bridge\Keys\ManifestKeysetResolver;
use Illuminate\Console\Command;

class DoctorCommand extends Command
{
    protected $signature = 'bridge:doctor';

    protected $description = 'Check bridge identity, manifest freshness, and reachability of every peer';

    public function handle(BridgeManager $bridge, KeysetResolver $keys): int
    {
        $identity = app(IdentityResolver::class);
        $healthy = true;

        foreach (['app_id' => $identity->appId(), 'key_id' => $identity->keyId(), 'private_key' => $identity->privateKey()] as $key => $value) {
            if (blank($value)) {
                $this->components->error("No bridge {$key} — enroll with bridge:connect (fleet key) or set it via bridge:keys:generate.");
                $healthy = false;
            }
        }

        if (! $healthy) {
            return self::FAILURE;
        }

        // Which fleet this app is in, and whether the control plane it talks
        // to is in the same one. Stated before anything else because every
        // check below is only meaningful within an environment: a green
        // manifest fetched from the wrong control plane is worse than a red
        // one, and nothing else on this report would say so.
        $appId = (string) $identity->appId();
        $controlHost = Environment::host((string) config('bridge.control.url'));
        $environment = Environment::of($appId);

        $this->components->twoColumnDetail('identity', $appId);
        $this->components->twoColumnDetail('environment', $environment);

        if ($controlHost !== '') {
            $this->components->twoColumnDetail('control plane', $controlHost.' ('.Environment::of($controlHost).')');

            if (! Environment::agree($appId, $controlHost)) {
                $this->components->error(
                    "This app is {$environment} but its control plane is ".Environment::of($controlHost).
                    ' — the two are separate fleets and must not share credentials.'
                );

                return self::FAILURE;
            }
        }

        if (Environment::isAmbiguous($appId)) {
            $this->components->error(
                "[{$appId}] does not name one particular application — set APP_URL to this app's own hostname and re-enrol."
            );

            return self::FAILURE;
        }

        // A configured slug that disagrees with the URL is ignored at runtime
        // (see IdentityResolver) but stays in the env misleading whoever reads
        // it next, so it is called out here rather than left to be discovered.
        $configured = config('bridge.app_id');

        if (filled($configured) && $configured !== $appId) {
            $this->components->warn(
                "BRIDGE_APP_ID is [{$configured}] but the identity is [{$appId}] — the env value is ignored. Remove it."
            );
        }

        if ($keys instanceof ManifestKeysetResolver) {
            try {
                $keys->refresh();
                $manifest = $keys->manifest();
                $this->components->info('Fleet manifest fetched and verified (issued '.($manifest['issued_at'] ?? 'unknown').').');
            } catch (\Throwable $e) {
                $this->components->error("Fleet manifest unavailable: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        foreach ($keys->peers() as $peer) {
            try {
                $started = microtime(true);
                $response = $bridge->to($peer)->timeout(5)->get('/ping');
                $latency = (int) ((microtime(true) - $started) * 1000);

                $skew = abs(now()->diffInSeconds($response->json('time')));
                $skewNote = $skew > 30 ? " ⚠ clock skew {$skew}s" : '';

                $this->components->twoColumnDetail($peer, "OK {$latency}ms{$skewNote}");
            } catch (BridgeException $e) {
                $this->components->twoColumnDetail($peer, 'FAIL '.class_basename($e));
                $this->line("  {$e->getMessage()}");
                $healthy = false;
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }
}
