<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** On a pretend booking site: "Create a new trip for this booking" needs only the person's name. */
class NewTripFromBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the demo sites are public on purpose; they are rate limited
    }

    public function rules(): array
    {
        return [
            'organiser_name' => ['required', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return [
            'organiser_name.required' => 'Type your name. You become the organiser of the new trip.',
        ];
    }
}
