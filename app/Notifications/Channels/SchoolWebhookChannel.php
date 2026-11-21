<?php

namespace App\Notifications\Channels;

use App\Notifications\SchoolNotification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SchoolWebhookChannel
{
    public function send(object $notifiable, SchoolNotification $notification): void
    {
        $notifiable->refresh()->unsetRelation('roles');
        if (! $notifiable->external_notifications_enabled || ! $notification->shouldSend($notifiable, self::class)) {
            return;
        }
        if (! config('school-delivery.webhook_enabled')) {
            return;
        }
        $url = config('school-delivery.webhook_url');
        $token = config('school-delivery.webhook_token');
        $channel = config('school-delivery.channel');
        if (! is_string($url) || ! str_starts_with($url, 'https://') || ! $token || ! in_array($channel, ['whatsapp', 'sms'], true)) {
            throw new RuntimeException('Configura un adaptador HTTPS autenticado para el canal externo.');
        }
        $digits = preg_replace('/\D+/', '', $notifiable->phone ?? '');
        if (strlen($digits) === 9) {
            $digits = '51'.$digits;
        }
        if (! preg_match('/^[1-9][0-9]{7,14}$/', $digits)) {
            return;
        }
        Http::withToken($token)->timeout(15)->withHeaders(['Idempotency-Key' => $notification->id.':'.$notifiable->id.':'.$channel])->post($url, ['channel' => $channel, 'to' => '+'.$digits, 'message' => 'Tienes una novedad del colegio. Ingresa a tu portal para consultarla.', 'url' => route('login')])->throw();
    }
}
