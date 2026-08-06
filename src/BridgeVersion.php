<?php

namespace Dashcore\Bridge;

use Composer\InstalledVersions;
use Throwable;

class BridgeVersion
{
    /**
     * The installed dashcore/bridge version as Composer knows it — a tag
     * like "v0.5.0" for VCS installs, a branch alias for path installs,
     * or "unknown" when Composer runtime metadata is unavailable.
     */
    public static function version(): string
    {
        try {
            if (InstalledVersions::isInstalled('dashcore/bridge')) {
                return InstalledVersions::getPrettyVersion('dashcore/bridge') ?? 'unknown';
            }
        } catch (Throwable) {
            // Composer runtime API missing (non-Composer autoload) — fall through.
        }

        return 'unknown';
    }
}
