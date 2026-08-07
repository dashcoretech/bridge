<?php

namespace Dashcore\Bridge\Http\Middleware;

use Closure;
use Dashcore\Bridge\BridgePeer;
use Dashcore\Bridge\Models\BridgeCall;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records which ability a bridge route exercises.
 *
 * Fleet membership is the authorization. A request that reaches this
 * middleware has already cleared VerifyBridgeRequest — known peer, known
 * key, fresh timestamp, valid Ed25519 signature, unused nonce — which is
 * to say the caller has cryptographically proven it is an enrolled member
 * of this fleet. Every member may call every endpoint of every other
 * member; there is nothing further to decide here.
 *
 * The ability string on the route (`bridge.scope:marketing.read`) is kept
 * as a *label*, not a gate. It names the capability in the audit trail and
 * in the fleet's endpoint directory, which is the only thing anyone was
 * ever reading it for.
 *
 * Revocation, not scoping, is the control: a revoked credential leaves the
 * signed manifest, so its signatures stop verifying and the caller is
 * refused at the door with a 401 rather than admitted and narrowed here.
 *
 * Setting `bridge.enforce_scope` restores per-ability denial for a fleet
 * that needs it — the grant data is still published in the manifest, so
 * that is a config change and not a migration.
 */
class EnsureBridgeScope
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $peer = $request->attributes->get('bridge_peer');

        // Label the call for the audit trail either way.
        $request->attributes->set('bridge_ability', $ability);

        if (! config('bridge.enforce_scope', false)) {
            return $next($request);
        }

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
