<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductThumbnailTest extends TestCase
{
    public function test_product_thumbnails_use_a_same_origin_url_for_local_images(): void
    {
        config()->set('filesystems.disks.public.url', 'http://127.0.0.1:8000/storage');

        $image = (object) [
            'is_primary' => true,
            'file_path' => 'sellers/1/products/chair.jpg',
            'alt_text' => 'Chair',
        ];
        $item = (object) [
            'product' => (object) ['images' => collect([$image])],
            'product_name' => 'Chair',
        ];

        $this->blade('<x-shared.product-thumbnail :item="$item" />', compact('item'))
            ->assertSee('src="/storage/sellers/1/products/chair.jpg"', false)
            ->assertDontSee('127.0.0.1:8000');
    }
}
