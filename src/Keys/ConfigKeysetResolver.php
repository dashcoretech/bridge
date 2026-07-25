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

    public function grantsFor(string $appId): array
    {
        return config("bridge.grants.{$appId}", []);
    }

    public function peers(): array
    {
        return array_values(array_diff(array_keys(config('bridge.peers', [])), [config('bridge.app_id')]));
    }
}
