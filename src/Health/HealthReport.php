<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Health;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * A fleet app's self-reported health, computed from what every Laravel
 * application has rather than from anything a particular app knows.
 *
 * The checks here are deliberately generic. An app with a richer notion of
 * healthy should serve its own endpoint and turn this one off — the point of
 * shipping a default is that "is the fleet well?" stops depending on eleven
 * teams each getting round to it, which is the state it stayed in.
 *
 * What leaves the app is an aggregate: a status, two counts, and a sentence.
 * Check labels travel; error messages, connection strings and table names do
 * not. A peer asking after your health has no business learning your schema.
 */
class HealthReport
{
    /** A backlog past this is worth mentioning; below it, a queue is just busy. */
    private const QUEUE_BACKLOG_WARN = 100;

    /**
     * @return list<array{label: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return array_values(array_filter([
            $this->database(),
            $this->cache(),
            $this->queue(),
            $this->failedJobs(),
            $this->debugMode(),
        ]));
    }

    /**
     * @return array{status: string, failures: int, warnings: int, summary: string}
     */
    public function verdict(): array
    {
        $checks = $this->checks();

        $failures = count(array_filter($checks, fn (array $c) => $c['status'] === 'fail'));
        $warnings = count(array_filter($checks, fn (array $c) => $c['status'] === 'warn'));

        return [
            'status' => $failures > 0 ? 'fail' : ($warnings > 0 ? 'warn' : 'ok'),
            'failures' => $failures,
            'warnings' => $warnings,
            'summary' => $this->summary($failures, $warnings),
        ];
    }

    /**
     * Queue depth and failure counts, in the shape the fleet already reads.
     *
     * @return array{by_status: array<string, int>, last_failure_at: ?string}
     */
    public function jobsLastDay(): array
    {
        return [
            'by_status' => array_filter([
                'queued' => $this->countTable('jobs'),
                'failed' => $this->failedSince(),
            ], fn (?int $value) => $value !== null),
            'last_failure_at' => $this->lastFailureAt(),
        ];
    }

    private function summary(int $failures, int $warnings): string
    {
        if ($failures > 0) {
            return $failures.' '.Str::plural('problem', $failures).' '.($failures === 1 ? 'needs' : 'need').' attention';
        }

        if ($warnings > 0) {
            return $warnings.' '.Str::plural('thing', $warnings).' worth a look';
        }

        return 'All checks passing';
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function database(): array
    {
        try {
            DB::connection()->getPdo();

            // The driver name is safe to publish; the database name is not —
            // it is half of what someone would need to go looking for it.
            return $this->check('Database', 'ok', DB::connection()->getDriverName().' reachable');
        } catch (Throwable) {
            return $this->check('Database', 'fail', 'unreachable');
        }
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function cache(): array
    {
        $key = 'bridge:health:'.Str::random(12);

        try {
            Cache::put($key, 'ok', 10);
            $roundTrip = Cache::get($key) === 'ok';
            Cache::forget($key);

            return $roundTrip
                ? $this->check('Cache', 'ok', config('cache.default').' read and write')
                : $this->check('Cache', 'fail', 'a value written to the cache did not read back');
        } catch (Throwable) {
            return $this->check('Cache', 'fail', 'unusable');
        }
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function queue(): array
    {
        $driver = (string) config('queue.default');

        if ($driver === 'sync') {
            // Not pedantry: a sync queue means a long job runs inside the web
            // request that triggered it, so the work and the page time out
            // together.
            return $this->check('Queue', 'warn', 'sync — jobs run inside the web request');
        }

        $depth = $this->countTable('jobs');

        if ($depth !== null && $depth > self::QUEUE_BACKLOG_WARN) {
            return $this->check('Queue', 'warn', $driver.' — '.$depth.' jobs waiting');
        }

        return $this->check('Queue', 'ok', $depth === null ? $driver : $driver.' — '.$depth.' waiting');
    }

    /**
     * @return array{label: string, status: string, detail: string}|null
     */
    private function failedJobs(): ?array
    {
        $failed = $this->failedSince();

        if ($failed === null) {
            return null;
        }

        return $failed > 0
            ? $this->check('Failed jobs', 'warn', $failed.' in the last 24 hours')
            : $this->check('Failed jobs', 'ok', 'none in the last 24 hours');
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function debugMode(): array
    {
        if (config('app.debug') && app()->environment('production')) {
            return $this->check('Debug mode', 'fail', 'on in production');
        }

        return $this->check('Debug mode', 'ok', config('app.debug') ? 'on (fine outside production)' : 'off');
    }

    private function countTable(string $table): ?int
    {
        try {
            return Schema::hasTable($table) ? DB::table($table)->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function failedSince(): ?int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return null;
            }

            return DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        } catch (Throwable) {
            return null;
        }
    }

    private function lastFailureAt(): ?string
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return null;
            }

            $at = DB::table('failed_jobs')->max('failed_at');

            return $at === null ? null : (string) $at;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{label: string, status: string, detail: string}
     */
    private function check(string $label, string $status, string $detail): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
