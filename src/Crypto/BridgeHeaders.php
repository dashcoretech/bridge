<?php

namespace Dashcore\Bridge\Crypto;

use Illuminate\Support\Str;

class BridgeHeaders
{
    /**
     * Signed Bridge-* headers for an outbound request as this app.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function sign(string $method, string $path, array $query = [], string $body = ''): array
    {
        $timestamp = now()->toIso8601ZuluString();
        $nonce = Str::random(32);

        $canonical = CanonicalRequest::build($method, $path, $query, $timestamp, $nonce, $body);

        return [
            'Bridge-App' => config('bridge.app_id'),
            'Bridge-Key' => config('bridge.key_id'),
            'Bridge-Time' => $timestamp,
            'Bridge-Nonce' => $nonce,
            'Bridge-Signature' => Signature::sign($canonical, config('bridge.private_key')),
        ];
    }
}
