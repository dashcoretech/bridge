<?php

declare(strict_types=1);

namespace Dashcore\Bridge\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One distinct failure, in one hour of this app's life.
 *
 * Not one row per exception thrown: see the migration for why an app in a
 * retry loop must not be able to turn its own incident into a second one.
 */
class ErrorGroup extends Model
{
    protected $table = 'bridge_error_groups';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_start' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'reported_at' => 'datetime',
            'count' => 'integer',
            'line' => 'integer',
        ];
    }
}
