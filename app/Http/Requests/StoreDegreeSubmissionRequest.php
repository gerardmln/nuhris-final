<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDegreeSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'degree_level' => ['required', 'in:undergraduate,masters,doctoral'],
            'degree_file' => ['required', 'file', 'mimes:pdf', 'max:5120'],
        ];
    }
}
