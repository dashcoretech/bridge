<?php

namespace Dashcore\Bridge\Keys;

interface KeysetResolver
{
    /**
     * The peer's published public keys, keyed by key ID (kid). Empty array
     * when the peer is unknown.
     *
     * @return array<string, string>
     */
    public function publicKeysFor(string $appId): array;

    /**
     * The peer's base URL, or null when unknown.
     */
    public function urlFor(string $appId): ?string;
}
