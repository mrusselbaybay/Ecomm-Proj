<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;

class RespondToReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'seller';
    }

    public function rules(): array
    {
        return [
            'response' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    /**
     * Defense-in-depth normalization before validation runs. The Vue side
     * only ever renders this text with `{{ }}` (never v-html), so there's
     * no actual render-time XSS vector — but stripping tags here means the
     * stored value can never accidentally end up somewhere that DOES
     * render raw HTML later, and collapses copy-pasted whitespace/control
     * characters into something clean.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('response') || !is_string($this->input('response'))) {
            return;
        }

        $clean = strip_tags($this->input('response'));
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $clean);
        $clean = trim(preg_replace('/[ \t]+/', ' ', $clean));

        $this->merge(['response' => $clean]);
    }
}