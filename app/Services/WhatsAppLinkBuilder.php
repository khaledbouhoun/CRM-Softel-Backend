<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\MessageTemplate;

/**
 * Builds a wa.me link with a prefilled message. Nothing is ever sent from here:
 * the employee opens the link and presses send, so the CRM never claims delivery.
 */
class WhatsAppLinkBuilder
{
    public function __construct(private readonly SchedulingService $scheduling) {}

    /** @return array{url: string, message: string, delivery_confirmed: bool}|null */
    public function forAppointment(Appointment $appointment, string $locale = 'fr'): ?array
    {
        $phone = $this->normalisePhone($appointment->prospect->phone);

        if (! $appointment->send_whatsapp || $phone === null) {
            return null;
        }

        $code = $appointment->training_kind === 'first' ? 'training_first' : 'training_review';
        $template = MessageTemplate::query()->where('code', $code)->where('is_active', true)
            ->whereIn('locale', [$locale, 'fr'])->orderByRaw('locale = ? desc', [$locale])->first();

        if (! $template) {
            return null;
        }

        $message = strtr($template->body, [
            '{contact}' => $appointment->prospect->contact_person ?? $appointment->prospect->name,
            '{product}' => $appointment->product?->name ?? '',
            '{date}' => $appointment->starts_at->format('d/m/Y'),
            '{start}' => $appointment->starts_at->format('H:i'),
            '{end}' => $appointment->ends_at->format('H:i'),
            '{resource_url}' => $appointment->resource_url ?? '',
        ]);

        return [
            'url' => 'https://wa.me/'.$phone.'?text='.rawurlencode($message),
            'message' => $message,
            'delivery_confirmed' => false,
        ];
    }

    private function normalisePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if ($digits === '') {
            return null;
        }

        $country = (string) $this->scheduling->setting('whatsapp_country_code');

        return str_starts_with($digits, '0') ? $country.substr($digits, 1) : $digits;
    }
}
