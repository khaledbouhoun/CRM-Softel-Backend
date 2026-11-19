<?php

namespace App\Services;

use App\Models\PortalAccount;
use App\Models\PortalRequest;
use App\Support\Wilayas;

/** What a signed-in customer has told us already, and where the website should send them first. */
class PortalProfileService
{
    public const FIELDS = ['company', 'contact_name', 'job_title', 'phone', 'wilaya', 'city', 'business_activity'];

    /** First request choices: a presentation (booked demo) or the demo version (trial). */
    public const ONBOARDING_OPTIONS = [
        ['type' => 'demo', 'label' => 'Presentation'],
        ['type' => 'trial', 'label' => 'Demo version'],
    ];

    /**
     * Values for the request form, most reliable first: saved profile, then the customer file, then the account.
     *
     * @return array<string, ?string>
     */
    public function requestDefaults(PortalAccount $account): array
    {
        $account->loadMissing('prospect');
        $p = $account->prospect;

        return [
            'company' => $account->company ?: $p?->name,
            'contact_name' => $account->contact_name ?: ($p?->contact_person ?: $account->name),
            'job_title' => $account->job_title ?: $p?->profession,
            'phone' => $account->phone ?: $p?->phone,
            'email' => $account->email,
            'wilaya' => $account->wilaya ?: ($p?->address ? (Wilayas::resolve($p->address)['label'] ?? null) : null),
            'city' => $account->city ?: $p?->city,
            'business_activity' => $account->business_activity ?: $p?->business_activity,
        ];
    }

    /** Remembers the details of a submitted request for next time (never blanks a saved value). */
    public function remember(PortalAccount $account, array $data): void
    {
        $values = collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => $data[$f] ?? null])->filter(fn ($v) => filled($v))->all();

        $account->forceFill($values + ['onboarded_at' => $account->onboarded_at ?? now()])->save();
    }

    /** @return array{required: bool, is_first_login: bool, redirect_to: string, options: list<array{type: string, label: string}>} */
    public function onboarding(PortalAccount $account): array
    {
        $first = $account->onboarded_at === null && ! PortalRequest::query()->where('portal_account_id', $account->id)->exists();

        return [
            'required' => $first,
            'is_first_login' => $first,
            'redirect_to' => $first ? 'new_request' : 'overview',
            'options' => self::ONBOARDING_OPTIONS,
        ];
    }
}
