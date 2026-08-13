<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Exceptions\InvalidIdentity;
use Dashcore\Bridge\Identity\AppId;
use Dashcore\Bridge\Identity\Environment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class InstallCommand extends Command
{
    protected $signature = 'bridge:install
        {--token= : One-time enrollment token minted by the control plane}
        {--control= : Control plane base URL}';

    protected $description = '(legacy) Token-based enroll printing an env block — bridge:connect with a fleet key is the supported path';

    public function handle(): int
    {
        $token = $this->option('token') ?: $this->ask('Enrollment token (from platform:enroll-token on the control plane)');
        $control = rtrim($this->option('control') ?: $this->ask('Control plane URL', 'https://api.dashcore.com'), '/');
        // Identity and URL both come from APP_URL — see AppId. Accepting
        // either by hand is what let a token minted for one app be redeemed
        // by another.
        try {
            $appId = AppId::require();
        } catch (InvalidIdentity $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $url = (string) config('app.url');

        if (! Environment::agree($appId, Environment::host($control))) {
            $this->components->error(sprintf(
                'Refusing to enrol across environments: this app is %s and the control plane is %s.',
                Environment::of($appId), Environment::of(Environment::host($control)),
            ));

            return self::FAILURE;
        }

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
