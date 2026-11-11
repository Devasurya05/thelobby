<?php

namespace App\Http\Controllers;

use App\Http\Middleware\AdminOnly;
use App\Models\ErrorLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Owner-only pages: log in with ADMIN_KEY, then read the recent errors. */
class AdminController extends Controller
{
    public function loginForm(): View
    {
        abort_unless(strlen(config('lobby.admin_key')) >= AdminOnly::MIN_KEY_LENGTH, 404);

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        abort_unless(strlen(config('lobby.admin_key')) >= AdminOnly::MIN_KEY_LENGTH, 404);

        $given = (string) $request->input('key', '');

        // hash_equals compares in constant time, so response timing cannot be used to guess the key.
        if (! hash_equals(config('lobby.admin_key'), $given)) {
            return back()->withErrors(['key' => 'That key is not right.']);
        }

        // New session id after logging in, so a session id planted beforehand is useless (session fixation).
        $request->session()->regenerate();
        $request->session()->put('lobby_admin', true);

        return redirect()->route('admin.errors');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('lobby_admin');
        $request->session()->regenerate();

        return redirect()->route('home');
    }

    public function errors(): View
    {
        return view('admin.errors', [
            // Newest first, capped: this page is for a quick look, the log files hold the rest.
            'logs' => ErrorLog::orderByDesc('id')->limit(100)->get(),
        ]);
    }
}
