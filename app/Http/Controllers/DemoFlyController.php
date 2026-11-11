<?php

namespace App\Http\Controllers;

use App\Http\Requests\DemoFlyRequest;
use App\Http\Requests\SendToLobbyRequest;
use App\Integrations\DemoCatalog;
use App\Integrations\PartnerStore;
use App\Models\PartnerBooking;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * DemoFly, a PRETEND airline site. Its own format (pnr, rev, segments...) differs from DemoStay's on purpose:
 * that is the whole reason The Lobby has one translator per site (see DemoFlyProvider).
 */
class DemoFlyController extends Controller
{
    /** Every flight in the demo takes 85 minutes, so the arrival time can be worked out. */
    private const FLIGHT_MINUTES = 85;

    public function __construct(private PartnerStore $store) {}

    public function create(): View
    {
        return view('demo.fly-form', ['airports' => DemoCatalog::AIRPORTS, 'times' => DemoCatalog::DEPARTURE_TIMES, 'form' => null]);
    }

    public function store(DemoFlyRequest $request): RedirectResponse
    {
        if ($this->store->isFull()) {
            return back()->withInput()->with('problem', 'The demo site is full right now. Please try again later.');
        }

        $reference = $this->store->newReference('FLY');
        PartnerBooking::create([
            'provider' => 'fly',
            'reference' => $reference,
            'payload' => $this->payload($request->validated(), $reference, 1, 'TICKETED'),
        ]);

        return redirect()->route('demo.fly.show', $reference);
    }

    public function show(string $reference): View
    {
        $booking = $this->find($reference);
        $segments = $booking->payload['segments'];
        $outbound = $segments[0];
        $return = $segments[1] ?? null;

        // Pre-fill the "change" form from the booking itself.
        $form = [
            'from' => $outbound['from'],
            'to' => $outbound['to'],
            'date' => substr($outbound['departs_at'], 0, 10),
            'time' => substr($outbound['departs_at'], 11, 5),
            'has_return' => $return !== null,
            'return_date' => $return ? substr($return['departs_at'], 0, 10) : null,
            'return_time' => $return ? substr($return['departs_at'], 11, 5) : null,
            'pax' => $booking->payload['pax'],
        ];

        return view('demo.fly-show', [
            'booking' => $booking,
            'airports' => DemoCatalog::AIRPORTS,
            'times' => DemoCatalog::DEPARTURE_TIMES,
            'form' => $form,
        ]);
    }

    public function send(SendToLobbyRequest $request, string $reference): RedirectResponse
    {
        $result = $this->store->sendTo($this->find($reference), $request->validated('trip_code'));

        return back()->with($result['status'] < 300 ? 'status' : 'problem', PartnerStore::describe($result));
    }

    public function change(DemoFlyRequest $request, string $reference): RedirectResponse
    {
        $booking = $this->find($reference);
        if ($booking->payload['status'] === 'VOID') {
            return back()->with('problem', 'A cancelled booking cannot be changed. Make a new booking instead.');
        }

        $booking->forceFill([
            'payload' => $this->payload($request->validated(), $reference, $booking->payload['rev'] + 1, 'TICKETED'),
        ])->save();
        $pushed = $this->store->pushUpdate($booking);

        return back()->with('status', 'Booking changed.'.($pushed > 0 ? ' The change was sent to '.$pushed.($pushed === 1 ? ' trip.' : ' trips.') : ''));
    }

    public function cancel(string $reference): RedirectResponse
    {
        $booking = $this->find($reference);
        if ($booking->payload['status'] === 'VOID') {
            return back();
        }

        $payload = $booking->payload;
        $payload['status'] = 'VOID';
        $payload['rev']++;
        $booking->forceFill(['payload' => $payload])->save();
        $pushed = $this->store->pushUpdate($booking);

        return back()->with('status', 'Booking cancelled.'.($pushed > 0 ? ' The cancellation was sent to '.$pushed.($pushed === 1 ? ' trip.' : ' trips.') : ''));
    }

    private function find(string $reference): PartnerBooking
    {
        return PartnerBooking::where('provider', 'fly')->where('reference', $reference)->firstOrFail();
    }

    /** DemoFly's own format. @param array<string, mixed> $form */
    private function payload(array $form, string $reference, int $revision, string $status): array
    {
        $segments = [$this->segment($reference, $form['from'], $form['to'], $form['date'], $form['time'])];

        if (! empty($form['has_return'])) {
            $segments[] = $this->segment($reference, $form['to'], $form['from'], $form['return_date'], $form['return_time']);
        }

        return ['pnr' => $reference, 'rev' => $revision, 'status' => $status, 'pax' => (int) $form['pax'], 'segments' => $segments];
    }

    /** One flight. Times carry a UTC offset (+05:30) like a real airline's data. */
    private function segment(string $reference, string $from, string $to, string $date, string $time): array
    {
        $departs = new DateTimeImmutable($date.'T'.$time.':00+05:30');

        return [
            // The same booking always gets the same flight numbers, so a change does not rename the flights.
            'flight' => 'DF '.(100 + crc32($reference.$from.$to) % 900),
            'from' => $from,
            'to' => $to,
            'departs_at' => $departs->format('Y-m-d\TH:i:sP'),
            'arrives_at' => $departs->modify('+'.self::FLIGHT_MINUTES.' minutes')->format('Y-m-d\TH:i:sP'),
        ];
    }
}
