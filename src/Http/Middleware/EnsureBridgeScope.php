<?php

namespace Dashcore\Bridge\Http\Middleware;

use Closure;
use Dashcore\Bridge\BridgePeer;
use Dashcore\Bridge\Models\BridgeCall;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBridgeScope
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $peer = $request->attributes->get('bridge_peer');

        $request->attributes->set('bridge_ability', $ability);

        if (! $peer instanceof BridgePeer || ! $peer->can($ability)) {
            $request->attributes->set('bridge_audited', true);

            BridgeCall::record(
                callerApp: $peer?->appId,
                keyId: $peer?->keyId,
                method: $request->method(),
                path: $request->getPathInfo(),
                outcome: 'BRIDGE_SCOPE_DENIED',
                status: 403,
                ability: $ability,
            );

            return response()->json([
                'error' => 'BRIDGE_SCOPE_DENIED',
                'detail' => "This app is not granted the [{$ability}] ability.",
            ], 403, ['Cache-Control' => 'no-store']);
        }

        return $next($request);
    }
}
