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
        $token = $this->option('token') ?: $this->ask('Enrollment token (from platform:enroll-token on the control plane)');
        $control = rtrim($this->option('control') ?: $this->ask('Control plane URL', 'https://api.dashcore.com'), '/');
        $appId = $this->option('app-id') ?: str(config('app.name'))->slug()->toString();
        $url = $this->option('url') ?: config('app.url');

        // The keypair is generated here and the private half never leaves this
        // machine — the control plane only ever sees the public key.
        $pair = Keypair::generate();
        $proposedCredentialId = "{$appId}-".now()->format('Y-m');

        $response = Http::acceptJson()->post("{$control}/api/bridge/v1/enroll", [
            'token' => $token,
            'url' => $url,
            'credential_id' => $proposedCredentialId,
            'public_key' => $pair->publicKey,
        ]);

        if ($response->failed()) {
            $this->components->error('Enrollment failed: '.$response->json('error.message', $response->body()));

            return self::FAILURE;
        }

        // Trust the server's answer rather than the proposal: the control
        // plane may have assigned a different credential ID.
        $credentialId = $response->json('data.credential.credential_id', $proposedCredentialId);

        $this->components->info('Enrolled with the control plane. Add to this app\'s environment:');
        $this->newLine();
        $this->line("BRIDGE_APP_ID={$appId}");
        $this->line("BRIDGE_KEY_ID={$credentialId}");
        $this->line("BRIDGE_PRIVATE_KEY={$pair->privateKey}");
        $this->line('BRIDGE_DRIVER=control');
        $this->line("BRIDGE_CONTROL_URL={$control}");
        $this->line('BRIDGE_CONTROL_KEY='.$response->json('data.control_plane.public_key'));

        return self::SUCCESS;
    }
}
