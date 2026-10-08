<?php

/*
|--------------------------------------------------------------------------
| Inline product photos stay out of JSON responses
|--------------------------------------------------------------------------
|
| Seller uploads are stored as base64 data: URLs inside products.images.
| Shipping them with every list/search response is what made searches run
| past PHP's time limit, so responses link to GET
| /api/products/{id}/images/{n} instead, which serves the decoded (and,
| for cards, resized) file with the catalogue's visibility rule.
|
*/

function jpegDataUrl(int $width = 1000, int $height = 600): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));
    ob_start();
    imagejpeg($image, null, 90);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

it('never returns inline photos in product JSON', function () {
    $seller = makeSeller();
    $inline = makeProduct($seller, ['name' => 'Inline photo cat', 'images' => [['url' => jpegDataUrl()], ['url' => 'https://cdn.example.test/b.jpg']]]);

    foreach ([
        '/api/products?search=cat',
        '/api/products?search=cat&fields=card',
        "/api/products/{$inline->id}",
        '/api/search/suggestions?q=cat',
        '/api/products/related?search=nothing-matches-this-dog&category=Electronics+and+Gadgets',
    ] as $url) {
        expect($this->getJson($url)->assertOk()->getContent())->not->toContain('data:image');
    }

    $product = $this->getJson("/api/products/{$inline->id}")->json('data');

    expect($product['image'])->toStartWith("/api/products/{$inline->id}/images/0?v=")->toContain('&w=480');
    expect($product['images'][0])->toStartWith("/api/products/{$inline->id}/images/0?v=")->not->toContain('&w=');
    expect($product['images'][1])->toBe('https://cdn.example.test/b.jpg');
});

it('serves an inline photo, and a resized card copy, from the image endpoint', function () {
    $product = makeProduct(makeSeller(), ['images' => [['url' => jpegDataUrl(1000, 600)]]]);

    $original = $this->get("/api/products/{$product->id}/images/0")->assertOk();
    expect($original->headers->get('Content-Type'))->toBe('image/jpeg');
    expect($original->headers->get('Cache-Control'))->toContain('immutable');
    expect(getimagesizefromstring($original->getContent())[0])->toBe(1000);

    $card = $this->get("/api/products/{$product->id}/images/0?w=480")->assertOk();
    $size = getimagesizefromstring($card->getContent());
    expect($size[0])->toBe(480);
    expect($size[1])->toBe(288);
    expect(strlen($card->getContent()))->toBeLessThan(strlen($original->getContent()));
});

it('applies the catalogue visibility rule to photos', function () {
    $hidden = makeProduct(makeSeller(), ['status' => 'inactive', 'images' => [['url' => jpegDataUrl()]]]);
    $suspended = makeProduct(makeSeller(['account_status' => 'suspended']), ['images' => [['url' => jpegDataUrl()]]]);
    $linked = makeProduct(makeSeller(), ['images' => [['url' => 'https://cdn.example.test/a.jpg']]]);

    $this->get("/api/products/{$hidden->id}/images/0")->assertNotFound();
    $this->get("/api/products/{$suspended->id}/images/0")->assertNotFound();
    // Not an inline photo: nothing to serve (the JSON links it directly).
    $this->get("/api/products/{$linked->id}/images/0")->assertNotFound();
    $this->get("/api/products/{$linked->id}/images/7")->assertNotFound();
    $this->get('/api/products/not-a-uuid/images/0')->assertNotFound();
});

it('returns card fields without options and variants for fields=card', function () {
    makeProduct(makeSeller(), ['name' => 'Card cat']);

    $card = $this->getJson('/api/products?search=cat&fields=card')->assertOk()->json('data.0');
    $full = $this->getJson('/api/products?search=cat')->assertOk()->json('data.0');

    expect($card['options'])->toBeNull();
    expect($card['variants'])->toBeNull();
    expect($card)->toHaveKeys(['id', 'name', 'price', 'image', 'seller', 'hasVariants', 'stock']);
    expect($full['options'])->toBe([]);
    expect($full['variants'])->toBe([]);
    expect($card['seller'])->toBe($full['seller'])->toBe('Test Storefront');
});
