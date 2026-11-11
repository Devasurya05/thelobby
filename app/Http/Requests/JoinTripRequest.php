<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JoinTripRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // anyone with a valid code may try to join
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:8', 'alpha_num'],
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
