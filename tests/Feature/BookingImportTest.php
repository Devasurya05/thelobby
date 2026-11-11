<?php

namespace Tests\Feature;

use App\Integrations\DemoStayProvider;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Booking;
use App\Models\Member;
use App\Models\PartnerBooking;
use App\Models\Room;
use App\Models\Trip;
use App\Services\ActionResult;
use App\Services\BedService;
use App\Services\BookingService;
use App\Services\ItineraryService;
use App\Services\LobbyState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * Importing a booking, approving a waiting one, and what happens to the trip when the booking changes or is cancelled.
 * The most important rule here: a change from a booking site never takes away a bed somebody holds or has confirmed.
 */
class BookingImportTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    private BookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(BookingService::class);
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function organiser(Trip $trip): Member
    {
        return $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER)[0];
    }

    /** Make the pretend site hold this version of the booking, then import it by reference. */
    private function importStay(Trip $trip, Member $actor, array $over = []): ActionResult
    {
        $payload = $this->stay($over);
        PartnerBooking::updateOrCreate(['reference' => $payload['booking_ref']], ['provider' => 'stay', 'payload' => $payload]);

        return $this->service->importByReference($trip, $actor, 'stay', $payload['booking_ref']);
    }

    /** Put a message from the booking site into the trip as a waiting (pending) booking. */
    private function stage(Trip $trip, array $over = []): Booking
    {
        $this->assertSame('staged', $this->service->receive($trip, (new DemoStayProvider)->parse($this->stay($over))));

        return Booking::where('trip_id', $trip->id)->where('reference', $this->stay($over)['booking_ref'])->firstOrFail();
    }

    /** The nth bed of the trip in creation order (0 is the first bed of the first room). */
    private function bed(Trip $trip, int $n): Bed
    {
        return Bed::where('trip_id', $trip->id)->orderBy('id')->get()[$n];
    }

    private function takeBed(Trip $trip, Member $member, Bed $bed): void
    {
        $beds = app(BedService::class);
        $this->assertTrue($beds->grab($trip, $member, $bed->id)->ok);
        $this->assertTrue($beds->confirm($trip, $member, $bed->id)->ok);
    }

    private function actions(Trip $trip, string $action): int
    {
        return AuditLog::where('trip_id', $trip->id)->where('action', $action)->count();
    }

    // ---- importing by reference -----------------------------------------------------------------

    public function test_importing_a_hotel_booking_creates_rooms_beds_a_plan_item_and_an_audit_row(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);

        $result = $this->importStay($trip, $org);

        $this->assertTrue($result->ok);
        $this->assertSame('Booking added to the trip.', $result->message);
        $this->assertSame([
            'Room 1 (Sea view): Bed 1', 'Room 1 (Sea view): Bed 2',
            'Room 2 (Sea view): Bed 1', 'Room 2 (Sea view): Bed 2',
            'Room 3 (Sea view): Bed 1', 'Room 3 (Sea view): Bed 2',
        ], $this->bedLabels($trip));

        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::APPLIED, $booking->status);
        $this->assertSame(1, $booking->version);
        $this->assertNull($booking->attention);
        $this->assertSame(3, $booking->rooms()->count());

        $item = $trip->itineraryItems()->firstOrFail();
        $this->assertSame($booking->id, $item->booking_id);
        $this->assertSame('stay', $item->kind);
        $this->assertSame('Hotel Sunrise', $item->place);
        $this->assertSame('2026-12-12', $item->starts_on->format('Y-m-d'));
        $this->assertNull($item->created_by_member_id);

        $log = AuditLog::where('action', 'booking_added')->firstOrFail();
        $this->assertSame($org->id, $log->member_id);
        $this->assertSame('STAY-ABC123', $log->details['reference']);

        // The lobby screen sees the new rooms straight away.
        $this->assertCount(3, LobbyState::build($trip, $org)['rooms']);
    }

    public function test_importing_a_flight_booking_adds_plan_items_and_no_rooms(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $this->partner('fly', $this->fly());

        $this->assertTrue($this->service->importByReference($trip, $org, 'fly', 'fly-xyz789')->ok); // case does not matter

        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(['DF 204: Goa to Bengaluru', 'DF 205: Bengaluru to Goa'], $trip->itineraryItems()->orderBy('starts_on')->pluck('title')->all());
    }

    public function test_importing_the_same_version_twice_changes_nothing(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);

        $this->importStay($trip, $org);
        $again = $this->importStay($trip, $org);

        $this->assertTrue($again->ok);
        $this->assertStringContainsString('already in the trip', $again->message);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(6, Bed::where('trip_id', $trip->id)->count());
        $this->assertSame(1, $trip->itineraryItems()->count());
        $this->assertSame(1, Booking::count());
        $this->assertSame(1, $this->actions($trip, 'booking_added'));
    }

    public function test_import_is_refused_for_everyone_but_the_organiser_and_is_logged(): void
    {
        $trip = $this->makeTrip();
        [$mia] = $this->makeMember($trip, 'Mia');
        [$vik] = $this->makeMember($trip, 'Vik', Member::ROLE_VIEWER);
        [$gone] = $this->makeMember($trip, 'Gone', Member::ROLE_ORGANISER);
        $gone->forceFill(['removed_at' => now()])->save();

        foreach ([$mia, $vik, $gone->fresh()] as $person) {
            $result = $this->importStay($trip, $person);
            $this->assertFalse($result->ok);
            $this->assertSame(403, $result->status);
        }

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(3, $this->actions($trip, 'action_refused'));
    }

    public function test_import_failures_say_what_went_wrong(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);

        $unknownRef = $this->service->importByReference($trip, $org, 'stay', 'STAY-NOPE00');
        $this->assertFalse($unknownRef->ok);
        $this->assertSame(404, $unknownRef->status);
        $this->assertStringContainsString('no booking STAY-NOPE00', $unknownRef->message);

        $unknownSite = $this->service->importByReference($trip, $org, 'hotelz', 'STAY-ABC123');
        $this->assertFalse($unknownSite->ok);
        $this->assertSame(422, $unknownSite->status);

        // A booking the site holds but we cannot read.
        $this->partner('stay', $this->stay(['booking_ref' => 'STAY-BAD000', 'state' => 'WHATEVER']));
        $unreadable = $this->service->importByReference($trip, $org, 'stay', 'STAY-BAD000');
        $this->assertFalse($unreadable->ok);
        $this->assertStringContainsString('could not be read', $unreadable->message);

        $this->assertSame(0, Booking::count());
    }

    public function test_a_flight_reference_does_not_import_through_the_hotel_site(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $this->partner('fly', $this->fly());

        $this->assertFalse($this->service->importByReference($trip, $org, 'stay', 'FLY-XYZ789')->ok);
        $this->assertSame(0, Booking::count());
    }

    public function test_nothing_can_be_imported_once_the_trip_has_ended(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $trip->forceFill(['ended_at' => now()])->save();

        $this->assertFalse($this->importStay($trip->fresh(), $org)->ok);
        $this->assertSame(0, Booking::count());
    }

    // ---- changes from the booking site ---------------------------------------------------------------

    public function test_a_newer_version_updates_dates_and_adds_rooms_without_duplicating_anything(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $this->importStay($trip, $org);

        $result = $this->importStay($trip, $org, ['version' => 2, 'check_in' => '2026-12-13', 'check_out' => '2026-12-16', 'rooms' => array_fill(0, 4, ['type' => 'Sea view', 'guests' => 2])]);

        $this->assertTrue($result->ok);
        $this->assertSame('Booking updated.', $result->message);
        $this->assertSame(4, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(8, Bed::where('trip_id', $trip->id)->count());
        $this->assertSame(1, $trip->itineraryItems()->count());
        $this->assertSame('2026-12-13', $trip->itineraryItems()->first()->starts_on->format('Y-m-d'));
        $this->assertSame(2, Booking::firstOrFail()->version);
        $this->assertSame(Booking::APPLIED, Booking::firstOrFail()->status);
        $this->assertSame(1, $this->actions($trip, 'booking_changed'));
    }

    public function test_fewer_rooms_remove_the_free_ones(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $this->importStay($trip, $org);

        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 2, ['type' => 'Sea view', 'guests' => 2])]);

        $this->assertSame(2, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(4, Bed::where('trip_id', $trip->id)->count());
        $this->assertSame(Booking::APPLIED, Booking::firstOrFail()->status);
    }

    public function test_th_e_be_d_rul_e_a_room_with_a_confirmed_bed_is_never_removed(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 4));   // Mia confirms the first bed of Room 3

        $result = $this->importStay($trip, $org, ['version' => 2, 'check_in' => '2026-12-14', 'rooms' => array_fill(0, 2, ['type' => 'Sea view', 'guests' => 2])]);

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Needs your attention', $result->message);

        // Room 3 and Mia's bed are untouched.
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $bed = $this->bed($trip, 4)->fresh();
        $this->assertSame(Bed::TAKEN, $bed->status);
        $this->assertSame($mia->id, $bed->taken_by_member_id);

        // The rest of the change still went through.
        $this->assertSame('2026-12-14', $trip->itineraryItems()->first()->starts_on->format('Y-m-d'));

        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::NEEDS_ATTENTION, $booking->status);
        $this->assertSame(2, $booking->version);
        $this->assertStringContainsString('Room 3 (Sea view) has beds that are held or taken, so it was kept.', $booking->attention);
    }

    public function test_a_held_bed_also_blocks_removal_but_an_expired_hold_does_not(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->assertTrue(app(BedService::class)->grab($trip, $mia, $this->bed($trip, 4)->id)->ok);   // a hold, not confirmed

        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 2, ['type' => 'Sea view', 'guests' => 2])]);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count(), 'a live hold keeps the room');
        $this->assertSame(Booking::NEEDS_ATTENTION, Booking::firstOrFail()->status);

        $this->travel(config('lobby.hold_seconds') + 5)->seconds();   // the hold has run out

        $this->importStay($trip, $org, ['version' => 3, 'rooms' => array_fill(0, 2, ['type' => 'Sea view', 'guests' => 2])]);
        $this->assertSame(2, Room::where('trip_id', $trip->id)->count(), 'an expired hold counts as free');
        $this->assertSame(Booking::APPLIED, Booking::firstOrFail()->status);
        $this->assertNull(Booking::firstOrFail()->attention);
    }

    public function test_fewer_guests_remove_only_the_free_beds_and_keep_a_taken_one(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 1));   // Room 1, Bed 2

        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 3, ['type' => 'Sea view', 'guests' => 1])]);

        $this->assertSame(['Room 1 (Sea view): Bed 1', 'Room 1 (Sea view): Bed 2', 'Room 2 (Sea view): Bed 1', 'Room 3 (Sea view): Bed 1'], $this->bedLabels($trip));
        $this->assertSame(Bed::TAKEN, $this->bed($trip, 1)->status);
        $this->assertSame(Booking::NEEDS_ATTENTION, Booking::firstOrFail()->status);
        $this->assertStringContainsString('Room 1 (Sea view): Bed 2 is held or taken', Booking::firstOrFail()->attention);
    }

    public function test_a_change_that_adds_beds_to_a_room_with_people_in_it_is_fine(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 0));

        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 3, ['type' => 'Sea view', 'guests' => 3])]);

        $this->assertSame(9, Bed::where('trip_id', $trip->id)->count());
        $this->assertSame(Bed::TAKEN, $this->bed($trip, 0)->status);
        $this->assertSame(Booking::APPLIED, Booking::firstOrFail()->status);
    }

    public function test_a_room_type_change_renames_the_room_and_keeps_its_beds_and_people(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 0));

        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 3, ['type' => 'Garden view', 'guests' => 2])]);

        $this->assertSame('Room 1 (Garden view)', Room::where('trip_id', $trip->id)->orderBy('id')->first()->name);
        $this->assertSame($mia->id, $this->bed($trip, 0)->taken_by_member_id);
    }

    // ---- cancellation ------------------------------------------------------------------------------

    public function test_a_cancellation_removes_the_plan_items_and_the_free_rooms(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $this->importStay($trip, $org);

        $this->importStay($trip, $org, ['version' => 2, 'state' => 'CANCELLED']);

        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(Booking::CANCELLED, Booking::firstOrFail()->status);
        $this->assertSame(1, $this->actions($trip, 'booking_cancelled'));
    }

    public function test_a_cancellation_keeps_rooms_with_people_in_them_and_says_so(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 0));

        $this->importStay($trip, $org, ['version' => 2, 'state' => 'CANCELLED']);

        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(1, Room::where('trip_id', $trip->id)->count());
        $this->assertSame($mia->id, $this->bed($trip, 0)->taken_by_member_id);
        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::NEEDS_ATTENTION, $booking->status);
        $this->assertStringStartsWith('Cancelled at DemoStay.', $booking->attention);
    }

    public function test_things_typed_in_by_hand_are_never_touched_by_a_booking(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $loft = $trip->rooms()->create(['name' => 'Loft']);
        $loft->beds()->create(['trip_id' => $trip->id, 'label' => 'Sofa']);
        $trip->itineraryItems()->create(['kind' => 'meal', 'title' => 'Dinner', 'starts_on' => '2026-12-13', 'starts_time' => '20:00']);

        $this->importStay($trip, $org);
        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 1, ['type' => 'Sea view', 'guests' => 1])]);
        $this->importStay($trip, $org, ['version' => 3, 'state' => 'CANCELLED']);

        $this->assertSame(['Loft: Sofa'], $this->bedLabels($trip));
        $this->assertSame(['Dinner'], $trip->itineraryItems()->pluck('title')->all());
    }

    // ---- removing a booking ----------------------------------------------------------------------

    public function test_removing_a_booking_takes_out_its_items_and_free_rooms_and_frees_kept_rooms_from_it(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 0));
        $booking = Booking::firstOrFail();

        $result = $this->service->remove($trip, $org, $booking->id);

        $this->assertTrue($result->ok);
        $this->assertStringContainsString('Booking removed from the trip.', $result->message);
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(1, Room::where('trip_id', $trip->id)->count());          // Mia's room stays
        $this->assertNull(Room::where('trip_id', $trip->id)->first()->booking_id);  // and no longer belongs to the booking
        $this->assertSame(Booking::DISMISSED, $booking->fresh()->status);
        $this->assertSame(1, $this->actions($trip, 'booking_removed'));
    }

    public function test_a_booking_item_cannot_be_deleted_by_hand(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $this->importStay($trip, $org);

        $result = app(ItineraryService::class)->delete($trip, $org, $trip->itineraryItems()->firstOrFail()->id);

        $this->assertFalse($result->ok);
        $this->assertStringContainsString('comes from a booking', $result->message);
        $this->assertSame(1, $trip->itineraryItems()->count());
    }

    // ---- limits ----------------------------------------------------------------------------------

    public function test_a_full_plan_keeps_the_rooms_but_flags_the_dates_for_attention(): void
    {
        config(['lobby.plan.max_items' => 1]);
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $trip->itineraryItems()->create(['kind' => 'meal', 'title' => 'Dinner', 'starts_on' => '2026-12-13']);

        $result = $this->importStay($trip, $org);

        $this->assertTrue($result->ok);
        $this->assertSame(['Dinner'], $trip->itineraryItems()->pluck('title')->all());
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::NEEDS_ATTENTION, $booking->status);
        $this->assertStringContainsString('The plan is full', $booking->attention);
    }

    public function test_a_booking_cannot_push_the_trip_past_the_bed_limit(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $big = $trip->rooms()->create(['name' => 'Dorm']);
        for ($i = 1; $i <= 198; $i++) {
            $big->beds()->create(['trip_id' => $trip->id, 'label' => "B$i"]);
        }

        $this->importStay($trip, $org);   // 198 + 6 beds is over the limit of 200

        $this->assertSame(1, Room::where('trip_id', $trip->id)->count());
        $this->assertStringContainsString('too many rooms or beds', Booking::firstOrFail()->attention);
    }

    // ---- waiting bookings: accept and dismiss -----------------------------------------------------------

    public function test_a_waiting_booking_adds_nothing_until_the_organiser_accepts_it(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $booking = $this->stage($trip);

        $this->assertSame(Booking::PENDING, $booking->status);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(1, $this->actions($trip, 'booking_received'));

        $result = $this->service->accept($trip, $org, $booking->id);

        $this->assertTrue($result->ok);
        $this->assertSame(Booking::APPLIED, $booking->fresh()->status);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(1, $trip->itineraryItems()->count());
        $this->assertSame(1, $this->actions($trip, 'booking_added'));
    }

    public function test_only_the_organiser_can_accept_dismiss_or_remove(): void
    {
        $trip = $this->makeTrip();
        $this->organiser($trip);
        [$mia] = $this->makeMember($trip, 'Mia');
        $booking = $this->stage($trip);

        $this->assertSame(403, $this->service->accept($trip, $mia, $booking->id)->status);
        $this->assertSame(403, $this->service->dismiss($trip, $mia, $booking->id)->status);
        $this->assertSame(403, $this->service->remove($trip, $mia, $booking->id)->status);
        $this->assertSame(Booking::PENDING, $booking->fresh()->status);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
    }

    public function test_a_dismissed_booking_adds_nothing_and_cannot_be_accepted_later(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $booking = $this->stage($trip);

        $this->assertTrue($this->service->dismiss($trip, $org, $booking->id)->ok);
        $this->assertSame(Booking::DISMISSED, $booking->fresh()->status);

        $this->assertFalse($this->service->accept($trip, $org, $booking->id)->ok);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(1, $this->actions($trip, 'booking_dismissed'));
    }

    public function test_accepting_a_booking_that_was_cancelled_while_waiting_adds_nothing(): void
    {
        $trip = $this->makeTrip();
        $org = $this->organiser($trip);
        $booking = $this->stage($trip, ['state' => 'CANCELLED']);

        $this->assertTrue($this->service->accept($trip, $org, $booking->id)->ok);

        $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(0, $trip->itineraryItems()->count());
    }

    public function test_a_booking_from_another_trip_cannot_be_accepted_here(): void
    {
        $trip = $this->makeTrip();
        $other = $this->makeTrip('Other');
        $org = $this->organiser($trip);
        $foreign = $this->stage($other);

        $result = $this->service->accept($trip, $org, $foreign->id);

        $this->assertFalse($result->ok);
        $this->assertSame(404, $result->status);
        $this->assertSame(Booking::PENDING, $foreign->fresh()->status);
    }

    // ---- the pages ---------------------------------------------------------------------------------

    public function test_the_organiser_can_import_and_manage_bookings_from_the_plan_page(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->partner('stay', $this->stay());

        $this->as($trip, $orgToken)->post($this->url($trip, 'bookings/import'), ['provider' => 'stay', 'reference' => 'stay-abc123'])
            ->assertRedirect($this->url($trip, 'plan'))->assertSessionHas('status', 'Booking added to the trip.');

        $page = $this->as($trip, $orgToken)->get($this->url($trip, 'plan'))->assertOk();
        $page->assertSee('STAY-ABC123')->assertSee('Added')->assertSee('Hotel Sunrise, 12 Dec to 15 Dec · 3 rooms, 6 beds')
            ->assertSee('From the DemoStay booking STAY-ABC123.')->assertSee('Remove from trip')->assertSee('Import a booking');

        $this->as($trip, $orgToken)->post($this->url($trip, 'bookings/import'), ['provider' => 'stay', 'reference' => 'STAY-NOPE00'])
            ->assertSessionHas('problem');
        $this->as($trip, $orgToken)->post($this->url($trip, 'bookings/import'), ['provider' => 'nope', 'reference' => 'X'])
            ->assertSessionHasErrors('provider');
    }

    public function test_members_see_bookings_but_get_no_buttons_and_cannot_post(): void
    {
        $trip = $this->makeTrip();
        $this->organiser($trip);
        [, $miaToken] = $this->makeMember($trip, 'Mia');
        $booking = $this->stage($trip);

        $this->as($trip, $miaToken)->get($this->url($trip, 'plan'))->assertOk()
            ->assertSee('STAY-ABC123')->assertSee('Waiting for the organiser')
            ->assertDontSee('Add to trip')->assertDontSee('Import a booking');

        $this->as($trip, $miaToken)->post($this->url($trip, 'bookings/import'), ['provider' => 'stay', 'reference' => 'STAY-ABC123'])->assertForbidden();
        $this->as($trip, $miaToken)->post($this->url($trip, "bookings/{$booking->id}/accept"))->assertForbidden();
        $this->assertSame(Booking::PENDING, $booking->fresh()->status);
    }

    public function test_the_organiser_sees_a_waiting_booking_and_can_accept_or_dismiss_it(): void
    {
        $trip = $this->makeTrip();
        [, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $first = $this->stage($trip);
        $second = $this->stage($trip, ['booking_ref' => 'STAY-OTHER1']);   // a second booking

        $this->as($trip, $orgToken)->get($this->url($trip, 'plan'))->assertSee('Waiting for you')->assertSee('Add to trip');

        $this->as($trip, $orgToken)->post($this->url($trip, "bookings/{$first->id}/accept"))->assertRedirect()->assertSessionHas('status');
        $this->as($trip, $orgToken)->post($this->url($trip, "bookings/{$second->id}/dismiss"))->assertRedirect()->assertSessionHas('status');

        $this->assertSame(Booking::APPLIED, $first->fresh()->status);
        $this->assertSame(Booking::DISMISSED, $second->fresh()->status);

        // A dismissed booking disappears from the list; the audit log keeps the history.
        $this->as($trip, $orgToken)->get($this->url($trip, 'plan'))->assertSee('STAY-ABC123')->assertDontSee('STAY-OTHER1');

        $this->as($trip, $orgToken)->post($this->url($trip, "bookings/{$first->id}/remove"))->assertRedirect()->assertSessionHas('status');
        $this->assertSame(Booking::DISMISSED, $first->fresh()->status);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
    }

    public function test_a_booking_that_needs_attention_shows_why_on_the_plan_page(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        [$mia] = $this->makeMember($trip, 'Mia');
        $this->importStay($trip, $org);
        $this->takeBed($trip, $mia, $this->bed($trip, 4));
        $this->importStay($trip, $org, ['version' => 2, 'rooms' => array_fill(0, 2, ['type' => 'Sea view', 'guests' => 2])]);

        $this->as($trip, $orgToken)->get($this->url($trip, 'plan'))->assertOk()
            ->assertSee('Needs attention')->assertSee('Room 3 (Sea view) has beds that are held or taken, so it was kept.');
    }

    public function test_text_from_a_booking_site_is_escaped_on_the_page(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->importStay($trip, $org, ['hotel' => ['name' => '<script>alert(1)</script>', 'address' => '<b>x</b>']]);

        $this->as($trip, $orgToken)->get($this->url($trip, 'plan'))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->as($trip, $orgToken)->get($this->url($trip, ''))->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_the_lobby_page_shows_the_place_from_the_booking(): void
    {
        $trip = $this->makeTrip();
        [$org, $orgToken] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->importStay($trip, $org);

        $this->as($trip, $orgToken)->get($this->url($trip, ''))->assertOk()
            ->assertSee('Hotel Sunrise, Candolim, Goa')->assertSee('query=Hotel%20Sunrise%2C%20Candolim%2C%20Goa', false);
    }
}
