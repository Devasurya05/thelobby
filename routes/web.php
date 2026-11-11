<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\BedController;
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

// --- Owner-only pages (ADMIN_KEY in .env). Login is limited to 5 tries per minute per IP. ---
Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('admin')->group(function () {
    Route::get('/admin/errors', [AdminController::class, 'errors'])->name('admin.errors');
    Route::post('/admin/logout', [AdminController::class, 'logout'])->name('admin.logout');
});

// --- Everything inside a trip: the ResolveMember middleware checks who you are first ------
Route::middleware('trip.member')
    ->prefix('t/{code}')
    ->where(['code' => $code])
    ->group(function () {
        Route::get('/', [LobbyController::class, 'show'])->name('lobby');

        // JSON the lobby screen polls every ~2 seconds.
        Route::get('/state', [LobbyController::class, 'state'])->name('lobby.state');

        // Bed actions. POST (never GET) so they can't be triggered by a link or an image tag.
        Route::middleware('throttle:lobby-actions')->group(function () {
            Route::post('/beds/{bedId}/grab', [BedController::class, 'grab'])->whereNumber('bedId')->name('beds.grab');
            Route::post('/beds/{bedId}/confirm', [BedController::class, 'confirm'])->whereNumber('bedId')->name('beds.confirm');
            Route::post('/beds/{bedId}/release', [BedController::class, 'release'])->whereNumber('bedId')->name('beds.release');
        });
    });
