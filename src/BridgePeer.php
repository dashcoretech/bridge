<?php

namespace Dashcore\Bridge;

class BridgePeer
{
    public function __construct(
        public readonly string $appId,
        public readonly string $keyId,
    ) {}

    /**
     * Abilities granted to this peer by the receiving app, plus the implicit
     * `bridge.ping` every known peer holds.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return [...config("bridge.grants.{$this->appId}", []), 'bridge.ping'];
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), strict: true);
    }
}
