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

Two environment values, and nothing to paste back:

```dotenv
BRIDGE_FLEET_KEY=fleet_…
BRIDGE_CONTROL_URL=https://api.dashcore.com
```

Mint the fleet key on the control plane at `/admin/connect` — it is the same
value for every app in the fleet, so there is no per-app secret to distribute.
Then, on the connecting site:

```bash
php artisan migrate --force && php artisan bridge:connect
```

That generates an Ed25519 keypair locally, enrolls with the control plane, and
stores the identity — app ID, credential ID, private key and the control
plane's public key — encrypted in this app's own database, under `APP_KEY`. The
private half never crosses the network. Leave the command in the deploy script:
once connected it is a no-op.

`BRIDGE_APP_ID`, `BRIDGE_KEY_ID`, `BRIDGE_PRIVATE_KEY`, `BRIDGE_CONTROL_KEY`
and `BRIDGE_DRIVER` are all learned or derived, so none of them belong in the
environment. The app ID defaults to the app's hostname; override it with
`bridge:connect --app-id=<slug>` if it needs to differ.

The site then sits **pending** and cannot authenticate until an admin approves
it at `/admin/connect` on the control plane. That is the point of the fleet key
being shared: possession enrolls, a human admits.

Enrolment grants only the baseline permissions (`bridge.manifest`,
`bridge.rotate`). Anything else is granted deliberately on the control plane
afterwards; it is never a path to privilege.

Two failures are worth recognising, because neither is fixed by retrying:

- **HTTP 409, "already has a live service named […]"** — that app ID is already
  enrolled. An admin must click *Re-enroll* for it on the control plane first;
  `--fresh` will not force past this.
- **"Already connected as […]"** — nothing to do. Do not reach for `--fresh`,
  which discards a working identity and requires an admin to re-admit the app.

### Legacy: token enrolment

`platform:enroll-token` on the control plane and `bridge:install --token=` here
are the superseded path, kept only for apps enrolled before fleet keys existed.
They print an env block containing `BRIDGE_PRIVATE_KEY` for you to paste. Do not
use them for a new site — there is no admin surface for minting the tokens any
more.

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

`BRIDGE_DRIVER` is inferred and rarely worth setting: a `BRIDGE_CONTROL_URL`
means `control`, and its absence means `config`. `control` resolves peers, keys
and grants from the fleet manifest published and signed by the control plane;
`config` reads static arrays from `config/bridge.php` instead — local
development and break-glass only.

`BRIDGE_MANIFEST_TTL` (default 300s) is how long a cached manifest is trusted,
and therefore how long a revocation elsewhere in the fleet takes to be noticed
here.

## Source of truth

This repository is it. The package was previously published from
`packages/dashcore/bridge` in `dashcoretech/api.dashcore.com`, and that path
copy has been removed — the control plane now installs `dashcore/bridge` from
here like every other app in the fleet. Send changes here, and tag a release;
nothing overwrites this repository.
