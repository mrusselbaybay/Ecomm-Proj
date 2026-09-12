<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadMessageAttachmentRequest extends FormRequest
{
    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'mov'];

    private const IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    private const VIDEO_MAX_BYTES = 50 * 1024 * 1024;

    public function authorize(): bool
    {
        return in_array($this->user()?->getAttribute('role'), ['buyer', 'seller', 'logistics'], true);
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
                File::types(['jpg', 'jpeg', 'png', 'webp', 'pdf', ...self::VIDEO_EXTENSIONS]),
                'extensions:jpg,jpeg,png,webp,pdf,'.implode(',', self::VIDEO_EXTENSIONS),
                function ($attribute, $value, $fail) {
                    $extension = strtolower($value->extension() ?: $value->getClientOriginalExtension() ?: '');
                    $isVideo = in_array($extension, self::VIDEO_EXTENSIONS, true);
                    $max = $isVideo ? self::VIDEO_MAX_BYTES : self::IMAGE_MAX_BYTES;

                    if ($value->getSize() > $max) {
                        $fail($isVideo ? 'Videos must be 50MB or smaller.' : 'Files must be 10MB or smaller.');
                    }
                },
            ],
        ];
    }
}
