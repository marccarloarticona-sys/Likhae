<?php

namespace Tests\Feature;

use App\Models\Buyer\Order;
use App\Models\Buyer\OrderItem;
use App\Models\Buyer\Review;
use App\Models\Admin\Notification;
use App\Models\Seller\Product;
use App\Models\Seller\SellerOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BuyerReviewSubmissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_text_accepts_500_characters_and_trims_outer_whitespace(): void
    {
        [$buyer, $order, $item] = $this->makeReviewableItem();
        $comment = '  '.str_repeat('a', 500).'  ';

        $this->actingAs($buyer)
            ->post(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => $comment])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(str_repeat('a', 500), $item->review()->value('comment'));
    }

    public function test_review_text_over_500_is_rejected_without_saving_a_review_and_is_preserved_as_input(): void
    {
        [$buyer, $order, $item] = $this->makeReviewableItem();
        $comment = str_repeat('x', 501);

        $this->actingAs($buyer)
            ->from(route('buyer.orders.show', $order))
            ->post(route('buyer.orders.review.store', $item), [
                'review_item_id' => $item->id,
                'rating' => 5,
                'comment' => $comment,
            ])
            ->assertRedirect(route('buyer.orders.show', $order))
            ->assertSessionHasErrors('comment')
            ->assertSessionHasInput('comment', $comment);

        $this->assertDatabaseMissing('reviews', ['order_item_id' => $item->id]);
    }

    public function test_only_the_owner_can_review_and_only_after_order_completion(): void
    {
        [$buyer, $order, $item] = $this->makeReviewableItem('PROCESSING');
        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => 'Too early'])
            ->assertConflict();
        $this->assertDatabaseMissing('reviews', ['order_item_id' => $item->id]);

        $order->update(['status' => 'COMPLETED']);
        $otherBuyer = User::factory()->create();
        $this->actingAs($otherBuyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => 'Not my order'])
            ->assertForbidden();
        $this->assertDatabaseMissing('reviews', ['order_item_id' => $item->id]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => 'Owner review'])
            ->assertOk();
        $this->actingAs($otherBuyer)
            ->postJson(route('buyer.orders.review.store', $item), ['comment' => 'Unauthorized edit'])
            ->assertForbidden();
        $this->assertDatabaseHas('reviews', ['order_item_id' => $item->id, 'comment' => 'Owner review']);
    }

    public function test_duplicate_submission_keeps_one_review_and_locks_stars_and_photos(): void
    {
        [$buyer, $order, $item, $product] = $this->makeReviewableItem();
        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => 'First'])
            ->assertOk();
        $reviewId = (int) $item->review()->value('id');
        $this->assertSame(1, Notification::query()
            ->where('type', 'SELLER_REVIEW_SUBMITTED')
            ->where('reference_type', 'SELLER_REVIEW')
            ->where('reference_id', $reviewId)
            ->count());

        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 3, 'comment' => 'Second'])
            ->assertConflict();

        $originalCreatedAt = $item->review()->firstOrFail()->created_at?->format('Y-m-d H:i:s');
        $updatedAt = now()->addMinute();
        \Carbon\Carbon::setTestNow($updatedAt);
        try {
            $this->actingAs($buyer)
                ->postJson(route('buyer.orders.review.store', $item), ['comment' => 'Updated text'])
                ->assertOk();
        } finally {
            \Carbon\Carbon::setTestNow();
        }

        $this->assertSame(1, Review::query()->where('order_item_id', $item->id)->count());
        $this->assertSame(1, Notification::query()
            ->where('type', 'SELLER_REVIEW_SUBMITTED')
            ->where('reference_type', 'SELLER_REVIEW')
            ->where('reference_id', $reviewId)
            ->count());
        $this->assertDatabaseHas('reviews', ['order_item_id' => $item->id, 'rating' => 5, 'comment' => 'Updated text']);
        $this->assertSame(1, $product->reviews()->count());
        $this->assertSame(5.0, round((float) $product->reviews()->avg('rating'), 1));
        $this->assertSame($originalCreatedAt, $item->review()->firstOrFail()->created_at?->format('Y-m-d H:i:s'));
        $this->assertSame($updatedAt->format('Y-m-d H:i:s'), $item->review()->firstOrFail()->updated_at?->format('Y-m-d H:i:s'));
    }

    public function test_review_text_can_be_edited_once_within_30_days_and_is_audited(): void
    {
        [$buyer, $order, $item] = $this->makeReviewableItem();
        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => 'Original review'])
            ->assertOk();
        $review = $item->review()->firstOrFail();

        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['comment' => 'First and only edit'])
            ->assertOk();

        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'comment' => 'First and only edit']);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $buyer->id,
            'event' => 'buyer.review.text.updated',
            'auditable_type' => Review::class,
            'auditable_id' => $review->id,
        ]);

        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['comment' => 'Second edit'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'comment' => 'First and only edit']);
    }

    public function test_review_text_cannot_be_edited_after_30_days_even_if_updated_at_was_refreshed(): void
    {
        [$buyer, $order, $item] = $this->makeReviewableItem();
        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['rating' => 5, 'comment' => 'Original review'])
            ->assertOk();
        $review = $item->review()->firstOrFail();
        $review->forceFill([
            'created_at' => now()->subDays(31),
            'updated_at' => now(),
        ])->save();

        $this->actingAs($buyer)
            ->postJson(route('buyer.orders.review.store', $item), ['comment' => 'Too late'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');
        $this->assertDatabaseHas('reviews', ['id' => $review->id, 'comment' => 'Original review']);
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'buyer.review.text.updated',
            'auditable_type' => Review::class,
            'auditable_id' => $review->id,
        ]);
    }

    public function test_purchase_review_form_displays_the_500_character_limit_and_counter(): void
    {
        [$buyer, $order] = $this->makeReviewableItem();

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('maxlength="500"', escape: false)
            ->assertSee('data-review-comment', escape: false)
            ->assertSee('data-review-comment-count', escape: false)
            ->assertSee('0 / 500');
    }

    public function test_up_to_five_valid_review_photos_are_stored_in_upload_order_and_the_sixth_is_rejected(): void
    {
        Storage::fake('public');
        [$buyer, $order, $item] = $this->makeReviewableItem();
        $files = collect(range(1, 5))
            ->map(fn (int $index): UploadedFile => UploadedFile::fake()->createWithContent('review-'.$index.'.png', $this->onePixelPng()))
            ->all();

        $this->actingAs($buyer)
            ->post(route('buyer.orders.review.store', $item), ['rating' => 4, 'images' => $files])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $review = $item->review()->firstOrFail();
        $photoUrls = app(\App\Services\ReviewImageService::class)->photoUrlsFor(collect([$review]))[$review->id] ?? [];
        $this->assertCount(5, $photoUrls);
        $paths = \App\Models\Admin\AuditLog::query()
            ->where('event', 'buyer.review.image.updated')
            ->where('auditable_id', $review->id)
            ->orderBy('id')
            ->pluck('new_values')
            ->map(fn (array $values): ?string => $values['image_path'] ?? null)
            ->filter()
            ->values();
        $this->assertCount(5, $paths);
        $this->assertSame($paths->unique()->count(), $paths->count());
        $sortOrder = \App\Models\Admin\AuditLog::query()
            ->where('event', 'buyer.review.image.updated')
            ->where('auditable_id', $review->id)
            ->orderBy('id')
            ->pluck('new_values')
            ->map(fn (array $values): ?int => $values['sort_order'] ?? null)
            ->values()
            ->all();
        $this->assertSame(range(0, 4), $sortOrder);
        foreach ($paths as $path) {
            Storage::disk('public')->assertExists($path);
        }

        [$sixthBuyer, $sixthOrder, $sixthItem] = $this->makeReviewableItem();
        $sixFiles = collect(range(1, 6))
            ->map(fn (int $index): UploadedFile => UploadedFile::fake()->createWithContent('extra-'.$index.'.png', $this->onePixelPng()))
            ->all();
        $this->actingAs($sixthBuyer)
            ->post(route('buyer.orders.review.store', $sixthItem), ['rating' => 4, 'images' => $sixFiles])
            ->assertSessionHasErrors('images');
        $this->assertDatabaseMissing('reviews', ['order_item_id' => $sixthItem->id]);
    }

    public function test_spoofed_and_oversized_review_photos_are_rejected(): void
    {
        Storage::fake('public');
        [$buyer, $order, $item] = $this->makeReviewableItem();
        $spoofed = UploadedFile::fake()->createWithContent('spoofed.jpg', 'not an image');

        $this->actingAs($buyer)
            ->post(route('buyer.orders.review.store', $item), ['rating' => 4, 'images' => [$spoofed]])
            ->assertSessionHasErrors('images.0');
        $this->assertDatabaseMissing('reviews', ['order_item_id' => $item->id]);

        $corrupt = UploadedFile::fake()->createWithContent('corrupt.png', substr($this->onePixelPng(), 0, 33));
        $this->actingAs($buyer)
            ->post(route('buyer.orders.review.store', $item), ['rating' => 4, 'images' => [$corrupt]])
            ->assertSessionHasErrors('images.0');
        $this->assertDatabaseMissing('reviews', ['order_item_id' => $item->id]);

        [$largeBuyer, $largeOrder, $largeItem] = $this->makeReviewableItem();
        $large = UploadedFile::fake()->create('large.png', 5121, 'image/png');
        $this->actingAs($largeBuyer)
            ->post(route('buyer.orders.review.store', $largeItem), ['rating' => 4, 'images' => [$large]])
            ->assertSessionHasErrors('images.0');
        $this->assertDatabaseMissing('reviews', ['order_item_id' => $largeItem->id]);
    }

    public function test_seller_response_is_visible_and_escaped_on_product_details(): void
    {
        [$buyer, $order, $item, $product, $seller] = $this->makeReviewableItem();
        $review = Review::query()->create([
            'order_item_id' => $item->id,
            'buyer_user_id' => $buyer->id,
            'rating' => 4,
            'comment' => 'A published review',
            'seller_reply' => '<script>alert(1)</script> Thank you for your feedback.',
            'seller_replied_at' => now()->setDate(2026, 10, 17),
            'status' => 'PUBLISHED',
        ]);
        [, , $hiddenItem] = $this->makeReviewableItem();
        Review::query()->create([
            'order_item_id' => $hiddenItem->id,
            'buyer_user_id' => $buyer->id,
            'rating' => 1,
            'comment' => 'Hidden review.',
            'status' => 'HIDDEN',
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.product-details', $product->slug))
            ->assertOk()
            ->assertSee('Seller Response')
            ->assertSee($seller->business_name)
            ->assertSee('A published review')
            ->assertSee('Oct 17, 2026')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
            ->assertDontSee('<script>alert(1)</script>', escape: false);

        $this->actingAs($buyer)
            ->get(route('buyer.orders.show', $order))
            ->assertOk()
            ->assertSee('Seller Response')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
            ->assertDontSee('<script>alert(1)</script>', escape: false);

        $this->followingRedirects()
            ->actingAs($buyer)
            ->get(route('buyer.account.reviews'))
            ->assertOk()
            ->assertSee('Your Product Reviews')
            ->assertSee('Seller Response')
            ->assertSee($product->name)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', escape: false)
            ->assertDontSee('<script>alert(1)</script>', escape: false);

        $this->assertSame(1, $product->reviews()->count());
        $this->assertSame(4.0, round((float) $product->reviews()->avg('rating'), 1));
    }

    /** @return array{User, Order, OrderItem, Product, \App\Models\Seller\SellerProfile} */
    private function makeReviewableItem(string $orderStatus = 'COMPLETED'): array
    {
        if (! User::query()->where('email', 'buyer@likhae.com')->exists()) {
            $this->seed(\Database\Seeders\DatabaseSeeder::class);
        }
        $buyer = User::query()->where('email', 'buyer@likhae.com')->firstOrFail();
        $product = Product::query()->with('variants')->where('slug', 'sample-handmade-tote')->firstOrFail();
        $seller = $product->sellerProfile;
        $variant = $product->variants->firstOrFail();
        $order = Order::query()->create([
            'order_number' => 'REVIEW-'.Str::uuid(),
            'buyer_user_id' => $buyer->id,
            'status' => $orderStatus,
            'subtotal' => 500,
            'grand_total' => 500,
            'payment_status' => 'PAID',
        ]);
        $sellerOrder = SellerOrder::query()->create([
            'seller_order_number' => 'REVIEW-SELLER-'.Str::uuid(),
            'order_id' => $order->id,
            'seller_profile_id' => $seller->id,
            'status' => 'COMPLETED',
            'item_subtotal' => 500,
            'grand_total' => 500,
        ]);
        $item = OrderItem::query()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'sku' => $variant->sku,
            'variant_description' => 'Review variation',
            'unit_price' => 500,
            'quantity' => 1,
            'discount_amount' => 0,
            'line_total' => 500,
        ]);

        return [$buyer, $order, $item, $product, $seller];
    }

    private function onePixelPng(): string
    {
        $image = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
