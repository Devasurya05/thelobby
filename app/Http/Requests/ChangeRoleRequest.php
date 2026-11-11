<?php

namespace App\Http\Requests;

use App\Services\MemberService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeRoleRequest extends FormRequest
{
    // Real permission check happens in MemberService (organiser only); the route middleware already blocks others.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role' => ['required', 'string', Rule::in(MemberService::ASSIGNABLE_ROLES)]];
    }
}
