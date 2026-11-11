<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BedController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VaultController;
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

        // The vault. Any member can look; the service decides who may upload. POST for everything that changes
        // something. Opening a file is a GET (it is just a redirect to a 60-second link) but is checked and audited.
        Route::get('/vault', [VaultController::class, 'index'])->name('vault.index');
        Route::middleware('throttle:vault-actions')->group(function () {
            Route::post('/vault', [VaultController::class, 'upload'])->name('vault.upload');
            Route::get('/vault/{fileId}/open', [VaultController::class, 'open'])->whereNumber('fileId')->name('vault.open');
        });

        // Organiser-only pages. `trip.organiser` runs after `trip.member` (which found out who you are).
        Route::middleware(['trip.organiser', 'throttle:vault-actions'])->group(function () {
            Route::post('/vault/{fileId}/delete', [VaultController::class, 'delete'])->whereNumber('fileId')->name('vault.delete');
            Route::post('/end-trip', [VaultController::class, 'endTrip'])->name('vault.end');
        });

        Route::middleware(['trip.organiser', 'throttle:30,1'])->group(function () {
            Route::get('/members', [MemberController::class, 'index'])->name('members.index');
            Route::post('/members/{memberId}/remove', [MemberController::class, 'remove'])->whereNumber('memberId')->name('members.remove');
            Route::post('/members/{memberId}/role', [MemberController::class, 'role'])->whereNumber('memberId')->name('members.role');
            Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        });

        // Bed actions. POST (never GET) so they can't be triggered by a link or an image tag.
        Route::middleware('throttle:lobby-actions')->group(function () {
            Route::post('/beds/{bedId}/grab', [BedController::class, 'grab'])->whereNumber('bedId')->name('beds.grab');
            Route::post('/beds/{bedId}/confirm', [BedController::class, 'confirm'])->whereNumber('bedId')->name('beds.confirm');
            Route::post('/beds/{bedId}/release', [BedController::class, 'release'])->whereNumber('bedId')->name('beds.release');
        });
    });
