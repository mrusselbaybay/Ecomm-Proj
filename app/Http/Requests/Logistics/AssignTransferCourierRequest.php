<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class AssignTransferCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'logistics';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rider_profile_id' => ['required', 'uuid'],
        ];
    }
}
