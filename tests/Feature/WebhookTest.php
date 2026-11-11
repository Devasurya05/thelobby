<?php

namespace Tests\Feature;

use App\Integrations\Providers;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Member;
use App\Models\Room;
use App\Services\BookingService;
use App\Services\WebhookReceiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BookingFixtures;
use Tests\TestCase;

/**
 * The public webhook address. The signature is the lock, so most of these tests try to get past it.
 */
class WebhookTest extends TestCase
{
    use BookingFixtures, RefreshDatabase;

    // ---- the happy path: staged, approved, then updated by itself -----------------------------------------

    public function test_a_signed_message_is_staged_and_changes_nothing_until_the_organiser_accepts(): void
    {
        $trip = $this->makeTrip();
        $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->webhook('stay', $this->stay(), $trip->join_code)
            ->assertStatus(202)->assertExactJson(['result' => 'staged']);

        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::PENDING, $booking->status);
        $this->assertSame($trip->id, $booking->trip_id);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(0, $trip->itineraryItems()->count());

        $log = AuditLog::where('action', 'booking_received')->firstOrFail();
        $this->assertNull($log->member_id);   // sent by a booking site, not by a person
    }

    public function test_once_accepted_later_versions_apply_by_themselves(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->webhook('stay', $this->stay(), $trip->join_code)->assertStatus(202);
        app(BookingService::class)->accept($trip, $org, Booking::firstOrFail()->id);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());

        $this->webhook('stay', $this->stay(['version' => 2, 'check_in' => '2026-12-14', 'rooms' => array_fill(0, 4, ['type' => 'Sea view', 'guests' => 2])]), $trip->join_code)
            ->assertOk()->assertExactJson(['result' => 'applied']);

        $this->assertSame(4, Room::where('trip_id', $trip->id)->count());
        $this->assertSame('2026-12-14', $trip->itineraryItems()->first()->starts_on->format('Y-m-d'));
        $this->assertSame(2, Booking::firstOrFail()->version);

        $log = AuditLog::where('action', 'booking_changed')->firstOrFail();
        $this->assertNull($log->member_id);
    }

    public function test_the_same_message_twice_and_an_older_message_change_nothing(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->webhook('stay', $this->stay(['version' => 2]), $trip->join_code)->assertStatus(202);

        $this->webhook('stay', $this->stay(['version' => 2]), $trip->join_code)->assertOk()->assertExactJson(['result' => 'duplicate']);   // a replay
        $this->webhook('stay', $this->stay(['version' => 1]), $trip->join_code)->assertOk()->assertExactJson(['result' => 'duplicate']);   // out of order

        $this->assertSame(1, Booking::count());
        $this->assertSame(2, Booking::firstOrFail()->version);
        $this->assertSame(1, AuditLog::where('action', 'booking_received')->count());

        // After approval, repeats still change nothing.
        app(BookingService::class)->accept($trip, $org, Booking::firstOrFail()->id);
        $this->webhook('stay', $this->stay(['version' => 2]), $trip->join_code)->assertOk()->assertExactJson(['result' => 'duplicate']);
        $this->assertSame(3, Room::where('trip_id', $trip->id)->count());
        $this->assertSame(1, $trip->itineraryItems()->count());
    }

    public function test_a_newer_message_for_a_waiting_booking_updates_the_waiting_copy_only(): void
    {
        $trip = $this->makeTrip();
        $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->webhook('stay', $this->stay(), $trip->join_code)->assertStatus(202);
        $this->webhook('stay', $this->stay(['version' => 2, 'rooms' => array_fill(0, 5, ['type' => 'Standard', 'guests' => 1])]), $trip->join_code)
            ->assertStatus(202)->assertExactJson(['result' => 'staged']);

        $booking = Booking::firstOrFail();
        $this->assertSame(Booking::PENDING, $booking->status);
        $this->assertSame(2, $booking->version);
        $this->assertCount(5, $booking->data['rooms']);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
    }

    public function test_after_the_organiser_dismisses_a_booking_the_site_cannot_bring_it_back(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);
        $this->webhook('stay', $this->stay(), $trip->join_code)->assertStatus(202);
        app(BookingService::class)->dismiss($trip, $org, Booking::firstOrFail()->id);

        $this->webhook('stay', $this->stay(['version' => 5]), $trip->join_code)->assertOk()->assertExactJson(['result' => 'ignored']);

        $this->assertSame(Booking::DISMISSED, Booking::firstOrFail()->status);
        $this->assertSame(0, Room::where('trip_id', $trip->id)->count());
    }

    public function test_a_finished_trip_ignores_webhooks(): void
    {
        $trip = $this->makeTrip();
        $trip->forceFill(['ended_at' => now()])->save();

        $this->webhook('stay', $this->stay(), $trip->join_code)->assertOk()->assertExactJson(['result' => 'ignored']);

        $this->assertSame(0, Booking::count());
    }

    public function test_the_flight_site_works_through_the_same_door(): void
    {
        $trip = $this->makeTrip();
        [$org] = $this->makeMember($trip, 'Olga', Member::ROLE_ORGANISER);

        $this->webhook('fly', $this->fly(), $trip->join_code)->assertStatus(202);
        app(BookingService::class)->accept($trip, $org, Booking::firstOrFail()->id);

        $this->assertSame(2, $trip->itineraryItems()->where('kind', 'flight')->count());
        $this->webhook('fly', $this->fly(['rev' => 2, 'status' => 'VOID']), $trip->join_code)->assertOk()->assertExactJson(['result' => 'applied']);
        $this->assertSame(0, $trip->itineraryItems()->count());
        $this->assertSame(Booking::CANCELLED, Booking::firstOrFail()->status);
    }

    // ---- trying to get past the lock ----------------------------------------------------------------------

    public function test_a_wrong_or_missing_signature_is_refused_and_nothing_is_stored(): void
    {
        $trip = $this->makeTrip();
        $booking = $this->stay();
        $body = json_encode(['trip_code' => $trip->join_code, 'booking' => $booking]);

        $this->webhook('stay', $booking, $trip->join_code, ['signature' => 'sha256='.str_repeat('0', 64)])->assertStatus(401)->assertExactJson(['error' => 'bad_signature']);
        $this->webhook('stay', $booking, $trip->join_code, ['signature' => null])->assertStatus(401);
        $this->webhook('stay', $booking, $trip->join_code, ['timestamp' => null])->assertStatus(401);
        $this->webhook('stay', $booking, $trip->join_code, ['signature' => ''])->assertStatus(401);
        // A different partner's secret does not work for this partner.
        $this->webhook('stay', $booking, $trip->join_code, ['signature' => Providers::sign('fly', time(), $body)])->assertStatus(401);

        $this->assertSame(0, Booking::count());
        $this->assertSame(0, AuditLog::where('action', 'booking_received')->count());
    }

    public function test_changing_even_one_character_after_signing_is_caught(): void
    {
        $trip = $this->makeTrip();
        $signedBody = json_encode(['trip_code' => $trip->join_code, 'booking' => $this->stay()]);
        $timestamp = time();
        $signature = Providers::sign('stay', $timestamp, $signedBody);

        $tampered = str_replace('Hotel Sunrise', 'Hotel Sunrisf', $signedBody);

        $this->webhook('stay', [], $trip->join_code, ['body' => $tampered, 'timestamp' => $timestamp, 'signature' => $signature])
            ->assertStatus(401)->assertExactJson(['error' => 'bad_signature']);
        $this->assertSame(0, Booking::count());
    }

    public function test_an_old_message_is_refused_even_with_a_valid_signature(): void
    {
        $trip = $this->makeTrip();

        $this->webhook('stay', $this->stay(), $trip->join_code, ['timestamp' => time() - 301])
            ->assertStatus(401)->assertExactJson(['error' => 'stale_or_missing_timestamp']);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_timestamp_that_is_not_a_number_is_refused(): void
    {
        $trip = $this->makeTrip();
        $body = json_encode(['trip_code' => $trip->join_code, 'booking' => $this->stay()]);

        $this->call('POST', '/webhooks/stay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_LOBBY_TIMESTAMP' => 'yesterday',
            'HTTP_X_LOBBY_SIGNATURE' => Providers::sign('stay', time(), $body),
        ], $body)->assertStatus(401)->assertExactJson(['error' => 'stale_or_missing_timestamp']);
    }

    public function test_an_unknown_partner_gets_a_404_and_the_body_is_never_read(): void
    {
        $trip = $this->makeTrip();

        $this->call('POST', '/webhooks/hotelz', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{"trip_code":"'.$trip->join_code.'"}')
            ->assertStatus(404)->assertExactJson(['error' => 'unknown_provider']);
        $this->call('POST', '/webhooks/Stay', [], [], [], [], '{}')->assertStatus(404);   // the route only allows lower-case letters
    }

    public function test_with_no_secret_available_every_message_is_refused(): void
    {
        $trip = $this->makeTrip();
        $body = json_encode(['trip_code' => $trip->join_code, 'booking' => $this->stay()]);
        $timestamp = time();
        $signature = Providers::sign('stay', $timestamp, $body);   // made while a key still existed

        config(['lobby.partners.stay.secret' => null, 'app.key' => '']);

        // Called on the receiver directly: with the app key blanked, Laravel's own encryption stops the HTTP request earlier.
        $result = app(WebhookReceiver::class)->handle('stay', $body, (string) $timestamp, $signature);

        $this->assertSame(503, $result['status']);
        $this->assertSame(['error' => 'not_configured'], $result['body']);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_real_partner_secret_in_the_settings_is_used_instead_of_the_derived_one(): void
    {
        $trip = $this->makeTrip();
        $booking = $this->stay();
        $body = json_encode(['trip_code' => $trip->join_code, 'booking' => $booking]);
        $derivedSignature = Providers::sign('stay', time(), $body);

        config(['lobby.partners.stay.secret' => 'a-real-partner-secret']);

        $this->webhook('stay', $booking, $trip->join_code, ['signature' => $derivedSignature])->assertStatus(401);
        $this->webhook('stay', $booking, $trip->join_code)->assertStatus(202);   // signed with the configured secret
    }

    public function test_an_oversized_body_is_refused_before_anything_else(): void
    {
        $trip = $this->makeTrip();
        $huge = json_encode(['trip_code' => $trip->join_code, 'booking' => $this->stay(), 'padding' => str_repeat('x', 100001)]);

        $this->webhook('stay', [], $trip->join_code, ['body' => $huge])->assertStatus(413)->assertExactJson(['error' => 'payload_too_large']);
        $this->assertSame(0, Booking::count());
    }

    // ---- signed, but still not trusted ------------------------------------------------------------------------

    public function test_a_signed_message_with_a_broken_envelope_is_a_400(): void
    {
        $trip = $this->makeTrip();

        $this->webhook('stay', [], $trip->join_code, ['body' => 'this is not json'])->assertStatus(400)->assertExactJson(['error' => 'bad_payload']);
        $this->webhook('stay', [], $trip->join_code, ['body' => json_encode(['booking' => $this->stay()])])->assertStatus(400);                 // no trip code
        $this->webhook('stay', [], $trip->join_code, ['body' => json_encode(['trip_code' => 'short', 'booking' => $this->stay()])])->assertStatus(400);
        $this->webhook('stay', [], $trip->join_code, ['body' => json_encode(['trip_code' => $trip->join_code, 'booking' => 'text'])])->assertStatus(400);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_signed_message_for_a_trip_that_does_not_exist_is_a_404(): void
    {
        $this->webhook('stay', $this->stay(), 'ZZZZZZZZ')->assertStatus(404)->assertExactJson(['error' => 'unknown_trip']);
        $this->assertSame(0, Booking::count());
    }

    public function test_a_signed_message_with_an_unreadable_booking_is_a_422_and_stores_nothing(): void
    {
        $trip = $this->makeTrip();

        $response = $this->webhook('stay', $this->stay(['check_in' => '2026-12-20', 'check_out' => '2026-12-10']), $trip->join_code);

        $response->assertStatus(422)->assertJsonPath('error', 'bad_booking');
        $this->assertSame(0, Booking::count());
    }

    public function test_responses_are_never_cached(): void
    {
        $trip = $this->makeTrip();

        $this->assertStringContainsString('no-store', $this->webhook('stay', $this->stay(), $trip->join_code)->headers->get('Cache-Control'));
    }

    public function test_a_message_cannot_reach_a_trip_through_another_trips_booking(): void
    {
        $one = $this->makeTrip();
        $two = $this->makeTrip('Second trip');

        $this->webhook('stay', $this->stay(), $one->join_code)->assertStatus(202);
        $this->webhook('stay', $this->stay(), $two->join_code)->assertStatus(202);   // same reference, different trip: its own row

        $this->assertSame(2, Booking::count());
        $this->assertSame([$one->id, $two->id], Booking::orderBy('id')->pluck('trip_id')->all());
    }
}
