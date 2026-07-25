<?php

namespace Dashcore\Bridge\Testing;

use Dashcore\Bridge\Crypto\CanonicalRequest;
use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Crypto\Signature;
use Illuminate\Support\Str;

trait InteractsWithBridge
{
    /**
     * Register an ephemeral peer with a real keypair and the given grants,
     * so tests can exercise the full verification path end-to-end.
     */
    protected function registerBridgePeer(string $appId, array $grants = [], string $url = 'https://peer.test'): Keypair
    {
        $pair = Keypair::generate();
        $keyId = "{$appId}-test";

        config()->set("bridge.peers.{$appId}", ['url' => $url, 'keys' => [$keyId => $pair->publicKey]]);
        config()->set("bridge.grants.{$appId}", $grants);

        $this->bridgePeerKeys[$appId] = [$keyId, $pair];

        return $pair;
    }

    /**
     * Produce valid signed Bridge-* headers for a request as a peer
     * registered via registerBridgePeer().
     *
     * @return array<string, string>
     */
    protected function bridgeHeaders(
        string $appId,
        string $method,
        string $path,
        array $query = [],
        string $body = '',
        ?string $timestamp = null,
        ?string $nonce = null,
    ): array {
        [$keyId, $pair] = $this->bridgePeerKeys[$appId];

        $timestamp ??= now()->toIso8601ZuluString();
        $nonce ??= Str::random(32);

        $canonical = CanonicalRequest::build($method, $path, $query, $timestamp, $nonce, $body);

        return [
            'Bridge-App' => $appId,
            'Bridge-Key' => $keyId,
            'Bridge-Time' => $timestamp,
            'Bridge-Nonce' => $nonce,
            'Bridge-Signature' => Signature::sign($canonical, $pair->privateKey),
        ];
    }

    /** @var array<string, array{0: string, 1: Keypair}> */
    protected array $bridgePeerKeys = [];
}
