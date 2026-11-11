<?php

namespace App\Integrations;

use App\Models\ItineraryItem;
use App\Support\RoomListParser;
use DateTimeImmutable;

/**
 * THE standard format. Every booking site is translated into this and nothing else is ever looked at.
 *
 *   rooms: [ ['name' => 'Room 1 (Sea view)', 'beds' => ['Bed 1', 'Bed 2']], ... ]
 *   items: [ ['kind' => 'stay', 'title' => ..., 'starts_on' => 'Y-m-d', 'starts_time' => 'H:i'|null,
 *             'ends_on' => 'Y-m-d'|null, 'ends_time' => 'H:i'|null, 'place' => ..., 'address' => ..., 'notes' => ...], ... ]
 *
 * An item is a line of the Plan; a room becomes a room with beds in the lobby. Times are wall-clock times
 * at the place (no time zone), the same as on the Plan page.
 *
 * Build one with BookingData::make(): it checks and cleans everything (types, lengths, dates, counts),
 * because the values come from another company's system and are not trusted.
 */
final class BookingData
{
    public const MAX_ROOMS = 10;

    public const MAX_ITEMS = 10;

    private const MAX_TEXT = 500;

    /**
     * @param  list<array{name: string, beds: list<string>}>  $rooms
     * @param  list<array<string, mixed>>  $items
     */
    private function __construct(
        public readonly string $provider,
        public readonly string $reference,
        public readonly int $version,
        public readonly bool $cancelled,
        public readonly string $title,
        // A name to use if a trip is created from this booking ("Hotel Sunrise trip"). Only used at that moment.
        public readonly string $tripName,
        public readonly array $rooms,
        public readonly array $items,
    ) {}

    /**
     * @param  array<int, mixed>  $rooms
     * @param  array<int, mixed>  $items
     *
     * @throws InvalidBookingPayload
     */
    public static function make(string $provider, string $reference, int $version, bool $cancelled, string $title, array $rooms, array $items, ?string $tripName = null): self
    {
        if (preg_match('/^[A-Z0-9-]{4,40}$/', $reference) !== 1) {
            throw new InvalidBookingPayload('The booking reference looks wrong.');
        }
        if ($version < 1 || $version > 1000000) {
            throw new InvalidBookingPayload('The booking version number looks wrong.');
        }
        if (count($rooms) > self::MAX_ROOMS || count($items) > self::MAX_ITEMS) {
            throw new InvalidBookingPayload('The booking has too many rooms or parts.');
        }

        $cleanRooms = [];
        foreach ($rooms as $room) {
            $room = (array) $room;
            $beds = array_values(array_map(fn ($label) => self::text($label, RoomListParser::MAX_NAME_LENGTH), (array) ($room['beds'] ?? [])));
            $name = self::text($room['name'] ?? '', RoomListParser::MAX_NAME_LENGTH);
            if ($name === '' || $beds === [] || count($beds) > RoomListParser::MAX_BEDS_PER_ROOM || in_array('', $beds, true)) {
                throw new InvalidBookingPayload('A room in the booking is missing a name or beds.');
            }
            $cleanRooms[] = ['name' => $name, 'beds' => $beds];
        }

        $cleanItems = array_map(fn ($item) => self::cleanItem((array) $item), array_values($items));

        $title = self::text($title, 120);
        if ($title === '') {
            throw new InvalidBookingPayload('The booking has no title.');
        }

        $tripName = self::text($tripName ?? $title.' trip', 100);

        return new self($provider, $reference, $version, $cancelled, $title, $tripName !== '' ? $tripName : 'Trip', $cleanRooms, $cleanItems);
    }

    /** @param array<string, mixed> $item */
    private static function cleanItem(array $item): array
    {
        if (! array_key_exists((string) ($item['kind'] ?? ''), ItineraryItem::KINDS)) {
            throw new InvalidBookingPayload('A part of the booking has an unknown type.');
        }

        $title = self::text($item['title'] ?? '', 120);
        $startsOn = self::date($item['starts_on'] ?? null);
        if ($title === '' || $startsOn === null) {
            throw new InvalidBookingPayload('A part of the booking is missing a title or a date.');
        }

        $endsOn = isset($item['ends_on']) ? self::date($item['ends_on']) : null;
        if (isset($item['ends_on']) && $endsOn === null) {
            throw new InvalidBookingPayload('A part of the booking has a bad end date.');
        }

        return [
            'kind' => $item['kind'],
            'title' => $title,
            'starts_on' => $startsOn,
            'starts_time' => self::time($item['starts_time'] ?? null),
            'ends_on' => $endsOn,
            'ends_time' => self::time($item['ends_time'] ?? null),
            'place' => self::text($item['place'] ?? '', 120) ?: null,
            'address' => self::text($item['address'] ?? '', 200) ?: null,
            'notes' => self::text($item['notes'] ?? '', self::MAX_TEXT) ?: null,
        ];
    }

    /** One line of plain text: no control characters, trimmed, cut to a length. Blade escapes it again on output. */
    private static function text(mixed $value, int $max): string
    {
        if (! is_scalar($value)) {
            return '';
        }
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '';

        return trim(mb_substr(trim($clean), 0, $max));
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value && $value > '2000-01-01' && $value < '2100-01-01' ? $value : null;
    }

    private static function time(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $t = DateTimeImmutable::createFromFormat('!H:i', $value);

        return $t !== false && $t->format('H:i') === $value ? $value : null;
    }

    /** The first day and the last day this booking covers (both Y-m-d), or null when it has no plan items. */
    public function span(): ?array
    {
        if ($this->items === []) {
            return null;
        }

        $starts = array_column($this->items, 'starts_on');
        $ends = array_map(fn ($i) => $i['ends_on'] ?? $i['starts_on'], $this->items);

        return [min($starts), max($ends)];
    }

    /** One short line for the Plan page: what it is and when, then what it adds to the lobby. */
    public function summary(): string
    {
        $parts = [];

        if ($this->items !== []) {
            $first = $this->items[0];
            $last = $this->items[array_key_last($this->items)];
            $from = new DateTimeImmutable($first['starts_on']);
            $to = new DateTimeImmutable($last['ends_on'] ?? $last['starts_on']);
            $dates = $from->format('Y-m-d') === $to->format('Y-m-d') ? $from->format('j M') : $from->format('j M').' to '.$to->format('j M');
            $parts[] = $first['title'].', '.$dates;
            if (count($this->items) > 1) {
                $parts[] = count($this->items).' parts';
            }
        }

        if ($this->rooms !== []) {
            $beds = array_sum(array_map(fn ($r) => count($r['beds']), $this->rooms));
            $parts[] = count($this->rooms).(count($this->rooms) === 1 ? ' room' : ' rooms').', '.$beds.($beds === 1 ? ' bed' : ' beds');
        }

        return $parts === [] ? $this->title : implode(' · ', $parts);
    }

    /** @return array<string, mixed> for the JSON column */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'reference' => $this->reference,
            'version' => $this->version,
            'cancelled' => $this->cancelled,
            'title' => $this->title,
            'trip_name' => $this->tripName,
            'rooms' => $this->rooms,
            'items' => $this->items,
        ];
    }

    /**
     * Rebuild from our own stored JSON. It already passed make() once, so this only restores it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['provider'],
            (string) $data['reference'],
            (int) $data['version'],
            (bool) $data['cancelled'],
            (string) $data['title'],
            (string) ($data['trip_name'] ?? $data['title'].' trip'),   // rows stored before this field existed have none
            $data['rooms'] ?? [],
            $data['items'] ?? [],
        );
    }
}
