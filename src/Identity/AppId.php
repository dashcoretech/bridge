<?php

namespace Dashcore\Bridge\Identity;

/**
 * The default bridge app ID is the app's own hostname — `travis.dashcore.com`,
 * not a slugged mash of its display name. The URL is already unique across the
 * fleet, already meaningful in every log line and manifest entry, and already
 * what an operator types to reach the thing.
 */
class AppId
{
    public static function derive(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        return str(config('app.name'))->slug()->toString();
    }
}
