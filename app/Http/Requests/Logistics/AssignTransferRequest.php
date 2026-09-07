<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class AssignTransferRequest extends FormRequest
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
            'transfer_to_company_id' => ['required', 'uuid'],
        ];
    }
}
