<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class WorkspaceSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_workspace_has_its_own_system_settings_page(): void
    {
        View::share('errors', new ViewErrorBag);

        foreach ([
            User::TYPE_SELLER => ['seller.settings', 'seller.settings.update'],
            User::TYPE_ADMIN => ['admin.system-settings', 'admin.system-settings.update'],
            User::TYPE_LOGISTICS => ['logistics.settings', 'logistics.settings.update'],
            User::TYPE_RIDER => ['rider.settings', 'rider.settings.update'],
        ] as $accountType => $route) {
            $user = User::factory()->create(['account_type' => $accountType, 'status' => User::STATUS_ACTIVE]);

            $this->withoutMiddleware()
                ->actingAs($user)
                ->get(route($route[0]))
                ->assertOk()
                ->assertSee('System Settings')
                ->assertSee('ws-settings-page')
                ->assertSee('ws-settings-card')
                ->assertSee('data-notification-preview="general_updates"', false)
                ->assertSee('action="'.route($route[1]).'"', false);
        }
    }

    public function test_workspace_settings_are_saved_without_removing_existing_preferences(): void
    {
        $seller = User::factory()->create([
            'account_type' => User::TYPE_SELLER,
            'status' => User::STATUS_ACTIVE,
            'notification_preferences' => ['existing_preference' => 'keep-me'],
        ]);

        $this->withoutMiddleware()
            ->actingAs($seller)
            ->put(route('seller.settings.update'), [
                'theme' => 'dark',
                'language' => 'en',
                'seller_order_updates' => '1',
                'seller_ai_assistant_enabled' => '1',
                'seller_ai_response_sound' => '1',
            ])
            ->assertRedirect();

        $preferences = $seller->fresh()->notification_preferences;
        $this->assertSame('keep-me', $preferences['existing_preference']);
        $this->assertSame('dark', $preferences['theme']);
        $this->assertTrue($preferences['seller_order_updates']);
        $this->assertTrue($preferences['seller_ai_assistant_enabled']);
        $this->assertTrue($preferences['seller_ai_response_sound']);
        $this->assertFalse($preferences['seller_inventory_updates']);
    }

    public function test_buyer_settings_lists_the_full_sound_catalog(): void
    {
        View::share('errors', new ViewErrorBag);

        $buyer = User::factory()->create([
            'account_type' => User::TYPE_BUYER,
            'status' => User::STATUS_ACTIVE,
        ]);

        $response = $this->withoutMiddleware()
            ->actingAs($buyer)
            ->get(route('buyer.settings'));

        $response->assertOk();
        foreach (['order_updates', 'delivery_updates', 'chat_updates', 'promotion_updates', 'earnings_updates', 'warning_updates', 'return_updates', 'general_updates'] as $sound) {
            $response->assertSee('data-notification-preview="'.$sound.'"', false);
        }
    }

    public function test_admin_workspace_settings_persist_the_sound_preference(): void
    {
        $admin = User::factory()->create([
            'account_type' => User::TYPE_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'notification_preferences' => ['existing_preference' => 'keep-me'],
        ]);

        $this->withoutMiddleware()
            ->actingAs($admin)
            ->put(route('admin.system-settings.update'), [
                'theme' => 'dark',
                'language' => 'en',
                'admin_notification_sounds' => '0',
            ])
            ->assertRedirect();

        $preferences = $admin->fresh()->notification_preferences;
        $this->assertSame('keep-me', $preferences['existing_preference']);
        $this->assertSame('dark', $preferences['theme']);
        $this->assertFalse($preferences['admin_notification_sounds']);
    }
}
