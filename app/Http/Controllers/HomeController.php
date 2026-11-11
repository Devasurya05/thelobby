<?php

namespace App\Http\Controllers;

class HomeController extends Controller
{
    // Landing page: a "create a trip" form and a "join a trip" form.
    public function index()
    {
        return view('home');
    }
}
