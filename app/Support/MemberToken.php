<?php

namespace App\Support;

use App\Models\Trip;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Everything about the "no passwords" login token in one small place.
 *
 * How it works (compare: a JWT in FastAPI, but simpler):
 *  1. When someone creates or joins a trip we make a long random token.
 *  2. The browser gets the token in a cookie. The database keeps only its SHA-256 hash.
 *  3. On every request the middleware hashes the cookie value and looks the member up.
 * A stolen database therefore contains no usable tokens. SHA-256 (not bcrypt) is fine here because
 * the token is 40 random characters, not a guessable human password.
 */
class MemberToken
{
    public static function generate(): string
    {
        return Str::random(40);
    }

    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    // One cookie per trip, so being in two trips at once never mixes the logins up.
    public static function cookieName(string $joinCode): string
    {
        return 'lobby_'.strtolower($joinCode);
    }

    public static function cookie(Trip $trip, string $plainToken): Cookie
    {
        return cookie(
            name: self::cookieName($trip->join_code),
            value: $plainToken,
            minutes: 60 * 24 * 30,
            // The browser only sends this cookie for this trip's pages.
            path: '/t/'.$trip->join_code,
            // Secure flag comes from .env: false on plain HTTP (phones would drop it otherwise).
            secure: (bool) config('session.secure'),
            // HttpOnly: JavaScript cannot read the cookie, so an XSS bug cannot steal it.
            httpOnly: true,
            // Lax: the cookie is not sent on requests triggered by other websites (basic CSRF defence).
            sameSite: 'lax',
        );
    }
}
