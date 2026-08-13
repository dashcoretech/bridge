<?php

namespace Dashcore\Bridge\Identity;

use Dashcore\Bridge\Exceptions\InvalidIdentity;

/**
 * A fleet member's identity is its hostname. Nothing else.
 *
 * `travis.dashcore.com`, not a slug someone typed. The URL is already unique
 * across the fleet, already meaningful in every log line and manifest entry,
 * and already what an operator types to reach the thing.
 *
 * ## Why this is now the rule rather than the default
 *
 * It used to be the last resort — `--app-id` and `BRIDGE_APP_ID` both won
 * ahead of it, and the control plane accepted whatever slug arrived alongside
 * the URL without checking the two agreed. So an enrolment token minted for
 * one application could be redeemed by another, and was: travis.dashcore.com
 * spent a week signing every request as `executiveos`, an unrelated site that
 * does not use the bridge at all. Every call it made was attributed to that
 * site, and revoking that site's credential would have silently killed travis.
 *
 * Deriving the identity from the URL closes that by construction. A copied
 * .env now fails at the door — the key registered for one host will not verify
 * a request claiming to be another — instead of quietly impersonating.
 *
 * ## What this does not do
 *
 * A URL identifies; it cannot authenticate. Anyone can put any host in a
 * header. The Ed25519 signature is what proves the claim, and that is
 * unchanged. All that moves here is which name the key is filed under.
 */
class AppId
{
    /**
     * This application's identity, or null when its URL cannot supply one.
     */
    public static function derive(): ?string
    {
        $host = Environment::host(config('app.url'));

        // No fall back to a slugged app.name. Every app scaffolded from the
        // same starter shares that name — several in this fleet are all
        // "DashCore" — so the fallback produced collisions precisely when the
        // real answer was missing.
        return $host === '' || Environment::isAmbiguous($host) ? null : $host;
    }

    /**
     * This application's identity, or an explanation of why it has none.
     *
     * Used by the enrolment commands, where continuing without a real identity
     * means minting a credential nobody can attribute.
     *
     * @throws InvalidIdentity
     */
    public static function require(): string
    {
        $configured = config('app.url');
        $host = Environment::host($configured);

        if ($host === '') {
            throw new InvalidIdentity(
                'APP_URL does not contain a hostname, so this app has no fleet identity. '
                .'Set APP_URL to the address other apps reach this one on, then enrol again.'
            );
        }

        if (Environment::isAmbiguous($host)) {
            throw new InvalidIdentity(
                "APP_URL is [{$configured}], and [{$host}] does not name one particular application — "
                .'every app left on the default would claim the same identity, and the last to enrol '
                .'would take over the others. Set APP_URL to this app\'s own hostname and enrol again.'
            );
        }

        return $host;
    }
}
