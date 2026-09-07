<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class AssignParcelRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->role === 'logistics';
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            // Optional on the first assignment ("send a courier to collect
            // this") — a parcel isn't committed to a delivery area until
            // after it's been picked up, since it might be routed to
            // another company entirely. ParcelAssignmentController::assign
            // enforces it for the post-pickup delivery dispatch.
            'delivery_area_id' => ['nullable', 'uuid'],
            'rider_profile_id' => ['required', 'uuid'],
        ];
    }
}
