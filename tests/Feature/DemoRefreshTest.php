<?php

use Dashcore\Bridge\Demo\DateShifter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    Schema::create('demo_campaigns', function ($table) {
        $table->id();
        $table->string('name');
        $table->date('starts_on')->nullable();
        $table->timestamps();
    });

    Schema::create('demo_leads', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamp('last_activity_at')->nullable();
        $table->timestamps();
    });

    // A dataset frozen 30 days ago, with the lead created a week after the
    // campaign that produced it. That gap is the thing worth protecting.
    $frozen = Carbon::today()->subDays(30);

    DB::table('demo_campaigns')->insert([
        'name' => 'Cold traffic',
        'starts_on' => $frozen->toDateString(),
        'created_at' => $frozen,
        'updated_at' => $frozen,
    ]);

    DB::table('demo_leads')->insert([
        'name' => 'Maria Alvarez',
        'last_activity_at' => $frozen->copy()->addDays(7),
        'created_at' => $frozen->copy()->addDays(7),
        'updated_at' => $frozen->copy()->addDays(7),
    ]);
});

it('measures how far behind the dataset is', function () {
    $shifter = app(DateShifter::class);

    // Newest created_at is the lead, 23 days ago.
    expect($shifter->drift($shifter->map()))->toBe(23);
});

it('brings the newest record up to today', function () {
    $this->artisan('demo:refresh')->assertSuccessful();

    $newest = Carbon::parse(DB::table('demo_leads')->max('created_at'));

    expect($newest->toDateString())->toBe(Carbon::today()->toDateString());
});

it('moves the whole dataset as one, so relative timing survives', function () {
    // The point of the single delta. Shifting each table to its own "today"
    // would make both current and put the lead before the campaign that
    // produced it.
    $gap = fn () => abs(Carbon::parse(DB::table('demo_leads')->max('created_at'))
        ->diffInDays(Carbon::parse(DB::table('demo_campaigns')->max('created_at'))));

    $gapBefore = $gap();

    $this->artisan('demo:refresh')->assertSuccessful();

    expect($gap())->toBe($gapBefore)->and($gap())->toBe(7.0);
});

it('shifts every dated column, not only the anchor', function () {
    $this->artisan('demo:refresh')->assertSuccessful();

    // starts_on is a plain date with no anchor role; it must still move, or
    // the campaign would begin a month before it was created.
    expect(Carbon::parse(DB::table('demo_campaigns')->max('starts_on'))->toDateString())
        ->toBe(Carbon::today()->subDays(7)->toDateString());
});

it('refuses to move data that is already current', function () {
    $this->artisan('demo:refresh')->assertSuccessful();
    $after = DB::table('demo_leads')->max('created_at');

    // Running it twice must not push the dataset into the future.
    $this->artisan('demo:refresh')
        ->expectsOutputToContain('Already current')
        ->assertSuccessful();

    expect(DB::table('demo_leads')->max('created_at'))->toBe($after);
});

it('changes nothing on a dry run', function () {
    $before = DB::table('demo_leads')->max('created_at');

    $this->artisan('demo:refresh', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('demo_leads')->max('created_at'))->toBe($before);
});

it('accepts an explicit shift', function () {
    $before = Carbon::parse(DB::table('demo_leads')->max('created_at'));

    $this->artisan('demo:refresh', ['--days' => 5])->assertSuccessful();

    expect(Carbon::parse(DB::table('demo_leads')->max('created_at'))->toDateString())
        ->toBe($before->addDays(5)->toDateString());
});

it('leaves the framework\'s own bookkeeping alone', function () {
    $map = app(DateShifter::class)->map();

    expect(array_keys($map))
        ->not->toContain('migrations')
        ->not->toContain('sessions')
        ->not->toContain('failed_jobs');
});

it('discovers dated columns from the schema rather than a list', function () {
    $map = app(DateShifter::class)->map();

    expect($map['demo_campaigns'])->toEqualCanonicalizing(['starts_on', 'created_at', 'updated_at'])
        ->and($map['demo_leads'])->toEqualCanonicalizing(['last_activity_at', 'created_at', 'updated_at']);
});
