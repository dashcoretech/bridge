<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Telemetry\TelemetryReporter;
use Illuminate\Console\Command;

/**
 * Send this app's errors and route timings to the collector.
 *
 * Meant for the scheduler, hourly, a few minutes past the hour — the reporter
 * only ships windows that have closed, so running it at :05 sends the hour
 * that just ended.
 *
 * On a timer rather than on each event, for the same reason call reporting is:
 * a request must not get slower, or fail, because the collector is having a
 * bad afternoon. That matters more here than anywhere else in the package,
 * because the moment this runs is by definition a moment when something is
 * already going wrong.
 */
class ReportTelemetryCommand extends Command
{
    protected $signature = 'bridge:report-telemetry
                            {--limit= : Maximum buckets of each kind to send in one run}
                            {--prune : Also delete reported buckets past the retention window}';

    protected $description = 'Report this application\'s errors and slow routes to the fleet collector';

    public function handle(TelemetryReporter $reporter): int
    {
        $result = $reporter->report(
            $this->option('limit') !== null ? (int) $this->option('limit') : TelemetryReporter::BATCH,
        );

        if ($result['skipped'] !== null) {
            $this->components->warn($result['skipped']);

            return self::SUCCESS;
        }

        $result['errors'] > 0 || $result['routes'] > 0
            ? $this->components->info("Reported {$result['errors']} error group(s) and {$result['routes']} route window(s).")
            : $this->components->info('Nothing new to report.');

        if ($this->option('prune')) {
            $this->components->info("Pruned {$reporter->prune()} reported bucket(s).");
        }

        return self::SUCCESS;
    }
}
