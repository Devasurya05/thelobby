<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinTripRequest;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Trip;
use App\Support\MemberToken;
use Illuminate\Http\Request;

class JoinController extends Controller
{
    private const MAX_MEMBERS = 200;

    // The join page. The shared link is /join/ABCD2345, which pre-fills the code.
    public function show(Request $request, ?string $code = null)
    {
        $code = $code === null ? null : strtoupper($code);

        // Already in this trip on this device? Go straight to the lobby.
        if ($code !== null && $request->cookie(MemberToken::cookieName($code)) !== null) {
            $trip = Trip::where('join_code', $code)->first();
            $token = $request->cookie(MemberToken::cookieName($code));
            if ($trip && $trip->members()->where('token_hash', MemberToken::hash($token))->whereNull('removed_at')->exists()) {
                return redirect()->route('lobby', $code);
            }
        }

        return view('join', ['code' => $code]);
    }

    public function store(JoinTripRequest $request)
    {
        $code = strtoupper($request->validated('code'));
        $name = $request->validated('name');

        $trip = Trip::where('join_code', $code)->first();

        // Same message for "no such trip" and "typo": do not help someone guessing codes.
        if ($trip === null) {
            return back()->withInput()->withErrors(['code' => 'We could not find a trip with that code.']);
        }
        if ($trip->hasEnded()) {
            return back()->withInput()->withErrors(['code' => 'This trip has already ended.']);
        }

        $activeMembers = $trip->members()->whereNull('removed_at');

        if ((clone $activeMembers)->count() >= self::MAX_MEMBERS) {
            return back()->withInput()->withErrors(['code' => 'This trip is full.']);
        }

        // Names must be unique inside a trip, otherwise two "Rahul"s could pose as each other in the lobby.
        // MySQL compares text case-insensitively by default, so "rahul" and "Rahul" count as the same.
        // (Two people joining with the same new name in the same millisecond could slip through;
        // acceptable for this app, a unique index would be the fix if it mattered.)
        if ((clone $activeMembers)->where('name', $name)->exists()) {
            return back()->withInput()->withErrors(['name' => 'That name is already used in this trip. Pick another.']);
        }

        $token = MemberToken::generate();

        $member = $trip->members()->create([
            'name' => $name,
            'role' => Member::ROLE_MEMBER,
            'token_hash' => MemberToken::hash($token),
        ]);

        AuditLog::record($trip->id, $member->id, 'member_joined', 'member', $member->id);

        return redirect()
            ->route('lobby', $trip->join_code)
            ->withCookie(MemberToken::cookie($trip, $token));
    }
}
