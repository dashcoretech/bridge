<?php

namespace Dashcore\Bridge\Crypto;

use Dashcore\Bridge\Identity\IdentityResolver;
use Illuminate\Support\Str;

class BridgeHeaders
{
    /**
     * Signed Bridge-* headers for an outbound request as this app. Identity
     * comes from the resolver so a bridge:connect-enrolled app signs with its
     * stored identity, env-configured apps exactly as before.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function sign(string $method, string $path, array $query = [], string $body = ''): array
    {
        $identity = app(IdentityResolver::class);
        $timestamp = now()->toIso8601ZuluString();
        $nonce = Str::random(32);

        $canonical = CanonicalRequest::build($method, $path, $query, $timestamp, $nonce, $body);

        return [
            'Bridge-App' => $identity->appId(),
            'Bridge-Key' => $identity->keyId(),
            'Bridge-Time' => $timestamp,
            'Bridge-Nonce' => $nonce,
            'Bridge-Signature' => Signature::sign($canonical, $identity->privateKey()),
        ];
    }
}
