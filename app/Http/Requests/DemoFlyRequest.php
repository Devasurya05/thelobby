<?php

namespace App\Http\Requests;

use App\Integrations\DemoCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The DemoFly booking form (used to book and to change a booking). */
class DemoFlyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the demo sites are public on purpose; they are rate limited
    }

    public function rules(): array
    {
        $airports = array_keys(DemoCatalog::AIRPORTS);

        return [
            'from' => ['required', Rule::in($airports)],
            'to' => ['required', Rule::in($airports), 'different:from'],
            'date' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'time' => ['required', Rule::in(DemoCatalog::DEPARTURE_TIMES)],
            'has_return' => ['nullable', 'boolean'],
            'return_date' => ['nullable', 'required_if:has_return,1', 'date_format:Y-m-d', 'after_or_equal:date', 'before:2100-01-01'],
            'return_time' => ['nullable', 'required_if:has_return,1', Rule::in(DemoCatalog::DEPARTURE_TIMES)],
            'pax' => ['required', 'integer', 'between:1,9'],
        ];
    }

    public function messages(): array
    {
        return [
            'to.different' => 'Choose two different airports.',
            'return_date.required_if' => 'Choose the return date.',
            'return_date.after_or_equal' => 'The return cannot be before the outbound flight.',
            'return_time.required_if' => 'Choose the return time.',
            'pax.between' => 'Choose between 1 and 9 passengers.',
        ];
    }
}
