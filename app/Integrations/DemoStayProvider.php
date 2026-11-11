<?php

namespace App\Integrations;

use App\Models\PartnerBooking;

/**
 * Translator for the pretend hotel site DemoStay.
 *
 * DemoStay's own format (note the field names: this is "their language"):
 *   booking_ref, version, state (CONFIRMED | CANCELLED),
 *   hotel {name, address}, check_in, check_out, check_in_from, check_out_by,
 *   rooms [ {type, guests} ]
 *
 * Standard format: every room becomes "Room N (type)" with one bed per guest, and the stay becomes one plan item.
 */
class DemoStayProvider implements BookingProvider
{
    public function key(): string
    {
        return 'stay';
    }

    public function label(): string
    {
        return 'DemoStay';
    }

    public function parse(array $payload): BookingData
    {
        $reference = $this->text($payload, 'booking_ref');
        $state = $this->text($payload, 'state');
        if (! in_array($state, ['CONFIRMED', 'CANCELLED'], true)) {
            throw new InvalidBookingPayload('DemoStay sent an unknown booking state.');
        }

        $hotel = $payload['hotel'] ?? null;
        if (! is_array($hotel) || ! isset($hotel['name'])) {
            throw new InvalidBookingPayload('DemoStay did not say which hotel.');
        }

        $checkIn = $this->text($payload, 'check_in');
        $checkOut = $this->text($payload, 'check_out');
        if ($checkOut < $checkIn) {
            throw new InvalidBookingPayload('The check-out date is before the check-in date.');
        }

        $rooms = [];
        foreach ((array) ($payload['rooms'] ?? []) as $i => $room) {
            $guests = (int) (is_array($room) ? ($room['guests'] ?? 0) : 0);
            if ($guests < 1) {
                throw new InvalidBookingPayload('A DemoStay room has no guests.');
            }
            $rooms[] = [
                'name' => 'Room '.($i + 1).' ('.(is_array($room) ? ($room['type'] ?? 'Standard') : 'Standard').')',
                'beds' => array_map(fn ($n) => 'Bed '.$n, range(1, min($guests, 50))),
            ];
        }

        return BookingData::make(
            $this->key(),
            $reference,
            (int) ($payload['version'] ?? 0),
            $state === 'CANCELLED',
            (string) $hotel['name'],
            $rooms,
            [[
                'kind' => 'stay',
                'title' => (string) $hotel['name'],
                'starts_on' => $checkIn,
                'starts_time' => $payload['check_in_from'] ?? null,
                'ends_on' => $checkOut,
                'ends_time' => $payload['check_out_by'] ?? null,
                'place' => (string) $hotel['name'],
                'address' => $hotel['address'] ?? null,
                'notes' => 'DemoStay booking '.$reference,
            ]],
            $hotel['name'].' trip',
        );
    }

    public function fetch(string $reference): ?array
    {
        return PartnerBooking::where('provider', $this->key())->where('reference', $reference)->first()?->payload;
    }

    /** @param array<string, mixed> $payload */
    private function text(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidBookingPayload("DemoStay left out \"$field\".");
        }

        return $value;
    }
}
