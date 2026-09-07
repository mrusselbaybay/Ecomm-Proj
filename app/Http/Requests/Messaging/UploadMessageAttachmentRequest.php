<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadMessageAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->getAttribute('role'), ['buyer', 'seller'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf'])->max('10mb'),
                'extensions:jpg,jpeg,png,webp,pdf',
            ],
        ];
    }
}
