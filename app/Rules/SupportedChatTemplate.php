<?php

namespace App\Rules;

use App\Services\ChatTemplateRenderer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SupportedChatTemplate implements ValidationRule
{
    public function __construct(private ChatTemplateRenderer $renderer) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $unsupported = $this->renderer->unsupportedPlaceholders((string) $value);

        if ($unsupported !== []) {
            $fail('The :attribute contains unsupported placeholders: '.implode(', ', $unsupported).'.');
        }
    }
}
