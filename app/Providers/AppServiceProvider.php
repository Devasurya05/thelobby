<?php

namespace App\Providers;

use App\Support\ThrottledResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Grab / confirm / release: 30 taps per minute per PERSON.
        // Keyed by member (not by IP) because friends on the same Wi-Fi share one IP address.
        RateLimiter::for('lobby-actions', function (Request $request) {
            $member = $request->attributes->get('member');

            return Limit::perMinute(30)
                ->by($member ? 'member:'.$member->id : 'ip:'.$request->ip())
                // Called when the limit is hit: logs it once a minute and answers 429.
                ->response(fn (Request $request, array $headers) => ThrottledResponse::make($request, $headers));
        });

        // Vault: upload, open, delete, end trip. 20 per minute per person (each upload also costs S3 traffic).
        RateLimiter::for('vault-actions', function (Request $request) {
            $member = $request->attributes->get('member');

            return Limit::perMinute(20)
                ->by('vault:'.($member ? 'member:'.$member->id : 'ip:'.$request->ip()))
                ->response(fn (Request $request, array $headers) => ThrottledResponse::make($request, $headers, 20));
        });
    }
}
