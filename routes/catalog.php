<?php

use App\Http\Controllers\CatalogController;
use Illuminate\Support\Facades\Route;

/**
 * Included from routes/web.php.
 *
 * Public, unauthenticated read-only product/category browsing for the
 * BuyTheWay marketing homepage (resources/js/home). Deliberately has no
 * 'supabase.auth' middleware — a visitor who hasn't signed in yet still
 * needs to see products before buying any. See CatalogController's
 * class docblock for why this is separate from the seller-scoped
 * /api/seller/products routes in routes/seller.php.
 */
Route::prefix('api/catalog')->name('api.catalog.')->group(function () {
    Route::get('/categories', [CatalogController::class, 'categories'])->name('categories');
    Route::get('/products', [CatalogController::class, 'products'])->name('products.index');
    Route::get('/products/{id}', [CatalogController::class, 'show'])->name('products.show');
});
