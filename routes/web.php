<?php

use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\TripController;
use Illuminate\Support\Facades\Route;

// Trip codes are exactly 8 letters/digits; anything else is a 404 before our code even runs.
$code = '[A-Za-z0-9]{8}';

// --- Public pages (no login) -------------------------------------------------------------
Route::get('/', [HomeController::class, 'index'])->name('home');

// throttle:10,1 = at most 10 requests per minute per IP (stops code guessing and spam).
Route::post('/trips', [TripController::class, 'store'])->middleware('throttle:10,1')->name('trips.store');
Route::get('/join/{code?}', [JoinController::class, 'show'])->where('code', $code)->name('join.show');
Route::post('/join', [JoinController::class, 'store'])->middleware('throttle:10,1')->name('join.store');

// --- Everything inside a trip: the ResolveMember middleware checks who you are first ------
Route::middleware('trip.member')
    ->prefix('t/{code}')
    ->where(['code' => $code])
    ->group(function () {
        Route::get('/', [LobbyController::class, 'show'])->name('lobby');
    });
