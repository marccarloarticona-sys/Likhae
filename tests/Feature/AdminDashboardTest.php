<?php

namespace Tests\Feature;

use App\Models\Auth\RegistrationApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_dashboard_counts_only_admin_managed_accounts_in_the_matching_status(): void
    {
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        $accountTypes = [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER];

        foreach ($accountTypes as $index => $accountType) {
            $pending = User::factory()->create(['account_type' => $accountType, 'status' => User::STATUS_PENDING]);
            RegistrationApplication::create([
                'application_number' => 'APP-PENDING-'.$index,
                'user_id' => $pending->id,
                'status' => $index % 2 === 0 ? RegistrationApplication::STATUS_PENDING : RegistrationApplication::STATUS_UNDER_REVIEW,
                'submitted_at' => now(),
            ]);
            User::factory()->create(['account_type' => $accountType, 'status' => User::STATUS_ACTIVE]);
        }

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-admin-header-host', false)
            ->assertSee('data-admin-header', false)
            ->assertSee('Search Admin Center')
            ->assertSee(route('admin.account'), false)
            ->assertSee(route('admin.messages'), false)
            ->assertViewHas('accountStats', [
                'pending' => 3,
                'active' => 3,
                'pending_older_than_day' => 0,
            ])
            ->assertViewHas('recentPendingApplications', fn ($applications) => $applications->count() === 3
                && $applications->every(fn (RegistrationApplication $application) => in_array(
                    $application->user->account_type,
                    [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS],
                    true,
                )))
            ->assertSee('Awaiting administrator review')
            ->assertSee('Buyer, Seller, and Logistics accounts')
            ->assertDontSee('12,480')
            ->assertDontSee('+4.2%');
    }

    public function test_pending_queue_counts_only_applications_older_than_twenty_four_hours(): void
    {
        $this->freezeTime();
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);
        foreach ([25, 48, 24, 23] as $index => $hours) {
            $user = User::factory()->create([
                'account_type' => [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER][$index],
                'status' => User::STATUS_PENDING,
            ]);
            RegistrationApplication::create([
                'application_number' => 'APP-AGE-'.$index,
                'user_id' => $user->id,
                'status' => RegistrationApplication::STATUS_PENDING,
                'submitted_at' => now()->subHours($hours),
            ]);
        }
        User::factory()->create(['account_type' => User::TYPE_BUYER, 'status' => User::STATUS_ACTIVE]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('accountStats', ['pending' => 3, 'active' => 1, 'pending_older_than_day' => 2])
            ->assertSee('2 older than 24 hours');
    }

    public function test_dashboard_shows_zero_when_no_public_accounts_exist(): void
    {
        $this->actingAs(User::factory()->create(['account_type' => User::TYPE_ADMIN]))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('accountStats', ['pending' => 0, 'active' => 0, 'pending_older_than_day' => 0])
            ->assertSee('0 older than 24 hours');
    }

    public function test_dashboard_shows_only_the_five_newest_admin_reviewable_applications(): void
    {
        $admin = User::factory()->create(['account_type' => User::TYPE_ADMIN]);

        foreach (range(1, 6) as $number) {
            $user = User::factory()->create(['account_type' => User::TYPE_BUYER]);
            RegistrationApplication::create([
                'application_number' => 'APP-RECENT-'.$number,
                'user_id' => $user->id,
                'status' => RegistrationApplication::STATUS_PENDING,
                'submitted_at' => now()->subMinutes(7 - $number),
            ]);
        }

        $rider = User::factory()->create(['account_type' => User::TYPE_RIDER]);
        RegistrationApplication::create([
            'application_number' => 'APP-RIDER',
            'user_id' => $rider->id,
            'status' => RegistrationApplication::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('recentPendingApplications', function ($applications): bool {
                return $applications->count() === 5
                    && $applications->pluck('application_number')->all() === [
                        'APP-RECENT-6',
                        'APP-RECENT-5',
                        'APP-RECENT-4',
                        'APP-RECENT-3',
                        'APP-RECENT-2',
                    ];
            })
            ->assertSee('Recent Accounts Pending Approval')
            ->assertDontSee('Recent orders');
    }
}
