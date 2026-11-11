<?php

namespace App\Console\Commands;

use App\Integrations\Providers;
use App\Models\PartnerBooking;
use App\Services\PartnerWebhookSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends a pretend booking to a trip as a REAL signed HTTP request, the way an actual booking site would.
 * (The buttons on the pretend sites skip the network hop; this command shows the public address works.)
 *
 *   php artisan demo:webhook <trip-code> <booking-reference> [--url=https://your-site]
 */
class DemoWebhook extends Command
{
    protected $signature = 'demo:webhook {trip_code : the 8-character code of the trip} {reference : for example STAY-7K2P9Q} {--url= : site address, default APP_URL}';

    protected $description = 'Send a pretend booking to a trip as a real signed HTTP webhook (demo)';

    public function handle(): int
    {
        if (! config('lobby.demo_partners')) {
            $this->error('The demo booking sites are switched off (DEMO_PARTNERS=false).');

            return self::FAILURE;
        }

        $booking = PartnerBooking::where('reference', strtoupper($this->argument('reference')))->first();
        if ($booking === null) {
            $this->error('No pretend booking with that reference. Make one at /demo first.');

            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
        $body = PartnerWebhookSender::body($booking->payload, strtoupper($this->argument('trip_code')));
        $timestamp = time();

        $this->line('POST '.$base.'/webhooks/'.$booking->provider);

        try {
            $response = Http::withHeaders([
                'X-Lobby-Timestamp' => (string) $timestamp,
                'X-Lobby-Signature' => Providers::sign($booking->provider, $timestamp, $body),
            ])->withBody($body, 'application/json')->timeout(15)->post($base.'/webhooks/'.$booking->provider);
        } catch (Throwable $e) {
            $this->error('Could not reach the site: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('HTTP '.$response->status().' '.$response->body());

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
