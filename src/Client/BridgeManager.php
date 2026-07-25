<?php

namespace Dashcore\Bridge\Client;

use Dashcore\Bridge\Exceptions\UnknownPeer;
use Dashcore\Bridge\Keys\KeysetResolver;

class BridgeManager
{
    public function __construct(protected KeysetResolver $keys) {}

    /**
     * Begin a signed request to another app in the fleet.
     */
    public function to(string $appId): PendingBridgeRequest
    {
        $url = $this->keys->urlFor($appId);

        if ($url === null) {
            throw new UnknownPeer("No configured peer [{$appId}].");
        }

        if (! str_starts_with($url, 'https://') && ! config('bridge.allow_insecure')) {
            throw new UnknownPeer("Peer [{$appId}] URL is not HTTPS and bridge.allow_insecure is off.");
        }

        return new PendingBridgeRequest($appId, $url);
    }
}
