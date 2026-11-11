<?php

namespace App\Integrations;

/**
 * The list of connected booking sites, and the signing rules shared by every webhook.
 *
 * A webhook is just another program calling a URL on our site. Anyone can call a URL, so each message
 * carries a signature: an HMAC-SHA256 of "timestamp.body" made with a secret that only we and that
 * partner know. We recompute it; if it does not match, the message is thrown away. The timestamp is
 * part of what is signed, so an old message cannot be replayed later.
 */
final class Providers
{
    /** Add a new booking site here and nowhere else. */
    private const CLASSES = [
        'stay' => DemoStayProvider::class,
        'fly' => DemoFlyProvider::class,
    ];

    /** @return array<string, BookingProvider> */
    public static function all(): array
    {
        $all = [];
        foreach (self::CLASSES as $key => $class) {
            $all[$key] = new $class;
        }

        return $all;
    }

    public static function find(string $key): ?BookingProvider
    {
        return isset(self::CLASSES[$key]) ? new (self::CLASSES[$key]) : null;
    }

    /**
     * The shared secret for one partner. A real partner's secret goes in .env (PARTNER_<NAME>_SECRET).
     * For our own pretend sites, if none is set, it is derived from the app's key, so there is no extra
     * secret to create or leak. Empty means "not configured": every message is then refused.
     */
    public static function secret(string $key): string
    {
        $configured = config("lobby.partners.$key.secret");
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $appKey = (string) config('app.key');

        return $appKey === '' ? '' : hash_hmac('sha256', 'lobby-partner-secret:'.$key, $appKey);
    }

    public static function sign(string $key, int $timestamp, string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, self::secret($key));
    }

    /** True only for a fresh message signed with the partner's secret. Compares in constant time. */
    public static function verify(string $key, ?string $timestamp, string $body, ?string $signature): bool
    {
        $secret = self::secret($key);
        if ($secret === '' || $timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > (int) config('lobby.webhook.max_age_seconds')) {
            return false;
        }

        return hash_equals(self::sign($key, (int) $timestamp, $body), $signature);
    }

    /** Separate check so the caller can tell "too old" from "wrong signature". */
    public static function isFresh(?string $timestamp): bool
    {
        return $timestamp !== null && ctype_digit($timestamp)
            && abs(time() - (int) $timestamp) <= (int) config('lobby.webhook.max_age_seconds');
    }
}
