<?php

namespace Dashcore\Bridge\Crypto;

class CanonicalRequest
{
    /**
     * Build the canonical string a bridge signature covers. Both sides must
     * produce byte-identical output: method, decoded path, RFC 3986 query
     * sorted by key, the signing timestamp, the nonce, and a hex SHA-256 of
     * the raw body (the empty string hashes the empty body).
     *
     * @param  array<string, mixed>  $query
     */
    public static function build(
        string $method,
        string $path,
        array $query,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        ksort($query);

        return implode("\n", [
            strtoupper($method),
            '/'.ltrim($path, '/'),
            http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }
}
