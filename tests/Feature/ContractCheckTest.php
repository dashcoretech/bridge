<?php

use Dashcore\Bridge\Contracts\ContractChecker;
use Dashcore\Bridge\Crypto\Keypair;
use Dashcore\Bridge\Testing\InteractsWithBridge;
use Illuminate\Support\Facades\Http;

uses(InteractsWithBridge::class);

beforeEach(function () {
    config()->set('bridge.key_id', 'receiver-test');
    config()->set('bridge.private_key', Keypair::generate()->privateKey);
    $this->registerBridgePeer('marketing');
});

/** The shape marketing actually publishes. */
function marketingSnapshot(array $overrides = []): void
{
    Http::fake(['*' => Http::response([
        'generated_at' => now()->toIso8601String(),
        'growth_goals' => [['name' => 'Lead Engine']],
        'daily_stats_last_14' => [],
        'leads' => ['total' => 12, 'by_stage' => ['lead' => 6]],
        'publishing_last_14' => ['posts_sent' => 3],
        ...$overrides,
    ], 200)]);
}

it('passes when every declared field is published', function () {
    marketingSnapshot();

    $results = app(ContractChecker::class)->check([
        'marketing' => ['/snapshot' => ['leads.total', 'leads.by_stage', 'publishing_last_14.posts_sent']],
    ]);

    expect(collect($results)->pluck('status')->unique()->all())->toBe(['ok']);
});

it('catches a consumer reading a key the producer does not send', function () {
    // The failure this exists for. travis read $snapshot['subscribers'] against
    // a payload carrying the counts under `leads`, and showed "No pipeline data
    // yet" for weeks while every producer-side check reported green.
    marketingSnapshot();

    $results = app(ContractChecker::class)->check([
        'marketing' => ['/snapshot' => ['subscribers.total']],
    ]);

    expect($results[0]['status'])->toBe('missing')
        ->and($results[0]['field'])->toBe('subscribers.total');
});

it('treats an empty list as present, not broken', function () {
    // A quiet fortnight is not a broken contract. Failing on it would make the
    // check cry wolf often enough to be ignored, which is worse than not
    // having it.
    marketingSnapshot();

    $results = app(ContractChecker::class)->check([
        'marketing' => ['/snapshot' => ['daily_stats_last_14']],
    ]);

    expect($results[0]['status'])->toBe('ok')
        ->and($results[0]['detail'])->toBe('present, empty');
});

it('distinguishes a published null from an absent key', function () {
    // data_get cannot tell these apart on its own, and they mean opposite
    // things: one is a peer saying "nothing yet", the other is a field that
    // does not exist.
    marketingSnapshot(['forecast' => null]);

    $results = app(ContractChecker::class)->check([
        'marketing' => ['/snapshot' => ['forecast', 'nonexistent']],
    ]);

    expect($results[0]['status'])->toBe('ok')
        ->and($results[0]['detail'])->toBe('present, null')
        ->and($results[1]['status'])->toBe('missing');
});

it('reads nested fields by dot path', function () {
    marketingSnapshot();

    $results = app(ContractChecker::class)->check([
        'marketing' => ['/snapshot' => ['leads.by_stage.lead', 'leads.by_stage.nope']],
    ]);

    expect($results[0]['status'])->toBe('ok')
        ->and($results[1]['status'])->toBe('missing');
});

it('reports an unreachable peer without calling it a contract failure', function () {
    // Otherwise a broken field list hides behind whichever app is down.
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

    $results = app(ContractChecker::class)->check([
        'marketing' => ['/snapshot' => ['leads.total']],
    ]);

    expect($results[0]['status'])->toBe('unreachable');
});

describe('the command', function () {
    it('fails the run when a field is missing', function () {
        marketingSnapshot();
        config()->set('bridge.expects', ['marketing' => ['/snapshot' => ['subscribers.total']]]);

        $this->artisan('bridge:check-contracts')->assertFailed();
    });

    it('passes when the contract holds', function () {
        marketingSnapshot();
        config()->set('bridge.expects', ['marketing' => ['/snapshot' => ['leads.total']]]);

        $this->artisan('bridge:check-contracts')->assertSuccessful();
    });

    it('does not fail a run merely because a peer is down', function () {
        Http::fake(['*' => Http::response([], 503)]);
        config()->set('bridge.expects', ['marketing' => ['/snapshot' => ['leads.total']]]);

        $this->artisan('bridge:check-contracts')->assertSuccessful();
    });

    it('says so when an app declares nothing', function () {
        config()->set('bridge.expects', []);

        $this->artisan('bridge:check-contracts')
            ->expectsOutputToContain('declares no peer expectations')
            ->assertSuccessful();
    });
});
