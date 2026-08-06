# dashcore/bridge

Secure Laravel-to-Laravel API access for the Dashcore fleet: Ed25519 signed
requests, deny-by-default grants, replay protection, and audit logging.

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

Enrolment grants only the baseline permissions (`bridge.manifest`,
`bridge.rotate`). Anything else is granted deliberately on the control plane
afterwards; it is never a path to privilege.

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

Routes protected by bridge auth verify the caller's signature and check the
declared scope:

```php
Route::middleware(['bridge.auth', 'bridge.scope:reports.read'])
    ->get('/api/bridge/v1/reports', ReportController::class);
```

The package ships one such route already — `GET /api/bridge/v1/ping`, scoped to
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
