<?php

namespace App\Http\Requests;

use App\Integrations\DemoCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The DemoStay booking form (used to book and to change a booking). */
class DemoStayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the demo sites are public on purpose; they are rate limited
    }

    public function rules(): array
    {
        return [
            'hotel' => ['required', Rule::in(array_keys(DemoCatalog::HOTELS))],
            'room_type' => ['required', Rule::in(DemoCatalog::ROOM_TYPES)],
            'check_in' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in', 'before:2100-01-01'],
            'rooms' => ['required', 'integer', 'between:1,6'],
            'guests' => ['required', 'integer', 'between:1,4'],
        ];
    }

    public function messages(): array
    {
        return [
            'check_out.after' => 'Check-out has to be after check-in.',
            'rooms.between' => 'Choose between 1 and 6 rooms.',
            'guests.between' => 'Choose between 1 and 4 guests per room.',
        ];
    }
}
