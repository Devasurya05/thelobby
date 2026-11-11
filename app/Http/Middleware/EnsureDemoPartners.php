<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The pretend booking sites exist only while DEMO_PARTNERS is on; otherwise /demo does not exist (404). */
class EnsureDemoPartners
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('lobby.demo_partners'), 404);

        return $next($request);
    }
}
