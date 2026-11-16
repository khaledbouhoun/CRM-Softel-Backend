<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use App\Services\PortalAuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Resolves the signed-in customer from the bearer token; exposes it as request attribute "portal_account". */
class AuthenticatePortalAccount
{
    public function __construct(private readonly PortalAuthService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();
        $account = $plain ? $this->auth->authenticate($plain) : null;

        if (! $account) {
            return ApiResponse::error('Please sign in.', 401, 'UNAUTHENTICATED');
        }

        $request->attributes->set('portal_account', $account);

        return $next($request);
    }
}
