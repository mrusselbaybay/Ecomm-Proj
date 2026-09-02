<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCourierApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resume' => ['required', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'license' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'cover_note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'resume.required' => 'Please attach your resume before applying.',
            'resume.mimes' => 'Resume must be a PDF, DOC, or DOCX file.',
            'resume.max' => 'Resume must not be larger than 5MB.',
            'license.required' => "Please attach your driver's license before applying.",
            'license.mimes' => "Driver's license must be a PDF, JPG, or PNG file.",
            'license.max' => "Driver's license must not be larger than 5MB.",
        ];
    }
}