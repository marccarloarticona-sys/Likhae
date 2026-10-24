<?php

namespace Tests\Feature;

use App\Models\Admin\Notification;
use App\Models\Buyer\FlashSaleReminder;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use App\Services\Marketplace\FlashSaleReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FlashSaleReminderFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_buyer_can_set_and_cancel_a_flash_sale_reminder(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $service = app(FlashSaleReminderService::class);
        $reminder = $service->register($buyer, $product);

        $this->assertSame('PENDING', $reminder->status);
        $this->assertTrue($reminder->scheduled_at->equalTo($product->flash_sale_starts_at->copy()->subMinutes(10)));
        $this->assertTrue($service->cancel($buyer, $product));
        $this->assertSame('CANCELLED', $reminder->fresh()->status);
    }

    public function test_duplicate_registration_reuses_one_pending_reminder(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $service = app(FlashSaleReminderService::class);
        $first = $service->register($buyer, $product);
        $second = $service->register($buyer, $product);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FlashSaleReminder::query()->count());
    }

    public function test_reminder_sends_at_ten_minutes_before_sale_once_with_product_link(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $reminder = app(FlashSaleReminderService::class)->register($buyer, $product);
        Carbon::setTestNow($reminder->scheduled_at);

        $service = app(FlashSaleReminderService::class);
        $this->assertSame(1, $service->sendDue());
        $this->assertSame(0, $service->sendDue());
        $notification = Notification::query()->sole();
        $this->assertSame('FLASH_SALE_REMINDER', $notification->type);
        $this->assertSame(route('buyer.product-details', ['slug' => $product->slug]), $notification->action_url);
        $this->assertSame('SENT', $reminder->fresh()->status);
        Carbon::setTestNow();
    }

    public function test_reminder_is_not_sent_after_sale_start_or_after_buyer_cancels(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $service = app(FlashSaleReminderService::class);
        $reminder = $service->register($buyer, $product);
        $service->cancel($buyer, $product);
        $this->travelTo($product->flash_sale_starts_at->copy()->addSecond());

        $this->assertSame(0, $service->sendDue());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame('CANCELLED', $reminder->fresh()->status);
        $this->travelBack();
    }

    public function test_reminder_respects_promotion_preferences_and_cancels_unavailable_sales(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $buyer->forceFill(['notification_preferences' => ['promotion_updates' => false]])->save();
        $service = app(FlashSaleReminderService::class);
        $reminder = $service->register($buyer, $product);
        $this->travelTo($reminder->scheduled_at);

        $this->assertSame(0, $service->sendDue());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame('CANCELLED', $reminder->fresh()->status);
        $this->travelBack();

        $buyer->forceFill(['notification_preferences' => ['promotion_updates' => true]])->save();
        $secondProduct = $this->makeProduct(now()->addMinutes(35), 'cancelled-sale');
        $secondReminder = $service->register($buyer, $secondProduct);
        $secondProduct->update(['status' => 'ARCHIVED']);
        $service->syncProduct($secondProduct);
        $this->assertSame('CANCELLED', $secondReminder->fresh()->status);
    }

    public function test_rescheduled_sale_updates_pending_reminder_timing(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $service = app(FlashSaleReminderService::class);
        $reminder = $service->register($buyer, $product);
        $newStart = now()->addHours(2);
        $product->update(['flash_sale_starts_at' => $newStart]);
        $service->syncProduct($product->fresh());

        $this->assertSame($newStart->timestamp, $reminder->fresh()->sale_starts_at->timestamp);
        $this->assertSame($newStart->copy()->subMinutes(10)->timestamp, $reminder->fresh()->scheduled_at->timestamp);
    }

    public function test_buyer_cannot_cancel_another_buyers_reminder_and_too_late_signups_are_rejected(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();
        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER, 'status' => User::STATUS_ACTIVE]);
        $service = app(FlashSaleReminderService::class);
        $reminder = $service->register($buyer, $product);

        $this->assertFalse($service->cancel($otherBuyer, $product));
        $this->assertSame('PENDING', $reminder->fresh()->status);

        $tooSoon = $this->makeProduct(now()->addMinutes(5), 'too-soon');
        try {
            $service->register($buyer, $tooSoon);
            $this->fail('A reminder registration less than 10 minutes before the sale must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('product', $exception->errors());
        }
    }

    public function test_flash_deals_page_shows_upcoming_sale_and_reminder_control(): void
    {
        [$buyer, , , $product] = $this->flashSaleFixture();

        $this->actingAs($buyer)
            ->get(route('buyer.flash-deals'))
            ->assertOk()
            ->assertSee('Upcoming flash sales')
            ->assertSee($product->name)
            ->assertSee('Remind Me');
    }

    public function test_upcoming_dedicated_flash_price_appears_in_flash_deals_and_supports_existing_reminders(): void
    {
        [$buyer, , , $product, $variant] = $this->flashSaleFixture();
        $variant->update([
            'discount_type' => 'none',
            'discount_value' => 0,
            'flash_sale_price' => 70,
            'flash_sale_allocation' => 4,
            'flash_sale_sold' => 0,
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.flash-deals'))
            ->assertOk()
            ->assertSee('Upcoming flash sales')
            ->assertSee($product->name)
            ->assertSee('Remind Me');

        $reminder = app(FlashSaleReminderService::class)->register($buyer, $product);
        $this->assertSame('PENDING', $reminder->status);
        $this->assertSame(1, FlashSaleReminder::query()->count());
    }

    /** @return array{User,User,SellerProfile,Product,ProductVariant} */
    private function flashSaleFixture(): array
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER, 'status' => User::STATUS_ACTIVE]);
        $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER, 'status' => User::STATUS_ACTIVE]);
        $category = Category::query()->create([
            'name' => 'Flash Sale Category',
            'slug' => 'flash-sale-category',
            'is_active' => true,
        ]);
        $seller = SellerProfile::query()->create([
            'user_id' => $sellerUser->id,
            'primary_category_id' => $category->id,
            'business_name' => 'Flash Sale Seller',
            'status' => 'ACTIVE',
            'store_visibility' => true,
        ]);
        $product = $this->makeProduct(now()->addMinutes(30), 'upcoming-flash-sale', $seller, $category);
        $variant = $product->variants()->firstOrFail();

        return [$buyer, $sellerUser, $seller, $product, $variant];
    }

    private function makeProduct(Carbon $startsAt, string $slug, ?SellerProfile $seller = null, ?Category $category = null): Product
    {
        if (! $seller || ! $category) {
            $sellerUser = User::factory()->create(['account_type' => User::TYPE_SELLER, 'status' => User::STATUS_ACTIVE]);
            $category = Category::query()->create([
                'name' => 'Flash '.$slug,
                'slug' => 'flash-'.$slug,
                'is_active' => true,
            ]);
            $seller = SellerProfile::query()->create([
                'user_id' => $sellerUser->id,
                'primary_category_id' => $category->id,
                'business_name' => 'Seller '.$slug,
                'status' => 'ACTIVE',
                'store_visibility' => true,
            ]);
        }

        $product = Product::query()->create([
            'seller_profile_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Sale '.$slug,
            'slug' => $slug,
            'description' => 'Upcoming flash sale product.',
            'status' => 'ACTIVE',
            'flash_sale_starts_at' => $startsAt,
            'flash_sale_ends_at' => $startsAt->copy()->addHour(),
        ]);
        ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => strtoupper($slug),
            'price' => 100,
            'discount_type' => 'percentage',
            'discount_value' => 20,
            'stock' => 10,
            'weight_kg' => 1,
            'is_default' => true,
            'is_active' => true,
        ]);

        return $product->load(['variants', 'sellerProfile.user', 'category']);
    }
}
