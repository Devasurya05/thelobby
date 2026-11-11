<?php

namespace App\Integrations;

use App\Models\PartnerBooking;
use DateTimeImmutable;
use Throwable;

/**
 * Translator for the pretend airline site DemoFly.
 *
 * DemoFly's own format (a different "language" from DemoStay on purpose):
 *   pnr, rev, status (TICKETED | VOID), pax,
 *   segments [ {flight, from, to, departs_at, arrives_at} ]   times like 2026-12-15T18:30:00+05:30
 *
 * Standard format: every segment becomes one plan item. A time with a UTC offset is kept as the clock time
 * written in it (18:30+05:30 is 18:30), which is the local time at the airport. A flight has no rooms.
 */
class DemoFlyProvider implements BookingProvider
{
    public function key(): string
    {
        return 'fly';
    }

    public function label(): string
    {
        return 'DemoFly';
    }

    public function parse(array $payload): BookingData
    {
        $reference = $payload['pnr'] ?? null;
        $status = $payload['status'] ?? null;
        if (! is_string($reference) || $reference === '') {
            throw new InvalidBookingPayload('DemoFly left out "pnr".');
        }
        if (! in_array($status, ['TICKETED', 'VOID'], true)) {
            throw new InvalidBookingPayload('DemoFly sent an unknown ticket status.');
        }

        $pax = (int) ($payload['pax'] ?? 0);
        if ($pax < 1 || $pax > 9) {
            throw new InvalidBookingPayload('DemoFly sent a strange passenger count.');
        }

        $items = [];
        foreach ((array) ($payload['segments'] ?? []) as $segment) {
            $items[] = $this->item((array) $segment, $reference, $pax);
        }
        if ($items === []) {
            throw new InvalidBookingPayload('DemoFly sent a booking with no flights.');
        }

        return BookingData::make(
            $this->key(),
            $reference,
            (int) ($payload['rev'] ?? 0),
            $status === 'VOID',
            $items[0]['title'],
            [],
            $items,
            // "Goa to Bengaluru trip": the first flight's cities.
            (DemoCatalog::AIRPORTS[$payload['segments'][0]['from']] ?? $payload['segments'][0]['from']).' to '
                .(DemoCatalog::AIRPORTS[$payload['segments'][0]['to']] ?? $payload['segments'][0]['to']).' trip',
        );
    }

    public function fetch(string $reference): ?array
    {
        return PartnerBooking::where('provider', $this->key())->where('reference', $reference)->first()?->payload;
    }

    /** @param array<string, mixed> $segment */
    private function item(array $segment, string $reference, int $pax): array
    {
        $from = $segment['from'] ?? null;
        $to = $segment['to'] ?? null;
        if (! is_string($from) || ! is_string($to) || preg_match('/^[A-Z]{3}$/', $from) !== 1 || preg_match('/^[A-Z]{3}$/', $to) !== 1) {
            throw new InvalidBookingPayload('A DemoFly flight has a bad airport code.');
        }

        $departs = $this->moment($segment['departs_at'] ?? null);
        $arrives = $this->moment($segment['arrives_at'] ?? null);
        $fromName = DemoCatalog::AIRPORTS[$from] ?? $from;
        $toName = DemoCatalog::AIRPORTS[$to] ?? $to;
        $flight = is_string($segment['flight'] ?? null) ? $segment['flight'] : 'Flight';

        return [
            'kind' => 'flight',
            'title' => $flight.': '.$fromName.' to '.$toName,
            'starts_on' => $departs->format('Y-m-d'),
            'starts_time' => $departs->format('H:i'),
            // Only set an end date when the flight lands on another day.
            'ends_on' => $arrives->format('Y-m-d') !== $departs->format('Y-m-d') ? $arrives->format('Y-m-d') : null,
            'ends_time' => $arrives->format('H:i'),
            'place' => $fromName.' airport ('.$from.')',
            'address' => null,
            'notes' => 'DemoFly booking '.$reference.', '.$pax.($pax === 1 ? ' passenger' : ' passengers'),
        ];
    }

    /**
     * Only a strict "2026-12-15T18:30:00+05:30" shape is accepted (no "tomorrow", no "+1 week"),
     * because PHP's date parser would otherwise happily read all kinds of text.
     */
    private function moment(mixed $value): DateTimeImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw new InvalidBookingPayload('A DemoFly flight has a bad time.');
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new InvalidBookingPayload('A DemoFly flight has a bad time.');
        }
    }
}
