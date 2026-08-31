<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Demo\DateShifter;
use Illuminate\Console\Command;

/**
 * Make this app's demo data about today again.
 *
 * Ships with the bridge because the problem is fleet-wide and identical in
 * every app: seeded rows stay put while the windows that read them move, so
 * every cockpit panel fed by "the last 14 days" empties itself a fortnight
 * after seeding, with the data still sitting there.
 */
class DemoRefreshCommand extends Command
{
    protected $signature = 'demo:refresh
        {--days= : Shift by this many days instead of measuring the drift}
        {--dry-run : Report what would move without touching anything}
        {--force : Run outside a local environment}';

    protected $description = 'Roll this application\'s demo data forward so it lands on today';

    public function handle(DateShifter $shifter): int
    {
        // A date shift across every table is the last thing anyone wants
        // pointed at real records, and "it was only demo data" is not a
        // sentence you get to say afterwards.
        if (! $this->option('force') && ! app()->environment('local', 'testing')) {
            $this->components->error(
                'demo:refresh rewrites every date in the database. It refuses to run outside local — pass --force if you are certain.'
            );

            return self::FAILURE;
        }

        $map = $shifter->map();

        if ($map === []) {
            $this->components->warn('No dated tables found — nothing to refresh.');

            return self::SUCCESS;
        }

        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : $shifter->drift($map);

        if ($days === null) {
            $this->components->warn('No anchor date found, so there is nothing to measure the drift against.');

            return self::SUCCESS;
        }

        $columns = array_sum(array_map('count', $map));
        $this->components->twoColumnDetail('Dated tables', (string) count($map).' ('.$columns.' columns)');
        $this->components->twoColumnDetail('Drift', $days > 0 ? $days.' days behind' : ($days === 0 ? 'current' : abs($days).' days ahead'));

        if ($days <= 0 && $this->option('days') === null) {
            $this->components->info('Already current — nothing moved. Refreshing now would push the data into the future.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($map as $table => $cols) {
                $this->components->twoColumnDetail($table, implode(', ', $cols));
            }

            $this->components->info("Dry run: would shift {$columns} column(s) forward by {$days} day(s).");

            return self::SUCCESS;
        }

        $shifted = $shifter->shift($map, $days);
        $moved = array_sum($shifted);

        $this->components->info("Shifted {$moved} column(s) across ".count($shifted)." table(s) by {$days} day(s).");

        if ($moved < $columns) {
            // Generated and read-only columns cannot be updated; saying so is
            // better than a count that quietly does not add up.
            $this->components->warn(($columns - $moved).' column(s) could not be updated and were left alone.');
        }

        return self::SUCCESS;
    }
}
