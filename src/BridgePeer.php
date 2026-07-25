<?php

namespace Dashcore\Bridge;

class BridgePeer
{
    public function __construct(
        public readonly string $appId,
        public readonly string $keyId,
    ) {}

    /**
     * Abilities granted to this peer by the receiving app, plus the
     * implicit abilities every known peer holds: ping, manifest read, and
     * rotating its own keys.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return [...app(Keys\KeysetResolver::class)->grantsFor($this->appId), 'bridge.ping', 'bridge.manifest', 'bridge.rotate'];
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), strict: true);
    }
}
