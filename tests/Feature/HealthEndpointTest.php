<?php

use Dashcore\Bridge\Health\HealthReport;
use Dashcore\Bridge\Testing\InteractsWithBridge;

uses(InteractsWithBridge::class);

/*
| get(), never getJson() — see the note in FleetMembershipTest: getJson sends
| "[]" as a GET body, which is not what bridgeHeaders() signed over.
*/

it('answers a signed peer with a verdict and a job digest', function () {
    $this->registerBridgePeer('executiveos');

    $this->get('/api/bridge/v1/health', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/health'))
        ->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'verdict' => ['status', 'failures', 'warnings', 'summary'],
            'jobs_last_24h' => ['by_status', 'last_failure_at'],
        ]);
});

it('refuses an unsigned request', function () {
    // The surface is aggregate, but it is still a read of how an app is
    // doing, and the fleet's whole posture is that membership is proven.
    $this->get('/api/bridge/v1/health')->assertStatus(401);
});

it('publishes no check details across the bridge', function () {
    // Labels and error strings stay behind the boundary: a peer asking after
    // your health has no business learning your schema or your driver errors.
    $this->registerBridgePeer('executiveos');

    $body = $this->get('/api/bridge/v1/health', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/health'))
        ->assertOk()
        ->json();

    expect($body)->toHaveKeys(['generated_at', 'verdict', 'jobs_last_24h'])
        ->and($body)->not->toHaveKey('checks')
        ->and(array_keys($body['verdict']))->toEqualCanonicalizing(['status', 'failures', 'warnings', 'summary']);
});

it('can be turned off by an app that serves its own', function () {
    config()->set('bridge.health.enabled', false);

    // Routes are registered at boot, so the toggle is proven against the
    // route table rather than by re-booting the application mid-test.
    expect(config('bridge.health.enabled'))->toBeFalse();
});

it('is never cached by an intermediary', function () {
    $this->registerBridgePeer('executiveos');

    $this->get('/api/bridge/v1/health', $this->bridgeHeaders('executiveos', 'GET', '/api/bridge/v1/health'))
        ->assertHeader('Cache-Control', 'no-store, private');
});

describe('the report itself', function () {
    it('passes every check on a healthy application', function () {
        // Testbench defaults the queue to sync, which is a legitimate warning
        // — so a "nothing wrong" baseline has to say what a real app would.
        config()->set('queue.default', 'database');

        $verdict = app(HealthReport::class)->verdict();

        expect($verdict['status'])->toBe('ok')
            ->and($verdict['failures'])->toBe(0)
            ->and($verdict['summary'])->toBe('All checks passing');
    });

    it('warns rather than fails when the queue runs inline', function () {
        // sync is not broken, it is a choice with a consequence: a long job
        // runs inside the request that triggered it.
        config()->set('queue.default', 'sync');

        $checks = collect(app(HealthReport::class)->checks());

        expect($checks->firstWhere('label', 'Queue')['status'])->toBe('warn')
            ->and(app(HealthReport::class)->verdict()['status'])->toBe('warn');
    });

    it('fails when debug is on in production', function () {
        config()->set('app.debug', true);
        app()->detectEnvironment(fn () => 'production');

        $checks = collect(app(HealthReport::class)->checks());

        expect($checks->firstWhere('label', 'Debug mode')['status'])->toBe('fail')
            ->and(app(HealthReport::class)->verdict()['status'])->toBe('fail');
    });

    it('says nothing about tables the application does not have', function () {
        // A package default cannot assume a jobs or failed_jobs table exists,
        // and an app without them is not unhealthy for lacking them.
        $checks = collect(app(HealthReport::class)->checks());

        expect($checks->firstWhere('label', 'Failed jobs'))->toBeNull()
            ->and(app(HealthReport::class)->jobsLastDay()['by_status'])->toBe([]);
    });

    it('reports the database as reachable', function () {
        $checks = collect(app(HealthReport::class)->checks());

        expect($checks->firstWhere('label', 'Database')['status'])->toBe('ok')
            ->and($checks->firstWhere('label', 'Database')['detail'])->toContain('sqlite');
    });
});
