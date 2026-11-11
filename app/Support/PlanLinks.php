<?php

namespace App\Support;

use App\Models\ItineraryItem;
use App\Models\Trip;

/**
 * Plain links to other apps. No API keys, nothing is sent anywhere until a person taps one.
 * They open Google Maps and Google Calendar (on a phone these open the installed app).
 */
class PlanLinks
{
    /** "Open in maps" for a place and/or address; null when there is nothing to look up. */
    public static function map(?string $place, ?string $address): ?string
    {
        $query = implode(', ', array_filter([trim((string) $place), trim((string) $address)]));

        return $query === '' ? null : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($query);
    }

    /** "Add to Google Calendar" for one plan item. */
    public static function googleCalendar(ItineraryItem $item): string
    {
        $e = IcsBuilder::eventFor($item);

        return self::google($e['summary'], $e['all_day'], $e['start'], $e['end'], $e['location'], $e['description']);
    }

    /** "Add to Google Calendar" for the trip's own dates. */
    public static function googleCalendarForTrip(Trip $trip): string
    {
        return self::google(
            $trip->name,
            true,
            $trip->starts_on->copy()->startOfDay(),
            $trip->ends_on->copy()->startOfDay()->addDay(),
            null,
            null,
        );
    }

    private static function google(string $title, bool $allDay, $start, $end, ?string $location, ?string $details): string
    {
        $format = $allDay ? 'Ymd' : 'Ymd\THis';

        $query = array_filter([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $start->format($format).'/'.$end->format($format),
            'location' => $location,
            'details' => $details,
        ], fn ($v) => $v !== null && $v !== '');

        return 'https://calendar.google.com/calendar/render?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
