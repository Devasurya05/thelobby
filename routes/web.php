<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\BedController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DemoFlyController;
use App\Http\Controllers\DemoStayController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ItineraryController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\LobbyController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\VaultController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\EnsureDemoPartners;
use Illuminate\Support\Facades\Route;

// Trip codes are exactly 8 letters/digits; anything else is a 404 before our code even runs.
$code = '[A-Za-z0-9]{8}';

// --- Public pages (no login) -------------------------------------------------------------
Route::get('/', [HomeController::class, 'index'])->name('home');

// throttle:10,1,name = at most 10 requests per minute per IP, with its OWN counter called "name". Without a name,
// every limit like this shares one counter per visitor, so creating trips would use up the join limit.
Route::post('/trips', [TripController::class, 'store'])->middleware('throttle:10,1,trips-create')->name('trips.store');
Route::get('/join/{code?}', [JoinController::class, 'show'])->where('code', $code)->name('join.show');
Route::post('/join', [JoinController::class, 'store'])->middleware('throttle:10,1,join')->name('join.store');

// --- Webhooks: booking sites call this with a SIGNED message (no browser, no cookie, no CSRF token) ---
// The signature check is the lock. 120 a minute per address is far more than a real partner would send.
Route::post('/webhooks/{provider}', [WebhookController::class, 'receive'])
    ->where('provider', '[a-z]{2,12}')->middleware('throttle:120,1,webhook')->name('webhooks.receive');

// --- The two PRETEND booking sites (DemoStay, DemoFly). They exist so the booking connection can be shown. ---
// Open to anyone, so: rate limited, a cap on stored bookings, and the whole group can be switched off (DEMO_PARTNERS=false).
Route::middleware([EnsureDemoPartners::class, 'throttle:60,1,demo'])->prefix('demo')->group(function () {
    Route::get('/', [DemoController::class, 'index'])->name('demo.index');

    Route::get('/stay', [DemoStayController::class, 'create'])->name('demo.stay.create');
    Route::post('/stay', [DemoStayController::class, 'store'])->middleware('throttle:10,1,demo-book')->name('demo.stay.store');
    Route::get('/stay/{reference}', [DemoStayController::class, 'show'])->where('reference', 'STAY-[A-Z0-9]{6}')->name('demo.stay.show');
    Route::post('/stay/{reference}/new-trip', [DemoStayController::class, 'newTrip'])->middleware('throttle:10,1,demo-send')->where('reference', 'STAY-[A-Z0-9]{6}')->name('demo.stay.newtrip');
    Route::post('/stay/{reference}/send', [DemoStayController::class, 'send'])->middleware('throttle:10,1,demo-send')->where('reference', 'STAY-[A-Z0-9]{6}')->name('demo.stay.send');
    Route::post('/stay/{reference}/change', [DemoStayController::class, 'change'])->where('reference', 'STAY-[A-Z0-9]{6}')->name('demo.stay.change');
    Route::post('/stay/{reference}/cancel', [DemoStayController::class, 'cancel'])->where('reference', 'STAY-[A-Z0-9]{6}')->name('demo.stay.cancel');

    Route::get('/fly', [DemoFlyController::class, 'create'])->name('demo.fly.create');
    Route::post('/fly', [DemoFlyController::class, 'store'])->middleware('throttle:10,1,demo-book')->name('demo.fly.store');
    Route::get('/fly/{reference}', [DemoFlyController::class, 'show'])->where('reference', 'FLY-[A-Z0-9]{6}')->name('demo.fly.show');
    Route::post('/fly/{reference}/new-trip', [DemoFlyController::class, 'newTrip'])->middleware('throttle:10,1,demo-send')->where('reference', 'FLY-[A-Z0-9]{6}')->name('demo.fly.newtrip');
    Route::post('/fly/{reference}/send', [DemoFlyController::class, 'send'])->middleware('throttle:10,1,demo-send')->where('reference', 'FLY-[A-Z0-9]{6}')->name('demo.fly.send');
    Route::post('/fly/{reference}/change', [DemoFlyController::class, 'change'])->where('reference', 'FLY-[A-Z0-9]{6}')->name('demo.fly.change');
    Route::post('/fly/{reference}/cancel', [DemoFlyController::class, 'cancel'])->where('reference', 'FLY-[A-Z0-9]{6}')->name('demo.fly.cancel');
});

// --- Owner-only pages (ADMIN_KEY in .env). Login is limited to 5 tries per minute per IP. ---
Route::get('/admin/login', [AdminController::class, 'loginForm'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->middleware('throttle:5,1,admin-login');
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

        // The plan (stays, flights, tickets) and its calendar files. Any member can read and download; only the
        // organiser can add or remove items (those routes are in the organiser group further down).
        Route::middleware('throttle:60,1,plan-read')->group(function () {
            Route::get('/plan', [ItineraryController::class, 'index'])->name('plan.index');
            Route::get('/calendar.ics', [ItineraryController::class, 'calendar'])->name('plan.calendar');
            Route::get('/plan/{itemId}.ics', [ItineraryController::class, 'item'])->whereNumber('itemId')->name('plan.item');
        });

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

        Route::middleware(['trip.organiser', 'throttle:30,1,organiser'])->group(function () {
            Route::post('/bookings/import', [BookingController::class, 'import'])->name('bookings.import');
            Route::post('/bookings/{bookingId}/accept', [BookingController::class, 'accept'])->whereNumber('bookingId')->name('bookings.accept');
            Route::post('/bookings/{bookingId}/dismiss', [BookingController::class, 'dismiss'])->whereNumber('bookingId')->name('bookings.dismiss');
            Route::post('/bookings/{bookingId}/remove', [BookingController::class, 'remove'])->whereNumber('bookingId')->name('bookings.remove');
            Route::post('/plan', [ItineraryController::class, 'store'])->name('plan.store');
            Route::post('/plan/{itemId}/delete', [ItineraryController::class, 'destroy'])->whereNumber('itemId')->name('plan.delete');
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
            Route::post('/beds/{bedId}/giveup', [BedController::class, 'giveUp'])->whereNumber('bedId')->name('beds.giveup');
        });
    });
