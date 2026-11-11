<?php

namespace App\Http\Requests;

use App\Support\RoomListParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validation for "create a trip". Same job as a Pydantic model in FastAPI:
 * the controller only runs if all of these rules pass.
 *
 * Two ways to set a trip up (the `setup` field):
 *  - "manual": dates plus rooms. Rooms normally arrive as simple rows (a name and a number of beds each).
 *    The older one-line-per-room text is still accepted, so nothing that already posts it breaks.
 *  - "booking": only the names. Rooms and dates come from the first booking sent to the trip.
 */
class CreateTripRequest extends FormRequest
{
    /** @var array<int, array{name: string, beds: array<int, string>}>|null */
    private ?array $roomList = null;

    public function authorize(): bool
    {
        return true; // anyone may create a trip
    }

    public function fromBooking(): bool
    {
        return $this->input('setup') === 'booking';
    }

    public function rules(): array
    {
        $rules = [
            'trip_name' => ['required', 'string', 'max:100'],
            'organiser_name' => ['required', 'string', 'max:60'],
            'setup' => ['nullable', Rule::in(['manual', 'booking'])],
        ];

        if ($this->fromBooking()) {
            return $rules;
        }

        return $rules + [
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'rooms' => ['required'],
        ];
    }

    public function messages(): array
    {
        return [
            'rooms.required' => 'Add at least one room.',
        ];
    }

    // Extra check that needs the finished room list (counts and name lengths).
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->fromBooking() || $validator->errors()->has('rooms')) {
                    return;
                }

                $rooms = $this->buildRooms();
                $problem = $this->roomProblem ?? RoomListParser::check($rooms);
                if ($problem !== null) {
                    $validator->errors()->add('rooms', $problem);
                }
            },
        ];
    }

    /** The rooms to create, as names with bed labels. Only call after validation passed. */
    public function rooms(): array
    {
        return $this->buildRooms();
    }

    private ?string $roomProblem = null;

    private function buildRooms(): array
    {
        if ($this->roomList !== null) {
            return $this->roomList;
        }

        $input = $this->input('rooms');

        // Old format: one line per room, "Room 1: Window, Door".
        if (is_string($input)) {
            return $this->roomList = RoomListParser::parse($input);
        }

        // New format: rows with a name (optional) and a number of beds. Beds are called Bed 1, Bed 2...
        $rooms = [];
        foreach (array_values(is_array($input) ? $input : []) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $beds = filter_var($row['beds'] ?? null, FILTER_VALIDATE_INT);

            if ($beds === false || $beds < 1 || $beds > RoomListParser::MAX_BEDS_PER_ROOM) {
                $this->roomProblem ??= 'Each room needs between 1 and '.RoomListParser::MAX_BEDS_PER_ROOM.' beds.';

                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $rooms[] = [
                'name' => $name !== '' ? $name : 'Room '.($i + 1),
                'beds' => array_map(fn ($n) => 'Bed '.$n, range(1, $beds)),
            ];
        }

        return $this->roomList = $rooms;
    }
}
