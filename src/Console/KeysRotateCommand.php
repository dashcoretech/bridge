<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\BridgeHeaders;
use Dashcore\Bridge\Crypto\Keypair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class KeysRotateCommand extends Command
{
    protected $signature = 'bridge:keys:rotate';

    protected $description = 'Generate a new keypair and register its public key with the control plane, signed under the current key';

    public function handle(): int
    {
        if (blank(config('bridge.control.url'))) {
            $this->components->error('bridge.control.url is not set — rotation registers the new key with the control plane.');

            return self::FAILURE;
        }

        $appId = config('bridge.app_id');
        $keyId = "{$appId}-".now()->format('Y-m');

        if ($keyId === config('bridge.key_id')) {
            $keyId .= '-'.now()->format('d-His');
        }

        $pair = Keypair::generate();

        $path = '/api/bridge/v1/keys';
        $body = json_encode(['key_id' => $keyId, 'public_key' => $pair->publicKey]);

        $response = Http::withHeaders([
            ...BridgeHeaders::sign('POST', $path, [], $body),
            'Accept' => 'application/json',
        ])
            ->withBody($body, 'application/json')
            ->timeout(config('bridge.timeout'))
            ->post(rtrim(config('bridge.control.url'), '/').$path);

        if ($response->failed()) {
            $this->components->error('Key registration failed: '.$response->json('detail', $response->body()));

            return self::FAILURE;
        }

        $this->components->info('New key registered with the control plane. Update this app\'s environment, then deploy:');
        $this->newLine();
        $this->line("BRIDGE_KEY_ID={$keyId}");
        $this->line("BRIDGE_PRIVATE_KEY={$pair->privateKey}");
        $this->newLine();
        $this->components->warn('The old key keeps verifying until it is revoked on the control plane (bridge:revoke-key).');

        return self::SUCCESS;
    }
}
