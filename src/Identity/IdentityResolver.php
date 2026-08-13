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

    /**
     * Who this app is on the fleet.
     *
     * The hostname in APP_URL is the answer, and BRIDGE_APP_ID may only agree
     * with it. It used to win outright, which is how travis.dashcore.com kept
     * signing as `executiveos` — an identity in its env from an enrolment run
     * long before, which no amount of re-enrolling would have displaced,
     * because this method never consulted the URL at all.
     *
     * A configured value that disagrees is ignored rather than obeyed, and
     * ignored loudly: it is a leftover from a copied env in every case seen so
     * far, and honouring it means impersonating another member.
     */
    public function appId(): ?string
    {
        $derived = AppId::derive();
        $configured = config('bridge.app_id') ?: null;

        if ($configured !== null && $derived !== null && $configured !== $derived) {
            logger()->warning('bridge: BRIDGE_APP_ID disagrees with APP_URL and is being ignored', [
                'configured' => $configured,
                'derived' => $derived,
                'hint' => 'Remove BRIDGE_APP_ID; the hostname in APP_URL is the identity.',
            ]);
        }

        // Falling back to the configured value when the URL cannot supply one
        // keeps an app whose APP_URL is unset running on its existing
        // credential rather than losing its identity mid-request. Enrolment
        // refuses that state; signing does not have to.
        return $derived ?: ($configured ?: $this->stored()?->app_id);
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
