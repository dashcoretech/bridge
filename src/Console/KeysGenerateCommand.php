<?php

namespace Dashcore\Bridge\Console;

use App\Bridge\ManifestBuilder;
use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Exceptions\InvalidIdentity;
use Dashcore\Bridge\Identity\AppId;
use Illuminate\Console\Command;

class KeysGenerateCommand extends Command
{
    protected $signature = 'bridge:keys:generate';

    protected $description = 'Generate an Ed25519 bridge keypair and print complete copy-paste env blocks';

    public function handle(): int
    {
        // Printing a keypair under a guessed identity produces an env block
        // that enrols the wrong app — the failure this whole change exists to
        // stop — so refuse rather than guess.
        try {
            $appId = AppId::require();
        } catch (InvalidIdentity $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
        $keyId = "{$appId}-".now()->format('Y-m');
        $pair = Keypair::generate();

        if ($this->isControlPlane()) {
            $this->block("BLOCK 1 — this app's environment (Laravel Cloud: dashboard → Environment, then redeploy)", [
                "BRIDGE_APP_ID={$appId}",
                "BRIDGE_KEY_ID={$keyId}",
                "BRIDGE_PRIVATE_KEY={$pair->privateKey}",
                'BRIDGE_DRIVER=database',
            ]);

            $this->block("BLOCK 2 — every fleet app's environment (same three lines in each)", [
                'BRIDGE_DRIVER=control',
                'BRIDGE_CONTROL_URL='.rtrim((string) config('app.url'), '/'),
                "BRIDGE_CONTROL_KEY={$pair->publicKey}",
            ]);
        } else {
            $this->block("BLOCK 1 of 1 — this app's environment (dashboard env on a hosting platform, then redeploy)", [
                "BRIDGE_APP_ID={$appId}",
                "BRIDGE_KEY_ID={$keyId}",
                "BRIDGE_PRIVATE_KEY={$pair->privateKey}",
            ]);

            $this->newLine();
            $this->line('  The control plane learns this app\'s public key automatically at enrollment');
            $this->line('  (bridge:install --token=… or bridge:connect). For a static config-driver');
            $this->line("  peer only, register: '{$keyId}' => '{$pair->publicKey}',");
        }

        $this->newLine();
        $this->line('  <fg=yellow>Nothing is stored — save both halves now. BRIDGE_PRIVATE_KEY is a secret:</>');
        $this->line('  <fg=yellow>never commit it, never paste it anywhere except the environment field.</>');

        return self::SUCCESS;
    }

    /**
     * The control plane is the one app that can build and sign the fleet
     * manifest. Recognising it by that class — not by BRIDGE_DRIVER — matters
     * because this command runs on a fresh hub *before* any BRIDGE_* env
     * exists. An explicit `control` driver always means a fleet app.
     */
    private function isControlPlane(): bool
    {
        return config('bridge.driver') !== 'control'
            && (config('bridge.driver') === 'database' || class_exists(ManifestBuilder::class));
    }

    /**
     * A header, then nothing but paste-ready lines — no commentary inside the
     * block, so one uninterrupted selection is always valid env content.
     *
     * @param  list<string>  $lines
     */
    private function block(string $title, array $lines): void
    {
        $this->newLine();
        $this->line("  <options=bold>{$title}</>");
        $this->newLine();

        foreach ($lines as $line) {
            $this->line($line);
        }
    }
}
