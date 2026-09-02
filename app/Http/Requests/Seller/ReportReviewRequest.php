<?php

namespace App\Http\Requests\Seller;

use App\Models\ReviewReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/seller/feedback/{id}/report
 *
 * The review being reported is identified by the route id and scoped to
 * the authenticated seller in the controller — nothing about ownership
 * is trusted from the body.
 */
class ReportReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', Rule::in(ReviewReport::REASONS)],
            'details' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.in' => 'Choose a valid reason for reporting this review.',
        ];
    }

    /**
     * Same defense-in-depth normalization as RespondToReviewRequest:
     * strip tags / control characters and collapse whitespace so the
     * stored value is always plain text.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('details') || ! is_string($this->input('details'))) {
            return;
        }

        $clean = strip_tags($this->input('details'));
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean);
        $clean = trim(preg_replace('/[ \t]+/', ' ', $clean));

        $this->merge(['details' => $clean === '' ? null : $clean]);
    }
}
