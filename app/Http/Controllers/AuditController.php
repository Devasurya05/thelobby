<?php

namespace App\Http\Controllers;

use App\Models\Bed;
use App\Models\Member;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The organiser's Audit log page: a read-only view of everything that happened in the trip. */
class AuditController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $trip = $request->attributes->get('trip');

        // "Older" paging by id (not page numbers): new rows keep arriving, and page numbers would shift under you.
        $before = max(0, (int) $request->query('before', 0));

        $rows = $trip->auditLogs()
            ->with('member:id,name')
            ->when($before > 0, fn ($q) => $q->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(self::PER_PAGE + 1)   // one extra row only tells us whether an older page exists
            ->get();

        $hasOlder = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE);

        // Look up every bed / person the rows mention in ONE query each (not one query per row).
        $beds = Bed::where('trip_id', $trip->id)
            ->whereIn('id', $rows->where('target_type', 'bed')->pluck('target_id')->unique())
            ->with('room:id,name')->get()->keyBy('id');
        $members = Member::where('trip_id', $trip->id)
            ->whereIn('id', $rows->where('target_type', 'member')->pluck('target_id')->unique())
            ->get()->keyBy('id');

        return view('audit', [
            'trip' => $trip,
            'rows' => $rows,
            'beds' => $beds,
            'members' => $members,
            'olderBefore' => $hasOlder ? $rows->last()->id : null,
            'isFirstPage' => $before === 0,
        ]);
    }
}
