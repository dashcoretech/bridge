<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Identity\AppId;
use Dashcore\Bridge\Identity\IdentityResolver;
use Dashcore\Bridge\Models\BridgeIdentity;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Zero-paste enrollment. The only environment a fleet app needs is:
 *
 *     BRIDGE_FLEET_KEY=fleet_…
 *     BRIDGE_CONTROL_URL=https://api.dashcore.com
 *
 * Everything else — keypair, app id, credential id, the control plane's
 * public key — is generated here or learned from the enrollment handshake,
 * then stored encrypted in this app's own database. Safe to leave in the
 * deploy command: once connected it is a no-op.
 */
class ConnectCommand extends Command
{
    protected $signature = 'bridge:connect
        {--fresh : Discard the stored identity and enroll again}
        {--app-id= : Override the app ID (defaults to this app\'s hostname)}';

    protected $description = 'Self-enroll with the control plane using the fleet key and store the identity — nothing to paste';

    public function handle(IdentityResolver $identity): int
    {
        if ($identity->isComplete() && ! $this->option('fresh')) {
            $this->components->info("Already connected as [{$identity->appId()}] — nothing to do. Use --fresh to re-enroll.");

            return self::SUCCESS;
        }

        $fleetKey = config('bridge.fleet_key');
        $control = rtrim((string) config('bridge.control.url'), '/');

        if (blank($fleetKey) || blank($control)) {
            $this->components->error('Connecting needs exactly two environment values:');
            $this->newLine();
            $this->line('BRIDGE_FLEET_KEY=fleet_…        # mint one on the control plane: /admin/connect');
            $this->line('BRIDGE_CONTROL_URL=https://api.dashcore.com');

            return self::FAILURE;
        }

        if (blank(config('app.key'))) {
            $this->components->error('APP_KEY is not set — the stored identity is encrypted with it. Run php artisan key:generate first.');

            return self::FAILURE;
        }

        $appId = $this->option('app-id') ?: config('bridge.app_id') ?: AppId::derive();
        $pair = Keypair::generate();

        try {
            $response = Http::acceptJson()
                ->timeout((int) config('bridge.timeout'))
                ->post("{$control}/api/bridge/v1/enroll", [
                    'fleet_key' => $fleetKey,
                    'slug' => $appId,
                    'name' => (string) config('app.name'),
                    'url' => (string) config('app.url'),
                    'credential_id' => "{$appId}-".now()->format('Y-m'),
                    'public_key' => $pair->publicKey,
                ]);
        } catch (ConnectionException $e) {
            $this->components->error("Could not reach the control plane at {$control}: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($response->status() === 409) {
            $this->components->error("The control plane already has a live service named [{$appId}].");
            $this->line("  An admin must allow re-enrollment first: {$control}/admin/connect → [{$appId}] → Re-enroll.");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->components->error('Enrollment failed: '.$response->json('error.message', $response->body()));

            return self::FAILURE;
        }

        DB::transaction(function () use ($response, $appId, $pair, $control) {
            // Replace, never append: at most one identity is meaningful.
            BridgeIdentity::query()->delete();

            BridgeIdentity::create([
                'app_id' => $appId,
                'key_id' => $response->json('data.credential.credential_id'),
                'private_key' => $pair->privateKey,
                'control_url' => $control,
                'control_public_key' => $response->json('data.control_plane.public_key'),
            ]);
        });

        $identity->forget();

        $this->components->info("Enrolled as [{$appId}]. The identity is stored (encrypted) in this app's database — nothing to paste anywhere.");

        if ($response->json('data.service.status') === 'pending') {
            $this->line("  Awaiting approval: {$control}/admin/connect");
        }

        return self::SUCCESS;
    }
}
