<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\PortalAccount;
use App\Models\PortalPasswordReset;
use App\Models\PortalToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Customer identity: separate tables, separate bearer tokens, nothing shared with employees. */
class PortalAuthService
{
    /** @param array{name: string, email: string, phone?: ?string, password: string, locale?: ?string} $data */
    public function register(array $data): array
    {
        $account = PortalAccount::query()->create([
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'fr',
        ]);

        return [$account, $this->issueToken($account)];
    }

    /** @return array{0: PortalAccount, 1: string} */
    public function login(string $email, string $password): array
    {
        $account = PortalAccount::query()->where('email', Str::lower(trim($email)))->first();

        // Same answer for unknown e-mail and wrong password: no account enumeration.
        if (! $account || ! $account->is_active || ! Hash::check($password, $account->password)) {
            throw new BusinessRuleException('Invalid e-mail or password.', 'INVALID_CREDENTIALS', [], 401);
        }

        $account->forceFill(['last_login_at' => now()])->save();

        return [$account, $this->issueToken($account)];
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
