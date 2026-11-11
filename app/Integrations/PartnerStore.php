<?php

namespace App\Integrations;

use App\Models\PartnerBooking;
use App\Services\PartnerWebhookSender;
use Throwable;

/**
 * The pretend booking sites' bookkeeping: new references, sending a booking to a trip, and pushing changes
 * to every trip the booking was sent to. Only the demo pages use this.
 */
class PartnerStore
{
    /** The demo tables are open to anyone who visits /demo, so they have a size limit. */
    public const MAX_BOOKINGS = 500;

    public function __construct(private PartnerWebhookSender $sender) {}

    public function isFull(): bool
    {
        return PartnerBooking::count() >= self::MAX_BOOKINGS;
    }

    /** For example STAY-7K2P9Q. Same look-alike-free alphabet as trip codes. */
    public function newReference(string $prefix): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $reference = $prefix.'-'.$suffix;
        } while (PartnerBooking::where('reference', $reference)->exists());

        return $reference;
    }

    /**
     * "Send to The Lobby": deliver the booking to one trip and remember the trip, so later changes follow.
     *
     * @return array{status: int, body: array<string, string>}
     */
    public function sendTo(PartnerBooking $booking, string $tripCode): array
    {
        $tripCode = strtoupper(trim($tripCode));
        $result = $this->sender->deliver($booking->provider, $booking->payload, $tripCode);

        if ($result['status'] < 300) {
            $sentTo = $booking->sent_to ?? [];
            if (! in_array($tripCode, $sentTo, true)) {
                $sentTo[] = $tripCode;
                $booking->forceFill(['sent_to' => $sentTo])->save();
            }
        }

        return $result;
    }

    /** After a change or cancellation: tell every trip that already has this booking. A failure never blocks the change. */
    public function pushUpdate(PartnerBooking $booking): int
    {
        $pushed = 0;

        foreach ($booking->sent_to ?? [] as $tripCode) {
            try {
                $this->sender->deliver($booking->provider, $booking->payload, $tripCode);
                $pushed++;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $pushed;
    }

    /** A sentence for the person using the pretend site. */
    public static function describe(array $result): string
    {
        $key = $result['body']['result'] ?? $result['body']['error'] ?? 'unknown';

        return match ($key) {
            'staged' => 'Sent. The organiser of that trip will see it on the Plan page and can add it to the trip.',
            'applied' => 'Sent. The trip was updated.',
            'duplicate' => 'That trip already has this version of the booking.',
            'ignored' => 'The organiser of that trip chose not to add this booking, so nothing changed.',
            'unknown_trip' => 'No trip has that code. Check it and try again.',
            default => 'The Lobby did not accept the message ('.$key.').',
        };
    }
}
