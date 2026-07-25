<?php

namespace Dashcore\Bridge\Client;

use Dashcore\Bridge\Crypto\BridgeHeaders;
use Dashcore\Bridge\Exceptions\PeerDeniedScope;
use Dashcore\Bridge\Exceptions\PeerError;
use Dashcore\Bridge\Exceptions\PeerRejectedSignature;
use Dashcore\Bridge\Exceptions\PeerUnreachable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PendingBridgeRequest
{
    protected const PREFIX = '/api/bridge/v1';

    protected ?int $timeout = null;

    protected ?int $retryTimes = null;

    protected int $retryBackoff = 100;

    public function __construct(
        protected string $appId,
        protected string $baseUrl,
    ) {}

    public function timeout(int $seconds): static
    {
        $this->timeout = $seconds;

        return $this;
    }

    public function retry(int $times, int $backoff = 100): static
    {
        $this->retryTimes = $times;
        $this->retryBackoff = $backoff;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): Response
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->send('POST', $path, [], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function put(string $path, array $data = []): Response
    {
        return $this->send('PUT', $path, [], $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function patch(string $path, array $data = []): Response
    {
        return $this->send('PATCH', $path, [], $data);
    }

    public function delete(string $path): Response
    {
        return $this->send('DELETE', $path);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $data
     */
    protected function send(string $method, string $path, array $query = [], ?array $data = null): Response
    {
        $path = static::PREFIX.'/'.ltrim($path, '/');
        $body = $data === null ? '' : json_encode($data);

        $pending = Http::withHeaders([
            ...BridgeHeaders::sign($method, $path, $query, $body),
            'Accept' => 'application/json',
        ])->timeout($this->timeout ?? config('bridge.timeout'));

        if ($this->retryTimes !== null) {
            $pending = $pending->retry($this->retryTimes, $this->retryBackoff, throw: false);
        }

        if ($body !== '') {
            $pending = $pending->withBody($body, 'application/json');
        }

        try {
            $response = $pending->send($method, rtrim($this->baseUrl, '/').$path, ['query' => $query]);
        } catch (ConnectionException $e) {
            throw new PeerUnreachable("Peer [{$this->appId}] is unreachable: {$e->getMessage()}", previous: $e);
        }

        return match (true) {
            $response->status() === 401 => throw new PeerRejectedSignature(
                "Peer [{$this->appId}] rejected this app's signature: ".$response->json('error', 'unknown').' — run bridge:doctor.'
            ),
            $response->status() === 403 => throw new PeerDeniedScope(
                "Peer [{$this->appId}] denied the call: ".$response->json('detail', 'missing ability grant')
            ),
            $response->serverError() => throw new PeerError(
                "Peer [{$this->appId}] returned {$response->status()} for [{$method} {$path}]."
            ),
            default => $response,
        };
    }
}
