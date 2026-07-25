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

    /**
     * Abilities the given caller has been granted against this app.
     *
     * @return list<string>
     */
    public function grantsFor(string $appId): array;

    /**
     * Every known peer app ID, excluding this app itself.
     *
     * @return list<string>
     */
    public function peers(): array;
}
