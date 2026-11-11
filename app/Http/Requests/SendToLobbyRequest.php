<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** On a pretend booking site: the trip code to send a booking to. */
class SendToLobbyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the demo sites are public on purpose; they are rate limited
    }

    public function rules(): array
    {
        return [
            'trip_code' => ['required', 'string', 'regex:/^[A-Za-z0-9]{8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'trip_code.required' => 'Type the 8-character trip code.',
            'trip_code.regex' => 'A trip code has exactly 8 letters and digits.',
        ];
    }
}
