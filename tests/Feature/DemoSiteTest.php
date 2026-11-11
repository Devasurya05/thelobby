<?php

namespace Tests\Feature;

use App\Integrations\PartnerStore;
use App\Integrations\Providers;
use App\Models\Booking;
use App\Models\Member;
use App\Models\PartnerBooking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * The two pretend booking sites (DemoStay, DemoFly): booking, sending to a trip, and changes following by themselves.
 */
class DemoSiteTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private function bookStay(array $over = []): PartnerBooking
    {
        $this->post('/demo/stay', $over + [
            'hotel' => 'sunrise', 'room_type' => 'Sea view', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'rooms' => 3, 'guests' => 2,
        ])->assertRedirect();

        return PartnerBooking::where('provider', 'stay')->latest('id')->firstOrFail();
    }

    private function bookFly(array $over = []): PartnerBooking
    {
        $this->post('/demo/fly', $over + [
            'from' => 'GOI', 'to' => 'BLR', 'date' => '2026-12-15', 'time' => '18:30', 'has_return' => 1, 'return_date' => '2026-12-18', 'return_time' => '09:15', 'pax' => 3,
        ])->assertRedirect();

        return PartnerBooking::where('provider', 'fly')->latest('id')->firstOrFail();
    }

    // ---- the pages --------------------------------------------------------------------------------------

    public function test_the_demo_pages_load_and_say_plainly_that_they_are_pretend(): void
    {
        foreach (['/demo', '/demo/stay', '/demo/fly'] as $path) {
            $this->get($path)->assertOk()->assertSee('Demo site, not a real booking site')->assertSee('noindex', false);
        }
        $this->get('/demo/stay')->assertSee('Hotel Sunrise')->assertSee('Check in');
        $this->get('/demo/fly')->assertSee('Goa (GOI)')->assertSee('Add a return flight');
    }

    public function test_the_whole_demo_is_off_when_the_switch_is_off(): void
    {
        config(['lobby.demo_partners' => false]);

        $this->get('/demo')->assertNotFound();
        $this->get('/demo/stay')->assertNotFound();
        $this->post('/demo/stay', [])->assertNotFound();
        $this->get('/demo/fly')->assertNotFound();
        $this->assertSame(0, PartnerBooking::count());
    }

    // ---- booking ---------------------------------------------------------------------------------------

    public function test_booking_a_hotel_stores_it_in_the_hotels_own_format(): void
    {
        $booking = $this->bookStay();

        $this->assertMatchesRegularExpression('/^STAY-[A-Z2-9]{6}$/', $booking->reference);
        $p = $booking->payload;
        $this->assertSame($booking->reference, $p['booking_ref']);   // DemoStay's field names, not our standard ones
        $this->assertSame(1, $p['version']);
        $this->assertSame('CONFIRMED', $p['state']);
        $this->assertSame(['name' => 'Hotel Sunrise', 'address' => 'Candolim, Goa'], $p['hotel']);
        $this->assertSame('2026-12-12', $p['check_in']);
        $this->assertCount(3, $p['rooms']);
        $this->assertSame(['type' => 'Sea view', 'guests' => 2], $p['rooms'][0]);

        $this->get('/demo/stay/'.$booking->reference)->assertOk()->assertSee('Booking confirmed')->assertSee($booking->reference);
    }

    public function test_booking_a_flight_stores_it_in_the_airlines_own_format(): void
    {
        $booking = $this->bookFly();

        $this->assertMatchesRegularExpression('/^FLY-[A-Z2-9]{6}$/', $booking->reference);
        $p = $booking->payload;
        $this->assertSame($booking->reference, $p['pnr']);
        $this->assertSame(1, $p['rev']);
        $this->assertSame('TICKETED', $p['status']);
        $this->assertSame(3, $p['pax']);
        $this->assertCount(2, $p['segments']);
        $out = $p['segments'][0];
        $this->assertSame('2026-12-15T18:30:00+05:30', $out['departs_at']);
        $this->assertSame('2026-12-15T19:55:00+05:30', $out['arrives_at']);   // 85 minutes later
        $this->assertSame(['BLR', 'GOI'], [$p['segments'][1]['from'], $p['segments'][1]['to']]);

        $this->get('/demo/fly/'.$booking->reference)->assertOk()->assertSee('Booking confirmed')->assertSee('Bengaluru');
    }

    public function test_a_one_way_flight_has_one_segment(): void
    {
        $booking = $this->bookFly(['has_return' => null, 'return_date' => null, 'return_time' => null]);

        $this->assertCount(1, $booking->payload['segments']);
    }

    public function test_bad_hotel_forms_are_refused_with_messages(): void
    {
        $stay = ['hotel' => 'sunrise', 'room_type' => 'Sea view', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'rooms' => 3, 'guests' => 2];
        $this->post('/demo/stay', ['check_out' => '2026-12-12'] + $stay)->assertSessionHasErrors('check_out');
        $this->post('/demo/stay', ['rooms' => 9] + $stay)->assertSessionHasErrors('rooms');
        $this->post('/demo/stay', ['guests' => 0] + $stay)->assertSessionHasErrors('guests');
        $this->post('/demo/stay', ['hotel' => 'palace'] + $stay)->assertSessionHasErrors('hotel');
        $this->post('/demo/stay', ['check_in' => 'tomorrow'] + $stay)->assertSessionHasErrors('check_in');

        $this->assertSame(0, PartnerBooking::count());
    }

    // (Split from the hotel test above: all "book" forms share one limit of 10 a minute per visitor.)
    public function test_bad_flight_forms_are_refused_with_messages(): void
    {
        $fly = ['from' => 'GOI', 'to' => 'BLR', 'date' => '2026-12-15', 'time' => '18:30', 'pax' => 3];
        $this->post('/demo/fly', ['to' => 'GOI'] + $fly)->assertSessionHasErrors('to');
        $this->post('/demo/fly', ['from' => 'XXX'] + $fly)->assertSessionHasErrors('from');
        $this->post('/demo/fly', ['time' => '03:33'] + $fly)->assertSessionHasErrors('time');
        $this->post('/demo/fly', ['pax' => 20] + $fly)->assertSessionHasErrors('pax');
        $this->post('/demo/fly', ['has_return' => 1] + $fly)->assertSessionHasErrors(['return_date', 'return_time']);
        $this->post('/demo/fly', ['has_return' => 1, 'return_date' => '2026-12-01', 'return_time' => '09:15'] + $fly)->assertSessionHasErrors('return_date');

        $this->assertSame(0, PartnerBooking::count());
    }

    public function test_an_unknown_reference_or_another_sites_reference_is_a_404(): void
    {
        $stay = $this->bookStay();

        $this->get('/demo/stay/STAY-ZZZZZZ')->assertNotFound();
        $this->get('/demo/fly/'.$stay->reference)->assertNotFound();    // a hotel booking is not a flight
        $this->get('/demo/stay/not-a-reference')->assertNotFound();
        $this->post('/demo/stay/STAY-ZZZZZZ/cancel')->assertNotFound();
    }

    public function test_the_demo_stops_taking_bookings_when_it_is_full(): void
    {
        $this->mock(PartnerStore::class, fn ($mock) => $mock->shouldReceive('isFull')->andReturn(true));

        $this->post('/demo/stay', ['hotel' => 'sunrise', 'room_type' => 'Sea view', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'rooms' => 3, 'guests' => 2])
            ->assertRedirect()->assertSessionHas('problem');
        $this->assertSame(0, PartnerBooking::count());
    }

    // ---- sending to a trip --------------------------------------------------------------------------------

    public function test_sending_a_booking_to_a_trip_stages_it_and_remembers_the_trip(): void
    {
        $trip = $this->makeTrip();
        $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $booking = $this->bookStay();

        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => strtolower($trip->join_code)])
            ->assertRedirect()->assertSessionHas('status');

        $staged = Booking::firstOrFail();
        $this->assertSame(Booking::PENDING, $staged->status);
        $this->assertSame($booking->reference, $staged->reference);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());     // nothing added until the organiser says so
        $this->assertSame([$trip->join_code], $booking->fresh()->sent_to);

        $this->get('/demo/stay/'.$booking->reference)->assertSee('Already sent to')->assertSee($trip->join_code);
    }

    public function test_sending_to_a_trip_code_that_does_not_exist_says_so_and_remembers_nothing(): void
    {
        $booking = $this->bookStay();

        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => 'ZZZZZZZZ'])
            ->assertRedirect()->assertSessionHas('problem', 'No trip has that code. Check it and try again.');
        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => 'abc'])->assertSessionHasErrors('trip_code');

        $this->assertNull($booking->fresh()->sent_to);
        $this->assertSame(0, Booking::count());
    }

    public function test_sending_the_same_version_twice_is_harmless(): void
    {
        $trip = $this->makeTrip();
        $booking = $this->bookStay();

        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => $trip->join_code])->assertSessionHas('status');
        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => $trip->join_code])->assertSessionHas('status');

        $this->assertSame(1, Booking::count());
        $this->assertSame([$trip->join_code], $booking->fresh()->sent_to);   // remembered once
    }

    // ---- changes follow the booking --------------------------------------------------------------------------

    public function test_a_change_on_the_hotel_site_reaches_an_approved_trip_by_itself_and_so_does_a_cancellation(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $booking = $this->bookStay();
        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => $trip->join_code]);
        app(BookingService::class)->accept($trip, $org, Booking::firstOrFail()->id);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());

        $this->post('/demo/stay/'.$booking->reference.'/change', [
            'hotel' => 'aurora', 'room_type' => 'Garden view', 'check_in' => '2026-12-13', 'check_out' => '2026-12-16', 'rooms' => 4, 'guests' => 2,
        ])->assertRedirect()->assertSessionHas('status', 'Booking changed. The change was sent to 1 trip.');

        $this->assertSame(2, $booking->fresh()->payload['version']);
        $this->assertSame(4, Room::where('trip_id', $trip->id)->count());
        $this->assertSame('Room 1 (Garden view)', Room::where('trip_id', $trip->id)->orderBy('id')->first()->name);
        $item = $trip->itineraryItems()->firstOrFail();
        $this->assertSame('Beach Villa Aurora', $item->place);
        $this->assertSame('2026-12-13', $item->starts_on->format('Y-m-d'));
        $this->assertSame(2, Booking::firstOrFail()->version);

        $this->post('/demo/stay/'.$booking->reference.'/cancel')->assertRedirect()->assertSessionHas('status', 'Booking cancelled. The cancellation was sent to 1 trip.');

        $this->assertSame(3, $booking->fresh()->payload['version']);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(Booking::CANCELLED, Booking::firstOrFail()->status);
    }

    public function test_a_change_made_before_approval_updates_the_waiting_copy_and_still_adds_nothing(): void
    {
        $trip = $this->makeTrip();
        $booking = $this->bookStay();
        $this->post('/demo/stay/'.$booking->reference.'/send', ['trip_code' => $trip->join_code]);

        $this->post('/demo/stay/'.$booking->reference.'/change', [
            'hotel' => 'sunrise', 'room_type' => 'Standard', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'rooms' => 5, 'guests' => 1,
        ]);

        $staged = Booking::firstOrFail();
        $this->assertSame(Booking::PENDING, $staged->status);
        $this->assertSame(2, $staged->version);
        $this->assertCount(5, $staged->data['rooms']);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
    }

    public function test_a_flight_change_and_cancellation_follow_too(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $booking = $this->bookFly();
        $this->post('/demo/fly/'.$booking->reference.'/send', ['trip_code' => $trip->join_code])->assertSessionHas('status');
        app(BookingService::class)->accept($trip, $org, Booking::firstOrFail()->id);
        $this->assertSame(2, $trip->itineraryItems()->count());

        $this->post('/demo/fly/'.$booking->reference.'/change', [
            'from' => 'GOI', 'to' => 'BLR', 'date' => '2026-12-16', 'time' => '21:10', 'has_return' => null, 'pax' => 2,
        ])->assertSessionHas('status');

        $this->assertSame(1, $trip->itineraryItems()->count());
        $item = $trip->itineraryItems()->firstOrFail();
        $this->assertSame('2026-12-16', $item->starts_on->format('Y-m-d'));
        $this->assertSame('21:10', substr($item->starts_time, 0, 5));
        $this->assertStringContainsString('2 passengers', $item->notes);
        $this->assertSame('DF '.explode(' ', $booking->payload['segments'][0]['flight'])[1], explode(':', $item->title)[0], 'a change keeps the flight number');

        $this->post('/demo/fly/'.$booking->reference.'/cancel')->assertSessionHas('status');
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(Booking::CANCELLED, Booking::firstOrFail()->status);
    }

    public function test_a_cancelled_booking_cannot_be_changed_and_cancelling_twice_does_nothing(): void
    {
        $stay = $this->bookStay();
        $this->post('/demo/stay/'.$stay->reference.'/cancel');
        $versionAfterCancel = $stay->fresh()->payload['version'];

        $this->post('/demo/stay/'.$stay->reference.'/change', ['hotel' => 'sunrise', 'room_type' => 'Sea view', 'check_in' => '2026-12-12', 'check_out' => '2026-12-15', 'rooms' => 2, 'guests' => 2])
            ->assertSessionHas('problem');
        $this->post('/demo/stay/'.$stay->reference.'/cancel');

        $this->assertSame($versionAfterCancel, $stay->fresh()->payload['version']);
        $this->assertSame('CANCELLED', $stay->fresh()->payload['state']);
        $this->get('/demo/stay/'.$stay->reference)->assertOk()->assertSee('Booking cancelled')->assertDontSee('Change this booking');

        $fly = $this->bookFly();
        $this->post('/demo/fly/'.$fly->reference.'/cancel');
        $this->post('/demo/fly/'.$fly->reference.'/change', ['from' => 'GOI', 'to' => 'BLR', 'date' => '2026-12-15', 'time' => '18:30', 'pax' => 3])->assertSessionHas('problem');
        $this->assertSame('VOID', $fly->fresh()->payload['status']);
    }

    // ---- the command that sends a real HTTP request --------------------------------------------------------------

    public function test_demo_webhook_command_sends_a_correctly_signed_http_request(): void
    {
        Http::fake(['*' => Http::response(['result' => 'staged'], 202)]);
        $trip = $this->makeTrip();
        $booking = $this->bookStay();

        $this->artisan('demo:webhook', ['trip_code' => $trip->join_code, 'reference' => $booking->reference, '--url' => 'https://lobby.example'])
            ->expectsOutputToContain('HTTP 202')->assertSuccessful();

        Http::assertSent(function ($request) use ($trip, $booking) {
            $envelope = json_decode($request->body(), true);

            return $request->url() === 'https://lobby.example/webhooks/stay'
                && $envelope['trip_code'] === $trip->join_code
                && $envelope['booking']['booking_ref'] === $booking->reference
                && Providers::verify('stay', $request->header('X-Lobby-Timestamp')[0], $request->body(), $request->header('X-Lobby-Signature')[0]);
        });
    }

    public function test_demo_webhook_command_refuses_an_unknown_reference_and_a_switched_off_demo(): void
    {
        Http::fake();
        $this->artisan('demo:webhook', ['trip_code' => 'ABCDEFGH', 'reference' => 'STAY-NOPE00'])->assertFailed();

        config(['lobby.demo_partners' => false]);
        $this->artisan('demo:webhook', ['trip_code' => 'ABCDEFGH', 'reference' => 'STAY-NOPE00'])->assertFailed();

        Http::assertNothingSent();
    }
}
