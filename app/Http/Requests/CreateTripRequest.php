<?php

namespace App\Http\Requests;

use App\Support\RoomListParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validation for "create a trip". Same job as a Pydantic model in FastAPI:
 * the controller only runs if all of these rules pass.
 */
class CreateTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // anyone may create a trip
    }

    public function rules(): array
    {
        return [
            'trip_name' => ['required', 'string', 'max:100'],
            'organiser_name' => ['required', 'string', 'max:60'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'rooms' => ['required', 'string', 'max:5000'],
        ];
    }

    // Extra check that needs the parsed room list (counts and name lengths).
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->has('rooms')) {
                    return;
                }
                $problem = RoomListParser::check(RoomListParser::parse($this->input('rooms')));
                if ($problem !== null) {
                    $validator->errors()->add('rooms', $problem);
                }
            },
        ];
    }

    /** The validated textarea, turned into rooms and beds. */
    public function rooms(): array
    {
        return RoomListParser::parse($this->validated('rooms'));
    }
}
