<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PortalForgotPasswordRequest;
use App\Http\Requests\Api\V1\PortalGoogleAuthRequest;
use App\Http\Requests\Api\V1\PortalLoginRequest;
use App\Http\Requests\Api\V1\PortalRegisterRequest;
use App\Http\Requests\Api\V1\PortalResendVerificationRequest;
use App\Http\Requests\Api\V1\PortalResetPasswordRequest;
use App\Http\Requests\Api\V1\PortalVerifyEmailRequest;
use App\Http\Resources\Api\V1\PortalPresenter;
use App\Http\Responses\ApiResponse;
use App\Models\PortalAccount;
use App\Services\PortalAuthService;
use App\Services\PortalProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PortalAuthController extends Controller
{
    public function __construct(private readonly PortalAuthService $auth, private readonly PortalProfileService $profile) {}

    public function register(PortalRegisterRequest $request): JsonResponse
    {
        [$account, $token, $code] = $this->auth->register($request->validated());
        $account->load('prospect');

        // The plain code goes back to the website server only, which e-mails it to the customer.
        return ApiResponse::created([
            'account' => PortalPresenter::account($account),
            'onboarding' => $this->profile->onboarding($account),
            'token' => $token,
            'email_verification_code' => $code,
        ], 'Account created.');
    }

    public function login(PortalLoginRequest $request): JsonResponse
    {
        [$account, $token] = $this->auth->login($request->validated('email'), $request->validated('password'));
        $account->load('prospect');

        return ApiResponse::success(['account' => PortalPresenter::account($account), 'onboarding' => $this->profile->onboarding($account), 'token' => $token], 'Signed in.');
    }

    public function googleAuth(PortalGoogleAuthRequest $request): JsonResponse
    {
        $v = $request->validated();
        [$account, $token] = $this->auth->googleAuth($v['google_id'], $v['email'], $v['name'], $v['locale'] ?? 'en', $v['avatar_url'] ?? null);
        $account->load('prospect');

        return ApiResponse::success(['account' => PortalPresenter::account($account), 'onboarding' => $this->profile->onboarding($account), 'token' => $token], 'Signed in.');
    }

    public function verifyEmail(PortalVerifyEmailRequest $request): JsonResponse
    {
        $result = $this->auth->verifyEmail($request->validated('email'), $request->validated('code'));

        if (($result['already_verified'] ?? false) || ($result['verified'] ?? false)) {
            return ApiResponse::success($result, 'Email verified.');
        }

        $locked = (bool) ($result['locked'] ?? false);

        return ApiResponse::error(
            $result['message'] ?? 'Invalid or expired code',
            422,
            $locked ? 'VERIFICATION_ATTEMPTS_EXCEEDED' : 'INVALID_VERIFICATION_CODE',
        );
    }

    /** The code goes back to the website server (which e-mails it); unknown/verified addresses get the same neutral answer. */
    public function resendVerification(PortalResendVerificationRequest $request): JsonResponse
    {
        $account = PortalAccount::query()
            ->where('email', Str::lower(trim($request->validated('email'))))
            ->where('is_active', true)
            ->first();

        $code = ($account && ! $account->hasVerifiedEmail())
            ? $this->auth->generateAndStoreVerificationCode($account)
            : null;

        return ApiResponse::success(['verification_code' => $code], 'If the account needs verification, a new code will be sent.');
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
