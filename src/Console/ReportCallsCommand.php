<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Reporting\CallReporter;
use Illuminate\Console\Command;

/**
 * Send this app's inbound call log to the control plane.
 *
 * Meant for the scheduler. Reporting in batches on a timer rather than on each
 * request keeps the audit trail off the hot path: a call must not get slower,
 * or fail, because the hub is having a bad afternoon.
 */
class ReportCallsCommand extends Command
{
    protected $signature = 'bridge:report-calls {--limit= : Maximum calls to send in one run}';

    protected $description = 'Report this application\'s inbound bridge calls to the control plane';

    public function handle(CallReporter $reporter): int
    {
        $result = $reporter->report(
            $this->option('limit') !== null ? (int) $this->option('limit') : CallReporter::BATCH,
        );

        if ($result['skipped'] !== null) {
            $this->components->warn($result['skipped']);

            return self::SUCCESS;
        }

        $result['sent'] > 0
            ? $this->components->info("Reported {$result['sent']} call(s).")
            : $this->components->info('Nothing new to report.');

        return self::SUCCESS;
    }
}
