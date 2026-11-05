<?php

namespace Tests\Feature;

use App\Models\Admin\Announcement;
use App\Models\Admin\Notification;
use App\Models\Seller\Product;
use App\Models\Seller\ProductVariant;
use App\Models\Seller\Voucher;
use App\Models\User;
use App\Services\Marketplace\CartService;
use App\Services\Marketplace\CheckoutService;
use App\Services\Notifications\AnnouncementDestination;
use App\Services\Notifications\AnnouncementNotificationPublisher;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MarketingMediaAnnouncementFlowTest extends TestCase
{
    use RefreshDatabase;

    public static function loginRedirectCases(): array
    {
        return [
            'buyer' => [User::TYPE_BUYER, 'login.post', 'buyer.home'],
            'seller' => [User::TYPE_SELLER, 'login.post', 'seller.dashboard'],
            'admin' => [User::TYPE_ADMIN, 'login.post', 'admin.dashboard'],
            'logistics' => [User::TYPE_LOGISTICS, 'logistics.login.store', 'logistics.dashboard'],
            'rider' => [User::TYPE_RIDER, 'logistics.login.store', 'rider.dashboard'],
        ];
    }

    public function test_campaign_artwork_does_not_change_checkout_discount_rules_and_renders_on_buyer_event_pages(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();

        $this->actingAs($admin)
            ->get(route('admin.marketing', ['section' => 'campaigns']))
            ->assertOk()
            ->assertSee('Campaign artwork')
            ->assertSee('does not change discounts');

        $this->post(route('admin.marketing.campaigns.store'), [
            'name' => 'Media only campaign',
            'code' => 'MEDIAONLY2026',
            'benefit_scope' => 'PRODUCT',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 250,
            'maximum_discount_amount' => 20,
            'usage_limit' => 50,
            'per_user_limit' => 1,
            'campaign_budget_total' => 100000,
            'campaign_budget_reason' => 'Initial test campaign allocation',
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'campaign_image' => UploadedFile::fake()->image('event.png'),
            'image_alt' => 'Warm marketplace event artwork',
            'theme_color' => '#641F19',
        ])->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $campaign = Voucher::query()->where('code', 'MEDIAONLY2026')->sole();
        $this->assertSame(10.0, (float) $campaign->discount_value);
        $this->assertSame(250.0, (float) $campaign->minimum_order_amount);
        $this->assertSame(20.0, (float) $campaign->maximum_discount_amount);
        $this->assertSame(50, $campaign->usage_limit);
        $this->assertSame(1, $campaign->per_user_limit);
        $this->assertTrue($campaign->is_active);
        Storage::disk('public')->assertExists($campaign->image_path);
        $campaignImageUrl = Storage::disk('public')->url($campaign->image_path);

        $product = Product::query()->where('slug', 'sample-handmade-tote')->firstOrFail();
        $variant = ProductVariant::query()
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->firstOrFail();
        $item = app(CartService::class)->add($buyer, $product, (int) $variant->id, 1);
        $preview = app(CheckoutService::class)->preview(collect([$item]), $campaign->code, $buyer);
        $this->assertSame(350.0, (float) $preview['subtotal']);
        $this->assertSame(20.0, (float) $preview['discount_total']);

        $this->actingAs($buyer)
            ->get(route('buyer.platform-events.index'))
            ->assertOk()
            ->assertSee('<img class="lk-event-card__image"', false)
            ->assertSee('src="'.$campaignImageUrl.'"', false)
            ->assertSee('Warm marketplace event artwork')
            ->assertSee('--lk-event-accent: #641F19', false);

        $this->get(route('buyer.platform-events.show', $campaign))
            ->assertOk()
            ->assertSee('<img class="lk-event-detail__image"', false)
            ->assertSee('src="'.$campaignImageUrl.'"', false)
            ->assertSee('MEDIAONLY2026')
            ->assertSee('--lk-event-accent: #641F19', false);
    }

    public function test_campaign_without_image_renders_without_an_empty_artwork_slot(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();

        $this->actingAs($admin)->post(route('admin.marketing.campaigns.store'), $this->campaignPayload('NOARTWORK2026'))
            ->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));
        $campaign = Voucher::query()->where('code', 'NOARTWORK2026')->sole();
        $this->assertNull($campaign->image_path);

        $this->actingAs($buyer)
            ->get(route('buyer.platform-events.show', $campaign))
            ->assertOk()
            ->assertDontSee('<img class="lk-event-detail__image"', false)
            ->assertSee('NOARTWORK2026');
    }

    public function test_buyer_home_uses_original_hero_and_only_active_ordered_public_carousel_campaigns(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();

        $emptyHome = $this->actingAs($buyer)->get(route('buyer.home'))->assertOk();
        $emptyHome->assertSee('Everything<br>for your', false)
            ->assertSee('Shop all products')
            ->assertDontSee('<button class="lk-home-hero-arrow" type="button" data-home-hero-previous', false)
            ->assertDontSee('No scheduled events right now');
        $this->assertStringNotContainsString('home/platform-events', $emptyHome->getContent());

        $first = $this->makeHomepageCarouselCampaign($admin, 'HEROFIRST2026', [
            'homepage_carousel_priority' => 1,
            'homepage_carousel_headline' => 'First campaign headline',
        ]);
        $singleEventHome = $this->get(route('buyer.home'))->assertOk()
            ->assertSee('First campaign headline')
            ->assertSee('data-home-hero-previous')
            ->assertSee(route('buyer.platform-events.show', $first), false);
        $this->assertSame(2, substr_count($singleEventHome->getContent(), '<article class="lk-home-editorial-hero lk-home-hero-slide'));

        $second = $this->makeHomepageCarouselCampaign($admin, 'HEROSCOND2026', [
            'homepage_carousel_priority' => 2,
            'homepage_carousel_headline' => 'Second campaign headline',
            'homepage_carousel_cta_label' => 'See all offers',
            'homepage_carousel_cta_destination' => 'OFFERS',
        ]);
        $this->makeHomepageCarouselCampaign($admin, 'HEROFUTURE2026', [
            'homepage_carousel_priority' => 3,
            'homepage_carousel_headline' => 'Future campaign hidden',
            'starts_at' => now()->addHour(),
        ]);
        $this->makeHomepageCarouselCampaign($admin, 'HEROEXPIRED2026', [
            'homepage_carousel_priority' => 4,
            'homepage_carousel_headline' => 'Expired campaign hidden',
            'ends_at' => now()->subHour(),
        ]);
        $this->makeHomepageCarouselCampaign($admin, 'HEROPAUSED2026', [
            'homepage_carousel_priority' => 5,
            'homepage_carousel_headline' => 'Unpublished campaign hidden',
            'is_active' => false,
        ]);
        $this->makeHomepageCarouselCampaign($admin, 'HEROOFF2026', [
            'homepage_carousel_priority' => 6,
            'homepage_carousel_headline' => 'Opted out campaign hidden',
            'homepage_carousel_enabled' => false,
        ]);
        $this->makeHomepageCarouselCampaign($admin, 'HEROPRIVATE2026', [
            'homepage_carousel_priority' => 7,
            'homepage_carousel_headline' => 'Private campaign hidden',
            'visibility' => 'CODE_ONLY',
        ]);
        $this->makeHomepageCarouselCampaign($admin, 'HERONOART2026', [
            'homepage_carousel_priority' => 8,
            'homepage_carousel_headline' => 'Artwork missing campaign hidden',
            'homepage_carousel_desktop_image_path' => null,
        ]);

        $multipleEventHome = $this->get(route('buyer.home'))->assertOk()
            ->assertSee('First campaign headline')
            ->assertSee('Second campaign headline')
            ->assertSee(route('buyer.platform-events.index'), false)
            ->assertDontSee('Future campaign hidden')
            ->assertDontSee('Expired campaign hidden')
            ->assertDontSee('Unpublished campaign hidden')
            ->assertDontSee('Opted out campaign hidden')
            ->assertDontSee('Private campaign hidden')
            ->assertDontSee('Artwork missing campaign hidden')
            ->assertSee('touchstart')
            ->assertSee('prefers-reduced-motion');
        $html = $multipleEventHome->getContent();
        $this->assertSame(3, substr_count($html, '<article class="lk-home-editorial-hero lk-home-hero-slide'));
        $this->assertLessThan(strpos($html, 'Second campaign headline'), strpos($html, 'First campaign headline'));
        $this->assertNotSame($first->id, $second->id);

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " lk-event-card ")]')->length);
        $offerSlide = $xpath->query('//article[@data-home-hero-slide][.//h2[contains(., "Second campaign headline")]]')->item(0);
        $this->assertNotNull($offerSlide);
        $offerCta = $xpath->query('.//a[contains(@class, "lk-home-editorial-cta")]', $offerSlide)->item(0);
        $this->assertNotNull($offerCta);
        $this->assertSame(route('buyer.platform-events.index'), $offerCta->getAttribute('href'));
        $this->assertStringContainsString('See all offers', trim($offerCta->textContent));
        $this->assertStringContainsString('}, 7000);', $html);
        $this->assertStringContainsString('pointerenter', $html);
        $this->assertStringContainsString('focusin', $html);
        $this->assertStringContainsString('visibilitychange', $html);

        $this->get(route('buyer.platform-events.index'))->assertOk();
        $this->get(route('buyer.platform-events.show', $first))->assertOk();
    }

    public function test_admin_can_enable_and_update_desktop_and_mobile_homepage_artwork(): void
    {
        $this->seed(DatabaseSeeder::class);
        Storage::fake('public');
        $admin = User::query()->where('email', 'admin@likhae.com')->sole();
        $buyer = User::query()->where('email', 'buyer@likhae.com')->sole();
        $desktop = UploadedFile::fake()->image('desktop-event.png', 1400, 520);
        $mobile = UploadedFile::fake()->image('mobile-event.png', 700, 900);

        $this->actingAs($admin)
            ->get(route('admin.marketing', ['section' => 'campaigns']))
            ->assertOk()
            ->assertSee('Show in Buyer Hero Carousel')
            ->assertSee('Desktop promotional image');

        $this->post(route('admin.marketing.campaigns.store'), $this->campaignPayload('HERONOIMAGE2026', [
            'homepage_carousel_enabled' => '1',
        ]))->assertSessionHasErrors('homepage_carousel_desktop_image');
        $this->assertDatabaseMissing('vouchers', ['code' => 'HERONOIMAGE2026']);

        $this->post(route('admin.marketing.campaigns.store'), $this->campaignPayload('HEROURL2026', [
            'homepage_carousel_enabled' => '1',
            'homepage_carousel_cta_destination' => 'https://example.com',
            'homepage_carousel_desktop_image' => UploadedFile::fake()->image('invalid-destination.png'),
        ]))->assertSessionHasErrors('homepage_carousel_cta_destination');
        $this->assertDatabaseMissing('vouchers', ['code' => 'HEROURL2026']);

        $this->post(route('admin.marketing.campaigns.store'), $this->campaignPayload('HEROEDIT2026', [
            'homepage_carousel_enabled' => '1',
            'homepage_carousel_headline' => 'Artwork before update',
            'homepage_carousel_description' => 'An event description for buyers.',
            'homepage_carousel_cta_label' => 'Explore this event',
            'homepage_carousel_cta_destination' => 'EVENT',
            'homepage_carousel_priority' => 4,
            'homepage_carousel_desktop_image' => $desktop,
            'homepage_carousel_mobile_image' => $mobile,
        ]))->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $campaign = Voucher::query()->where('code', 'HEROEDIT2026')->sole();
        $oldDesktopPath = $campaign->homepage_carousel_desktop_image_path;
        $oldMobilePath = $campaign->homepage_carousel_mobile_image_path;
        Storage::disk('public')->assertExists($oldDesktopPath);
        Storage::disk('public')->assertExists($oldMobilePath);
        $initialBuyerHome = $this->actingAs($buyer)->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('Artwork before update')
            ->assertSee('An event description for buyers.')
            ->assertSee('Explore this event')
            ->assertSee(route('buyer.platform-events.show', $campaign), false)
            ->assertSee(Storage::disk('public')->url($oldMobilePath), false);
        $this->assertStringContainsString(
            'media="(max-width: 640px)" srcset="'.Storage::disk('public')->url($oldMobilePath).'"',
            $initialBuyerHome->getContent(),
        );
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $initialBuyerHome->getContent());

        $updatedDesktop = UploadedFile::fake()->image('updated-desktop-event.png', 1400, 520);
        $this->actingAs($admin)
            ->patch(route('admin.marketing.campaigns.update', $campaign), $this->campaignPayload('HEROEDIT2026', [
                'name' => $campaign->name,
                'homepage_carousel_enabled' => '1',
                'homepage_carousel_headline' => 'Updated campaign headline',
                'homepage_carousel_description' => 'Updated event copy.',
                'homepage_carousel_cta_label' => 'See all offers',
                'homepage_carousel_cta_destination' => 'OFFERS',
                'homepage_carousel_priority' => 2,
                'homepage_carousel_desktop_image' => $updatedDesktop,
            ]))->assertRedirect(route('admin.marketing', ['section' => 'campaigns']));

        $campaign->refresh();
        Storage::disk('public')->assertMissing($oldDesktopPath);
        Storage::disk('public')->assertExists($campaign->homepage_carousel_desktop_image_path);
        Storage::disk('public')->assertExists($oldMobilePath);
        $this->actingAs($buyer)->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('Updated campaign headline')
            ->assertSee(route('buyer.platform-events.index'), false)
            ->assertDontSee('Artwork before update');
    }

    public function test_campaign_image_validation_rejects_executable_oversized_and_invalid_mime_files(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $invalidFiles = [
            UploadedFile::fake()->create('payload.exe', 20, 'application/x-msdownload'),
            UploadedFile::fake()->create('payload.php', 20, 'application/x-php'),
            UploadedFile::fake()->image('too-large.jpg')->size(5121),
            UploadedFile::fake()->create('not-really-an-image.jpg', 20, 'text/plain'),
        ];

        foreach ($invalidFiles as $index => $file) {
            $this->actingAs($admin)
                ->post(route('admin.marketing.campaigns.store'), array_merge(
                    $this->campaignPayload('REJECTCAMPAIGN'.$index),
                    ['campaign_image' => $file],
                ))
                ->assertSessionHasErrors('campaign_image');
        }

        Storage::disk('public')->assertDirectoryEmpty('marketing/campaigns');
        $this->assertSame(0, Voucher::query()->where('code', 'like', 'REJECTCAMPAIGN%')->count());
    }

    public function test_announcement_media_cta_and_audience_save_and_popup_omits_missing_images(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $seller = $this->makeUser(User::TYPE_SELLER);

        $this->actingAs($admin)
            ->get(route('admin.marketing', ['section' => 'announcements']))
            ->assertOk()
            ->assertSee('Show as login popup')
            ->assertSee('CTA destination');

        $this->actingAs($admin)->post(route('admin.marketing.announcements.store'), [
            'title' => 'Buyer event announcement',
            'body' => 'Discover featured products and participating stores.',
            'target_audience' => 'BUYERS',
            'published_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'announcement_image' => UploadedFile::fake()->image('announcement.webp'),
            'image_alt' => 'Featured local products',
            'theme_color' => '#641F19',
            'cta_label' => 'Shop Now',
            'cta_url' => '/buyer/products',
            'show_popup' => 1,
        ])->assertRedirect(route('admin.marketing', ['section' => 'announcements']));

        $announcement = Announcement::query()->where('title', 'Buyer event announcement')->sole();
        Storage::disk('public')->assertExists($announcement->image_path);
        $this->assertSame('Shop Now', $announcement->cta_label);
        $this->assertSame('/buyer/products', $announcement->cta_url);
        $this->assertSame('#641F19', $announcement->safe_theme_color);
        $this->assertTrue($announcement->show_popup);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'reference_id' => $announcement->id,
            'type' => 'ANNOUNCEMENT',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $seller->id,
            'reference_id' => $announcement->id,
            'type' => 'ANNOUNCEMENT',
        ]);

        $this->renderPopup($buyer)
            ->assertSee('data-announcement-popup', false)
            ->assertSee('Buyer event announcement')
            ->assertSee('Discover featured products and participating stores.')
            ->assertSee('lk-announcement-image-wrap', false)
            ->assertSee('Shop Now')
            ->assertSee('--lk-announcement-accent: #641F19', false);

        $this->actingAs($admin)->post(route('admin.marketing.announcements.store'), [
            'title' => 'No image announcement',
            'body' => 'This one has no artwork.',
            'target_audience' => 'BUYERS',
            'published_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'show_popup' => 1,
        ])->assertRedirect(route('admin.marketing', ['section' => 'announcements']));
        $this->assertNull(Announcement::query()->where('title', 'No image announcement')->sole()->image_path);

        $this->renderPopup($buyer)
            ->assertSee('No image announcement')
            ->assertDontSee('<div class="lk-announcement-image-wrap"', false)
            ->assertDontSee('lk-announcement-image"', false)
            ->assertDontSee('lk-announcement-cta', false)
            ->assertSee('aria-label="Close announcement"', false)
            ->assertSee('Not now');
    }

    public function test_announcement_image_validation_rejects_corrupt_oversized_and_non_image_uploads(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $invalidFiles = [
            UploadedFile::fake()->create('broken.png', 20, 'image/png'),
            UploadedFile::fake()->image('too-large.webp')->size(5121),
            UploadedFile::fake()->create('fake-image.jpg', 20, 'text/plain'),
        ];

        foreach ($invalidFiles as $file) {
            $this->actingAs($admin)
                ->post(route('admin.marketing.announcements.store'), [
                    'title' => 'Rejected upload',
                    'body' => 'Invalid image must not save.',
                    'target_audience' => 'ALL',
                    'announcement_image' => $file,
                ])
                ->assertSessionHasErrors('announcement_image');
        }

        Storage::disk('public')->assertDirectoryEmpty('marketing/announcements');
        $this->assertSame(0, Announcement::query()->where('title', 'Rejected upload')->count());
    }

    public function test_audience_targeting_covers_buyer_seller_logistics_center_rider_all_and_excludes_admin(): void
    {
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $seller = $this->makeUser(User::TYPE_SELLER);
        $logistics = $this->makeUser(User::TYPE_LOGISTICS);
        $rider = $this->makeUser(User::TYPE_RIDER);
        $admin = $this->makeUser(User::TYPE_ADMIN);

        $cases = [
            ['BUYERS', [User::TYPE_BUYER]],
            ['SELLERS', [User::TYPE_SELLER]],
            ['LOGISTICS_CENTERS', [User::TYPE_LOGISTICS]],
            ['RIDERS', [User::TYPE_RIDER]],
            ['LOGISTICS', [User::TYPE_LOGISTICS, User::TYPE_RIDER]],
            ['ALL', [User::TYPE_BUYER, User::TYPE_SELLER, User::TYPE_LOGISTICS, User::TYPE_RIDER]],
        ];
        $users = [$buyer, $seller, $logistics, $rider, $admin];

        foreach ($cases as [$audience, $eligibleTypes]) {
            $announcement = $this->makeAnnouncement([
                'title' => 'Audience test '.$audience,
                'target_audience' => $audience,
            ]);
            app(AnnouncementNotificationPublisher::class)->publish($announcement);
            $this->assertTrue($announcement->show_popup);
            if (in_array(User::TYPE_BUYER, $eligibleTypes, true)) {
                $this->assertDatabaseHas('notifications', [
                    'user_id' => $buyer->id,
                    'reference_id' => $announcement->id,
                    'type' => 'ANNOUNCEMENT',
                    'read_at' => null,
                ]);
                $this->assertSame(1, Announcement::query()
                    ->published()
                    ->where('show_popup', true)
                    ->whereIn('target_audience', ['ALL', 'BUYERS'])
                    ->whereExists(function ($query) use ($buyer, $announcement): void {
                        $query->selectRaw('1')->from('notifications')
                            ->whereColumn('notifications.reference_id', 'announcements.id')
                            ->where('notifications.user_id', $buyer->id)
                            ->where('notifications.reference_id', $announcement->id)
                            ->where('notifications.type', 'ANNOUNCEMENT')
                            ->where('notifications.reference_type', Announcement::class)
                            ->whereNull('notifications.read_at');
                    })
                    ->count());
            }

            foreach ($users as $user) {
                $view = $this->renderPopup($user);
                $popupText = $this->popupMarkup($view);
                if (in_array($user->account_type, $eligibleTypes, true)) {
                    $this->assertSame($user->id, auth()->id());
                    $this->assertStringContainsString('Audience test '.$audience, $popupText);
                } else {
                    $this->assertSame('', $popupText);
                }
            }

            Notification::query()
                ->where('reference_type', Announcement::class)
                ->where('reference_id', $announcement->id)
                ->update(['read_at' => now()]);
        }
    }

    public function test_guest_pages_do_not_render_the_authenticated_announcement_popup(): void
    {
        $this->makeAnnouncement(['target_audience' => 'ALL']);

        auth()->logout();
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('data-announcement-popup', false);
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('data-announcement-popup', false);
    }

    public function test_popup_filters_wrong_audience_expired_future_inactive_and_popup_disabled_announcements(): void
    {
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $cases = [
            ['target_audience' => 'SELLERS'],
            ['expires_at' => now()->subMinute()],
            ['published_at' => now()->addDay()],
            ['is_active' => false],
            ['show_popup' => false],
        ];

        foreach ($cases as $index => $overrides) {
            $announcement = $this->makeAnnouncement(array_merge([
                'title' => 'Ineligible announcement '.$index,
                'published_at' => now()->subMinute(),
                'expires_at' => null,
                'is_active' => true,
                'show_popup' => true,
                'target_audience' => 'BUYERS',
            ], $overrides), false);
            $this->attachNotification($announcement, $buyer);

            $this->assertSame('', $this->popupMarkup($this->renderPopup($buyer)));
        }

        $draft = $this->makeAnnouncement([
            'title' => 'Draft announcement',
            'published_at' => null,
            'is_active' => false,
            'target_audience' => 'BUYERS',
        ], false);
        $this->attachNotification($draft, $buyer);
        $this->assertSame('', $this->popupMarkup($this->renderPopup($buyer)));

        $this->assertSame('', $this->popupMarkup($this->renderPopup($admin)));
    }

    public function test_only_the_newest_one_of_multiple_eligible_announcements_is_shown(): void
    {
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $this->makeAnnouncement(['title' => 'Older announcement']);
        $this->makeAnnouncement(['title' => 'Middle announcement', 'published_at' => now()->subSeconds(30)]);
        $this->makeAnnouncement(['title' => 'Newest announcement', 'published_at' => now()]);

        $view = $this->renderPopup($buyer);
        $popupText = $this->popupMarkup($view);
        $this->assertStringContainsString('Newest announcement', $popupText);
        $this->assertStringNotContainsString('Older announcement', $popupText);
        $this->assertStringNotContainsString('Middle announcement', $popupText);
        $this->assertSame(1, $this->popupCount($view));
    }

    public function test_dismiss_stays_on_the_same_page_persists_seen_state_and_allows_a_new_announcement(): void
    {
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $first = $this->makeAnnouncement(['title' => 'Announcement A', 'target_audience' => 'BUYERS']);
        $firstNotification = Notification::query()->where('reference_id', $first->id)->where('user_id', $buyer->id)->sole();

        $this->assertStringContainsString('Announcement A', $this->popupMarkup($this->renderPopup($buyer)));
        $this->actingAs($buyer)
            ->postJson(route('notifications.announcements.dismiss', $firstNotification), [
                'return_to' => '/buyer/platform-events',
            ])
            ->assertOk()
            ->assertJson(['dismissed' => true]);
        $this->assertNotNull($firstNotification->fresh()->read_at);
        $this->assertSame('', $this->popupMarkup($this->renderPopup($buyer)));

        $second = $this->makeAnnouncement(['title' => 'Announcement B', 'target_audience' => 'BUYERS']);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $buyer->id,
            'reference_id' => $second->id,
            'type' => 'ANNOUNCEMENT',
        ]);
        $popupText = $this->popupMarkup($this->renderPopup($buyer));
        $this->assertStringContainsString('Announcement B', $popupText);
        $this->assertStringNotContainsString('Announcement A', $popupText);
    }

    public function test_close_control_records_dismissal_without_navigating_away(): void
    {
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $announcement = $this->makeAnnouncement(['title' => 'Close control announcement', 'target_audience' => 'BUYERS']);
        $notification = Notification::query()->where('reference_id', $announcement->id)->where('user_id', $buyer->id)->sole();

        $this->renderPopup($buyer)
            ->assertSee('aria-label="Close announcement"', false)
            ->assertSee('data-announcement-dismiss', false);
        $this->actingAs($buyer)
            ->postJson(route('notifications.announcements.dismiss', $notification))
            ->assertOk()
            ->assertJson(['dismissed' => true]);
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_cta_opens_a_valid_internal_page_and_unsafe_destinations_are_rejected(): void
    {
        $buyer = $this->makeUser(User::TYPE_BUYER);
        $announcement = $this->makeAnnouncement([
            'title' => 'CTA announcement',
            'target_audience' => 'BUYERS',
            'cta_label' => 'Shop Now',
            'cta_url' => '/buyer/products',
        ]);
        $notification = Notification::query()->where('reference_id', $announcement->id)->where('user_id', $buyer->id)->sole();

        $view = $this->renderPopup($buyer);
        $this->assertStringContainsString('Shop Now', $this->popupMarkup($view));
        $view->assertSee(route('notifications.announcements.open', $notification), false);

        $this->actingAs($buyer)
            ->post(route('notifications.announcements.open', $notification))
            ->assertRedirect('/buyer/products');
        $this->assertNotNull($notification->fresh()->read_at);
        $this->get(route('buyer.products'))->assertOk();

        $destinations = app(AnnouncementDestination::class);
        foreach (['https://example.com', '//example.com', '/%2f%2fexample.com', '/not-a-real-route'] as $unsafe) {
            $this->assertNull($destinations->resolve($unsafe));
        }
        $this->assertSame('/buyer/products', $destinations->resolve('/buyer/products'));
        $this->assertSame(route('buyer.products', [], false), $destinations->resolve('buyer.products'));

        $admin = $this->makeUser(User::TYPE_ADMIN);
        $this->actingAs($admin)
            ->from(route('admin.marketing', ['section' => 'announcements']))
            ->post(route('admin.marketing.announcements.store'), [
                'title' => 'Unsafe CTA',
                'body' => 'Must not save.',
                'target_audience' => 'ALL',
                'cta_label' => 'Leave Likhae',
                'cta_url' => 'https://example.com',
            ])
            ->assertSessionHasErrors('cta_url');
        $this->assertDatabaseMissing('announcements', ['title' => 'Unsafe CTA']);
    }

    public function test_campaign_and_announcement_images_can_be_replaced_and_removed_safely(): void
    {
        Storage::fake('public');
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $buyer = $this->makeUser(User::TYPE_BUYER);

        $this->actingAs($admin)->post(route('admin.marketing.campaigns.store'), array_merge(
            $this->campaignPayload('REPLACEME2026'),
            ['campaign_image' => UploadedFile::fake()->image('campaign-one.png')],
        ))->assertRedirect();
        $campaign = Voucher::query()->where('code', 'REPLACEME2026')->sole();
        $oldCampaignPath = $campaign->image_path;
        $this->assertNotEmpty($oldCampaignPath);

        $campaignPayload = array_merge($this->campaignPayload('REPLACEME2026'), [
            'campaign_image' => UploadedFile::fake()->image('campaign-two.webp'),
            'image_alt' => 'Replacement campaign art',
            'theme_color' => '#641F19',
        ]);
        $this->actingAs($admin)->patch(route('admin.marketing.campaigns.update', $campaign), $campaignPayload)
            ->assertRedirect();
        $campaign->refresh();
        $this->assertNotSame($oldCampaignPath, $campaign->image_path);
        $this->assertSame(10.0, (float) $campaign->discount_value);
        $this->assertSame(250.0, (float) $campaign->minimum_order_amount);
        $this->assertSame(20.0, (float) $campaign->maximum_discount_amount);
        $this->assertSame(50, $campaign->usage_limit);
        $this->assertSame(1, $campaign->per_user_limit);
        $this->assertTrue($campaign->is_active);
        Storage::disk('public')->assertMissing($oldCampaignPath);
        Storage::disk('public')->assertExists($campaign->image_path);
        $newCampaignPath = $campaign->image_path;

        $this->actingAs($admin)->patch(route('admin.marketing.campaigns.update', $campaign), array_merge(
            $this->campaignPayload('REPLACEME2026'),
            ['remove_image' => 1, 'image_alt' => 'Removed campaign image'],
        ))->assertRedirect();
        $campaign->refresh();
        $this->assertNull($campaign->image_path);
        Storage::disk('public')->assertMissing($newCampaignPath);

        $this->actingAs($admin)->post(route('admin.marketing.announcements.store'), [
            'title' => 'Replace announcement art',
            'body' => 'Announcement remains informational.',
            'target_audience' => 'ALL',
            'announcement_image' => UploadedFile::fake()->image('announcement-one.jpg'),
            'show_popup' => 1,
        ])->assertRedirect();
        $announcement = Announcement::query()->where('title', 'Replace announcement art')->sole();
        $oldAnnouncementPath = $announcement->image_path;

        $this->actingAs($admin)->patch(route('admin.marketing.announcements.update', $announcement), [
            'title' => 'Replace announcement art',
            'body' => 'Announcement remains informational.',
            'target_audience' => 'ALL',
            'announcement_image' => UploadedFile::fake()->image('announcement-two.webp'),
            'image_alt' => 'Replacement announcement image',
            'theme_color' => '#641F19',
            'show_popup' => 1,
        ])->assertRedirect();
        $announcement->refresh();
        $newAnnouncementPath = $announcement->image_path;
        $this->assertNotSame($oldAnnouncementPath, $newAnnouncementPath);
        Storage::disk('public')->assertMissing($oldAnnouncementPath);
        Storage::disk('public')->assertExists($newAnnouncementPath);

        $this->actingAs($admin)->patch(route('admin.marketing.announcements.update', $announcement), [
            'title' => 'Replace announcement art',
            'body' => 'Announcement remains informational.',
            'target_audience' => 'ALL',
            'remove_image' => 1,
            'show_popup' => 1,
        ])->assertRedirect();
        $announcement->refresh();
        $this->assertNull($announcement->image_path);
        Storage::disk('public')->assertMissing($newAnnouncementPath);
        $popupText = $this->popupMarkup($this->renderPopup($buyer));
        $this->assertStringContainsString('Replace announcement art', $popupText);
        $this->assertStringNotContainsString('lk-announcement-image-wrap', $popupText);
    }

    public function test_admin_marketing_tabs_crud_status_and_access_control_remain_available(): void
    {
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $buyer = $this->makeUser(User::TYPE_BUYER);

        $this->actingAs($admin)->get(route('admin.marketing', ['section' => 'campaigns']))
            ->assertOk()->assertSee('Platform Campaigns');
        $this->get(route('admin.marketing', ['section' => 'announcements']))
            ->assertOk()->assertSee('Announcement History');

        $this->post(route('admin.marketing.campaigns.store'), $this->campaignPayload('ADMINFLOW2026'))->assertRedirect();
        $campaign = Voucher::query()->where('code', 'ADMINFLOW2026')->sole();
        $this->patch(route('admin.marketing.campaigns.update', $campaign), $this->campaignPayload('ADMINFLOW2026', ['name' => 'Updated campaign']))
            ->assertRedirect();
        $this->patch(route('admin.marketing.campaigns.toggle', $campaign))->assertRedirect();
        $this->assertFalse($campaign->fresh()->is_active);

        $this->post(route('admin.marketing.announcements.store'), [
            'title' => 'Admin flow announcement',
            'body' => 'Admin announcement CRUD remains available.',
            'target_audience' => 'BUYERS',
            'show_popup' => 1,
        ])->assertRedirect();
        $announcement = Announcement::query()->where('title', 'Admin flow announcement')->sole();
        $this->patch(route('admin.marketing.announcements.update', $announcement), [
            'title' => 'Updated admin announcement',
            'body' => 'Admin announcement CRUD remains available.',
            'target_audience' => 'BUYERS',
            'show_popup' => 0,
        ])->assertRedirect();
        $announcement->refresh();
        $this->assertSame('Updated admin announcement', $announcement->title);
        $this->assertFalse($announcement->show_popup);
        $this->patch(route('admin.marketing.announcements.toggle', $announcement))->assertRedirect();

        $this->actingAs($buyer)
            ->post(route('admin.marketing.campaigns.store'), $this->campaignPayload('FORBIDDEN2026'))
            ->assertForbidden();
        $this->actingAs($buyer)
            ->patch(route('admin.marketing.announcements.update', $announcement), [
                'title' => 'Unauthorized edit',
                'body' => 'No.',
                'target_audience' => 'ALL',
            ])
            ->assertForbidden();
    }

    #[DataProvider('loginRedirectCases')]
    public function test_successful_login_keeps_the_existing_role_redirect(string $accountType, string $loginRoute, string $workspaceRoute): void
    {
        $user = $this->makeUser($accountType);
        $user->forceFill(['password' => bcrypt('Password1')])->save();

        $this->post(route($loginRoute), [
            'email' => $user->email,
            'password' => 'Password1',
        ])->assertRedirect(route($workspaceRoute));
    }

    public function test_popup_theme_color_is_validated_and_invalid_stored_values_use_default_styling(): void
    {
        $admin = $this->makeUser(User::TYPE_ADMIN);
        $buyer = $this->makeUser(User::TYPE_BUYER);

        $this->actingAs($admin)
            ->from(route('admin.marketing', ['section' => 'announcements']))
            ->post(route('admin.marketing.announcements.store'), [
                'title' => 'Injected theme attempt',
                'body' => 'A style string must never be emitted.',
                'target_audience' => 'BUYERS',
                'theme_color' => 'red; background:url(javascript:alert(1))',
            ])
            ->assertSessionHasErrors('theme_color');
        $this->assertDatabaseMissing('announcements', ['title' => 'Injected theme attempt']);

        $announcement = $this->makeAnnouncement([
            'title' => 'Invalid stored theme',
            'target_audience' => 'BUYERS',
            'theme_color' => 'invalid',
        ], false);
        $this->attachNotification($announcement, $buyer);
        $this->assertNull($announcement->safe_theme_color);
        $view = $this->renderPopup($buyer);
        $this->assertStringContainsString('Invalid stored theme', $this->popupMarkup($view));
        $this->assertStringNotContainsString('--lk-announcement-accent: invalid', $this->popupMarkup($view));
    }

    public function test_popup_styles_include_desktop_mobile_and_reduced_motion_rules(): void
    {
        $css = file_get_contents(resource_path('css/shared/announcement-popup.css'));

        $this->assertIsString($css);
        $this->assertStringContainsString('place-items: center', $css);
        $this->assertStringContainsString('width: min(100%, 520px)', $css);
        $this->assertStringContainsString('aspect-ratio: 16 / 8', $css);
        $this->assertStringContainsString('object-fit: cover', $css);
        $this->assertStringContainsString('@media (max-width: 560px)', $css);
        $this->assertStringContainsString('width: 100%', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        $this->assertStringContainsString('min-height: 43px', $css);
    }

    private function makeUser(string $accountType): User
    {
        return User::factory()->create([
            'account_type' => $accountType,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
    }

    private function makeAnnouncement(array $overrides = [], bool $publishNotifications = true): Announcement
    {
        $creator = User::query()->where('account_type', User::TYPE_ADMIN)->first() ?? $this->makeUser(User::TYPE_ADMIN);
        $announcement = Announcement::query()->create(array_merge([
            'created_by_user_id' => $creator->id,
            'title' => 'Audience test '.str()->upper(str()->random(8)),
            'body' => 'Announcement test body.',
            'target_audience' => 'ALL',
            'image_path' => null,
            'image_alt' => null,
            'theme_color' => null,
            'cta_label' => null,
            'cta_url' => null,
            'show_popup' => true,
            'published_at' => now()->subMinute(),
            'expires_at' => null,
            'is_active' => true,
        ], $overrides));

        if ($publishNotifications) {
            app(AnnouncementNotificationPublisher::class)->publish($announcement);
        }

        return $announcement;
    }

    private function attachNotification(Announcement $announcement, User $user): Notification
    {
        return Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'ANNOUNCEMENT',
            'title' => $announcement->title,
            'message' => $announcement->body,
            'reference_type' => Announcement::class,
            'reference_id' => $announcement->id,
            'action_url' => route('notifications.index'),
        ]);
    }

    private function renderPopup(User $user)
    {
        return $this->actingAs($user)->get(route('notifications.index'));
    }

    private function popupMarkup($response): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $popups = (new \DOMXPath($document))->query('//*[@data-announcement-popup]');

        return $popups?->item(0)?->textContent ?? '';
    }

    private function popupCount($response): int
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return (new \DOMXPath($document))->query('//*[@data-announcement-popup]')->length;
    }

    private function campaignPayload(string $code, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test campaign',
            'code' => $code,
            'benefit_scope' => 'PRODUCT',
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 250,
            'maximum_discount_amount' => 20,
            'usage_limit' => 50,
            'per_user_limit' => 1,
            'campaign_budget_total' => 100000,
            'campaign_budget_reason' => 'Initial test campaign allocation',
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ], $overrides);
    }

    private function makeHomepageCarouselCampaign(User $admin, string $code, array $overrides = []): Voucher
    {
        $desktopPath = array_key_exists('homepage_carousel_desktop_image_path', $overrides)
            ? $overrides['homepage_carousel_desktop_image_path']
            : UploadedFile::fake()->image('hero-'.$code.'.png', 1400, 520)->store('marketing/platform-events', 'public');
        $campaign = Voucher::query()->create(array_merge([
            'seller_profile_id' => null,
            'platform_code_key' => $code,
            'benefit_scope' => 'PRODUCT',
            'visibility' => 'PUBLIC',
            'code' => $code,
            'name' => 'Campaign '.$code,
            'discount_type' => 'PERCENT',
            'discount_value' => 10,
            'minimum_order_amount' => 100,
            'maximum_discount_amount' => 20,
            'usage_limit' => 50,
            'per_user_limit' => 1,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDay(),
            'is_active' => true,
            'requires_review' => false,
            'campaign_budget_total' => null,
            'homepage_carousel_enabled' => true,
            'homepage_carousel_desktop_image_path' => $desktopPath,
            'homepage_carousel_mobile_image_path' => null,
            'homepage_carousel_headline' => 'Campaign headline '.$code,
            'homepage_carousel_description' => 'A short description for '.$code,
            'homepage_carousel_cta_label' => 'View Offer',
            'homepage_carousel_cta_destination' => 'EVENT',
            'homepage_carousel_priority' => 0,
        ], $overrides));

        app(\App\Services\Finance\PlatformCampaignBudgetService::class)
            ->setAllocation($campaign, 100000, 'Homepage carousel test allocation', $admin);

        return $campaign->fresh();
    }
}
