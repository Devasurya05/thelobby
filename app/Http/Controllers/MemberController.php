<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeRoleRequest;
use App\Models\Bed;
use App\Services\ActionResult;
use App\Services\MemberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The organiser's Members page. Everything here is behind the `trip.organiser` middleware. */
class MemberController extends Controller
{
    public function __construct(private MemberService $members) {}

    public function index(Request $request): View
    {
        $trip = $request->attributes->get('trip');

        // Who has which confirmed bed: one query, then a lookup table (avoids one query per member).
        $beds = Bed::where('trip_id', $trip->id)->whereNotNull('taken_by_member_id')
            ->with('room:id,name')->get()->keyBy('taken_by_member_id');

        return view('members', [
            'trip' => $trip,
            'me' => $request->attributes->get('member'),
            // Active people first, removed ones at the bottom; join order inside each group.
            'members' => $trip->members()->orderByRaw('removed_at is not null')->orderBy('id')->get(),
            'beds' => $beds,
        ]);
    }

    // Controller methods get route params in URL order: {code} (from the prefix) first, then {memberId}.
    public function remove(Request $request, string $code, int $memberId): RedirectResponse
    {
        return $this->back($request, $this->members->remove(
            $request->attributes->get('trip'), $request->attributes->get('member'), $memberId
        ));
    }

    public function role(ChangeRoleRequest $request, string $code, int $memberId): RedirectResponse
    {
        return $this->back($request, $this->members->changeRole(
            $request->attributes->get('trip'), $request->attributes->get('member'), $memberId, $request->validated('role')
        ));
    }

    private function back(Request $request, ActionResult $result): RedirectResponse
    {
        // "status" = green message, "problem" = red message (shown by the members view).
        return redirect()->route('members.index', $request->attributes->get('trip')->join_code)
            ->with($result->ok ? 'status' : 'problem', $result->message);
    }
}
