<?php

namespace App\Http\Requests;

use App\Integrations\Providers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Import a booking" on the Plan page: which site, and the booking reference. (Who may import is checked in BookingService.) */
class ImportBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // trip.member and trip.organiser middleware already ran
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(array_keys(Providers::all()))],
            'reference' => ['required', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.in' => 'Choose one of the booking sites in the list.',
            'reference.required' => 'Type the booking reference, for example STAY-7K2P9Q.',
        ];
    }
}
