<?php

namespace Dashcore\Bridge\Identity;

/**
 * Which environment a URL belongs to, decided by its hostname alone.
 *
 * ## Why the hostname decides
 *
 * A fleet member's identity is its URL (see AppId), so the environment is
 * already encoded in that identity and does not need to be configured
 * separately. `travis.dashcore.com.test` and `travis.dashcore.com` are
 * different hosts, therefore different members, therefore different keypairs
 * — which is exactly right, because they are different instances.
 *
 * The alternative — a BRIDGE_ENV flag — is a second source of truth that can
 * disagree with the first. A production app with a stale local flag would
 * enrol against the wrong control plane and nothing would notice. Deriving it
 * means the two cannot drift.
 *
 * ## Why cross-environment is refused rather than merely discouraged
 *
 * The failure it prevents is a local machine holding a credential the
 * production manifest accepts. Nothing about the signing scheme stops that on
 * its own: a key is a key. Refusing at enrolment and at call time — where the
 * caller's own host and the control plane's host must agree — is what keeps a
 * developer laptop out of the production fleet.
 */
final class Environment
{
    public const LOCAL = 'local';

    public const PRODUCTION = 'production';

    /**
     * Suffixes that only ever resolve on a developer machine.
     *
     * `.test` is Herd's; the others are conventional. Anything else is treated
     * as production, which is the safe direction to be wrong in: a real host
     * misfiled as local would be refused by the production control plane
     * rather than admitted by it.
     */
    private const LOCAL_SUFFIXES = ['.test', '.localhost', '.local'];

    /**
     * Hosts that identify no particular application.
     *
     * `localhost` is the dangerous one. It is the Laravel default, so an app
     * deployed without APP_URL set would enrol as "localhost" — and so would
     * every other app in the same state, all claiming one identity. There is
     * a site in this fleet sitting on `APP_URL=http://localhost` right now.
     */
    private const AMBIGUOUS = ['localhost', '127.0.0.1', '0.0.0.0', '::1', 'host.docker.internal'];

    /**
     * The environment a hostname belongs to.
     */
    public static function of(string $host): string
    {
        $host = self::normalise($host);

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return self::LOCAL;
            }
        }

        return in_array($host, self::AMBIGUOUS, true) ? self::LOCAL : self::PRODUCTION;
    }

    /**
     * Whether a hostname names one specific application.
     *
     * Ambiguous hosts are rejected at enrolment rather than allowed through
     * with a warning: an identity that several applications can legitimately
     * claim is not an identity, and the resulting collision is silent — the
     * second app to enrol simply takes over the first one's credential.
     */
    public static function isAmbiguous(string $host): bool
    {
        $host = self::normalise($host);

        return $host === '' || in_array($host, self::AMBIGUOUS, true);
    }

    /**
     * Whether two hosts belong to the same environment.
     */
    public static function agree(string $a, string $b): bool
    {
        return self::of($a) === self::of($b);
    }

    /**
     * The host portion of a URL, canonicalised for use as an identity.
     *
     * Scheme and port are dropped deliberately. A site reached over http in
     * development and https in production is one member of the fleet, and
     * making `http://x.test` and `https://x.test` distinct identities would
     * hand out two credentials for one application.
     */
    public static function host(?string $url): string
    {
        if ($url === null || trim($url) === '') {
            return '';
        }

        $url = trim($url);

        // parse_url wants a scheme to find a host; a bare "example.com" would
        // otherwise parse as a path.
        $host = parse_url(str_contains($url, '//') ? $url : '//'.$url, PHP_URL_HOST);

        if (! is_string($host)) {
            return '';
        }

        $host = self::normalise(trim($host, '[]'));

        // parse_url is permissive enough to hand back "not a url" as a host.
        // A value that cannot be a hostname must not become an identity, so it
        // is rejected here rather than enrolled and puzzled over later.
        return preg_match('/^[a-z0-9]([a-z0-9.:_-]*[a-z0-9])?$/', $host) === 1 ? $host : '';
    }

    private static function normalise(string $host): string
    {
        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }
}
