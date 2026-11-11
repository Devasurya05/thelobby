<?php

namespace App\Services;

/**
 * What BedService hands back: did it work, a message for the person, and an HTTP status.
 *
 * Why not throw an exception on failure? A thrown exception rolls the database transaction back,
 * and we WANT the "grab_rejected" audit entry to be saved even though the grab failed.
 */
final class BedResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly int $status,
    ) {}

    public static function ok(string $message = ''): self
    {
        return new self(true, $message, 200);
    }

    public static function fail(string $message, int $status = 409): self
    {
        return new self(false, $message, $status);
    }
}
