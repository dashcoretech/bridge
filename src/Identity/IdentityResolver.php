<?php

namespace Dashcore\Bridge\Identity;

use Dashcore\Bridge\Models\BridgeIdentity;

/**
 * The one answer to "who am I on the bridge?". Environment configuration
 * wins when present (the control plane and legacy installs), otherwise the
 * identity persisted by `bridge:connect` is used — which is how a fleet app
 * runs with nothing in its env but the fleet key and the control URL.
 *
 * The stored row is read at most once per request. Resolution must never
 * touch the network: signing a manifest fetch consults this resolver, so a
 * remote lookup here would recurse.
 */
class IdentityResolver
{
    private bool $loaded = false;

    private ?BridgeIdentity $stored = null;

    public function appId(): ?string
    {
        return config('bridge.app_id') ?: $this->stored()?->app_id;
    }

    public function keyId(): ?string
    {
        return config('bridge.key_id') ?: $this->stored()?->key_id;
    }

    public function privateKey(): ?string
    {
        return config('bridge.private_key') ?: $this->stored()?->private_key;
    }

    public function controlPublicKey(): ?string
    {
        return config('bridge.control.public_key') ?: $this->stored()?->control_public_key;
    }

    /**
     * Whether this app can sign an outbound bridge request at all.
     */
    public function isComplete(): bool
    {
        return filled($this->appId()) && filled($this->keyId()) && filled($this->privateKey());
    }

    public function forget(): void
    {
        $this->loaded = false;
        $this->stored = null;
    }

    /**
     * Null when nothing is stored — including when the table does not exist
     * yet (migrations not run) or the database is down; identity resolution
     * degrades to env-only rather than throwing from inside a signer.
     */
    private function stored(): ?BridgeIdentity
    {
        if (! $this->loaded) {
            $this->stored = rescue(fn () => BridgeIdentity::query()->latest('id')->first(), report: false);
            $this->loaded = true;
        }

        return $this->stored;
    }
}
