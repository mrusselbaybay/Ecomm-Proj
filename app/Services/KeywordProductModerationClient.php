<?php

namespace App\Services;

use App\Enums\AiModerationStatus;
use App\Models\Product;
use App\Support\CategoryFieldConfig;

/**
 * Zero-cost stand-in for ProductModerationClient (OpenAI), used by
 * ModerateProductJob when the OpenAI call fails (e.g. no billing on the
 * account — see the 429 "Too Many Requests" this project currently gets
 * back with no rate-limit headers, a project-has-no-tier response, not a
 * real rate limit). Approves a product only when its name contains a
 * keyword recognized for its own category/subcategory; everything else
 * falls through to human review, same as an OpenAI "needs review"
 * verdict would via ProductApprovalRouter.
 *
 * The keyword pool isn't hand-written here: it's drawn from
 * CategoryFieldConfig's own subcategory name, its select-field option
 * values (toy_type, food_type, accessory_type, ...) and its variant
 * option values (Flavor, Pet Type, ...) — vocabulary that's already
 * maintained for the product form, so it grows automatically as that
 * config grows instead of drifting out of sync with it.
 *
 * IMPORTANT: unlike OpenAI's moderation, this never looks at images, and
 * the category-match check below can't detect harmful content on its
 * own — it only confirms the listing's name looks like it belongs to
 * its claimed category. The prohibited-term check (below) is the actual
 * safety net: it scans name + description for known-bad terms and, if
 * one hits, forces human review regardless of category match or mode —
 * see ProductApprovalRouter::route(), which checks flagged_signals
 * before it ever looks at status/confidence. Both checks are lexicon
 * based, so neither catches a bad *image* or cleverly-worded text the
 * way OpenAI's moderation would. See ModerateProductJob for when this
 * class is used instead of OpenAI.
 */
class KeywordProductModerationClient
{
    /**
     * Generic words that show up across many categories' option lists
     * (Yes/No toggles, "Other"/"Custom" escape hatches, "All ...");
     * matching on these would approve almost anything, so they're
     * dropped from the keyword pool regardless of which category they
     * came from.
     */
    private const STOPWORDS = [
        'other', 'custom', 'none', 'all', 'any', 'yes', 'no',
        'required', 'type', 'other pets',
    ];

    /**
     * Terms that should never auto-approve regardless of category —
     * weapons, drugs, counterfeit-goods language, explicit content.
     * Deliberately not single common words with legitimate uses
     * (e.g. not "fake", which is a normal term in beauty listings like
     * "fake eyelashes" — every entry here is unambiguous on its own).
     * A starting set, not exhaustive: extend as needed, same pattern.
     */
    private const PROHIBITED_TERMS = [
        // Weapons / ammunition
        'firearm', 'handgun', 'pistol', 'revolver', 'shotgun', 'rifle',
        'ammunition', 'gunpowder', 'grenade', 'explosive', 'switchblade',
        'brass knuckles',
        // Drugs / controlled substances
        'cocaine', 'heroin', 'methamphetamine', 'fentanyl', 'ecstasy',
        'lsd', 'opioid', 'narcotic',
        // Counterfeit / illicit goods language
        'counterfeit', 'bootleg', 'knockoff', 'unauthorized replica',
        'stolen goods',
        // Explicit content
        'explicit content', 'pornographic',
    ];

    /**
     * @return array{status: string, confidence_score: float, flagged_signals: list<string>, reasoning: string}
     */
    public function moderate(Product $product): array
    {
        $text = $this->normalize((string) $product->name.' '.(string) $product->description);
        $blocked = null;

        foreach (self::PROHIBITED_TERMS as $term) {
            if (str_contains($text, $term)) {
                $blocked = $term;
                break;
            }
        }

        if ($blocked !== null) {
            return [
                'status' => AiModerationStatus::NeedsReview->value,
                'confidence_score' => 1.0,
                'flagged_signals' => ["prohibited_term:{$blocked}"],
                'reasoning' => sprintf(
                    'Keyword check: listing text matched a prohibited term ("%s") — routed for manual review.',
                    $blocked,
                ),
            ];
        }

        // A single normalized string, not a word list — sellers often
        // write names with no spaces ("CatFood", "PetBed123"), so this
        // checks substrings rather than requiring a whole-word match.
        $name = $this->normalize((string) $product->name);
        $keywords = $this->keywordsFor($product->category, $product->subcategory);
        $matched = null;

        foreach ($keywords as $keyword) {
            if (str_contains($name, $keyword)) {
                $matched = $keyword;
                break;
            }
        }

        if ($matched !== null) {
            return [
                'status' => AiModerationStatus::Approve->value,
                'confidence_score' => 1.0,
                'flagged_signals' => [],
                'reasoning' => sprintf(
                    'Keyword check: product name matched "%s", a recognized term for %s.',
                    $matched,
                    $this->categoryLabel($product->category, $product->subcategory),
                ),
            ];
        }

        return [
            'status' => AiModerationStatus::NeedsReview->value,
            'confidence_score' => 0.5,
            'flagged_signals' => [],
            'reasoning' => sprintf(
                'Keyword check: product name did not match any recognized term for %s — routed for manual review.',
                $this->categoryLabel($product->category, $product->subcategory),
            ),
        ];
    }

    private function categoryLabel(?string $category, ?string $subcategory): string
    {
        if (! $category) {
            return 'an unrecognized category';
        }

        return $subcategory ? "{$category} > {$subcategory}" : $category;
    }

    /** @return list<string> */
    private function keywordsFor(?string $category, ?string $subcategory): array
    {
        if (! $category) {
            return [];
        }

        $keywords = [
            ...$this->words($category),
            ...($subcategory ? $this->words($subcategory) : []),
        ];

        $template = CategoryFieldConfig::for($category, $subcategory);

        foreach ($template['specifications'] as $field) {
            if (($field['type'] ?? null) === 'select') {
                foreach ($field['options'] ?? [] as $option) {
                    $keywords = [...$keywords, ...$this->words((string) $option)];
                }
            }
        }

        foreach ($template['variant_options'] as $option) {
            foreach ($option['values'] ?? [] as $value) {
                $keywords = [...$keywords, ...$this->words((string) $value)];
            }
        }

        return array_values(array_unique(array_diff($keywords, self::STOPWORDS)));
    }

    /**
     * Lowercased whole words, punctuation stripped, words under 3
     * letters dropped (kills stray single letters like size options).
     * Used to build the keyword pool from CategoryFieldConfig's option
     * labels/values, which are properly spaced.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        $normalized = $this->normalize($text);
        $words = array_filter(explode(' ', trim($normalized)), fn ($word) => mb_strlen($word) >= 3);

        return array_values($words);
    }

    /**
     * Lowercase, non-alphanumeric runs collapsed to single spaces — kept
     * as one string (not split into words) so a keyword can match inside
     * a seller's un-spaced product name ("CatFood" contains "cat"/"food").
     */
    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($text)) ?? '');
    }
}
