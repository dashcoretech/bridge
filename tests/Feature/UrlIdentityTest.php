<?php

use Dashcore\Bridge\Exceptions\InvalidIdentity;
use Dashcore\Bridge\Identity\AppId;
use Dashcore\Bridge\Identity\Environment;
use Dashcore\Bridge\Identity\IdentityResolver;

describe('reading a host from a URL', function () {
    // Scheme and port are dropped on purpose: a site reached over http in
    // development and https in production is one member of the fleet, and
    // splitting them would hand out two credentials for one application.
    it('canonicalises to the bare lowercase host', function (string $url, string $expected) {
        expect(Environment::host($url))->toBe($expected);
    })->with([
        ['https://travis.dashcore.com', 'travis.dashcore.com'],
        ['http://travis.dashcore.com.test', 'travis.dashcore.com.test'],
        ['https://TRAVIS.DashCore.com/', 'travis.dashcore.com'],
        ['http://travis.dashcore.com:8080/path?x=1', 'travis.dashcore.com'],
        ['travis.dashcore.com', 'travis.dashcore.com'],
        ['', ''],
        ['not a url', ''],
    ]);
});

describe('which fleet a host belongs to', function () {
    it('reads the environment off the hostname', function (string $host, string $expected) {
        expect(Environment::of($host))->toBe($expected);
    })->with([
        ['travis.dashcore.com.test', Environment::LOCAL],
        ['api.dashcore.com.test', Environment::LOCAL],
        ['something.localhost', Environment::LOCAL],
        ['travis.dashcore.com', Environment::PRODUCTION],
        ['api.dashcore.com', Environment::PRODUCTION],
    ]);

    // Wrong in the safe direction: a real host misfiled as local is refused by
    // the production control plane, rather than admitted by it.
    it('treats an unrecognised suffix as production', function () {
        expect(Environment::of('some.internal.host'))->toBe(Environment::PRODUCTION);
    });

    it('knows when two hosts are in different fleets', function () {
        expect(Environment::agree('travis.dashcore.com.test', 'api.dashcore.com.test'))->toBeTrue()
            ->and(Environment::agree('travis.dashcore.com', 'api.dashcore.com'))->toBeTrue()
            ->and(Environment::agree('travis.dashcore.com.test', 'api.dashcore.com'))->toBeFalse();
    });
});

describe('hosts that identify nothing', function () {
    // localhost is the Laravel default, so an app deployed without APP_URL set
    // would enrol as "localhost" — and so would every other app in that state,
    // all claiming one identity. A site in this fleet is sitting on
    // APP_URL=http://localhost right now.
    it('rejects a host several applications could claim', function (string $host) {
        expect(Environment::isAmbiguous($host))->toBeTrue();
    })->with(['localhost', '127.0.0.1', '0.0.0.0', '::1', '']);

    it('accepts a host that names one application', function () {
        expect(Environment::isAmbiguous('travis.dashcore.com'))->toBeFalse();
    });
});

describe('deriving this app\'s identity', function () {
    it('is the hostname in APP_URL', function () {
        config()->set('app.url', 'https://travis.dashcore.com');

        expect(AppId::derive())->toBe('travis.dashcore.com')
            ->and(AppId::require())->toBe('travis.dashcore.com');
    });

    it('is the local hostname when running locally', function () {
        config()->set('app.url', 'http://travis.dashcore.com.test');

        expect(AppId::require())->toBe('travis.dashcore.com.test');
    });

    it('has no identity when the URL names no application', function () {
        config()->set('app.url', 'http://localhost');

        expect(AppId::derive())->toBeNull();
    });

    it('explains itself rather than guessing when the URL is unusable', function () {
        config()->set('app.url', 'http://localhost');

        expect(fn () => AppId::require())
            ->toThrow(InvalidIdentity::class, 'does not name one particular application');
    });

    // Every app scaffolded from the same starter shares app.name — several in
    // this fleet are all "DashCore" — so the old fallback produced collisions
    // exactly when the real answer was missing.
    it('never falls back to the app name', function () {
        config()->set('app.url', '');
        config()->set('app.name', 'DashCore');

        expect(AppId::derive())->toBeNull()
            ->and(fn () => AppId::require())->toThrow(InvalidIdentity::class);
    });
});

describe('the identity used at runtime', function () {
    // The regression this whole change exists for. travis.dashcore.com spent a
    // week signing every request as `executiveos` — an unrelated site that does
    // not use the bridge — because BRIDGE_APP_ID won outright and nothing ever
    // consulted the URL.
    it('ignores a configured app id that disagrees with the URL', function () {
        config()->set('app.url', 'https://travis.dashcore.com');
        config()->set('bridge.app_id', 'executiveos');

        expect(app(IdentityResolver::class)->appId())->toBe('travis.dashcore.com');
    });

    it('accepts a configured app id that agrees with the URL', function () {
        config()->set('app.url', 'https://travis.dashcore.com');
        config()->set('bridge.app_id', 'travis.dashcore.com');

        expect(app(IdentityResolver::class)->appId())->toBe('travis.dashcore.com');
    });

    // Signing does not have to fail just because enrolment would: an app whose
    // APP_URL is unset keeps running on the credential it already has.
    it('falls back to the configured id when the URL supplies none', function () {
        config()->set('app.url', 'http://localhost');
        config()->set('bridge.app_id', 'legacy-app');

        expect(app(IdentityResolver::class)->appId())->toBe('legacy-app');
    });
});
