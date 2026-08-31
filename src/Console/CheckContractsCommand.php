<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Console;

use Dashcore\Bridge\Contracts\ContractChecker;
use Illuminate\Console\Command;

/**
 * Verify that the fields this app reads are fields its peers publish.
 *
 * Exits non-zero on a broken contract so it can gate a deploy: shipping a
 * consumer that reads a key nobody sends produces an empty panel, not an
 * error, and nobody notices for weeks.
 */
class CheckContractsCommand extends Command
{
    protected $signature = 'bridge:check-contracts {--json : Emit machine-readable output}';

    protected $description = 'Check that the peer fields this app depends on still exist';

    public function handle(ContractChecker $checker): int
    {
        $expectations = (array) config('bridge.expects', []);

        if ($expectations === []) {
            $this->components->warn(
                'This app declares no peer expectations. Add them to bridge.expects so a peer dropping a field is a failure here rather than an empty panel in production.'
            );

            return self::SUCCESS;
        }

        $results = $checker->check($expectations);

        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($results);
        }

        foreach ($results as $result) {
            $label = $result['peer'].' '.$result['path'].' → '.$result['field'];

            match ($result['status']) {
                'ok' => $this->components->twoColumnDetail($label, $result['detail']),
                'missing' => $this->components->error($label.' — '.$result['detail']),
                default => $this->components->warn($label.' — '.$result['detail']),
            };
        }

        $broken = $this->broken($results);
        $checked = count($results);

        $broken === 0
            ? $this->components->info("All {$checked} declared field(s) are published by their peers.")
            : $this->components->error("{$broken} of {$checked} declared field(s) are not published.");

        return $this->exitCode($results);
    }

    /**
     * @param  list<array<string, string>>  $results
     */
    private function broken(array $results): int
    {
        return count(array_filter($results, fn (array $r) => $r['status'] === 'missing'));
    }

    /**
     * @param  list<array<string, string>>  $results
     */
    private function exitCode(array $results): int
    {
        // Only a missing field fails. An unreachable peer is an outage, and
        // failing the contract check for it would bury a real field mismatch
        // behind whichever app happened to be down.
        return $this->broken($results) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
