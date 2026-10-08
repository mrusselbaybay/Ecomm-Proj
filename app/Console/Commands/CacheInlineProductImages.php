<?php

namespace App\Console\Commands;

use App\Http\Controllers\ProductImageController;
use App\Models\Product;
use App\Support\ProductImage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Pre-fills the image endpoint's file cache (ProductImageController) with
 * every buyer-visible product photo stored inline as a data: URL, in the
 * original size and the card size.
 *
 * Reading one such photo from Supabase can take tens of seconds over a slow
 * link; here it happens once, from the CLI (no web time limit), instead of
 * in a buyer's first request. Safe to re-run: cached entries are reused,
 * and an edited product (new updated_at) is fetched again.
 */
#[Signature('catalog:cache-inline-images')]
#[Description('Cache inline (base64) product photos as image files for the image endpoint')]
class CacheInlineProductImages extends Command
{
    public function handle(): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->warn('Only needed on PostgreSQL (the catalogue database).');

            return self::SUCCESS;
        }

        // Which products have inline photos, and where — decided in the
        // database, so the photos themselves aren't downloaded here.
        $products = ProductImage::selectWithLiteImages(Product::query()->visibleToBuyers(), ['products.id', 'products.updated_at'])
            ->whereRaw("products.images::text like '%data:%'")
            ->get();

        $count = 0;

        foreach ($products as $product) {
            foreach ((array) $product->images as $entry) {
                if (! is_array($entry) || ! isset($entry['inline'])) {
                    continue;
                }

                $started = microtime(true);
                $original = ProductImageController::cached($product->id, (int) $entry['inline']);
                ProductImageController::cached($product->id, (int) $entry['inline'], ProductImageController::CARD_WIDTH);

                $this->line(sprintf(
                    '%s #%d: %s (%.1fs)',
                    $product->id,
                    $entry['inline'],
                    $original ? number_format(strlen($original['bytes']) / 1024, 1).' kB' : 'not an image',
                    microtime(true) - $started,
                ));

                $count++;
            }
        }

        $this->info("Cached {$count} inline photo(s).");

        return self::SUCCESS;
    }
}
