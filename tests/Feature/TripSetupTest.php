<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Member;
use App\Models\Room;
use App\Models\Trip;
use App\Services\BookingService;
use App\Services\TripService;
use App\Support\MemberToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * How a trip can be set up: simple room rows, "rooms and dates will come from a booking", and a trip created
 * straight from a booking on a booking site.
 */
class TripSetupTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    // ---- helpers -------------------------------------------------------------------------------

    private function createTrip(array $data)
    {
        return $this->post('/trips', $data + ['trip_name' => 'Goa weekend', 'organiser_name' => 'Asha']);
    }

    /** A trip made the "they will come from a booking" way, plus its organiser. */
    private function waitingTrip(): array
    {
        $created = app(TripService::class)->create('Waiting trip', 'Olga', now()->toDateString(), now()->addDays(60)->toDateString(), [], awaitingBooking: true);

        return [$created->trip, $created->organiser, $created->token];
    }

    // ---- the room rows --------------------------------------------------------------------------

    public function test_rooms_made_from_rows_get_default_names_and_numbered_beds(): void
    {
        $response = $this->createTrip([
            'starts_on' => '2026-12-12', 'ends_on' => '2026-12-15',
            'rooms' => [['name' => '', 'beds' => '2'], ['name' => '  Attic  ', 'beds' => '3'], ['name' => '', 'beds' => '1']],
        ]);

        $trip = Trip::firstOrFail();
        $response->assertRedirect(route('lobby', $trip->join_code));
        $this->assertNotNull($response->getCookie(MemberToken::cookieName($trip->join_code)));
        $this->assertFalse($trip->awaiting_booking);
        $this->assertSame([
            'Room 1: Bed 1', 'Room 1: Bed 2',
            'Attic: Bed 1', 'Attic: Bed 2', 'Attic: Bed 3',
            'Room 3: Bed 1',
        ], $this->bedLabels($trip));
        $this->assertSame(3, AuditLog::where('action', 'trip_created')->firstOrFail()->details['rooms']);
    }

    public function test_rows_with_gaps_in_their_numbers_still_work(): void
    {
        $this->createTrip(['starts_on' => '2026-12-12', 'ends_on' => '2026-12-15', 'rooms' => [0 => ['name' => 'A', 'beds' => '1'], 7 => ['name' => 'B', 'beds' => '2']]]);

        $this->assertSame(['A: Bed 1', 'B: Bed 1', 'B: Bed 2'], $this->bedLabels(Trip::firstOrFail()));
    }

    public function test_bad_room_rows_are_refused_with_one_clear_message_and_nothing_is_created(): void
    {
        $base = ['starts_on' => '2026-12-12', 'ends_on' => '2026-12-15'];

        foreach ([[['name' => 'A', 'beds' => '0']], [['name' => 'A', 'beds' => '11']], [['name' => 'A', 'beds' => 'many']], [['name' => 'A']]] as $rooms) {
            $this->createTrip($base + ['rooms' => $rooms])->assertSessionHasErrors(['rooms' => 'Each room needs between 1 and 10 beds.']);
        }
        $this->createTrip($base + ['rooms' => []])->assertSessionHasErrors('rooms');
        $this->createTrip($base + ['rooms' => [['name' => str_repeat('x', 61), 'beds' => '1']]])->assertSessionHasErrors('rooms');
        // 21 rooms of 10 beds is 210 beds, over the limit of 200.
        $this->createTrip($base + ['rooms' => array_fill(0, 21, ['name' => '', 'beds' => '10'])])->assertSessionHasErrors('rooms');

        $this->assertSame(0, Trip::count());
    }

    public function test_the_older_one_line_per_room_text_still_works_for_anything_that_sends_it(): void
    {
        $this->createTrip(['starts_on' => '2026-12-12', 'ends_on' => '2026-12-15', 'rooms' => "Room 1: Window, Door\nRoom 2"]);

        $this->assertSame(['Room 1: Window', 'Room 1: Door', 'Room 2: Bed 1'], $this->bedLabels(Trip::firstOrFail()));
    }

    public function test_the_create_form_no_longer_asks_people_to_type_a_format(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('bed, bed, bed')
            ->assertDontSee('Fill in an example')
            ->assertSee('Add another room')
            ->assertSee('Beds are called Bed 1, Bed 2')
            ->assertSee('I will add them myself')
            ->assertSee('They will come from a booking');
    }

    // ---- "rooms and dates will come from a booking" -------------------------------------------------------

    public function test_a_trip_set_up_from_a_booking_needs_only_two_names(): void
    {
        $response = $this->createTrip(['setup' => 'booking']);

        $trip = Trip::firstOrFail();
        $response->assertRedirect(route('lobby', $trip->join_code));
        $this->assertNotNull($response->getCookie(MemberToken::cookieName($trip->join_code)));
        $this->assertTrue($trip->awaiting_booking);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(now()->toDateString(), $trip->starts_on->toDateString());   // placeholder dates
        $this->assertSame(now()->addDays(60)->toDateString(), $trip->ends_on->toDateString());
    }

    public function test_booking_mode_ignores_dates_and_rooms_that_are_sent_anyway_and_still_needs_the_names(): void
    {
        $this->createTrip(['setup' => 'booking', 'starts_on' => '1999-01-01', 'ends_on' => 'nonsense', 'rooms' => 'Room 1: A'])->assertSessionHasNoErrors();
        $this->assertSame(0, Room::count());
        $this->assertSame(now()->toDateString(), Trip::firstOrFail()->starts_on->toDateString());

        $this->post('/trips', ['setup' => 'booking', 'trip_name' => '', 'organiser_name' => 'Asha'])->assertSessionHasErrors('trip_name');
        $this->post('/trips', ['setup' => 'sideways', 'trip_name' => 'X', 'organiser_name' => 'Asha'])->assertSessionHasErrors('setup');
        $this->assertSame(1, Trip::count());
    }

    public function test_a_waiting_trip_explains_what_to_do_and_hides_placeholder_dates(): void
    {
        [$trip, $org, $orgToken] = $this->waitingTrip();
        [, $miaToken] = $this->makeMember($trip, 'Mia');

        $this->as($trip, $orgToken)->get($this->url($trip, ''))->assertOk()
            ->assertSee('Waiting for a booking')->assertSee($trip->join_code)->assertSee('Keep this code to yourself')
            ->assertSee('The dates will come from the booking.')->assertSee('Open the demo booking sites')
            ->assertDontSee('Add to calendar');

        $this->as($trip, $miaToken)->get($this->url($trip, ''))->assertOk()
            ->assertSee('Waiting for a booking')->assertSee('The organiser has not added the booking yet')
            ->assertDontSee('Keep this code to yourself');

        $this->as($trip, $orgToken)->get($this->url($trip, 'plan'))->assertOk()
            ->assertSee('The trip dates will come from the first booking')->assertDontSee('Download calendar file');
        $this->as($trip, $orgToken)->get($this->url($trip, 'vault'))->assertOk()->assertSee('which will come from the booking');
    }

    public function test_a_waiting_trips_calendar_file_has_no_made_up_trip_event(): void
    {
        [$trip, , $orgToken] = $this->waitingTrip();

        $file = $this->as($trip, $orgToken)->get($this->url($trip, 'calendar.ics'))->assertOk()->getContent();

        $this->assertSame(0, substr_count($file, 'BEGIN:VEVENT'));
    }

    public function test_the_first_booking_sent_to_a_waiting_trip_is_added_at_once_and_gives_it_its_dates(): void
    {
        [$trip, $org] = $this->waitingTrip();

        $this->webhook('stay', $this->stay(), $trip->join_code)->assertOk()->assertExactJson(['result' => 'applied']);

        $trip->refresh();
        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::APPLIED, $booking->status);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(1, $trip->itineraryItems()->count());
        $this->assertFalse($trip->awaiting_booking);
        $this->assertSame('2026-12-12', $trip->starts_on->toDateString());
        $this->assertSame('2026-12-15', $trip->ends_on->toDateString());
        $this->assertSame($booking->id, $trip->dates_booking_id);
        $this->assertSame(['booking_received', 'booking_added'], AuditLog::whereIn('action', ['booking_received', 'booking_added'])->orderBy('id')->pluck('action')->all());
    }

    public function test_only_the_first_booking_skips_approval_and_a_cancelled_one_does_not_count(): void
    {
        [$trip] = $this->waitingTrip();

        // A cancelled booking cannot fill a trip: it waits like any other.
        $this->webhook('stay', $this->stay(['booking_ref' => 'STAY-CANCEL', 'state' => 'CANCELLED']), $trip->join_code)->assertStatus(202);
        $this->assertTrue($trip->fresh()->awaiting_booking);

        $this->webhook('stay', $this->stay(), $trip->join_code)->assertOk()->assertExactJson(['result' => 'applied']);
        $this->assertFalse($trip->fresh()->awaiting_booking);

        // From now on a new booking needs the organiser, as usual.
        $this->webhook('stay', $this->stay(['booking_ref' => 'STAY-SECOND']), $trip->join_code)->assertStatus(202)->assertExactJson(['result' => 'staged']);
        $this->assertSame(Booking::PENDING, Booking::where('reference', 'STAY-SECOND')->firstOrFail()->status);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
    }

    public function test_a_flight_can_fill_a_waiting_trip_with_dates_and_no_rooms(): void
    {
        [$trip] = $this->waitingTrip();

        $this->webhook('fly', $this->fly(), $trip->join_code)->assertOk()->assertExactJson(['result' => 'applied']);

        $trip->refresh();
        $this->assertFalse($trip->awaiting_booking);
        $this->assertSame('2026-12-15', $trip->starts_on->toDateString());   // first flight
        $this->assertSame('2026-12-18', $trip->ends_on->toDateString());     // return flight
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(2, $trip->itineraryItems()->count());
    }

    public function test_importing_by_reference_also_fills_a_waiting_trip(): void
    {
        [$trip, $org] = $this->waitingTrip();
        $this->partner('stay', $this->stay());

        $this->assertTrue(app(BookingService::class)->importByReference($trip, $org, 'stay', 'STAY-ABC123')->ok);

        $this->assertFalse($trip->fresh()->awaiting_booking);
        $this->assertSame('2026-12-12', $trip->fresh()->starts_on->toDateString());
    }

    public function test_the_trip_follows_its_booking_but_never_into_the_past(): void
    {
        [$trip] = $this->waitingTrip();
        $this->webhook('stay', $this->stay(), $trip->join_code)->assertOk();

        // A move to other future dates: the trip follows.
        $this->webhook('stay', $this->stay(['version' => 2, 'check_in' => '2026-12-14', 'check_out' => '2026-12-18']), $trip->join_code)->assertOk();
        $this->assertSame(['2026-12-14', '2026-12-18'], [$trip->fresh()->starts_on->toDateString(), $trip->fresh()->ends_on->toDateString()]);

        // A move into the past would end the trip and empty its vault, so the trip keeps its dates and says why.
        $this->webhook('stay', $this->stay(['version' => 3, 'check_in' => '2020-01-05', 'check_out' => '2020-01-08']), $trip->join_code)->assertOk();
        $this->assertSame('2026-12-18', $trip->fresh()->ends_on->toDateString());
        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::NEEDS_ATTENTION, $booking->status);
        $this->assertStringContainsString('new end date is in the past', $booking->attention);
    }

    public function test_a_trip_with_typed_in_dates_does_not_follow_a_booking(): void
    {
        $trip = $this->makeTrip();   // dates 12 to 15 Dec, set by hand
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->partner('stay', $this->stay(['check_in' => '2026-12-20', 'check_out' => '2026-12-22']));

        app(BookingService::class)->importByReference($trip, $org, 'stay', 'STAY-ABC123');

        $this->assertSame('2026-12-15', $trip->fresh()->ends_on->toDateString());
    }

    // ---- creating a trip from a booking site ---------------------------------------------------------------

    public function test_a_trip_can_be_created_from_a_hotel_booking_with_one_name(): void
    {
        $stay = $this->partner('stay', $this->stay());

        $response = $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha']);

        $trip = Trip::firstOrFail();
        $response->assertRedirect(route('lobby', $trip->join_code))->assertSessionHas('status');
        $this->assertNotNull($response->getCookie(MemberToken::cookieName($trip->join_code)), 'the person is logged in as the organiser');

        $this->assertSame('Hotel Sunrise trip', $trip->name);
        $this->assertSame(['2026-12-12', '2026-12-15'], [$trip->starts_on->toDateString(), $trip->ends_on->toDateString()]);
        $this->assertFalse($trip->awaiting_booking);
        $organiser = $trip->members()->firstOrFail();
        $this->assertSame(['Asha', Member::ROLE_ORGANISER], [$organiser->name, $organiser->role]);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(Booking::APPLIED, Booking::firstOrFail()->status);
        $this->assertSame([$trip->join_code], $stay->fresh()->sent_to);

        $created = AuditLog::where('action', 'trip_created')->firstOrFail();
        $this->assertSame('booking', $created->details['source']);
        $this->assertSame($organiser->id, $created->member_id);
        $this->assertSame(1, AuditLog::where('action', 'booking_added')->count());

        // The organiser can open the new lobby straight away, with no joining step.
        $token = $response->getCookie(MemberToken::cookieName($trip->join_code))->getValue();
        $this->as($trip, $token)->get('/t/'.$trip->join_code)->assertOk()->assertSee('Hotel Sunrise trip')->assertSee('Hotel Sunrise, Candolim, Goa');
    }

    public function test_a_trip_can_be_created_from_a_flight_booking(): void
    {
        $this->partner('fly', $this->fly());

        $this->post('/demo/fly/FLY-XYZ789/new-trip', ['organiser_name' => 'Asha'])->assertRedirect();

        $trip = Trip::firstOrFail();
        $this->assertSame('Goa to Bengaluru trip', $trip->name);
        $this->assertSame(['2026-12-15', '2026-12-18'], [$trip->starts_on->toDateString(), $trip->ends_on->toDateString()]);
        $this->assertSame(2, $trip->itineraryItems()->count());
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
    }

    public function test_a_booking_that_is_already_in_a_trip_does_not_make_a_second_trip(): void
    {
        $this->partner('stay', $this->stay());
        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha']);
        $code = Trip::firstOrFail()->join_code;

        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha'])
            ->assertRedirect()->assertSessionHas('problem');

        $this->assertSame(1, Trip::count());
        $this->assertSame(1, Booking::count());
        $this->get('/demo/stay/STAY-ABC123')->assertOk()
            ->assertSee('This booking is already in the trip')->assertSee($code)->assertSee('Open the trip')
            ->assertSee('Send it to another trip too')->assertDontSee('Create a new trip');
    }

    public function test_a_new_trip_is_allowed_again_when_the_old_one_is_finished_or_dropped_the_booking(): void
    {
        $this->partner('stay', $this->stay());
        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha']);
        $first = Trip::firstOrFail();

        // The organiser removed the booking from that trip: the booking site may start another one.
        $organiser = $first->members()->firstOrFail();
        app(BookingService::class)->remove($first, $organiser, Booking::firstOrFail()->id);
        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha'])->assertSessionMissing('problem');
        $this->assertSame(2, Trip::count());

        // And the same when the trip has ended.
        Trip::where('id', '!=', $first->id)->firstOrFail()->forceFill(['ended_at' => now()])->save();
        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha'])->assertSessionMissing('problem');
        $this->assertSame(3, Trip::count());
    }

    public function test_a_cancelled_booking_cannot_start_a_trip_and_the_page_does_not_offer_it(): void
    {
        $this->partner('stay', $this->stay(['state' => 'CANCELLED', 'version' => 2]));

        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha'])->assertSessionHas('problem', 'A cancelled booking cannot start a trip.');

        $this->assertSame(0, Trip::count());
        $this->get('/demo/stay/STAY-ABC123')->assertOk()->assertDontSee('Create a new trip');
    }

    public function test_starting_a_trip_from_a_booking_needs_a_name_and_a_real_booking(): void
    {
        $this->partner('stay', $this->stay());

        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => ''])->assertSessionHasErrors('organiser_name');
        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => str_repeat('n', 61)])->assertSessionHasErrors('organiser_name');
        $this->post('/demo/stay/STAY-NOPE00/new-trip', ['organiser_name' => 'Asha'])->assertNotFound();
        $this->post('/demo/fly/STAY-ABC123/new-trip', ['organiser_name' => 'Asha'])->assertNotFound();   // a hotel booking is not a flight

        $this->assertSame(0, Trip::count());
    }

    public function test_the_demo_cannot_start_trips_when_it_is_switched_off(): void
    {
        $this->partner('stay', $this->stay());
        config(['lobby.demo_partners' => false]);

        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha'])->assertNotFound();

        $this->assertSame(0, Trip::count());
    }

    public function test_changes_on_the_booking_site_follow_into_a_trip_it_created(): void
    {
        $this->partner('stay', $this->stay());
        $this->post('/demo/stay/STAY-ABC123/new-trip', ['organiser_name' => 'Asha']);
        $trip = Trip::firstOrFail();

        $this->post('/demo/stay/STAY-ABC123/change', [
            'hotel' => 'aurora', 'room_type' => 'Garden view', 'check_in' => '2026-12-13', 'check_out' => '2026-12-17', 'rooms' => 4, 'guests' => 2,
        ])->assertSessionHas('status');

        $trip->refresh();
        $this->assertSame(['2026-12-13', '2026-12-17'], [$trip->starts_on->toDateString(), $trip->ends_on->toDateString()]);
        $this->assertSame(4, Room::where('trip_id', $trip->id)->count());

        $this->post('/demo/stay/STAY-ABC123/cancel');
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(Booking::CANCELLED, Booking::firstOrFail()->status);
        $this->assertSame('2026-12-17', $trip->fresh()->ends_on->toDateString(), 'cancelling does not rewrite the trip dates');
    }

    public function test_sending_a_booking_to_a_waiting_trip_from_the_demo_site_fills_it_in(): void
    {
        [$trip, , $orgToken] = $this->waitingTrip();
        $this->partner('stay', $this->stay());

        $this->post('/demo/stay/STAY-ABC123/send', ['trip_code' => $trip->join_code])
            ->assertRedirect()->assertSessionHas('status', 'Sent. The trip was updated.');

        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $this->assertFalse($trip->fresh()->awaiting_booking);
        $this->as($trip, $orgToken)->get($this->url($trip, ''))->assertOk()->assertDontSee('Waiting for a booking')->assertSee('Hotel Sunrise, Candolim, Goa');
    }

    // ---- links to the demo sites ---------------------------------------------------------------------------

    public function test_the_home_page_menu_and_footer_link_to_the_demo_sites_and_vanish_when_they_are_off(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Demo booking sites')->assertSee('Open the demo booking sites')
            ->assertSee(route('demo.index'), false);

        config(['lobby.demo_partners' => false]);

        $this->get('/')->assertOk()->assertDontSee('Demo booking sites')->assertDontSee(route('demo.index'), false);
    }

    public function test_every_demo_page_can_get_you_back_to_the_lobby(): void
    {
        $this->get('/demo')->assertSee('Create a new trip')->assertSee('they will come from a booking', false);
    }
}
