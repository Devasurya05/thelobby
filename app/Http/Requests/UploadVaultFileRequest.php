<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/**
 * Checks the uploaded file BEFORE the controller runs: right type, not too big.
 * File::types() looks at the file's real content, not just its name, so renaming virus.exe to ticket.pdf does not work.
 * (Who may upload is checked separately in VaultService, against the database.)
 */
class UploadVaultFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the trip.member middleware already identified the person
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(config('lobby.vault.allowed_types'))->max(config('lobby.vault.max_kb')),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file first.',
            'file.uploaded' => 'The file did not upload. It may be bigger than '.(int) (config('lobby.vault.max_kb') / 1024).' MB.',
            'file.max' => 'That file is too big. The limit is '.(int) (config('lobby.vault.max_kb') / 1024).' MB.',
            'file.mimes' => 'Only PDF and photo files (JPG, PNG, WebP, HEIC) are allowed.',
            'file.extensions' => 'Only PDF and photo files (JPG, PNG, WebP, HEIC) are allowed.',
        ];
    }
}
