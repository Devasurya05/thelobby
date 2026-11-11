<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LobbyController extends Controller
{
    // The trip page. For now it only shows who you are and the join link;
    // the live bed grid arrives in the next step.
    public function show(Request $request)
    {
        return view('lobby', [
            // Put there by the ResolveMember middleware.
            'trip' => $request->attributes->get('trip'),
            'member' => $request->attributes->get('member'),
        ]);
    }
}
