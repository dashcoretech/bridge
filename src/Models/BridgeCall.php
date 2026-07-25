<?php

namespace Dashcore\Bridge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class BridgeCall extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    protected $guarded = [];

    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(90));
    }

    /**
     * Record an inbound bridge call outcome. Never throws — auditing must not
     * take down the request path.
     */
    public static function record(
        ?string $callerApp,
        ?string $keyId,
        string $method,
        string $path,
        string $outcome,
        ?int $status = null,
        ?string $ability = null,
        ?int $durationMs = null,
    ): void {
        try {
            static::create([
                'caller_app' => $callerApp,
                'key_id' => $keyId,
                'method' => $method,
                'path' => $path,
                'ability' => $ability,
                'outcome' => $outcome,
                'status' => $status,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
