<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Demo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Rolls a demo dataset forward so it is about today again.
 *
 * Seeded data is written once and then read through rolling windows — "the
 * last 14 days", "this fortnight", "closing within 30 days". The rows do not
 * move, the windows do, and a dashboard that was full at seed time empties
 * itself a fortnight later while every underlying record sits there intact.
 * That is not a hypothetical failure: marketing's cockpit read "0 leads in the
 * last two weeks" for seven weeks against a table with fourteen rows in it.
 *
 * The important decision here is **one delta for the whole database**. Shifting
 * each table by its own offset would make every table individually current and
 * collectively nonsense — a lead created before the campaign that produced it,
 * an invoice paid before it was raised. Relative timing is most of what demo
 * data is for, so the whole set moves as one rigid body.
 */
class DateShifter
{
    /**
     * Laravel's own bookkeeping. Moving a migration timestamp or a session's
     * last_activity does nothing for a demo and can actively confuse the
     * framework, so these are left where they are.
     *
     * @var list<string>
     */
    private const SKIP_TABLES = [
        'migrations', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
        'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring',
        'pulse_values', 'pulse_entries', 'pulse_aggregates',

        // This package's own tables, and they matter more than the rest.
        // `bridge_calls` gains a row on every inbound request, so its newest
        // created_at is always a few seconds ago — which pins the anchor to
        // now and makes the drift zero in every app that is actually part of a
        // fleet. Left in, this command reports "already current" across a
        // dozen applications whose demo data is a week stale, which is worse
        // than not shipping it: it answers the question wrongly and
        // confidently.
        'bridge_calls', 'bridge_identities',
    ];

    /**
     * The anchor is the newest `created_at` in the database, not the newest
     * date of any kind. A record is created in the past by definition, whereas
     * a scheduled post or a forecast legitimately sits in the future — anchor
     * on those and the whole dataset gets dragged backwards so that a plan for
     * next month lands on today.
     *
     * @var list<string>
     */
    private const ANCHOR_COLUMNS = ['created_at', 'sent_at', 'closed_at', 'occurred_at'];

    /**
     * Every column shaped like a moment. Discovered from the schema rather
     * than configured, because a list of column names per app is a list that
     * goes stale exactly as silently as the data it was meant to fix.
     *
     * @return array<string, list<string>> table => date columns
     */
    public function map(): array
    {
        $map = [];

        foreach ($this->tables() as $table) {
            $columns = [];

            foreach (Schema::getColumns($table) as $column) {
                $type = strtolower((string) ($column['type_name'] ?? $column['type'] ?? ''));

                if (preg_match('/^(date|datetime|timestamp|timestamptz)/', $type) === 1) {
                    $columns[] = (string) $column['name'];
                }
            }

            if ($columns !== []) {
                $map[$table] = $columns;
            }
        }

        return $map;
    }

    /**
     * How many days the dataset is behind. Positive means it needs moving
     * forward; zero or negative means it is already current and nothing should
     * be touched — refreshing a fresh database would push it into the future.
     *
     * @param  array<string, list<string>>  $map
     */
    public function drift(array $map, ?Carbon $today = null): ?int
    {
        $newest = $this->newestAnchor($map);

        if ($newest === null) {
            return null;
        }

        $days = $newest->copy()->startOfDay()->diffInDays(($today ?? Carbon::today())->startOfDay(), false);

        return (int) $days;
    }

    /**
     * @param  array<string, list<string>>  $map
     * @return array<string, int> table => columns shifted
     */
    public function shift(array $map, int $days): array
    {
        if ($days === 0) {
            return [];
        }

        $shifted = [];

        foreach ($map as $table => $columns) {
            foreach ($columns as $column) {
                try {
                    DB::statement($this->sql($table, $column, $days));
                    $shifted[$table] = ($shifted[$table] ?? 0) + 1;
                } catch (Throwable) {
                    // A generated or read-only column cannot be updated, and a
                    // demo refresh is not worth failing over one of them.
                    continue;
                }
            }
        }

        return $shifted;
    }

    /**
     * The fleet is not single-engine — the hub is Postgres and marketing is
     * MySQL — so date arithmetic cannot be written once and assumed.
     */
    private function sql(string $table, string $column, int $days): string
    {
        $t = $this->quote($table);
        $c = $this->quote($column);

        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "update {$t} set {$c} = date_add({$c}, interval {$days} day) where {$c} is not null",
            'sqlite' => "update {$t} set {$c} = datetime({$c}, '{$days} days') where {$c} is not null",
            'sqlsrv' => "update {$t} set {$c} = dateadd(day, {$days}, {$c}) where {$c} is not null",
            default => "update {$t} set {$c} = {$c} + (interval '1 day' * {$days}) where {$c} is not null",
        };
    }

    private function quote(string $identifier): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => '`'.str_replace('`', '', $identifier).'`',
            'sqlsrv' => '['.str_replace([']', '['], '', $identifier).']',
            default => '"'.str_replace('"', '', $identifier).'"',
        };
    }

    /**
     * @param  array<string, list<string>>  $map
     */
    private function newestAnchor(array $map): ?Carbon
    {
        $newest = null;

        foreach ($map as $table => $columns) {
            foreach (array_intersect(self::ANCHOR_COLUMNS, $columns) as $column) {
                try {
                    $max = DB::table($table)->max($column);
                } catch (Throwable) {
                    continue;
                }

                if ($max === null) {
                    continue;
                }

                try {
                    $at = Carbon::parse((string) $max);
                } catch (Throwable) {
                    continue;
                }

                if ($newest === null || $at->greaterThan($newest)) {
                    $newest = $at;
                }
            }
        }

        return $newest;
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        $names = array_map(
            fn (array $table) => (string) $table['name'],
            Schema::getTables(),
        );

        return array_values(array_filter(
            $names,
            fn (string $name) => ! in_array($name, self::SKIP_TABLES, true),
        ));
    }
}
