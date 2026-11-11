<?php

namespace App\Support;

/**
 * Turns the organiser's textarea into rooms and beds. One room per line:
 *
 *     Room 1: Window, Door
 *     Room 2: Bed 1, Bed 2, Bed 3
 *     Room 3
 *
 * A line with no colon is a room with a single bed called "Bed 1".
 * The create form no longer asks people to type this (it uses simple rows); this older text format is still
 * accepted by the server so anything that already sends it keeps working, and the limits below apply to both.
 */
class RoomListParser
{
    public const MAX_ROOMS = 60;

    public const MAX_BEDS_PER_ROOM = 10;

    public const MAX_BEDS_TOTAL = 200;

    public const MAX_NAME_LENGTH = 60;

    /** @return array<int, array{name: string, beds: array<int, string>}> */
    public static function parse(string $text): array
    {
        $rooms = [];

        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            [$name, $bedText] = array_pad(explode(':', $line, 2), 2, null);

            $beds = $bedText === null
                ? ['Bed 1']
                : array_values(array_filter(array_map('trim', explode(',', $bedText)), fn ($b) => $b !== ''));

            $rooms[] = ['name' => trim($name), 'beds' => $beds];
        }

        return $rooms;
    }

    /** Returns an error message for the form, or null if the list is fine. */
    public static function check(array $rooms): ?string
    {
        if ($rooms === []) {
            return 'Add at least one room.';
        }
        if (count($rooms) > self::MAX_ROOMS) {
            return 'Too many rooms (maximum '.self::MAX_ROOMS.').';
        }

        $totalBeds = 0;
        foreach ($rooms as $room) {
            if ($room['name'] === '' || mb_strlen($room['name']) > self::MAX_NAME_LENGTH) {
                return 'Every room needs a name of up to '.self::MAX_NAME_LENGTH.' characters.';
            }
            if ($room['beds'] === []) {
                return 'Room "'.$room['name'].'" has no beds listed.';
            }
            if (count($room['beds']) > self::MAX_BEDS_PER_ROOM) {
                return 'Room "'.$room['name'].'" has too many beds (maximum '.self::MAX_BEDS_PER_ROOM.').';
            }
            foreach ($room['beds'] as $bed) {
                if (mb_strlen($bed) > self::MAX_NAME_LENGTH) {
                    return 'Bed names can be at most '.self::MAX_NAME_LENGTH.' characters.';
                }
            }
            $totalBeds += count($room['beds']);
        }

        if ($totalBeds > self::MAX_BEDS_TOTAL) {
            return 'Too many beds in total (maximum '.self::MAX_BEDS_TOTAL.').';
        }

        return null;
    }
}
