<?php

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Crypto\Keypair;
use Illuminate\Console\Command;

class KeysGenerateCommand extends Command
{
    protected $signature = 'bridge:keys:generate {--app-id= : Fleet-wide slug for this app}';

    protected $description = 'Generate an Ed25519 bridge keypair and print the env block for this app';

    public function handle(): int
    {
        $appId = $this->option('app-id') ?: str(config('app.name'))->slug()->toString();
        $keyId = "{$appId}-".now()->format('Y-m');
        $pair = Keypair::generate();

        $this->components->info('Bridge keypair generated. Add to this app\'s environment:');
        $this->newLine();
        $this->line("BRIDGE_APP_ID={$appId}");
        $this->line("BRIDGE_KEY_ID={$keyId}");
        $this->line("BRIDGE_PRIVATE_KEY={$pair->privateKey}");
        $this->newLine();
        $this->components->info('Public key — publish to peers / the control plane (never the private key):');
        $this->newLine();
        $this->line("'{$keyId}' => '{$pair->publicKey}',");

        return self::SUCCESS;
    }
}
