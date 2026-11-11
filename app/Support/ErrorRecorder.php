<?php

namespace App\Support;

use App\Models\ErrorLog;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Copies every UNEXPECTED error into the `error_logs` table (the daily log file gets it as well).
 * Wired up in bootstrap/app.php. Laravel only calls this for errors that are really bugs: normal
 * 404s, validation failures, 403s and 429s are not "reported", so they do not land here.
 *
 * Rules:
 *  - Recording must NEVER make things worse: any failure in here is swallowed (the log file still has it).
 *  - Store no secrets: no cookies, tokens, request bodies or query strings. We keep the route
 *    PATTERN (t/{code}/beds/{bedId}/grab), not the real URL, so join codes never end up here.
 */
class ErrorRecorder
{
    public static function record(Throwable $e): void
    {
        try {
            // From the command line (artisan, scheduler) Laravel still makes a dummy request for "/",
            // but it never matched a route. A real web request that failed has a route (or is not on the CLI).
            $request = app()->bound('request') ? request() : null;
            if (app()->runningInConsole() && $request?->route() === null) {
                $request = null;
            }

            ErrorLog::create([
                'trip_id' => $request?->attributes->get('trip')?->id,
                'member_id' => $request?->attributes->get('member')?->id,
                'url' => $request ? mb_substr($request->route()?->uri() ?? $request->path(), 0, 500) : 'artisan',
                'method' => $request?->method() ?? 'CLI',
                'message' => mb_substr(self::safeMessage($e), 0, 5000),
                'exception_class' => $e::class,
                'file' => mb_substr(str_replace(base_path().DIRECTORY_SEPARATOR, '', $e->getFile()), 0, 500),
                'line' => $e->getLine(),
            ]);
        } catch (Throwable) {
            // Deliberately empty: if the database itself is the problem, the log file is our record.
        }
    }

    // A QueryException message contains the full SQL including the values (which could be names or
    // token hashes). The database driver's own message has the useful part without the values.
    private static function safeMessage(Throwable $e): string
    {
        if ($e instanceof QueryException && $e->getPrevious() !== null) {
            return $e->getPrevious()->getMessage();
        }

        return $e->getMessage();
    }
}
