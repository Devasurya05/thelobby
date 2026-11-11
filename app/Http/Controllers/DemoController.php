<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/** Front page of the pretend booking sites. Not part of the real product: it exists so The Lobby's booking connection can be shown. */
class DemoController extends Controller
{
    public function index(): View
    {
        return view('demo.index');
    }
}
