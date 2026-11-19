<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PortalAccount;
use App\Models\PortalPasswordReset;
use App\Models\PortalToken;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Customer identity: separate tables, separate bearer tokens, nothing shared with employees. */
class PortalAuthService
{
    /** Wrong e-mail codes tolerated before the current code is discarded. */
    private const VERIFY_MAX_ATTEMPTS = 3;

    private const VERIFY_TTL_MINUTES = 10;

    /**
     * @param  array{name: string, email: string, phone?: ?string, password: string, locale?: ?string}  $data
     * @return array{0: PortalAccount, 1: string, 2: string} account, bearer token, plain e-mail verification code
     */
    public function register(array $data): array
    {
        $account = PortalAccount::query()->create([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'fr',
        ]);

        // The e-mail is NOT verified yet: the website server e-mails this code and the customer confirms it.
        $code = $this->generateAndStoreVerificationCode($account);

        return [$account, $this->issueToken($account), $code];
    }

    /** @return array{0: PortalAccount, 1: string} */
    public function login(string $email, string $password): array
    {
        $account = PortalAccount::query()->where('email', Str::lower(trim($email)))->first();

        // Same answer for unknown e-mail and wrong password: no account enumeration.
        if (! $account || ! $account->is_active) {
            throw new BusinessRuleException('Invalid e-mail or password.', 'INVALID_CREDENTIALS', [], 401);
        }

        // Accounts created through Google have no local password: point the customer to the Google button.
        if ($account->password === null) {
            throw new BusinessRuleException('This account uses Google Sign-In. Please use the Google button.', 'GOOGLE_ACCOUNT_ONLY', [], 409);
        }

        if (! Hash::check($password, $account->password)) {
            throw new BusinessRuleException('Invalid e-mail or password.', 'INVALID_CREDENTIALS', [], 401);
        }

        $account->forceFill(['last_login_at' => now()])->save();

        return [$account, $this->issueToken($account)];
    }

    /**
     * Sign in (or sign up) with a Google identity already validated by the website server.
     * Lookup order: google_id, then e-mail (links the existing password account, never duplicates it).
     * Google accounts are pre-verified.
     *
     * @return array{0: PortalAccount, 1: string}
     */
    public function googleAuth(string $googleId, string $email, string $name, ?string $locale = 'en', ?string $avatarUrl = null): array
    {
        $email = Str::lower(trim($email));

        $account = PortalAccount::query()->where('google_id', $googleId)->first()
            ?? PortalAccount::query()->where('email', $email)->first();

        if ($account && $account->google_id !== null && $account->google_id !== $googleId) {
            // Same e-mail already tied to a different Google identity: never silently re-link.
            throw new BusinessRuleException('This e-mail is linked to another Google account.', 'GOOGLE_ID_MISMATCH', [], 409);
        }

        if ($account && ! $account->is_active) {
            throw new BusinessRuleException('This account is disabled.', 'ACCOUNT_DISABLED', [], 403);
        }

        if (! $account) {
            try {
                $account = PortalAccount::query()->create([
                    'google_id' => $googleId,
                    'name' => $name,
                    'email' => $email,
                    'locale' => $locale ?: 'en',
                    'avatar_url' => $avatarUrl,
                    'is_active' => true,
                    'email_verified_at' => now(),
                    'password' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Concurrent first sign-in: the other request created it; use that row.
                $account = PortalAccount::query()->where('email', $email)->firstOrFail();
            }
        }

        if ($account->google_id === null) {
            $account->forceFill(['google_id' => $googleId])->save();
        }

        if (! $account->hasVerifiedEmail()) {
            $account->markEmailAsVerified();
        }

        // The Google picture follows the Google profile; a sign-in without a picture never wipes the saved one.
        if ($avatarUrl !== null && $account->avatar_url !== $avatarUrl) {
            $account->avatar_url = $avatarUrl;
        }

        $account->forceFill(['last_login_at' => now()])->save();

        return [$account, $this->issueToken($account)];
    }

    /** Fresh 6-digit code; only its SHA-256 hash is stored. Resets the failed-attempt counter. */
    public function generateAndStoreVerificationCode(PortalAccount $account): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $account->forceFill([
            'email_verification_token' => hash('sha256', $code),
            'email_verification_expires_at' => now()->addMinutes(self::VERIFY_TTL_MINUTES),
        ])->save();

        Cache::forget($this->attemptsKey($account));

        return $code;
    }

    /** @return array{already_verified?: bool, verified?: bool, message?: string, locked?: bool} */
    public function verifyEmail(string $email, string $code): array
    {
        $account = PortalAccount::query()->where('email', Str::lower(trim($email)))->first();

        if (! $account) {
            throw new BusinessRuleException('Account not found.', 'ACCOUNT_NOT_FOUND', [], 404);
        }

        if ($account->hasVerifiedEmail()) {
            return ['already_verified' => true];
        }

        $stored = $account->email_verification_token;
        $valid = $stored !== null
            && $account->email_verification_expires_at !== null
            && $account->email_verification_expires_at->isFuture()
            && hash_equals($stored, hash('sha256', $code));

        if ($valid) {
            $account->markEmailAsVerified();
            Cache::forget($this->attemptsKey($account));

            return ['verified' => true];
        }

        // After 3 wrong codes the current code is discarded: the customer must request a new one.
        $key = $this->attemptsKey($account);
        Cache::add($key, 0, now()->addMinutes(self::VERIFY_TTL_MINUTES));
        $attempts = (int) Cache::increment($key);

        if ($stored !== null && $attempts >= self::VERIFY_MAX_ATTEMPTS) {
            $account->forceFill(['email_verification_token' => null, 'email_verification_expires_at' => null])->save();
            Cache::forget($key);

            return ['verified' => false, 'message' => 'Invalid or expired code', 'locked' => true];
        }

        return ['verified' => false, 'message' => 'Invalid or expired code'];
    }

    private function attemptsKey(PortalAccount $account): string
    {
        return 'portal_verify_attempts:'.$account->id;
    }

    public function issueToken(PortalAccount $account): string
    {
        $plain = Str::random(64);

        PortalToken::query()->create([
            'portal_account_id' => $account->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays((int) config('crm.portal.token_ttl_days')),
        ]);

        return $plain;
    }

    public function authenticate(string $plain): ?PortalAccount
    {
        $token = PortalToken::query()->with('account')->where('token_hash', hash('sha256', $plain))->first();

        if (! $token || $token->expires_at->lte(now()) || ! $token->account->is_active) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->save();

        return $token->account;
    }

    public function logout(string $plain): void
    {
        PortalToken::query()->where('token_hash', hash('sha256', $plain))->delete();
    }

    /**
     * Returns the plain reset token for the website server to e-mail, or null
     * for an unknown address (the caller answers identically either way).
     */
    public function createPasswordReset(string $email): ?string
    {
        $account = PortalAccount::query()->where('email', Str::lower(trim($email)))->where('is_active', true)->first();

        if (! $account) {
            return null;
        }

        $plain = Str::random(64);
        PortalPasswordReset::query()->create([
            'portal_account_id' => $account->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addMinutes((int) config('crm.portal.reset_ttl_minutes')),
        ]);

        return $plain;
    }

    public function resetPassword(string $plain, string $password): void
    {
        $reset = PortalPasswordReset::query()->where('token_hash', hash('sha256', $plain))->first();

        if (! $reset || $reset->used_at !== null || $reset->expires_at->lte(now())) {
            throw new BusinessRuleException('This reset link is invalid or has expired.', 'INVALID_RESET_TOKEN', [], 422);
        }

        $account = PortalAccount::query()->findOrFail($reset->portal_account_id);
        $account->forceFill(['password' => $password])->save();
        $reset->forceFill(['used_at' => Carbon::now()])->save();
        PortalToken::query()->where('portal_account_id', $account->id)->delete(); // sign out everywhere
    }
}
