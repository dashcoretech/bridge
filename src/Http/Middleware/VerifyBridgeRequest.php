<?php

namespace Dashcore\Bridge\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Dashcore\Bridge\BridgePeer;
use Dashcore\Bridge\Crypto\CanonicalRequest;
use Dashcore\Bridge\Crypto\Signature;
use Dashcore\Bridge\Keys\KeysetResolver;
use Dashcore\Bridge\Keys\ManifestKeysetResolver;
use Dashcore\Bridge\Models\BridgeCall;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyBridgeRequest
{
    public function __construct(protected KeysetResolver $keys) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $app = $request->header('Bridge-App');
        $keyId = $request->header('Bridge-Key');
        $time = $request->header('Bridge-Time');
        $nonce = $request->header('Bridge-Nonce');
        $signature = $request->header('Bridge-Signature');

        if (! $app || ! $keyId || ! $time || ! $nonce || ! $signature) {
            return $this->reject($request, $app, $keyId, 'BRIDGE_MISSING_HEADERS', 'One or more Bridge-* headers are missing.');
        }

        $publicKey = $this->keys->publicKeysFor($app)[$keyId] ?? null;

        if ($publicKey === null && $this->keys instanceof ManifestKeysetResolver) {
            $this->keys->refresh();
            $publicKey = $this->keys->publicKeysFor($app)[$keyId] ?? null;
        }

        if ($publicKey === null) {
            $code = $this->keys->publicKeysFor($app) === [] ? 'BRIDGE_UNKNOWN_PEER' : 'BRIDGE_UNKNOWN_KEY';

            return $this->reject($request, $app, $keyId, $code, "No public key for app [{$app}] with key ID [{$keyId}].");
        }

        $timestamp = rescue(fn () => CarbonImmutable::make($time), report: false);

        if ($timestamp === null || abs(now()->diffInSeconds($timestamp)) > config('bridge.clock_skew')) {
            return $this->reject($request, $app, $keyId, 'BRIDGE_CLOCK_SKEW', 'Bridge-Time is missing, malformed, or outside the accepted window.', [
                'server_time' => now()->toIso8601ZuluString(),
            ]);
        }

        $canonical = CanonicalRequest::build(
            method: $request->method(),
            path: $request->getPathInfo(),
            query: $request->query(),
            timestamp: $time,
            nonce: $nonce,
            body: $request->getContent(),
        );

        if (! Signature::verify($canonical, $signature, $publicKey)) {
            return $this->reject($request, $app, $keyId, 'BRIDGE_BAD_SIGNATURE', 'Signature verification failed.');
        }

        if (! Cache::add("bridge:nonce:{$app}:{$nonce}", 1, config('bridge.nonce_ttl'))) {
            return $this->reject($request, $app, $keyId, 'BRIDGE_REPLAY', 'Nonce has already been used.');
        }

        $request->attributes->set('bridge_peer', new BridgePeer($app, $keyId));

        $response = $next($request);

        if ($request->attributes->get('bridge_audited')) {
            return $response;
        }

        BridgeCall::record(
            callerApp: $app,
            keyId: $keyId,
            method: $request->method(),
            path: $request->getPathInfo(),
            outcome: 'ok',
            status: $response->getStatusCode(),
            ability: $request->attributes->get('bridge_ability'),
            durationMs: (int) ((microtime(true) - $started) * 1000),
        );

        return $response;
    }

    /**
     * @param  array<string, string>  $extra
     */
    protected function reject(Request $request, ?string $app, ?string $keyId, string $code, string $detail, array $extra = []): Response
    {
        BridgeCall::record(
            callerApp: $app,
            keyId: $keyId,
            method: $request->method(),
            path: $request->getPathInfo(),
            outcome: $code,
            status: 401,
        );

        return response()->json(['error' => $code, 'detail' => $detail, ...$extra], 401, ['Cache-Control' => 'no-store']);
    }
}
