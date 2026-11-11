<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBookingRequest;
use App\Services\ActionResult;
use App\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The organiser's booking actions on the Plan page. Behind `trip.organiser`; BookingService checks the role again. */
class BookingController extends Controller
{
    public function __construct(private BookingService $bookings) {}

    public function import(ImportBookingRequest $request): RedirectResponse
    {
        return $this->back($request, $this->bookings->importByReference(
            $request->attributes->get('trip'),
            $request->attributes->get('member'),
            $request->validated('provider'),
            $request->validated('reference'),
        ));
    }

    // Route params arrive in URL order: {code} (from the prefix) first, then {bookingId}.
    public function accept(Request $request, string $code, int $bookingId): RedirectResponse
    {
        return $this->back($request, $this->bookings->accept(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bookingId
        ));
    }

    public function dismiss(Request $request, string $code, int $bookingId): RedirectResponse
    {
        return $this->back($request, $this->bookings->dismiss(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bookingId
        ));
    }

    public function remove(Request $request, string $code, int $bookingId): RedirectResponse
    {
        return $this->back($request, $this->bookings->remove(
            $request->attributes->get('trip'), $request->attributes->get('member'), $bookingId
        ));
    }

    private function back(Request $request, ActionResult $result): RedirectResponse
    {
        return redirect()->route('plan.index', $request->attributes->get('trip')->join_code)
            ->with($result->ok ? 'status' : 'problem', $result->message);
    }
}
