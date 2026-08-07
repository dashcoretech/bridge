# dashcore/bridge

Secure Laravel-to-Laravel API access for the Dashcore fleet: Ed25519 signed
requests, replay protection, and audit logging. Fleet membership is the
authorization.

The calling application holds a private key; the control plane stores only the
public half. No secret capable of producing a signature ever leaves the
application that owns it.

## Installing in a connecting site

The package lives in a public repository — no credentials are needed to
install it, locally or on a build host:

```bash
composer config repositories.dashcore-bridge vcs https://github.com/dashcoretech/bridge
composer require dashcore/bridge:^0.5
```

The repository is deliberately public: nothing secret ships in the package.
Every real secret — `BRIDGE_PRIVATE_KEY`, fleet keys — lives in each app's
environment or database, never here.

## Enrolling

Mint a one-time token on the control plane:

```bash
# on api.dashcore.com
php artisan platform:enroll-token <slug> --company=<company> --name="<Name>"
```

Redeem it from the connecting site. The keypair is generated locally and the
private half never crosses the network:

```bash
php artisan bridge:install --token=<token> --control=https://api.dashcore.com
```

The command prints an env block to paste in:

```dotenv
BRIDGE_APP_ID=your-app-slug
BRIDGE_KEY_ID=your-app-slug-2026-08
BRIDGE_PRIVATE_KEY=<base64 Ed25519 secret key>

BRIDGE_DRIVER=control
BRIDGE_CONTROL_URL=https://api.dashcore.com
BRIDGE_CONTROL_KEY=<control plane public key, returned by enrolment>
```

`BRIDGE_PRIVATE_KEY` is the only real secret here. `BRIDGE_KEY_ID` and the
public key are not secrets — they select which key to verify against.

`BRIDGE_PRIVATE_KEY` is what makes this app a member of the fleet, and
membership is the authorization — see below.

## Calling the platform

```php
use Dashcore\Bridge\Facades\Bridge;

$response = Bridge::to('api')->get('/api/v1/me');

$response = Bridge::to('api')->post('/api/v1/events', ['type' => 'user.created']);
```

`get`, `post`, `put`, `patch` and `delete` are available, plus `timeout()` and
`retry()`. Signing, nonces, timestamps and the canonical string are handled for
you — including the one detail that breaks hand-rolled clients, which is that
the body must be transmitted byte-for-byte as it was hashed.

## Receiving calls from the fleet

```php
Route::middleware(['bridge.auth', 'bridge.scope:reports.read'])
    ->get('/api/bridge/v1/reports', ReportController::class);
```

`bridge.auth` is the gate. It rejects anything that cannot prove fleet
membership: an unknown app, an unknown or revoked key, a stale timestamp, a
bad signature, or a reused nonce. All of those are a `401` before your
controller runs.

`bridge.scope:reports.read` is a **label, not a gate**. Fleet membership is
the authorization: a caller that has cryptographically proven it is an
enrolled member may call any endpoint of any other member. The ability names
the capability in the audit trail and in the fleet's endpoint directory,
which is what reads it.

That is a deliberate posture, and it rests on revocation rather than
scoping. Revoke a credential and it leaves the signed manifest, so the
caller is refused at the door instead of admitted and narrowed afterwards.
Because the manifest is cached (`BRIDGE_MANIFEST_TTL`, 300s), that is how
long a revocation takes to reach the whole fleet — the number worth knowing
if you are relying on it.

To police abilities individually instead:

```env
BRIDGE_ENFORCE_SCOPE=true
```

Grants are still resolved from the manifest either way, so this is a switch
rather than a migration.

The package ships one route already — `GET /api/bridge/v1/ping`, labelled
`bridge.ping` — so a peer can prove reachability without exposing anything.

## Checking it works

```bash
php artisan bridge:doctor
```

Reports this app's identity, manifest freshness, and whether every peer is
reachable. Run it first whenever a call starts failing.

## Keys

```bash
php artisan bridge:keys:generate   # a fresh keypair, printed as an env block
php artisan bridge:keys:rotate     # register a new key, signed under the current one
```

Rotation is overlap-then-retire, never swap-in-place: the new credential goes
live immediately and the outgoing one keeps verifying for a grace period
(24 hours by default), so a fleet rolling one instance at a time never hits a
window where neither key is accepted.

## Configuration

`BRIDGE_DRIVER=control` resolves peers, keys and grants from the fleet manifest
published and signed by the control plane. `config` reads static arrays from
`config/bridge.php` instead — local development and break-glass only.

`BRIDGE_MANIFEST_TTL` (default 300s) is how long a cached manifest is trusted,
and therefore how long a revocation elsewhere in the fleet takes to be noticed
here.

## Source of truth

This repository is published from `packages/dashcore/bridge` in
`dashcoretech/api.dashcore.com`. Send changes there; commits pushed here
directly will be overwritten by the next publish.
