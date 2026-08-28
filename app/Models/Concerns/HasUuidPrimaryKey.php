<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * For models with a uuid primary key and $incrementing = false.
 *
 * The products/product_options/product_option_values/product_variants
 * tables generate `id` on the Postgres side via a `gen_random_uuid()`
 * column default (see the create-table migrations). That's fine for a
 * lone insert, but Eloquent only re-reads a DB-generated key via
 * `RETURNING` when the model is `incrementing()` — which a uuid PK
 * isn't — so after `Model::create()` the in-memory `$model->id` stays
 * null. Any relation call made off that model in the same request
 * (`$product->options()->create(...)`, `$option->values()->create(...)`,
 * etc.) then inserts its foreign key as null and fails the not-null
 * constraint.
 *
 * Assigning the id in PHP before insert sidesteps that entirely: the
 * model knows its own key immediately, with no round trip needed. The
 * DB column default is left in place as a harmless fallback for any
 * insert that bypasses Eloquent.
 */
trait HasUuidPrimaryKey
{
    public static function bootHasUuidPrimaryKey(): void
    {
        static::creating(function ($model) {
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = (string) Str::uuid();
            }
        });
    }
}