<?php

use App\Support\ProductImage;

it('keeps local public image paths relative to the current application host', function () {
    expect(ProductImage::normalize('/storage/product-images/seller/photo.jpg'))
        ->toBe('/storage/product-images/seller/photo.jpg');
});
