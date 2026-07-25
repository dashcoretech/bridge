<?php

namespace Dashcore\Bridge\Keys;

class ConfigKeysetResolver implements KeysetResolver
{
    public function publicKeysFor(string $appId): array
    {
        return config("bridge.peers.{$appId}.keys", []);
    }

    public function urlFor(string $appId): ?string
    {
        return config("bridge.peers.{$appId}.url");
    }
}
