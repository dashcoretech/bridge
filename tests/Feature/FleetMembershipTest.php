<?php

use Dashcore\Bridge\Models\BridgeCall;
use Dashcore\Bridge\Testing\InteractsWithBridge;

uses(InteractsWithBridge::class);

/*
| Use get()/post() here, never getJson(): getJson() encodes its (empty) data
| argument and sends "[]" as the body even on a GET, which is not what
| bridgeHeaders() signed over — every request would fail BRIDGE_BAD_SIGNATURE
| for reasons that have nothing to do with the test.
*/

/*
|--------------------------------------------------------------------------
| Fleet membership is the authorization
|--------------------------------------------------------------------------
|
| These pin the posture: a cryptographically proven member of the fleet may
| call any endpoint of any other member, and everything that establishes
| membership still refuses everything else. If a future change reintroduces
| per-ability denial by default, the first test here fails.
*/

it('lets a signed peer call an endpoint it holds no grant for', function () {
    // No grants at all — under the old deny-by-default posture this 403'd.
    $this->registerBridgePeer('executiveos', grants: []);

    $this->get('/api/bridge/v1/vault', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault'))
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('still records which ability the call exercised', function () {
    $this->registerBridgePeer('executiveos');

    $this->get('/api/bridge/v1/vault', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault'))
        ->assertOk();

    // The ability survives as a label even though it gated nothing — the
    // audit trail and the endpoint directory are what read it now.
    $call = BridgeCall::query()->latest('id')->first();

    expect($call)->not->toBeNull()
        ->and($call->ability)->toBe('vault.read')
        ->and($call->outcome)->toBe('ok')
        ->and($call->caller_app)->toBe('executiveos');
});

/*
|--------------------------------------------------------------------------
| Membership is still hard to forge
|--------------------------------------------------------------------------
|
| Opening up authorization only makes sense if authentication is airtight,
| so these guard the door the scope layer no longer guards.
*/

it('refuses a request with no signature headers', function () {
    $this->registerBridgePeer('executiveos');

    $this->get('/api/bridge/v1/vault')
        ->assertUnauthorized()
        ->assertJson(['error' => 'BRIDGE_MISSING_HEADERS']);
});

it('refuses a forged signature', function () {
    $this->registerBridgePeer('executiveos');

    $headers = $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault');
    $headers['Bridge-Signature'] = base64_encode(random_bytes(64));

    $this->get('/api/bridge/v1/vault', $headers)
        ->assertUnauthorized()
        ->assertJson(['error' => 'BRIDGE_BAD_SIGNATURE']);
});

it('refuses a peer that is not enrolled', function () {
    $this->registerBridgePeer('executiveos');

    $headers = $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault');
    $headers['Bridge-App'] = 'not-in-the-fleet';

    $this->get('/api/bridge/v1/vault', $headers)
        ->assertUnauthorized()
        ->assertJson(['error' => 'BRIDGE_UNKNOWN_PEER']);
});

it('refuses a signature whose body has been tampered with', function () {
    $this->registerBridgePeer('executiveos');

    // Signed for an empty query string, replayed with one appended.
    $headers = $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault');

    $this->get('/api/bridge/v1/vault?scope=everything', $headers)
        ->assertUnauthorized()
        ->assertJson(['error' => 'BRIDGE_BAD_SIGNATURE']);
});

it('refuses a replayed nonce', function () {
    $this->registerBridgePeer('executiveos');

    $headers = $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault');

    $this->get('/api/bridge/v1/vault', $headers)->assertOk();

    $this->get('/api/bridge/v1/vault', $headers)
        ->assertUnauthorized()
        ->assertJson(['error' => 'BRIDGE_REPLAY']);
});

it('refuses a timestamp outside the clock-skew window', function () {
    $this->registerBridgePeer('executiveos');

    $headers = $this->bridgeHeaders(
        'executiveos', 'GET', '/api/bridge/v1/vault',
        timestamp: now()->subHour()->toIso8601ZuluString(),
    );

    $this->get('/api/bridge/v1/vault', $headers)
        ->assertUnauthorized()
        ->assertJson(['error' => 'BRIDGE_CLOCK_SKEW']);
});

/*
|--------------------------------------------------------------------------
| The switch back
|--------------------------------------------------------------------------
|
| Grants are still resolved, so a fleet that needs per-ability policing can
| have it with a config change rather than a migration.
*/

it('denies an ungranted ability when enforce_scope is on', function () {
    config()->set('bridge.enforce_scope', true);

    $this->registerBridgePeer('executiveos', grants: ['something.else']);

    $this->get('/api/bridge/v1/vault', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault'))
        ->assertForbidden()
        ->assertJson(['error' => 'BRIDGE_SCOPE_DENIED']);
});

it('allows a granted ability when enforce_scope is on', function () {
    config()->set('bridge.enforce_scope', true);

    $this->registerBridgePeer('executiveos', grants: ['vault.read']);

    $this->get('/api/bridge/v1/vault', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/vault'))
        ->assertOk();
});
