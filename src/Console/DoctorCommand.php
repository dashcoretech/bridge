<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Client\BridgeManager;
use Dashcore\Bridge\Exceptions\BridgeException;
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
