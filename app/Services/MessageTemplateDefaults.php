<?php

namespace App\Services;

use App\Enums\AppointmentKind;
use App\Models\MessageTemplate;

/** Installs and audits the message templates every appointment kind needs. */
class MessageTemplateDefaults
{
    /** Adds the missing default templates; templates that exist (even edited or deactivated) are left alone. */
    public static function install(): int
    {
        $created = 0;

        foreach (config('crm_templates.templates') as [$code, $language, $body]) {
            $template = MessageTemplate::query()->firstOrCreate(['code' => $code, 'locale' => $language], ['body' => $body, 'is_active' => true]);
            $created += $template->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** One template per code and language is required: the four appointment kinds plus first/follow-up training. */
    public static function requiredCodes(): array
    {
        return [...array_map(fn ($kind) => 'appointment_'.$kind, AppointmentKind::values()), 'training_first', 'training_review'];
    }

    /** @return array{languages: list<string>, required: list<string>, missing: list<array{code: string, language: string, reason: string}>, placeholders: list<string>} */
    public static function audit(): array
    {
        $languages = config('crm_templates.languages');
        $existing = MessageTemplate::query()->get()->keyBy(fn ($t) => $t->code.'|'.$t->locale);
        $missing = [];

        foreach (self::requiredCodes() as $code) {
            foreach ($languages as $language) {
                $template = $existing->get($code.'|'.$language);

                if (! $template) {
                    $missing[] = ['code' => $code, 'language' => $language, 'reason' => 'missing'];
                } elseif (! $template->is_active) {
                    $missing[] = ['code' => $code, 'language' => $language, 'reason' => 'inactive'];
                } elseif (trim((string) $template->body) === '') {
                    $missing[] = ['code' => $code, 'language' => $language, 'reason' => 'empty'];
                }
            }
        }

        return ['languages' => $languages, 'required' => self::requiredCodes(), 'missing' => $missing, 'placeholders' => array_keys(WhatsAppLinkBuilder::placeholderHelp())];
    }
}
