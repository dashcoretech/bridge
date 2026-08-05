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
    | Inbound grants
    |--------------------------------------------------------------------------
    |
    | Which abilities each peer may exercise against this app. Deny by
    | default: a peer with no entry here is rejected even with a valid
    | signature. `bridge.ping` is implicitly granted to every known peer.
    */

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

];
