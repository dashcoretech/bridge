<?php

namespace Dashcore\Bridge\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * This app's enrolled bridge identity, written once by `bridge:connect` and
 * read as the fallback whenever the BRIDGE_* env vars are absent. Keeping it
 * in the app's own database is what makes zero-paste onboarding possible:
 * the only secrets in the environment are the fleet key and the control URL.
 *
 * At most one row is meaningful; `bridge:connect` replaces rather than
 * appends.
 */
class BridgeIdentity extends Model
{
    protected $guarded = [];

    protected $hidden = ['private_key'];

    protected function casts(): array
    {
        return ['private_key' => 'encrypted'];
    }
}
