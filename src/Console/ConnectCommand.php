<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\Keypair;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * One-command fleet onboarding: reads BRIDGE_FLEET_KEY from the
 * environment, self-enrolls with the control plane (landing as a pending
 * service awaiting approval), and writes the BRIDGE_* block into .env
 * itself. The keypair is generated here; the private half never leaves
 * this machine.
 */
class ConnectCommand extends Command
{
    protected $signature = 'bridge:connect
        {--control= : Control plane base URL (defaults to BRIDGE_CONTROL_URL, then https://api.dashcore.com)}
        {--app-id= : Fleet-wide slug for this app (defaults to a slug of the app name)}
        {--url= : This app\'s public base URL (defaults to app.url)}
        {--force : Reconnect even though this install already has bridge credentials}';

    protected $description = 'Self-enroll this app in the fleet using BRIDGE_FLEET_KEY and write the credentials to .env';

    public function handle(): int
    {
        $fleetKey = (string) env('BRIDGE_FLEET_KEY', '');

        if ($fleetKey === '') {
            $this->components->error('BRIDGE_FLEET_KEY is not set. Mint one on the control plane with platform:fleet-key and add it to this app\'s environment.');

            return self::FAILURE;
        }

        if (config('bridge.app_id') && config('bridge.private_key') && ! $this->option('force')) {
            $this->components->warn('This install already has bridge credentials ('.config('bridge.app_id').'). Use --force to reconnect with a fresh keypair.');

            return self::FAILURE;
        }

        $control = rtrim($this->option('control') ?: env('BRIDGE_CONTROL_URL') ?: 'https://api.dashcore.com', '/');
        $appId = $this->option('app-id') ?: str(config('app.name'))->slug()->toString();
        $url = $this->option('url') ?: config('app.url');

        $pair = Keypair::generate();
        $credentialId = "{$appId}-".now()->format('Y-m');

        $response = Http::acceptJson()->post("{$control}/api/bridge/v1/enroll", [
            'fleet_key' => $fleetKey,
            'slug' => $appId,
            'name' => config('app.name'),
            'url' => $url,
            'credential_id' => $credentialId,
            'public_key' => $pair->publicKey,
        ]);

        if ($response->failed()) {
            $this->components->error('Enrollment failed: '.$response->json('error.message', $response->body()));

            return self::FAILURE;
        }

        $credentialId = $response->json('data.credential.credential_id', $credentialId);

        $written = $this->writeEnv([
            'BRIDGE_APP_ID' => $appId,
            'BRIDGE_KEY_ID' => $credentialId,
            'BRIDGE_PRIVATE_KEY' => $pair->privateKey,
            'BRIDGE_DRIVER' => 'control',
            'BRIDGE_CONTROL_URL' => $control,
            'BRIDGE_CONTROL_KEY' => (string) $response->json('data.control_plane.public_key'),
        ]);

        if ($written) {
            $this->components->info("Connected as [{$appId}] — credentials written to .env.");
        } else {
            $this->components->warn('Could not write .env — add the credentials manually:');
            $this->line("BRIDGE_APP_ID={$appId}");
            $this->line("BRIDGE_KEY_ID={$credentialId}");
            $this->line("BRIDGE_PRIVATE_KEY={$pair->privateKey}");
            $this->line('BRIDGE_DRIVER=control');
            $this->line("BRIDGE_CONTROL_URL={$control}");
            $this->line('BRIDGE_CONTROL_KEY='.$response->json('data.control_plane.public_key'));
        }

        if ($response->json('data.service.status') === 'pending') {
            $this->components->info('This service is PENDING: approve it on the control plane (platform:approve '.$appId.' or the admin panel), then grant abilities with platform:grant.');
        }

        return self::SUCCESS;
    }

    /**
     * Append-or-replace the BRIDGE_* lines in this app's .env.
     *
     * @param  array<string, string>  $values
     */
    private function writeEnv(array $values): bool
    {
        $path = base_path('.env');

        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $contents = (string) file_get_contents($path);

        foreach ($values as $key => $value) {
            $line = "{$key}={$value}";

            $contents = preg_match("/^{$key}=.*$/m", $contents) === 1
                ? (string) preg_replace("/^{$key}=.*$/m", $line, $contents)
                : rtrim($contents, "\n")."\n{$line}\n";
        }

        return file_put_contents($path, $contents) !== false;
    }
}
