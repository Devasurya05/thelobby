<?php

use App\Http\Middleware\AdminOnly;
use App\Http\Middleware\ResolveMember;
use App\Support\ErrorRecorder;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A short name we can put on routes: ->middleware('trip.member')
        $middleware->alias(['trip.member' => ResolveMember::class, 'admin' => AdminOnly::class]);

        // Laravel sorts "important" middleware (like the rate limiter) to run first, no matter the order
        // written on the route. Our per-person rate limit needs to know WHO is asking, so the login
        // middleware must run before the rate limiter.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ResolveMember::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every unexpected error is also saved in the error_logs table (the log file gets it anyway).
        $exceptions->report(function (Throwable $e): void {
            ErrorRecorder::record($e);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
