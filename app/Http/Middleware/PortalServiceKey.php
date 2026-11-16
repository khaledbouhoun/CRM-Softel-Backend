<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the customer portal API: only the public website's server, holding the
 * shared service key, may call it (browsers never receive this key). The staff
 * LAN allow-list is deliberately NOT applied here, and the staff routes never
 * accept this key.
 */
class PortalServiceKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('crm.portal.service_key');

        if ($expected === '') {
            return ApiResponse::error('The customer portal is not configured.', 503, 'PORTAL_DISABLED');
        }

        $networks = config('crm.portal.allowed_networks', []);
        if ($networks !== [] && ! IpUtils::checkIp((string) $request->ip(), $networks)) {
            return ApiResponse::error('Access denied.', 403, 'NETWORK_NOT_ALLOWED');
        }

        if (! hash_equals($expected, (string) $request->header('X-Portal-Key'))) {
            return ApiResponse::error('Invalid service credentials.', 401, 'INVALID_SERVICE_KEY');
        }

        return $next($request);
    }
}
