<?php

namespace App\Services;

use App\Integrations\InvalidBookingPayload;
use App\Integrations\Providers;
use App\Models\Trip;
use Illuminate\Support\Facades\Log;

/**
 * Receives one signed message from a booking site and decides what happens to it. The web route and our own
 * pretend sites both call this same method, so what the demo shows is the real code path.
 *
 * The checks run in this order, cheapest and most important first, and each failure says as little as possible:
 *   1. is it a partner we know?   2. is the body a sane size?   3. is a secret configured for it?
 *   4. is the timestamp fresh (stops replays)?   5. does the signature match (proves who sent it)?
 *   6. only now is the body read as JSON, then translated, then handed to BookingService.
 * Nothing in the body is looked at before the signature has been verified.
 */
class WebhookReceiver
{
    public function __construct(private BookingService $bookings) {}

    /**
     * @return array{status: int, body: array<string, string>}
     */
    public function handle(string $providerKey, string $body, ?string $timestamp, ?string $signature): array
    {
        $provider = Providers::find($providerKey);
        if ($provider === null) {
            return $this->reply(404, ['error' => 'unknown_provider']);
        }

        if (strlen($body) > (int) config('lobby.webhook.max_bytes')) {
            return $this->reply(413, ['error' => 'payload_too_large']);
        }

        if (Providers::secret($providerKey) === '') {
            return $this->reject($providerKey, 503, 'not_configured');
        }

        if (! Providers::isFresh($timestamp)) {
            return $this->reject($providerKey, 401, 'stale_or_missing_timestamp');
        }

        if (! Providers::verify($providerKey, $timestamp, $body, $signature)) {
            return $this->reject($providerKey, 401, 'bad_signature');
        }

        // From here on we know the partner really sent it and it is fresh.
        $envelope = json_decode($body, true);
        $code = is_array($envelope) ? ($envelope['trip_code'] ?? null) : null;
        $booking = is_array($envelope) ? ($envelope['booking'] ?? null) : null;

        if (! is_string($code) || preg_match('/^[A-Za-z0-9]{8}$/', $code) !== 1 || ! is_array($booking)) {
            return $this->reply(400, ['error' => 'bad_payload']);
        }

        $trip = Trip::where('join_code', strtoupper($code))->first();
        if ($trip === null) {
            return $this->reply(404, ['error' => 'unknown_trip']);
        }

        try {
            $data = $provider->parse($booking);
        } catch (InvalidBookingPayload $e) {
            return $this->reply(422, ['error' => 'bad_booking', 'detail' => $e->getMessage()]);
        }

        $result = $this->bookings->receive($trip, $data);

        // 202 = accepted but waiting for the organiser; 200 = dealt with.
        return $this->reply($result === 'staged' ? 202 : 200, ['result' => $result]);
    }

    /** A refused message is logged (without its body) so repeated attempts can be spotted. */
    private function reject(string $provider, int $status, string $reason): array
    {
        Log::warning('Webhook refused', ['provider' => $provider, 'reason' => $reason, 'ip' => request()->ip()]);

        return $this->reply($status, ['error' => $reason]);
    }

    /**
     * @param  array<string, string>  $body
     * @return array{status: int, body: array<string, string>}
     */
    private function reply(int $status, array $body): array
    {
        return ['status' => $status, 'body' => $body];
    }
}
