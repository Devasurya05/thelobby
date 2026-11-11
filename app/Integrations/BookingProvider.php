<?php

namespace App\Integrations;

/**
 * One booking site. To connect a new site you write one class that implements this, and add it to
 * Providers::CLASSES. Nothing else in The Lobby has to change: everything else only ever sees BookingData.
 *
 * Think of it like a travel plug adapter: each country's socket is different, but the charger you plug in is the same.
 */
interface BookingProvider
{
    /** Short stable word used in URLs and the database, for example "stay". */
    public function key(): string;

    /** Name shown to people, for example "DemoStay". */
    public function label(): string;

    /**
     * Turn the site's OWN format into the standard format. Throws InvalidBookingPayload for anything unreadable.
     * Never trust the input: it comes from another company's system.
     *
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): BookingData;

    /**
     * Ask the site for one booking by its reference ("pull"). Returns the site's own format, or null if there is none.
     * A real partner would be an HTTP call to their API; our pretend sites read their own table.
     *
     * @return array<string, mixed>|null
     */
    public function fetch(string $reference): ?array;
}
