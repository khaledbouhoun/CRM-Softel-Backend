<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Network-level access control (this CRM has no user login): only clients
 * whose IP matches config('crm.allowed_networks') may call the API.
 */
class RestrictToAllowedNetworks
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('crm.allowed_networks', []);

        if (in_array('*', $allowed, true)) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip === null || ! IpUtils::checkIp($ip, $allowed)) {
            return ApiResponse::error(
                'Access denied: this computer is not on an authorized network.',
                403,
                'NETWORK_NOT_ALLOWED',
            );
        }

        return $next($request);
    }
}
