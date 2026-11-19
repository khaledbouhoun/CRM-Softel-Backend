<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\MessageTemplate;

/**
 * Builds a wa.me link with a prefilled message for any appointment kind (demo, meeting,
 * training, accompaniment). Nothing is ever sent from here: the employee opens the link
 * and presses send, so the CRM never claims delivery.
 *
 * The template is chosen by appointment kind AND the client's preferred language.
 */
class WhatsAppLinkBuilder
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    /** @return array<string, string> placeholder => meaning */
    public static function placeholderHelp(): array
    {
        return [
            '{client_name}' => 'Contact person (or company name)', '{company_name}' => 'Company name', '{product_name}' => 'Product',
            '{date}' => 'Date d/m/Y', '{time}' => 'Start time H:i', '{end}' => 'End time H:i',
            '{link}' => 'Meeting link, else the resource link', '{meeting_url}' => 'Meeting link', '{resource_url}' => 'Resource link',
            '{employee}' => 'Assigned employee', '{appointment_type}' => 'Appointment type',
        ];
    }

    /**
     * Template codes to try, most specific first.
     *
     * @return list<string>
     */
    public static function templateCodes(Appointment $appointment): array
    {
        $group = $appointment->type->group;

        if ($group === 'training') {
            return [$appointment->training_kind === 'first' ? 'training_first' : 'training_review', 'appointment_training'];
        }

        return ['appointment_'.$group];
    }

    /** @return list<string> language, then the configured fallbacks */
    public static function languageChain(?string $language): array
    {
        return array_values(array_unique(array_filter([$language, ...config('crm_templates.fallback_languages')])));
    }

    /**
     * @return array{url: string, message: string, phone: string, language: string, template_code: string, delivery_confirmed: bool}|null
     */
    public function forAppointment(Appointment $appointment, ?string $language = null): ?array
    {
        if (! $appointment->send_whatsapp || $appointment->status !== Appointment::CONFIRMED) {
            return null;
        }

        $phone = $this->normalisePhone($appointment->prospect->phone);

        if ($phone === null) {
            return null;
        }

        $chain = self::languageChain($language ?? $appointment->prospect->language);
        $codes = self::templateCodes($appointment);
        $templates = MessageTemplate::query()->where('is_active', true)->whereIn('code', $codes)->whereIn('locale', $chain)->get();

        // Most specific code first, then the client's language before the fallbacks.
        $template = null;
        foreach ($codes as $code) {
            foreach ($chain as $locale) {
                $template = $templates->first(fn ($t) => $t->code === $code && $t->locale === $locale);
                if ($template) {
                    break 2;
                }
            }
        }

        if (! $template) {
            return null;
        }

        $message = trim(preg_replace('/[ \t]{2,}/', ' ', strtr($template->body, $this->placeholders($appointment))));

        return [
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message),
            'message' => $message,
            'phone' => '+'.$phone,
            'language' => $template->locale,
            'template_code' => $template->code,
            'delivery_confirmed' => false,
        ];
    }

    /** @return array<string, string> */
    public function placeholders(Appointment $appointment): array
    {
        $prospect = $appointment->prospect;
        $name = $prospect->contact_person ?: $prospect->name;
        $product = $appointment->product?->name ?? '';
        $time = $appointment->starts_at->format('H:i');
        $link = $appointment->meeting_url ?: ($appointment->resource_url ?? '');

        return [
            '{client_name}' => $name, '{contact}' => $name, '{company_name}' => $prospect->name,
            '{product_name}' => $product, '{product}' => $product,
            '{date}' => $appointment->starts_at->format('d/m/Y'), '{time}' => $time, '{start}' => $time,
            '{end}' => $appointment->ends_at->format('H:i'),
            '{link}' => $link, '{meeting_url}' => (string) $appointment->meeting_url, '{resource_url}' => (string) $appointment->resource_url,
            '{employee}' => (string) $appointment->assigned_employee, '{appointment_type}' => $appointment->type->name,
        ];
    }

    /** Digits in international (E.164) form without "+", or null when it cannot be a valid number. */
    public function normalisePhone(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        $country = (string) $this->scheduling->setting('whatsapp_country_code');

        $e164 = match (true) {
            str_starts_with($raw, '+') => $digits,
            str_starts_with($digits, '00') => substr($digits, 2),
            str_starts_with($digits, '0') => $country.substr($digits, 1),
            strlen($digits) <= 9 => $country.$digits,        // national number written without the leading 0
            default => $digits,
        };

        return strlen($e164) >= 8 && strlen($e164) <= 15 ? $e164 : null;
    }
}
