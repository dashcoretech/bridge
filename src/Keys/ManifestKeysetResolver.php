<?php

namespace Dashcore\Bridge\Keys;

use Dashcore\Bridge\Crypto\BridgeHeaders;
use Dashcore\Bridge\Crypto\Signature;
use Dashcore\Bridge\Exceptions\PeerUnreachable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ManifestKeysetResolver implements KeysetResolver
{
    protected const CACHE_KEY = 'bridge:manifest';

    protected const LAST_KNOWN_GOOD_KEY = 'bridge:manifest:lkg';

    public function publicKeysFor(string $appId): array
    {
        return $this->manifest()['apps'][$appId]['keys'] ?? [];
    }

    public function urlFor(string $appId): ?string
    {
        return $this->manifest()['apps'][$appId]['url'] ?? null;
    }

    public function grantsFor(string $appId): array
    {
        return $this->manifest()['grants'][config('bridge.app_id')][$appId] ?? [];
    }

    public function peers(): array
    {
        return array_values(array_diff(array_keys($this->manifest()['apps'] ?? []), [config('bridge.app_id')]));
    }

    /**
     * The verified fleet manifest: served from cache while fresh, refetched
     * from the control plane when stale, and falling back to the persisted
     * last-known-good copy when the control plane is unreachable — a
     * control-plane outage freezes fleet state rather than breaking traffic.
     *
     * @return array{apps: array<string, array{url: string, keys: array<string, string>}>, grants: array<string, array<string, list<string>>>}
     */
    public function manifest(): array
    {
        $fresh = Cache::get(static::CACHE_KEY);

        if ($fresh !== null) {
            return $fresh;
        }

        try {
            $manifest = $this->fetch();
        } catch (\Throwable $e) {
            $lastKnownGood = Cache::get(static::LAST_KNOWN_GOOD_KEY);

            if ($lastKnownGood === null) {
                throw $e;
            }

            report($e);

            return $lastKnownGood;
        }

        Cache::put(static::CACHE_KEY, $manifest, config('bridge.manifest_ttl'));
        Cache::forever(static::LAST_KNOWN_GOOD_KEY, $manifest);

        return $manifest;
    }

    /**
     * Drop the fresh-cache entry so the next lookup refetches (used after an
     * unknown-kid miss so key rotation propagates within one request).
     */
    public function refresh(): void
    {
        Cache::forget(static::CACHE_KEY);
    }

    protected function fetch(): array
    {
        $path = '/api/bridge/v1/manifest';
        $url = rtrim(config('bridge.control.url'), '/').$path;

        $response = Http::withHeaders(BridgeHeaders::sign('GET', $path))
            ->timeout(config('bridge.timeout'))
            ->get($url);

        if ($response->failed()) {
            throw new PeerUnreachable("Control plane returned {$response->status()} for the fleet manifest.");
        }

        // The control plane wraps every response in the platform envelope;
        // the manifest itself is the `data` member.
        $payload = base64_decode($response->json('data.payload', ''), strict: true);
        $signature = $response->json('data.signature', '');

        if ($payload === false || ! Signature::verify($payload, $signature, config('bridge.control.public_key'))) {
            throw new PeerUnreachable('Fleet manifest signature verification failed — refusing unsigned fleet state.');
        }

        return json_decode($payload, associative: true);
    }
}
