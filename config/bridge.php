<?php

return [

    /*
    |--------------------------------------------------------------------------
    | This app's bridge identity
    |--------------------------------------------------------------------------
    |
    | Every app in the fleet has a stable slug, an Ed25519 keypair generated
    | by `bridge:keys:generate`, and a key ID so peers can verify against the
    | right public key during rotation. The private key never leaves this app.
    */

    'app_id' => env('BRIDGE_APP_ID'),

    'private_key' => env('BRIDGE_PRIVATE_KEY'),

    'key_id' => env('BRIDGE_KEY_ID'),

    /*
    |--------------------------------------------------------------------------
    | Keyset driver
    |--------------------------------------------------------------------------
    |
    | `control` resolves peers, keys, and grants from the fleet manifest
    | published and signed by the control plane (api.dashcore.com). `config`
    | reads the static peers/grants arrays below — local dev and break-glass.
    */

    // Unset, the driver follows the evidence: a control URL means this is a
    // fleet app resolving peers from the manifest.
    'driver' => env('BRIDGE_DRIVER') ?: (env('BRIDGE_CONTROL_URL') ? 'control' : 'config'),

    'control' => [
        'url' => env('BRIDGE_CONTROL_URL'),
        'public_key' => env('BRIDGE_CONTROL_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet key (zero-paste enrollment)
    |--------------------------------------------------------------------------
    |
    | With this and the control URL set, `bridge:connect` self-enrolls: the
    | keypair is generated locally, the control plane's public key is learned
    | from the handshake, and the whole identity is stored encrypted in this
    | app's database. No other BRIDGE_* env is needed.
    */

    'fleet_key' => env('BRIDGE_FLEET_KEY'),

    'manifest_ttl' => env('BRIDGE_MANIFEST_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Peers (static keyset driver)
    |--------------------------------------------------------------------------
    |
    | Known peers keyed by app ID. Each entry carries the peer's base URL and
    | its published public keys keyed by key ID. In production this map is
    | superseded by the control-plane fleet manifest; the static driver stays
    | for local development and break-glass fallback.
    */

    'peers' => [
        // 'crm' => [
        //     'url' => env('BRIDGE_PEER_CRM_URL'),
        //     'keys' => ['crm-2026-07' => 'base64-public-key'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound authorization
    |--------------------------------------------------------------------------
    |
    | Fleet membership is the authorization. A validly signed request comes
    | from an enrolled member of this fleet, and members may call one
    | another freely — the ability on each route is a label for the audit
    | trail and the endpoint directory, not a gate.
    |
    | Turn `enforce_scope` on to police abilities individually instead. The
    | grants below (and those published in the signed manifest) are still
    | resolved either way, so this is a switch rather than a migration.
    */

    'enforce_scope' => env('BRIDGE_ENFORCE_SCOPE', false),

    'grants' => [
        // 'crm' => ['contacts.read', 'contacts.write'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay protection
    |--------------------------------------------------------------------------
    */

    'clock_skew' => env('BRIDGE_CLOCK_SKEW', 120),

    'nonce_ttl' => env('BRIDGE_NONCE_TTL', 300),

    /*
    |--------------------------------------------------------------------------
    | Transport
    |--------------------------------------------------------------------------
    |
    | Plaintext HTTP is refused unless explicitly allowed (local dev only).
    */

    'allow_insecure' => env('BRIDGE_ALLOW_INSECURE', false),

    'timeout' => env('BRIDGE_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Health surface
    |--------------------------------------------------------------------------
    |
    | Serves GET /api/bridge/v1/health, guarded by the `bridge.health` ability
    | so it is readable only by a peer that has been granted it. On by default:
    | fleet-wide observability that each app has to opt into is observability
    | most of the fleet will not have.
    |
    | Turn it off in an app that serves its own richer health route at the same
    | path, so the two never both register it.
    |
    */

    'health' => [
        'enabled' => (bool) env('BRIDGE_HEALTH_ENABLED', true),
    ],

];
