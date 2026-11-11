<?php

namespace App\Http\Controllers;

use App\Http\Requests\DemoStayRequest;
use App\Http\Requests\NewTripFromBookingRequest;
use App\Http\Requests\SendToLobbyRequest;
use App\Integrations\DemoCatalog;
use App\Integrations\PartnerStore;
use App\Models\PartnerBooking;
use App\Support\MemberToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * DemoStay, a PRETEND hotel booking site. It keeps bookings in its own format (see DemoStayProvider for the
 * field names) and tells The Lobby about them with signed webhooks, the way a real partner would.
 */
class DemoStayController extends Controller
{
    public function __construct(private PartnerStore $store) {}

    public function create(): View
    {
        return view('demo.stay-form', ['hotels' => DemoCatalog::HOTELS, 'roomTypes' => DemoCatalog::ROOM_TYPES, 'booking' => null]);
    }

    public function store(DemoStayRequest $request): RedirectResponse
    {
        if ($this->store->isFull()) {
            return back()->withInput()->with('problem', 'The demo site is full right now. Please try again later.');
        }

        $reference = $this->store->newReference('STAY');
        PartnerBooking::create([
            'provider' => 'stay',
            'reference' => $reference,
            'payload' => $this->payload($request->validated(), $reference, 1, 'CONFIRMED'),
        ]);

        return redirect()->route('demo.stay.show', $reference);
    }

    public function show(string $reference): View
    {
        $booking = $this->find($reference);

        return view('demo.stay-show', [
            'booking' => $booking,
            'existingTrip' => $this->store->existingTrip($booking),
            'hotels' => DemoCatalog::HOTELS,
            'roomTypes' => DemoCatalog::ROOM_TYPES,
        ]);
    }

    /** "Create a new trip for this booking": the trip, its organiser (this person) and the booking, all in one step. */
    public function newTrip(NewTripFromBookingRequest $request, string $reference): RedirectResponse
    {
        $result = $this->store->createTrip($this->find($reference), $request->validated('organiser_name'));

        if (is_string($result)) {
            return back()->with('problem', $result);
        }

        // The person is logged in to the new trip as its organiser (the cookie is for that trip's pages only).
        return redirect()->route('lobby', $result->trip->join_code)
            ->withCookie(MemberToken::cookie($result->trip, $result->token))
            ->with('status', 'Your trip is ready, and this booking is already in it.');
    }

    public function send(SendToLobbyRequest $request, string $reference): RedirectResponse
    {
        $result = $this->store->sendTo($this->find($reference), $request->validated('trip_code'));

        return back()->with($result['status'] < 300 ? 'status' : 'problem', PartnerStore::describe($result));
    }

    public function change(DemoStayRequest $request, string $reference): RedirectResponse
    {
        $booking = $this->find($reference);
        if ($booking->payload['state'] === 'CANCELLED') {
            return back()->with('problem', 'A cancelled booking cannot be changed. Make a new booking instead.');
        }

        $booking->forceFill([
            'payload' => $this->payload($request->validated(), $reference, $booking->payload['version'] + 1, 'CONFIRMED'),
        ])->save();
        $pushed = $this->store->pushUpdate($booking);

        return back()->with('status', 'Booking changed.'.($pushed > 0 ? ' The change was sent to '.$pushed.($pushed === 1 ? ' trip.' : ' trips.') : ''));
    }

    public function cancel(string $reference): RedirectResponse
    {
        $booking = $this->find($reference);
        if ($booking->payload['state'] === 'CANCELLED') {
            return back();
        }

        $payload = $booking->payload;
        $payload['state'] = 'CANCELLED';
        $payload['version']++;
        $booking->forceFill(['payload' => $payload])->save();
        $pushed = $this->store->pushUpdate($booking);

        return back()->with('status', 'Booking cancelled.'.($pushed > 0 ? ' The cancellation was sent to '.$pushed.($pushed === 1 ? ' trip.' : ' trips.') : ''));
    }

    /** A 404 for anything that is not a DemoStay booking: never leak a booking from another site. */
    private function find(string $reference): PartnerBooking
    {
        return PartnerBooking::where('provider', 'stay')->where('reference', $reference)->firstOrFail();
    }

    /** DemoStay's own format. @param array<string, mixed> $form */
    private function payload(array $form, string $reference, int $version, string $state): array
    {
        $hotel = DemoCatalog::HOTELS[$form['hotel']];

        return [
            'booking_ref' => $reference,
            'version' => $version,
            'state' => $state,
            'hotel' => ['name' => $hotel['name'], 'address' => $hotel['address']],
            'check_in' => $form['check_in'],
            'check_out' => $form['check_out'],
            'check_in_from' => '14:00',
            'check_out_by' => '11:00',
            'rooms' => array_fill(0, (int) $form['rooms'], ['type' => $form['room_type'], 'guests' => (int) $form['guests']]),
        ];
    }
}
