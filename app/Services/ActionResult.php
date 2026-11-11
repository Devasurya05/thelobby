<?php

namespace App\Services;

/**
 * What BedService and MemberService hand back: did it work, a message for the person, and an HTTP status.
 *
 * Why not throw an exception on failure? A thrown exception rolls the database transaction back,
 * and we WANT a "rejected" audit entry to be saved even though the grab failed.
 */
final class ActionResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly int $status,
        // Optional extra answer on success (the vault returns the 60-second link here).
        public readonly ?string $value = null,
    ) {}

    public static function ok(string $message = '', ?string $value = null): self
    {
        return new self(true, $message, 200, $value);
    }

    public static function fail(string $message, int $status = 409): self
    {
        return new self(false, $message, $status);
    }
}
