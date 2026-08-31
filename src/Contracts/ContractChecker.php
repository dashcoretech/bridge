<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Contracts;

use Dashcore\Bridge\Facades\Bridge;
use Throwable;

/**
 * Checks that the fields this app reads are fields its peers actually publish.
 *
 * Everything else in this package verifies the producer: the endpoint exists,
 * the signature is good, the grant is held, a 200 comes back. None of that can
 * see the failure that actually happens most often — a consumer reading a key
 * the producer does not publish.
 *
 * That failure is silent by construction. `$snapshot['subscribers']` on a
 * payload with no `subscribers` key is null, and null renders as an empty
 * panel, which is indistinguishable from a peer having no data. travis showed
 * "No pipeline data yet" for weeks against a response that carried the counts
 * under `leads` the whole time, while every producer-side check reported
 * green.
 *
 * So the consumer declares what it depends on, and this walks the live
 * response looking for each path. A missing key is a broken contract; an empty
 * one is just a quiet week, and the difference is the entire point.
 */
class ContractChecker
{
    /** Distinguishes "absent" from "present and null", which data_get cannot. */
    private const MISSING = '__bridge_contract_missing__';

    /**
     * @return list<array{peer: string, path: string, field: string, status: string, detail: string}>
     */
    public function check(?array $expectations = null): array
    {
        $expectations ??= (array) config('bridge.expects', []);
        $results = [];

        foreach ($expectations as $peer => $paths) {
            foreach ((array) $paths as $path => $fields) {
                $results = array_merge($results, $this->checkEndpoint((string) $peer, (string) $path, (array) $fields));
            }
        }

        return $results;
    }

    /**
     * @param  list<string>  $fields
     * @return list<array{peer: string, path: string, field: string, status: string, detail: string}>
     */
    private function checkEndpoint(string $peer, string $path, array $fields): array
    {
        try {
            $response = Bridge::to($peer)->timeout(20)->get($path);
        } catch (Throwable $e) {
            // Unreachable is a real failure, but it is not a contract failure —
            // calling it one would bury a broken field list behind an outage.
            return [$this->result($peer, $path, '*', 'unreachable', substr($e->getMessage(), 0, 200))];
        }

        if (! $response->successful()) {
            return [$this->result($peer, $path, '*', 'unreachable', 'HTTP '.$response->status())];
        }

        $body = $response->json();

        if (! is_array($body)) {
            return [$this->result($peer, $path, '*', 'unreadable', 'The response was not a JSON object.')];
        }

        $results = [];

        foreach ($fields as $field) {
            $value = data_get($body, $field, self::MISSING);

            $results[] = $value === self::MISSING
                ? $this->result($peer, $path, $field, 'missing', 'The peer does not publish this key.')
                : $this->result($peer, $path, $field, 'ok', $this->describe($value));
        }

        return $results;
    }

    /**
     * An empty list is reported, not failed. A consumer that declared a
     * dependency on it is right to be told it is empty; it is not evidence of
     * a broken contract, and treating it as one would make the check cry wolf
     * every quiet week.
     */
    private function describe(mixed $value): string
    {
        return match (true) {
            is_array($value) && $value === [] => 'present, empty',
            is_array($value) => 'present, '.count($value).' item(s)',
            $value === null => 'present, null',
            is_bool($value) => 'present, '.($value ? 'true' : 'false'),
            default => 'present',
        };
    }

    /**
     * @return array{peer: string, path: string, field: string, status: string, detail: string}
     */
    private function result(string $peer, string $path, string $field, string $status, string $detail): array
    {
        return ['peer' => $peer, 'path' => $path, 'field' => $field, 'status' => $status, 'detail' => $detail];
    }
}
