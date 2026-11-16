<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PortalForgotPasswordRequest;
use App\Http\Requests\Api\V1\PortalLoginRequest;
use App\Http\Requests\Api\V1\PortalRegisterRequest;
use App\Http\Requests\Api\V1\PortalResetPasswordRequest;
use App\Http\Resources\Api\V1\PortalPresenter;
use App\Http\Responses\ApiResponse;
use App\Services\PortalAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAuthController extends Controller
{
    public function __construct(private readonly PortalAuthService $auth) {}

    public function register(PortalRegisterRequest $request): JsonResponse
    {
        [$account, $token] = $this->auth->register($request->validated());
        $account->load('prospect');

        return ApiResponse::created(['account' => PortalPresenter::account($account), 'token' => $token], 'Account created.');
    }

    public function login(PortalLoginRequest $request): JsonResponse
    {
        [$account, $token] = $this->auth->login($request->validated('email'), $request->validated('password'));
        $account->load('prospect');

        return ApiResponse::success(['account' => PortalPresenter::account($account), 'token' => $token], 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout((string) $request->bearerToken());

        return ApiResponse::success(null, 'Signed out.');
    }

    /** Always answers the same; the token goes back to the website server, which e-mails the link. */
    public function forgotPassword(PortalForgotPasswordRequest $request): JsonResponse
    {
        $token = $this->auth->createPasswordReset($request->validated('email'));

        return ApiResponse::success(['reset_token' => $token], 'If the account exists, a reset message will be sent.');
    }

    public function resetPassword(PortalResetPasswordRequest $request): JsonResponse
    {
        $this->auth->resetPassword($request->validated('token'), $request->validated('password'));

        return ApiResponse::success(null, 'Password updated. Please sign in.');
    }
}
