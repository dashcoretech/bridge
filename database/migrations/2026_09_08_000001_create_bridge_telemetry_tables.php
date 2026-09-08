<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What went wrong in this app, and what was slow, bucketed by the hour.
 *
 * Both tables aggregate on write rather than storing one row per event, and
 * that is the load-bearing decision. An app in a bad loop can throw the same
 * exception ten thousand times a minute; a table of raw events turns an
 * incident into a second incident, in the database, on the machine already
 * having a bad day. Bucketing bounds these by *distinct* errors and *distinct*
 * routes — a number that changes when the code changes, not when traffic does.
 *
 * The hour is the unit because it is the smallest window that still answers
 * "is this getting worse" without making the report chatty, and because a
 * closed hour is unambiguous: it will never be written to again, so it can be
 * shipped and forgotten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bridge_error_groups', function (Blueprint $table) {
            $table->id();

            // class + file + line, hashed. Deliberately not the message: two
            // failures of the same code differing only in which id they name
            // are one problem, and fingerprinting the message would file them
            // as thousands.
            $table->string('fingerprint', 64);
            $table->timestamp('window_start');

            $table->string('level', 16);
            $table->string('exception')->nullable();
            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();

            // Where it happened — a route pattern or an artisan command name,
            // never a URL with ids in it.
            $table->string('context')->nullable();

            $table->unsignedInteger('count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('reported_at')->nullable();

            // One row per distinct problem per hour. The upsert that keeps
            // these bounded depends on this being unique.
            $table->unique(['fingerprint', 'window_start']);
            $table->index(['reported_at', 'window_start']);
        });

        Schema::create('bridge_route_stats', function (Blueprint $table) {
            $table->id();

            $table->string('method', 10);

            // The route *pattern* — `/leads/{lead}`, not `/leads/412`. A URL
            // carries ids and sometimes worse; a pattern is the thing you
            // would actually tune, and there are a few hundred of them rather
            // than a few million.
            $table->string('route');
            $table->timestamp('window_start');

            $table->unsignedInteger('count')->default(0);
            $table->unsignedInteger('slow_count')->default(0);
            $table->unsignedBigInteger('total_ms')->default(0);
            $table->unsignedInteger('max_ms')->default(0);
            $table->unsignedSmallInteger('error_count')->default(0);

            $table->timestamp('reported_at')->nullable();

            $table->unique(['method', 'route', 'window_start']);
            $table->index(['reported_at', 'window_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bridge_route_stats');
        Schema::dropIfExists('bridge_error_groups');
    }
};
