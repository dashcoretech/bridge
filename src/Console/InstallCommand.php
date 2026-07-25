<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\Keypair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class InstallCommand extends Command
{
    protected $signature = 'bridge:install
        {--token= : One-time enrollment token minted by the control plane}
        {--control= : Control plane base URL}
        {--app-id= : Fleet-wide slug for this app}
        {--url= : This app\'s public base URL (defaults to app.url)}';

    protected $description = 'Generate this app\'s bridge keypair, enroll with the control plane, and print the env block';

    public function handle(): int
    {
        $token = $this->option('token') ?: $this->ask('Enrollment token (from bridge:enroll-token on the control plane)');
        $control = rtrim($this->option('control') ?: $this->ask('Control plane URL', 'https://api.dashcore.com'), '/');
        $appId = $this->option('app-id') ?: str(config('app.name'))->slug()->toString();
        $url = $this->option('url') ?: config('app.url');

        $keyId = "{$appId}-".now()->format('Y-m');
        $pair = Keypair::generate();

        $response = Http::acceptJson()->post("{$control}/api/bridge/v1/enroll", [
            'token' => $token,
            'url' => $url,
            'key_id' => $keyId,
            'public_key' => $pair->publicKey,
        ]);

        if ($response->failed()) {
            $this->components->error('Enrollment failed: '.$response->json('detail', $response->body()));

            return self::FAILURE;
        }

        $this->components->info('Enrolled with the control plane. Add to this app\'s environment:');
        $this->newLine();
        $this->line("BRIDGE_APP_ID={$appId}");
        $this->line("BRIDGE_KEY_ID={$keyId}");
        $this->line("BRIDGE_PRIVATE_KEY={$pair->privateKey}");
        $this->line('BRIDGE_DRIVER=control');
        $this->line("BRIDGE_CONTROL_URL={$control}");
        $this->line('BRIDGE_CONTROL_KEY='.$response->json('control.public_key'));

        return self::SUCCESS;
    }
}
