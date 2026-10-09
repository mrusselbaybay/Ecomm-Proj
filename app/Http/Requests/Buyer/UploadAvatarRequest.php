<?php

namespace App\Http\Requests\Buyer;

use App\Support\Avatar;
use Illuminate\Foundation\Http\FormRequest;

class UploadAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'buyer';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'avatar' => [
                'required',
                'file',
                'mimetypes:'.implode(',', Avatar::MIMES),
                'max:'.Avatar::MAX_KILOBYTES,
                'dimensions:min_width='.Avatar::MIN_SIZE.',min_height='.Avatar::MIN_SIZE.',max_width='.Avatar::MAX_SIZE.',max_height='.Avatar::MAX_SIZE,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'avatar.required' => 'Choose a photo to upload.',
            'avatar.mimetypes' => 'Use a JPEG, PNG or WebP image.',
            'avatar.max' => 'The photo must be 2 MB or smaller.',
            'avatar.dimensions' => 'Use a photo at least '.Avatar::MIN_SIZE.' × '.Avatar::MIN_SIZE.' pixels.',
        ];
    }
}
