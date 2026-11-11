<?php

namespace App\Services;

use App\Integrations\BookingData;
use App\Integrations\InvalidBookingPayload;
use App\Integrations\Providers;
use App\Models\AuditLog;
use App\Models\Bed;
use App\Models\Booking;
use App\Models\Member;
use App\Models\Room;
use App\Models\Trip;
use App\Support\RoomListParser;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turns bookings from booking sites into rooms, beds and plan items, and keeps them in step when the site changes them.
 *
 * Two ways in, one set of rules:
 *  - PULL: the organiser types a booking reference (importByReference). The organiser is the one asking,
 *    so the booking is applied straight away.
 *  - PUSH: the booking site sends a signed webhook (receive). The trip code is known to every member, so
 *    a first message is only STAGED ("pending") until the organiser presses "Add to trip" (accept).
 *    After that, later versions of the same booking apply by themselves.
 *
 * THE BED RULE: a change from the booking site may add rooms and beds and may change dates and places, but it
 * never takes away a bed that somebody holds or has confirmed. Such a room or bed is kept, the booking is marked
 * "needs attention" and says why. Nobody silently loses a bed because a hotel changed its mind.
 *
 * Locking: every path first locks the TRIP row (one booking change at a time per trip, so two identical
 * messages cannot both create rows), then the rooms and beds it touches. The unique index on
 * bookings (trip, provider, reference) is the database's own backstop.
 */
class BookingService
{
    /** PULL: the organiser asks The Lobby to fetch one booking by its reference. */
    public function importByReference(Trip $trip, Member $actor, string $providerKey, string $reference): ActionResult
    {
        if ($problem = $this->cannotManage($trip, $actor, 'booking_import')) {
            return $problem;
        }

        $provider = Providers::find($providerKey);
        if ($provider === null) {
            return ActionResult::fail('Choose one of the booking sites in the list.', 422);
        }

        $reference = strtoupper(trim($reference));

        // The slow part (asking another system) happens BEFORE we lock anything in our database.
        try {
            $payload = $provider->fetch($reference);
        } catch (Throwable $e) {
            report($e);

            return ActionResult::fail('Could not reach '.$provider->label().' just now. Try again in a moment.', 502);
        }
        if ($payload === null) {
            return ActionResult::fail('There is no booking '.$reference.' at '.$provider->label().'. Check the reference.', 404);
        }

        try {
            $data = $provider->parse($payload);
        } catch (InvalidBookingPayload $e) {
            return ActionResult::fail('That booking could not be read: '.$e->getMessage(), 422);
        }
        if ($data->reference !== $reference) {
            return ActionResult::fail('That booking could not be read: the reference did not match.', 422);
        }

        return DB::transaction(function () use ($trip, $actor, $data) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($locked->hasEnded()) {
                return ActionResult::fail('This trip has ended, so bookings can no longer change it.');
            }

            $booking = $this->findLocked($locked, $data);

            if ($booking !== null && $booking->isInTrip() && $data->version <= $booking->version) {
                return ActionResult::ok('This booking is already in the trip.');
            }

            $wasInTrip = $booking !== null && $booking->isInTrip();
            $booking ??= $this->stage($locked, $data);

            $result = $this->apply($locked, $booking, $data, $actor->id, $wasInTrip ? 'booking_changed' : 'booking_added');

            return ActionResult::ok($this->message($wasInTrip ? 'Booking updated.' : 'Booking added to the trip.', $result['issues']));
        }, 3);
    }

    /** The organiser approves a booking that arrived by webhook and is waiting. */
    public function accept(Trip $trip, Member $actor, int $bookingId): ActionResult
    {
        if ($problem = $this->cannotManage($trip, $actor, 'booking_accept')) {
            return $problem;
        }

        return DB::transaction(function () use ($trip, $actor, $bookingId) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($locked->hasEnded()) {
                return ActionResult::fail('This trip has ended, so bookings can no longer change it.');
            }

            $booking = $locked->bookings()->whereKey($bookingId)->lockForUpdate()->first();
            if ($booking === null) {
                return ActionResult::fail('That booking is already gone.', 404);
            }
            if ($booking->status !== Booking::PENDING) {
                return ActionResult::fail('That booking is not waiting for approval.');
            }

            $result = $this->apply($locked, $booking, $booking->bookingData(), $actor->id, 'booking_added');

            return ActionResult::ok($this->message('Booking added to the trip.', $result['issues']));
        }, 3);
    }

    /** The organiser says "not this one". Nothing is added, and later messages for it are ignored. */
    public function dismiss(Trip $trip, Member $actor, int $bookingId): ActionResult
    {
        if ($problem = $this->cannotManage($trip, $actor, 'booking_dismiss')) {
            return $problem;
        }

        return DB::transaction(function () use ($trip, $actor, $bookingId) {
            $booking = $trip->bookings()->whereKey($bookingId)->lockForUpdate()->first();
            if ($booking === null || $booking->status !== Booking::PENDING) {
                return ActionResult::fail('That booking is not waiting for approval.');
            }

            $booking->forceFill(['status' => Booking::DISMISSED])->save();
            $this->audit($trip, $actor->id, 'booking_dismissed', $booking);

            return ActionResult::ok('Booking dismissed. Nothing was added.');
        }, 3);
    }

    /** The organiser takes an added booking out of the trip again (its plan items, and its rooms if nobody is in them). */
    public function remove(Trip $trip, Member $actor, int $bookingId): ActionResult
    {
        if ($problem = $this->cannotManage($trip, $actor, 'booking_remove')) {
            return $problem;
        }

        return DB::transaction(function () use ($trip, $actor, $bookingId) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            $booking = $locked->bookings()->whereKey($bookingId)->lockForUpdate()->first();
            if ($booking === null || ! $booking->isInTrip()) {
                return ActionResult::fail('That booking is not part of the trip.');
            }

            $booking->items()->delete();
            $issues = $this->syncRooms($locked, $booking, []);
            // Rooms that had to stay (people in them) become ordinary rooms: they no longer belong to any booking.
            Room::where('booking_id', $booking->id)->update(['booking_id' => null]);

            $booking->forceFill(['status' => Booking::DISMISSED, 'attention' => null])->save();
            $this->audit($trip, $actor->id, 'booking_removed', $booking, ['issues' => count($issues)]);

            return ActionResult::ok($this->message('Booking removed from the trip.', $issues));
        }, 3);
    }

    /**
     * PUSH: a signed message from a booking site (already verified by WebhookReceiver).
     *
     * @return string staged | applied | duplicate | ignored
     */
    public function receive(Trip $trip, BookingData $data): string
    {
        return DB::transaction(function () use ($trip, $data) {
            $locked = Trip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($locked->hasEnded()) {
                return 'ignored';
            }

            $booking = $this->findLocked($locked, $data);

            if ($booking === null) {
                $this->stage($locked, $data);

                return 'staged';
            }

            if ($booking->status === Booking::DISMISSED) {
                return 'ignored'; // the organiser said no to this one
            }

            // Same or older version: a repeat or an out-of-order message. Doing nothing is the safe answer.
            if ($data->version <= $booking->version) {
                return 'duplicate';
            }

            if ($booking->status === Booking::PENDING) {
                $booking->forceFill(['version' => $data->version, 'data' => $data->toArray()])->save();
                $this->audit($locked, null, 'booking_received', $booking, ['version' => $data->version]);

                return 'staged';
            }

            // Already approved once: later versions apply by themselves.
            $this->apply($locked, $booking, $data, null, 'booking_changed');

            return 'applied';
        }, 3);
    }

    // ---- applying a booking to the trip ------------------------------------------------------------

    /**
     * Make the trip match the booking. Returns the new status and a list of things that could not be applied.
     *
     * @return array{status: string, issues: list<string>}
     */
    private function apply(Trip $trip, Booking $booking, BookingData $data, ?int $actorId, string $auditAction): array
    {
        $issues = [];

        if ($data->cancelled) {
            $booking->items()->delete();
            $issues = $this->syncRooms($trip, $booking, []);
            $status = $issues === [] ? Booking::CANCELLED : Booking::NEEDS_ATTENTION;
            $auditAction = 'booking_cancelled';
            if ($issues !== []) {
                array_unshift($issues, 'Cancelled at '.$booking->providerLabel().'.');
            }
        } else {
            $issues = array_merge($this->replaceItems($trip, $booking, $data), $this->syncRooms($trip, $booking, $data->rooms));
            $status = $issues === [] ? Booking::APPLIED : Booking::NEEDS_ATTENTION;
        }

        $booking->forceFill([
            'status' => $status,
            'version' => $data->version,
            'data' => $data->toArray(),
            'attention' => $issues === [] ? null : mb_substr(implode(' ', $issues), 0, 600),
            'applied_at' => now(),
        ])->save();

        $this->audit($trip, $actorId, $auditAction, $booking, ['version' => $data->version, 'status' => $status, 'issues' => count($issues)]);

        return ['status' => $status, 'issues' => $issues];
    }

    /**
     * Replace this booking's plan items with the ones in the booking. Items typed in by hand are never touched.
     *
     * @return list<string> problems
     */
    private function replaceItems(Trip $trip, Booking $booking, BookingData $data): array
    {
        $others = $trip->itineraryItems()
            ->where(fn ($q) => $q->whereNull('booking_id')->orWhere('booking_id', '!=', $booking->id))
            ->count();

        if ($others + count($data->items) > config('lobby.plan.max_items')) {
            return ['The plan is full, so the dates and places from this booking were not added.'];
        }

        $booking->items()->delete();
        foreach ($data->items as $item) {
            $trip->itineraryItems()->create($item + ['booking_id' => $booking->id, 'created_by_member_id' => null]);
        }

        return [];
    }

    /**
     * Make this booking's rooms match $desired. Rooms are matched by position (first room, second room...).
     * Adding is always fine. Removing a bed or room only happens when nobody holds or has confirmed it.
     *
     * @param  list<array{name: string, beds: list<string>}>  $desired
     * @return list<string> problems
     */
    private function syncRooms(Trip $trip, Booking $booking, array $desired): array
    {
        $existing = $booking->rooms()->orderBy('id')->lockForUpdate()->get();
        $beds = Bed::whereIn('room_id', $existing->pluck('id'))->orderBy('id')->lockForUpdate()->get()->groupBy('room_id');

        // Never let a booking push the trip past the same limits a hand-typed room list has.
        $otherRooms = Room::where('trip_id', $trip->id)->whereNotIn('id', $existing->pluck('id'))->count();
        $otherBeds = Bed::where('trip_id', $trip->id)->whereNotIn('room_id', $existing->pluck('id'))->count();
        $wantBeds = array_sum(array_map(fn ($r) => count($r['beds']), $desired));
        if ($otherRooms + count($desired) > RoomListParser::MAX_ROOMS || $otherBeds + $wantBeds > RoomListParser::MAX_BEDS_TOTAL) {
            return ['The trip would end up with too many rooms or beds, so the rooms from this booking were not changed.'];
        }

        $issues = [];

        foreach ($desired as $i => $want) {
            $room = $existing->get($i);

            if ($room === null) {
                $room = $trip->rooms()->create(['booking_id' => $booking->id, 'name' => $want['name']]);
                foreach ($want['beds'] as $label) {
                    $room->beds()->create(['trip_id' => $trip->id, 'label' => $label]);
                }

                continue;
            }

            if ($room->name !== $want['name']) {
                $room->update(['name' => $want['name']]);
            }

            /** @var Collection<int, Bed> $roomBeds */
            $roomBeds = ($beds->get($room->id) ?? collect())->values();

            foreach ($want['beds'] as $j => $label) {
                if ($roomBeds->has($j)) {
                    if ($roomBeds[$j]->label !== $label) {
                        $roomBeds[$j]->update(['label' => $label]);
                    }
                } else {
                    $room->beds()->create(['trip_id' => $trip->id, 'label' => $label]);
                }
            }

            foreach ($roomBeds->slice(count($want['beds'])) as $bed) {
                if ($bed->isAvailable()) {
                    $bed->delete();
                } else {
                    $issues[] = $room->name.': '.$bed->label.' is held or taken, so it was kept.';
                }
            }
        }

        foreach ($existing->slice(count($desired)) as $room) {
            $roomBeds = $beds->get($room->id) ?? collect();
            if ($roomBeds->every(fn (Bed $b) => $b->isAvailable())) {
                $room->delete(); // its beds go with it (cascade)
            } else {
                $issues[] = $room->name.' has beds that are held or taken, so it was kept.';
            }
        }

        return $issues;
    }

    // ---- small helpers -----------------------------------------------------------------------------

    private function findLocked(Trip $trip, BookingData $data): ?Booking
    {
        return Booking::where('trip_id', $trip->id)
            ->where('provider', $data->provider)
            ->where('reference', $data->reference)
            ->lockForUpdate()
            ->first();
    }

    /** Store a booking as "pending": nothing in the trip changes until the organiser approves it. */
    private function stage(Trip $trip, BookingData $data): Booking
    {
        $booking = Booking::create([
            'trip_id' => $trip->id,
            'provider' => $data->provider,
            'reference' => $data->reference,
            'status' => Booking::PENDING,
            'version' => $data->version,
            'data' => $data->toArray(),
        ]);

        $this->audit($trip, null, 'booking_received', $booking, ['version' => $data->version]);

        return $booking;
    }

    /** @param list<string> $issues */
    private function message(string $done, array $issues): string
    {
        return $issues === [] ? $done : $done.' Needs your attention: '.implode(' ', $issues);
    }

    /** @param array<string, scalar> $extra */
    private function audit(Trip $trip, ?int $memberId, string $action, Booking $booking, array $extra = []): void
    {
        AuditLog::record($trip->id, $memberId, $action, 'booking', $booking->id, [
            'provider' => $booking->provider,
            'reference' => $booking->reference,
        ] + $extra);
    }

    // Same rule as the other services: look the person up fresh, so a just-removed organiser is stopped.
    private function cannotManage(Trip $trip, Member $actor, string $attempted): ?ActionResult
    {
        $fresh = Member::where('trip_id', $trip->id)->whereKey($actor->id)->first();

        if ($fresh === null || $fresh->isRemoved() || ! $fresh->isOrganiser()) {
            AuditLog::record($trip->id, $actor->id, 'action_refused', null, null, [
                'attempted' => $attempted,
                'reason' => 'not_organiser',
            ]);

            return ActionResult::fail('Only the organiser can manage bookings.', 403);
        }

        return null;
    }
}
