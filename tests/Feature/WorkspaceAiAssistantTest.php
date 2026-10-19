<?php

namespace Tests\Feature;

use App\Models\Seller\Category;
use App\Models\Seller\SellerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WorkspaceAiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_assistant_uses_safe_local_help_when_gemini_is_not_configured(): void
    {
        $seller = $this->seller();
        config(['services.gemini.key' => null]);

        $this->actingAs($seller)
            ->postJson(route('seller.ai.chat'), ['message' => 'How do I update stock?', 'page' => 'products'])
            ->assertOk()
            ->assertExactJson([
                'reply' => 'Open Products and use the stock control for the listing you need to update. Keep the available quantity accurate so buyers do not order unavailable items.',
                'mode' => 'local-help',
            ]);
        Http::assertNothingSent();
    }

    public function test_seller_ai_reply_is_tagged_as_generated_and_live_notification_count_stays_data_backed(): void
    {
        $seller = $this->seller();
        config(['services.gemini.key' => 'test-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Use the Products page to review stock.']]]]],
            ]),
        ]);

        $this->actingAs($seller)
            ->postJson(route('seller.ai.chat'), ['message' => 'Can you explain this page?', 'page' => 'products'])
            ->assertOk()
            ->assertExactJson(['reply' => 'Use the Products page to review stock.', 'mode' => 'ai']);

        $seller->notifications()->create(['type' => 'SELLER_ORDER_PLACED', 'title' => 'Order received', 'message' => 'Review this order.']);
        $this->postJson(route('seller.ai.chat'), ['message' => 'How many unread notifications do I have?', 'page' => 'dashboard'])
            ->assertOk()
            ->assertJsonPath('mode', 'local-live-data')
            ->assertJsonPath('reply', 'You have 1 unread notification out of 1 total in your Seller workspace. Use the notification bell to review them.');
    }

    private function seller(): User
    {
        $seller = User::factory()->create(['account_type' => User::TYPE_SELLER]);
        $category = Category::query()->create([
            'name' => 'AI Test Category '.uniqid(),
            'slug' => 'ai-test-category-'.uniqid(),
            'is_active' => true,
        ]);
        SellerProfile::query()->create([
            'user_id' => $seller->id,
            'primary_category_id' => $category->id,
            'business_name' => 'AI Test Shop',
            'status' => 'ACTIVE',
        ]);

        return $seller;
    }
}
