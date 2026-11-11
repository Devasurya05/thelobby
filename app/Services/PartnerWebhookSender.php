<?php

namespace App\Services;

use App\Integrations\Providers;

/**
 * What a booking site does when it tells The Lobby about a booking: wrap the booking and the trip code in a message,
 * sign it with the shared secret, and deliver it.
 *
 * A real partner would send this as an HTTP POST from its own servers. Our pretend sites live inside this same app,
 * and the laptop's test server can only handle one request at a time, so the message is handed straight to
 * WebhookReceiver instead of going out over the network. Everything else (the signature, the timestamp, all the
 * checks) is the same. `php artisan demo:webhook` sends the same message over real HTTP.
 */
class PartnerWebhookSender
{
    public function __construct(private WebhookReceiver $receiver) {}

    /**
     * @param  array<string, mixed>  $payload  the booking in the partner's own format
     * @return array{status: int, body: array<string, string>}
     */
    public function deliver(string $providerKey, array $payload, string $tripCode): array
    {
        $body = self::body($payload, $tripCode);
        $timestamp = time();

        return $this->receiver->handle($providerKey, $body, (string) $timestamp, Providers::sign($providerKey, $timestamp, $body));
    }

    /** The exact text that gets signed and sent. */
    public static function body(array $payload, string $tripCode): string
    {
        return json_encode(['trip_code' => $tripCode, 'booking' => $payload], JSON_THROW_ON_ERROR);
    }
}
