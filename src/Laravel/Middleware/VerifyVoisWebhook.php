<?php

namespace Vois\Laravel\Middleware;

use Closure;
use Illuminate\Http\Request;
use Vois\Exception\InvalidSignatureException;
use Vois\Webhook;

/**
 * Route middleware `vois.webhook`: rejects unsigned/stale VOIS webhooks with 401 and puts the
 * verified event on $request->attributes->get('vois_event').
 * Exclude the route from CSRF (it is called by the VOIS server, not a browser).
 */
final class VerifyVoisWebhook
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $event = Webhook::verify(
                $request->getContent(),
                $request->header('X-VOIS-Signature'),
                (string) config('vois.webhook.secret'),
                (int) config('vois.webhook.tolerance', 300),
            );
        } catch (InvalidSignatureException $e) {
            return response()->json(['error' => 'invalid_signature', 'message' => $e->getMessage()], 401);
        }
        $request->attributes->set('vois_event', $event);

        return $next($request);
    }
}
