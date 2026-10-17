<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_notification_page_and_header_badge_only_show_the_signed_in_accounts_notifications(): void
    {
        $signedInUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $signedInNotification = $signedInUser->notifications()->create([
            'type' => 'ACCOUNT_UPDATE',
            'title' => 'Your account update',
            'message' => 'This notification belongs to you.',
        ]);
        $otherUser->notifications()->create([
            'type' => 'ACCOUNT_UPDATE',
            'title' => 'Another accounts update',
            'message' => 'This notification belongs to another account.',
        ]);

        $this->actingAs($signedInUser)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($signedInNotification->title)
            ->assertDontSee('Another accounts update')
            ->assertSee('class="lk-badge">1</span>', escape: false);
    }

    public function test_opening_one_notification_marks_only_that_owned_notification_read_and_redirects_safely(): void
    {
        $buyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $otherBuyer = User::factory()->create(['account_type' => User::TYPE_BUYER]);
        $opened = $buyer->notifications()->create([
            'type' => 'ORDER_PLACED',
            'title' => 'Order update',
            'message' => 'Your order was placed.',
            'action_url' => '/buyer/orders/42',
        ]);
        $untouched = $buyer->notifications()->create([
            'type' => 'ORDER_PLACED',
            'title' => 'Another order update',
            'message' => 'This one is still unread.',
            'action_url' => 'https://outside.example/path',
        ]);

        $this->actingAs($buyer)
            ->get(route('buyer.notifications.open', $opened))
            ->assertRedirect('/buyer/orders/42');

        $this->assertNotNull($opened->fresh()->read_at);
        $this->assertNull($untouched->fresh()->read_at);

        $this->actingAs($buyer)
            ->get(route('buyer.notifications.open', $untouched))
            ->assertRedirect(route('buyer.notifications'));
        $this->assertNotNull($untouched->fresh()->read_at);

        $this->actingAs($otherBuyer)
            ->get(route('buyer.notifications.open', $opened))
            ->assertNotFound();
    }

    public function test_logistics_and_rider_headers_show_the_signed_in_accounts_unread_count(): void
    {
        foreach ([
            User::TYPE_LOGISTICS => 'class="absolute -right-1 -top-1 grid h-4 min-w-4 place-items-center rounded-full border-2 border-white bg-red-600 px-1 text-[8px] font-bold leading-none text-white">1</span>',
            User::TYPE_RIDER => 'class="rider-notification-badge">1</span>',
        ] as $accountType => $badge) {
            $user = User::factory()->create(['account_type' => $accountType]);
            $user->notifications()->create([
                'type' => 'ACCOUNT_UPDATE',
                'title' => 'Unread account update',
                'message' => 'This notification belongs to you.',
            ]);

            $this->actingAs($user)
                ->get(route('notifications.index'))
                ->assertOk()
                ->assertSee($badge, escape: false);
        }
    }
}
