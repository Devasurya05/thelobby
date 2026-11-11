<?php

namespace App\Support;

use App\Models\ItineraryItem;
use App\Models\Trip;
use Carbon\Carbon;

/**
 * Builds a calendar file (.ics, the iCalendar format) that Apple, Google and Outlook calendars all open.
 *
 * Rules of the format we follow (RFC 5545):
 *  - lines end with CRLF (\r\n), and no line is longer than 75 bytes (longer ones are "folded":
 *    cut, then continued on the next line starting with one space)
 *  - in text, backslash, semicolon, comma and newline must be escaped
 * Escaping also stops injection: text with a line break in it cannot start a fake extra event.
 *
 * Times are written WITHOUT a time zone ("floating"), so 14:00 shows as 14:00 in whatever calendar opens it.
 * An event without a time is an all-day event.
 */
class IcsBuilder
{
    /** One calendar file with the whole trip (its dates) plus every plan item. */
    public static function forTrip(Trip $trip, iterable $items): string
    {
        $events = [[
            'uid' => 'trip-'.$trip->id.'@thelobby',
            'summary' => $trip->name,
            'all_day' => true,
            'start' => Carbon::parse($trip->starts_on->format('Y-m-d')),
            'end' => Carbon::parse($trip->ends_on->format('Y-m-d'))->addDay(),
            'location' => null,
            'description' => null,
        ]];

        foreach ($items as $item) {
            $events[] = self::eventFor($item);
        }

        return self::calendar($trip->name, $events);
    }

    /** A calendar file with a single plan item. */
    public static function forItem(Trip $trip, ItineraryItem $item): string
    {
        return self::calendar($trip->name, [self::eventFor($item)]);
    }

    /**
     * The same facts as an event array, also used for the "Add to Google Calendar" link.
     *
     * @return array{uid: string, summary: string, all_day: bool, start: Carbon, end: Carbon, location: ?string, description: ?string}
     */
    public static function eventFor(ItineraryItem $item): array
    {
        return [
            'uid' => 'item-'.$item->id.'-trip-'.$item->trip_id.'@thelobby',
            'summary' => $item->title,
            'all_day' => $item->isAllDay(),
            'start' => $item->startsAt(),
            'end' => $item->endsAt(),
            'location' => implode(', ', array_filter([$item->place, $item->address])) ?: null,
            'description' => $item->notes,
        ];
    }

    /** @param  array<int, array<string, mixed>>  $events */
    private static function calendar(string $name, array $events): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//The Lobby//Trip plan//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($name),
        ];

        $stamp = gmdate('Ymd\THis\Z');

        foreach ($events as $e) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$e['uid'];
            $lines[] = 'DTSTAMP:'.$stamp;
            if ($e['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$e['start']->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$e['end']->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$e['start']->format('Ymd\THis');
                $lines[] = 'DTEND:'.$e['end']->format('Ymd\THis');
            }
            $lines[] = 'SUMMARY:'.self::escape($e['summary']);
            if ($e['location']) {
                $lines[] = 'LOCATION:'.self::escape($e['location']);
            }
            if ($e['description']) {
                $lines[] = 'DESCRIPTION:'.self::escape($e['description']);
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'fold'], $lines))."\r\n";
    }

    /** Backslash first (so we do not double-escape our own backslashes), then ; , and newlines. */
    private static function escape(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);

        return $text;
    }

    /** Cut a line into pieces of at most 75 bytes without splitting a multi-byte character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];
        $current = '';
        $limit = 75;

        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $parts[] = $current;
                $current = '';
                $limit = 74; // continuation lines start with one space, which counts too
            }
            $current .= $char;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
