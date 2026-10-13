<?php

namespace App\Http\Middleware;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateGpsDevice
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('gps_received_at', CarbonImmutable::now('UTC'));

        $expectedToken = config('gps.ingest_token');
        abort_unless(is_string($expectedToken) && $expectedToken !== '', 503, 'GPS reception is not configured.');

        $providedToken = $request->bearerToken();
        abort_unless(
            is_string($providedToken) && hash_equals($expectedToken, $providedToken),
            401,
            'Invalid GPS device token.',
        );

        return $next($request);
    }
}
