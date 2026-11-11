<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItineraryItemRequest;
use App\Integrations\Providers;
use App\Models\Booking;
use App\Models\ItineraryItem;
use App\Models\Trip;
use App\Services\ActionResult;
use App\Services\ItineraryService;
use App\Support\IcsBuilder;
use App\Support\PlanLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The Plan page and the calendar downloads. Everyone in the trip can read and download.
 * Only the organiser can add or remove items (routes use `trip.organiser`; ItineraryService checks again).
 */
class ItineraryController extends Controller
{
    public function __construct(private ItineraryService $plan) {}

    public function index(Request $request): View
    {
        $trip = $request->attributes->get('trip');

        $items = $trip->itineraryItems()->with('booking:id,provider,reference')->orderBy('starts_on')->orderBy('starts_time')->orderBy('id')->get();

        return view('plan', [
            'trip' => $trip,
            // Dismissed bookings are hidden here (the Activity log keeps the history).
            'bookings' => $trip->bookings()->where('status', '!=', Booking::DISMISSED)->orderByDesc('id')->get(),
            'providers' => Providers::all(),
            'me' => $request->attributes->get('member'),
            // Grouped by day so the page reads like a diary.
            'days' => $items->groupBy(fn (ItineraryItem $i) => $i->starts_on->format('Y-m-d')),
            'kinds' => ItineraryItem::KINDS,
            'tripGoogleLink' => PlanLinks::googleCalendarForTrip($trip),
            'full' => $items->count() >= config('lobby.plan.max_items'),
        ]);
    }

    public function store(StoreItineraryItemRequest $request): RedirectResponse
    {
        return $this->back($request, $this->plan->add(
            $request->attributes->get('trip'), $request->attributes->get('member'), $request->validated()
        ));
    }

    // Route params arrive in URL order: {code} (from the prefix) first, then {itemId}.
    public function destroy(Request $request, string $code, int $itemId): RedirectResponse
    {
        return $this->back($request, $this->plan->delete(
            $request->attributes->get('trip'), $request->attributes->get('member'), $itemId
        ));
    }

    /** One calendar file with the trip's dates and every plan item. */
    public function calendar(Request $request): Response
    {
        $trip = $request->attributes->get('trip');
        $items = $trip->itineraryItems()->orderBy('starts_on')->orderBy('starts_time')->orderBy('id')->get();

        return $this->download(IcsBuilder::forTrip($trip, $items), $trip);
    }

    /** A calendar file with just one item. Scoped to the trip: an id from another trip is a 404. */
    public function item(Request $request, string $code, int $itemId): Response
    {
        $trip = $request->attributes->get('trip');
        $item = $trip->itineraryItems()->whereKey($itemId)->firstOrFail();

        return $this->download(IcsBuilder::forItem($trip, $item), $trip, '-'.Str::slug($item->title));
    }

    // no-store: the file lists private plans, so browsers and proxies must not keep a copy.
    private function download(string $ics, Trip $trip, string $suffix = ''): Response
    {
        $name = (Str::slug($trip->name) ?: 'trip').$suffix;

        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Str::limit($name, 60, '').'.ics"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function back(Request $request, ActionResult $result): RedirectResponse
    {
        return redirect()->route('plan.index', $request->attributes->get('trip')->join_code)
            ->with($result->ok ? 'status' : 'problem', $result->message);
    }
}
