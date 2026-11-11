<?php

namespace Tests\Feature;

use App\Integrations\BookingData;
use App\Integrations\DemoFlyProvider;
use App\Integrations\DemoStayProvider;
use App\Integrations\InvalidBookingPayload;
use App\Integrations\Providers;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * The translators: each booking site's own format in, the standard format out. These sample messages are the
 * "contract" with each partner: if a partner changes its format, the matching test here fails first.
 */
class BookingAdapterTest extends TestCase
{
    use BookingFixtures;

    public function test_demostay_becomes_rooms_with_one_bed_per_guest_and_one_stay_item(): void
    {
        $data = (new DemoStayProvider)->parse($this->stay());

        $this->assertSame('stay', $data->provider);
        $this->assertSame('STAY-ABC123', $data->reference);
        $this->assertSame(1, $data->version);
        $this->assertFalse($data->cancelled);
        $this->assertSame('Hotel Sunrise', $data->title);
        $this->assertCount(3, $data->rooms);
        $this->assertSame(['name' => 'Room 1 (Sea view)', 'beds' => ['Bed 1', 'Bed 2']], $data->rooms[0]);
        $this->assertSame('Room 3 (Sea view)', $data->rooms[2]['name']);
        $this->assertCount(1, $data->items);
        $this->assertSame([
            'kind' => 'stay', 'title' => 'Hotel Sunrise',
            'starts_on' => '2026-12-12', 'starts_time' => '14:00', 'ends_on' => '2026-12-15', 'ends_time' => '11:00',
            'place' => 'Hotel Sunrise', 'address' => 'Candolim, Goa', 'notes' => 'DemoStay booking STAY-ABC123',
        ], $data->items[0]);
        $this->assertSame('Hotel Sunrise, 12 Dec to 15 Dec · 3 rooms, 6 beds', $data->summary());
    }

    public function test_demostay_cancelled_state_sets_the_cancelled_flag(): void
    {
        $this->assertTrue((new DemoStayProvider)->parse($this->stay(['state' => 'CANCELLED', 'version' => 2]))->cancelled);
    }

    public function test_demofly_becomes_one_flight_item_per_segment_with_local_clock_times(): void
    {
        $data = (new DemoFlyProvider)->parse($this->fly());

        $this->assertSame('fly', $data->provider);
        $this->assertSame('FLY-XYZ789', $data->reference);
        $this->assertSame([], $data->rooms);
        $this->assertCount(2, $data->items);
        $this->assertSame('DF 204: Goa to Bengaluru', $data->items[0]['title']);
        $this->assertSame('flight', $data->items[0]['kind']);
        $this->assertSame('2026-12-15', $data->items[0]['starts_on']);
        $this->assertSame('18:30', $data->items[0]['starts_time']);   // the +05:30 is dropped: this is the clock at the airport
        $this->assertNull($data->items[0]['ends_on']);                // lands the same day
        $this->assertSame('19:55', $data->items[0]['ends_time']);
        $this->assertSame('Goa airport (GOI)', $data->items[0]['place']);
        $this->assertSame('DemoFly booking FLY-XYZ789, 3 passengers', $data->items[0]['notes']);
        $this->assertSame('DF 204: Goa to Bengaluru, 15 Dec to 18 Dec · 2 parts', $data->summary());
    }

    public function test_a_flight_that_lands_the_next_day_gets_an_end_date_and_other_offsets_keep_their_clock(): void
    {
        $data = (new DemoFlyProvider)->parse($this->fly(['segments' => [
            ['flight' => 'DF 9', 'from' => 'DEL', 'to' => 'BOM', 'departs_at' => '2026-12-15T23:30:00-05:00', 'arrives_at' => '2026-12-16T01:00:00-05:00'],
        ]]));

        $this->assertSame('23:30', $data->items[0]['starts_time']);
        $this->assertSame('2026-12-16', $data->items[0]['ends_on']);
        $this->assertSame('01:00', $data->items[0]['ends_time']);
    }

    public function test_a_void_ticket_is_cancelled(): void
    {
        $this->assertTrue((new DemoFlyProvider)->parse($this->fly(['status' => 'VOID', 'rev' => 2]))->cancelled);
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>}> */
    public static function badMessages(): array
    {
        $stay = fn (array $o) => $o;

        return [
            'stay: no reference' => ['stay', ['booking_ref' => '']],
            'stay: reference with bad characters' => ['stay', ['booking_ref' => 'bad ref!']],
            'stay: unknown state' => ['stay', ['state' => 'PENDING']],
            'stay: no hotel' => ['stay', ['hotel' => 'Sunrise']],
            'stay: check-out before check-in' => ['stay', ['check_in' => '2026-12-15', 'check_out' => '2026-12-12']],
            'stay: impossible date' => ['stay', ['check_in' => '2026-02-30']],
            'stay: date in words' => ['stay', ['check_in' => 'tomorrow']],
            'stay: a room with no guests' => ['stay', ['rooms' => [['type' => 'Standard', 'guests' => 0]]]],
            'stay: a room with too many guests' => ['stay', ['rooms' => [['type' => 'Standard', 'guests' => 11]]]],
            'stay: far too many rooms' => ['stay', ['rooms' => array_fill(0, 11, ['type' => 'Standard', 'guests' => 1])]],
            'stay: version zero' => ['stay', ['version' => 0]],
            'stay: bad clock time is ignored not trusted' => ['stay', ['check_in_from' => '25:99'], false],
            'fly: no pnr' => ['fly', ['pnr' => '']],
            'fly: unknown status' => ['fly', ['status' => 'MAYBE']],
            'fly: zero passengers' => ['fly', ['pax' => 0]],
            'fly: no flights' => ['fly', ['segments' => []]],
            'fly: time in words' => ['fly', ['segments' => [['flight' => 'X', 'from' => 'GOI', 'to' => 'BLR', 'departs_at' => 'tomorrow', 'arrives_at' => '2026-12-15T19:55:00+05:30']]]],
            'fly: relative time' => ['fly', ['segments' => [['flight' => 'X', 'from' => 'GOI', 'to' => 'BLR', 'departs_at' => '2026-12-15T18:30:00+05:30', 'arrives_at' => '+1 week']]]],
            'fly: lowercase airport code' => ['fly', ['segments' => [['flight' => 'X', 'from' => 'goa', 'to' => 'BLR', 'departs_at' => '2026-12-15T18:30:00+05:30', 'arrives_at' => '2026-12-15T19:55:00+05:30']]]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('badMessages')]
    public function test_unreadable_messages_are_refused_with_a_clear_exception(string $provider, array $override, bool $mustThrow = true): void
    {
        $payload = $provider === 'stay' ? $this->stay($override) : $this->fly($override);

        if (! $mustThrow) {
            // A bad optional clock time is treated as "no time" (an all-day item), never as a crash.
            $this->assertNull(Providers::find($provider)->parse($payload)->items[0]['starts_time']);

            return;
        }

        $this->expectException(InvalidBookingPayload::class);
        Providers::find($provider)->parse($payload);
    }

    public function test_text_from_a_partner_is_cleaned_and_cut_to_length(): void
    {
        $data = (new DemoStayProvider)->parse($this->stay([
            'hotel' => ['name' => "Evil\x00Hotel\nName ".str_repeat('x', 300), 'address' => str_repeat('a', 500)],
        ]));

        $this->assertStringNotContainsString("\x00", $data->title);
        $this->assertStringNotContainsString("\n", $data->title);
        $this->assertSame(120, mb_strlen($data->title));
        $this->assertSame(200, mb_strlen($data->items[0]['address']));
    }

    public function test_the_standard_format_survives_being_stored_and_read_back(): void
    {
        $data = (new DemoStayProvider)->parse($this->stay());

        $this->assertEquals($data, BookingData::fromArray(json_decode(json_encode($data->toArray()), true)));
    }

    public function test_the_registry_knows_the_two_sites_and_nothing_else(): void
    {
        $this->assertSame(['stay', 'fly'], array_keys(Providers::all()));
        $this->assertSame('DemoStay', Providers::find('stay')->label());
        $this->assertSame('DemoFly', Providers::find('fly')->label());
        $this->assertNull(Providers::find('hotelz'));
        $this->assertNull(Providers::find('../etc'));
    }

    // ---- the signing rules ---------------------------------------------------------------------------

    public function test_a_fresh_correctly_signed_message_verifies_and_anything_else_does_not(): void
    {
        $body = '{"hello":"world"}';
        $now = time();
        $good = Providers::sign('stay', $now, $body);

        $this->assertTrue(Providers::verify('stay', (string) $now, $body, $good));

        $this->assertFalse(Providers::verify('stay', (string) $now, $body.' ', $good), 'changed body');
        $this->assertFalse(Providers::verify('stay', (string) ($now + 1), $body, $good), 'changed timestamp');
        $this->assertFalse(Providers::verify('fly', (string) $now, $body, $good), 'another partner\'s secret');
        $this->assertFalse(Providers::verify('stay', (string) $now, $body, 'sha256=deadbeef'), 'wrong signature');
        $this->assertFalse(Providers::verify('stay', (string) $now, $body, substr($good, 7)), 'missing sha256= prefix');
        $this->assertFalse(Providers::verify('stay', (string) $now, $body, null), 'no signature');
        $this->assertFalse(Providers::verify('stay', null, $body, $good), 'no timestamp');
        $this->assertFalse(Providers::verify('stay', 'abc', $body, $good), 'timestamp that is not a number');

        $old = $now - 301;
        $this->assertFalse(Providers::verify('stay', (string) $old, $body, Providers::sign('stay', $old, $body)), 'too old: a replay');
        $future = $now + 301;
        $this->assertFalse(Providers::verify('stay', (string) $future, $body, Providers::sign('stay', $future, $body)), 'too far in the future');
    }

    public function test_a_configured_secret_replaces_the_derived_one_and_an_empty_key_means_not_configured(): void
    {
        $derived = Providers::secret('stay');
        $this->assertSame(64, strlen($derived));
        $this->assertNotSame($derived, Providers::secret('fly'));

        config(['lobby.partners.stay.secret' => 'a-real-partner-secret']);
        $this->assertSame('a-real-partner-secret', Providers::secret('stay'));

        config(['lobby.partners.stay.secret' => null, 'app.key' => '']);
        $this->assertSame('', Providers::secret('stay'));
        $this->assertFalse(Providers::verify('stay', (string) time(), 'x', 'sha256=anything'));
    }
}
