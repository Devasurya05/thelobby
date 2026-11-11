<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the owner-only pages. Not for trip members: even an organiser cannot open these.
 * You log in once with ADMIN_KEY (from .env) and the server remembers it in your session.
 */
class AdminOnly
{
    public const MIN_KEY_LENGTH = 16;

    public function handle(Request $request, Closure $next): Response
    {
        // No usable key configured = feature off. 404 (not 403) so the page does not even look like it exists.
        abort_unless(strlen(config('lobby.admin_key')) >= self::MIN_KEY_LENGTH, 404);

        if (! $request->session()->get('lobby_admin')) {
            return redirect()->route('admin.login');
        }

        return $next($request);
    }
}
