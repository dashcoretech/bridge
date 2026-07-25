<?php

namespace Dashcore\Bridge\Facades;

use Dashcore\Bridge\Client\BridgeManager;
use Dashcore\Bridge\Client\PendingBridgeRequest;
use Illuminate\Support\Facades\Facade;

/**
 * @method static PendingBridgeRequest to(string $appId)
 *
 * @see BridgeManager
 */
class Bridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BridgeManager::class;
    }
}
