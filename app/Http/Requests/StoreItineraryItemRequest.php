<?php

namespace App\Http\Requests;

use App\Models\ItineraryItem;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Checks the "Add to the plan" form before the controller runs. Nothing from the browser is trusted:
 * every field has a type and a length limit. (Who may add is checked separately in ItineraryService.)
 */
class StoreItineraryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the trip.member and trip.organiser middleware already identified the person
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(ItineraryItem::KINDS))],
            'title' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d', 'after:2000-01-01', 'before:2100-01-01'],
            'starts_time' => ['nullable', 'date_format:H:i'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on', 'before:2100-01-01'],
            'ends_time' => ['nullable', 'date_format:H:i'],
            'place' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'kind.in' => 'Choose one of the types in the list.',
            'title.required' => 'Give it a title, for example "Hotel Sunrise".',
            'title.max' => 'The title can be at most 120 characters.',
            'starts_on.required' => 'Choose the date it starts.',
            'starts_on.date_format' => 'Use the date picker for the start date.',
            'ends_on.after_or_equal' => 'The end date cannot be before the start date.',
            'starts_time.date_format' => 'Use the time picker for the start time.',
            'ends_time.date_format' => 'Use the time picker for the end time.',
        ];
    }

    /** One more rule that needs two fields together: with times, the end must come after the start. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->input('starts_time');
            $end = $this->input('ends_time');

            if ($start === null) {
                return; // an all-day item has no clock times to compare
            }

            $endDate = $this->input('ends_on') ?? $this->input('starts_on');
            $endTime = $end ?? ($this->input('ends_on') ? $start : null);

            if ($endTime !== null && Carbon::parse($endDate.' '.$endTime)->lte(Carbon::parse($this->input('starts_on').' '.$start))) {
                $v->errors()->add('ends_time', 'The end has to come after the start.');
            }
        });
    }
}
