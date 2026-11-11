<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For organiser-only pages inside a trip (members, audit log). Runs AFTER ResolveMember,
 * which has already put the logged-in member on the request.
 * (MemberService checks the same rule again for actions: the page check is for tidiness,
 * the service check is the real lock.)
 */
class RequireOrganiser
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->attributes->get('member')?->isOrganiser(), 403, 'Only the organiser can open this page.');

        return $next($request);
    }
}
