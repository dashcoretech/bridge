<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * How one route behaved in one hour: how often, how slow, how badly.
 *
 * Keyed by the route *pattern*, so the row is about a piece of code rather
 * than about one visitor's URL.
 */
class RouteStat extends Model
{
    protected $table = 'bridge_route_stats';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'reported_at' => 'datetime',
            'count' => 'integer',
            'slow_count' => 'integer',
            'total_ms' => 'integer',
            'max_ms' => 'integer',
            'error_count' => 'integer',
        ];
    }

    /** Mean duration over the window, which is all a counter can honestly give. */
    public function meanMs(): int
    {
        return $this->count > 0 ? (int) round($this->total_ms / $this->count) : 0;
    }
}
