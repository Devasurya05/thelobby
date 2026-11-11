<?php

namespace Tests\Concerns;

use App\Integrations\Providers;
use App\Models\Bed;
use App\Models\Member;
use App\Models\PartnerBooking;
use App\Models\Trip;
use App\Support\MemberToken;

/** Shared helpers for the booking tests: trips, people, sample partner messages and signed requests. */
trait BookingFixtures
{
    private function makeTrip(string $name = 'Goa trip'): Trip
    {
        return Trip::create(['name' => $name, 'join_code' => Trip::newJoinCode(), 'starts_on' => '2026-12-12', 'ends_on' => '2026-12-15']);
    }

    /** @return array{0: Member, 1: string} */
    private function makeMember(Trip $trip, string $name, string $role = Member::ROLE_MEMBER): array
    {
        $token = MemberToken::generate();

        return [
            $trip->members()->create(['name' => $name, 'role' => $role, 'token_hash' => MemberToken::hash($token)]),
            $token,
        ];
    }

    private function as(Trip $trip, string $token)
    {
        return $this->withCookie(MemberToken::cookieName($trip->join_code), $token)->withCredentials();
    }

    private function url(Trip $trip, string $path): string
    {
        return "/t/{$trip->join_code}/{$path}";
    }

    /** A sample message in DemoStay's own format (3 rooms, 2 guests each). */
    private function stay(array $over = []): array
    {
        return array_replace([
            'booking_ref' => 'STAY-ABC123',
            'version' => 1,
            'state' => 'CONFIRMED',
            'hotel' => ['name' => 'Hotel Sunrise', 'address' => 'Candolim, Goa'],
            'check_in' => '2026-12-12',
            'check_out' => '2026-12-15',
            'check_in_from' => '14:00',
            'check_out_by' => '11:00',
            'rooms' => array_fill(0, 3, ['type' => 'Sea view', 'guests' => 2]),
        ], $over);
    }

    /** A sample message in DemoFly's own format (outbound plus return, 3 passengers). */
    private function fly(array $over = []): array
    {
        return array_replace([
            'pnr' => 'FLY-XYZ789',
            'rev' => 1,
            'status' => 'TICKETED',
            'pax' => 3,
            'segments' => [
                ['flight' => 'DF 204', 'from' => 'GOI', 'to' => 'BLR', 'departs_at' => '2026-12-15T18:30:00+05:30', 'arrives_at' => '2026-12-15T19:55:00+05:30'],
                ['flight' => 'DF 205', 'from' => 'BLR', 'to' => 'GOI', 'departs_at' => '2026-12-18T09:15:00+05:30', 'arrives_at' => '2026-12-18T10:40:00+05:30'],
            ],
        ], $over);
    }

    /** Put a booking into the pretend site's own table, so "import by reference" can find it. */
    private function partner(string $provider, array $payload): PartnerBooking
    {
        return PartnerBooking::create([
            'provider' => $provider,
            'reference' => $payload['booking_ref'] ?? $payload['pnr'],
            'payload' => $payload,
        ]);
    }

    /** POST a signed webhook message over the real route. */
    private function webhook(string $provider, array $booking, string $tripCode, array $options = [])
    {
        $body = $options['body'] ?? json_encode(['trip_code' => $tripCode, 'booking' => $booking]);
        // An explicit null means "leave this header out" (to test missing headers); no key means "use the right value".
        $timestamp = array_key_exists('timestamp', $options) ? $options['timestamp'] : time();
        $signature = array_key_exists('signature', $options) ? $options['signature'] : Providers::sign($provider, $timestamp ?? time(), $body);

        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($timestamp !== null) {
            $server['HTTP_X_LOBBY_TIMESTAMP'] = (string) $timestamp;
        }
        if ($signature !== null) {
            $server['HTTP_X_LOBBY_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/webhooks/'.$provider, [], [], [], $server, $body);
    }

    /** @return list<string> bed labels of the trip's rooms: "Room 1 (Sea view): Bed 1" and so on */
    private function bedLabels(Trip $trip): array
    {
        return Bed::where('trip_id', $trip->id)->with('room')->orderBy('id')->get()
            ->map(fn (Bed $b) => $b->room->name.': '.$b->label)->all();
    }
}
