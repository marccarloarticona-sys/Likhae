<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Announcement;
use App\Models\Admin\Notification;
use App\Models\Seller\Voucher;
use App\Models\Seller\Category;
use App\Models\Seller\Product;
use App\Models\Seller\SellerProfile;
use App\Models\Finance\FinanceAuditEvent;
use App\Services\Notifications\AnnouncementDestination;
use App\Services\Notifications\AnnouncementNotificationPublisher;
use App\Services\Marketplace\VoucherRedemptionCounter;
use App\Services\Marketplace\PlatformCampaignEligibility;
use Illuminate\Http\JsonResponse;
use App\Services\Finance\PlatformCampaignBudgetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AdminMarketingController extends Controller
{
    public function index(Request $request, AnnouncementDestination $destinations, PlatformCampaignBudgetService $budgets): View
    {
        $campaignsReady = $this->campaignSchemaReady();
        $campaignBudgetReady = $this->campaignBudgetSchemaReady();
        $campaigns = $campaignsReady
            ? Voucher::query()->whereNull('seller_profile_id')->latest()->paginate(15, ['*'], 'campaigns_page')->withQueryString()
            : Voucher::query()->whereRaw('1 = 0')->latest()->paginate(15, ['*'], 'campaigns_page')->withQueryString();
        $redemptionCounts = VoucherRedemptionCounter::countsFor($campaigns->getCollection());
        $campaigns->getCollection()->each(fn (Voucher $voucher) => $voucher->setAttribute(
            'redemption_count',
            $redemptionCounts->get($voucher->id, 0),
        ));
        $budgetSummaries = $campaigns->getCollection()->mapWithKeys(fn (Voucher $voucher): array => [
            $voucher->id => $budgets->summary($voucher),
        ]);
        $budgetHistory = $campaignBudgetReady
            ? $campaigns->getCollection()->mapWithKeys(fn (Voucher $voucher): array => [$voucher->id => $budgets->history($voucher)])
            : collect();

        return view('Admin.marketing.index', [
            'campaigns' => $campaigns,
            'campaignsReady' => $campaignsReady,
            'campaignBudgetReady' => $campaignBudgetReady,
            'homepageCarouselReady' => $this->homepageCarouselSchemaReady(),
            'campaignControlsReady' => app(PlatformCampaignEligibility::class)->controlsReady(),
            'campaignBudgetSummaries' => $budgetSummaries,
            'campaignBudgetHistory' => $budgetHistory,
            'marketingMediaReady' => $this->marketingMediaSchemaReady(),
            'announcementDestinationOptions' => $destinations->options(),
            'announcements' => Announcement::query()->latest()->paginate(10, ['*'], 'announcements_page')->withQueryString(),
            'audienceTargetingReady' => Schema::hasColumn('announcements', 'target_audience'),
            'section' => in_array($request->query('section'), ['campaigns', 'announcements'], true)
                ? $request->query('section')
                : 'campaigns',
        ]);
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        if (! $this->campaignSchemaReady() || ! $this->campaignBudgetSchemaReady()) {
            return redirect()->route('admin.marketing')->with('status', 'Campaign finance fields are read-only until the additive campaign budget migration is applied.');
        }

        $data = $this->validatedCampaign($request);
        $data = $this->campaignControlAttributes($data, null);

        $code = mb_strtoupper(trim($data['code']));
        if (Voucher::query()->whereRaw('UPPER(code) = ?', [$code])->exists()) {
            throw ValidationException::withMessages(['code' => 'This code is already used by a seller or platform voucher. Choose a unique code.']);
        }

        $existingPath = null;
        $newPath = null;
        if ($this->marketingMediaSchemaReady()) {
            $media = $this->campaignMediaAttributes($data, null);
            $data = array_merge($data, $media);
            unset($data['campaign_image'], $data['remove_image']);
            $newPath = $data['image_path'];
        }
        $newHomepageImagePaths = [];
        if ($this->homepageCarouselSchemaReady()) {
            $homepageMedia = $this->homepageCarouselMediaAttributes($data, null);
            $data = array_merge($data, $homepageMedia);
            $newHomepageImagePaths = array_values(array_filter($homepageMedia));
        }

        try {
            DB::transaction(function () use ($data, $code, $request): void {
                $budget = $data['campaign_budget_total'];
                $reason = $data['campaign_budget_reason'];
                unset($data['campaign_budget_reason']);
                $campaign = Voucher::query()->create(array_merge($data, [
                    'seller_profile_id' => null,
                    'platform_code_key' => $code,
                    'code' => $code,
                    'campaign_budget_total' => null,
                    'is_active' => ($data['campaign_status'] ?? 'ACTIVE') === 'ACTIVE',
                ]));
                app(PlatformCampaignBudgetService::class)->setAllocation($campaign, $budget, $reason, $request->user());
            });
        } catch (Throwable $exception) {
            if ($newPath && $newPath !== $existingPath) {
                Storage::disk('public')->delete($newPath);
            }
            $this->deleteHomepageCarouselImages($newHomepageImagePaths);

            throw $exception;
        }

        return redirect()->route('admin.marketing', ['section' => 'campaigns'])->with('status', 'Platform campaign created with an authorized budget allocation.');
    }

    public function updateCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($this->campaignSchemaReady(), 404);
        abort_unless($voucher->seller_profile_id === null && $voucher->platform_code_key !== null, 404);

        $campaignBudgetReady = $this->campaignBudgetSchemaReady();
        $data = $this->validatedCampaign($request, $voucher);
        $data = $this->campaignControlAttributes($data, $voucher);
        $code = mb_strtoupper(trim($data['code']));
        if (Voucher::query()->whereRaw('UPPER(code) = ?', [$code])->whereKeyNot($voucher->getKey())->exists()) {
            throw ValidationException::withMessages(['code' => 'This code is already used by a seller or platform voucher. Choose a unique code.']);
        }

        $oldPath = $voucher->image_path;
        $newPath = null;
        if ($this->marketingMediaSchemaReady()) {
            $data = array_merge($data, $this->campaignMediaAttributes($data, $oldPath));
            unset($data['campaign_image'], $data['remove_image']);
            $newPath = $data['image_path'];
        }
        $oldHomepageImagePaths = [
            $voucher->homepage_carousel_desktop_image_path,
            $voucher->homepage_carousel_mobile_image_path,
        ];
        $newHomepageImagePaths = [];
        if ($this->homepageCarouselSchemaReady()) {
            $homepageMedia = $this->homepageCarouselMediaAttributes($data, $voucher);
            $data = array_merge($data, $homepageMedia);
            $newHomepageImagePaths = array_values(array_filter($homepageMedia));
        }

        try {
            DB::transaction(function () use ($voucher, $data, $code, $request, $campaignBudgetReady): void {
                $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
                if ($campaignBudgetReady) {
                    $budget = $data['campaign_budget_total'];
                    $reason = $data['campaign_budget_reason'];
                    unset($data['campaign_budget_total'], $data['campaign_budget_reason']);
                }
                $locked->update(array_merge($data, ['platform_code_key' => $code, 'code' => $code]));
                if ($campaignBudgetReady) {
                    app(PlatformCampaignBudgetService::class)->setAllocation($locked, $budget, $reason, $request->user());
                }
            });
        } catch (Throwable $exception) {
            if ($newPath && $newPath !== $oldPath) {
                Storage::disk('public')->delete($newPath);
            }
            $this->deleteHomepageCarouselImages(array_values(array_diff($newHomepageImagePaths, $oldHomepageImagePaths)));

            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath) {
            $this->deleteMarketingImageIfUnused($oldPath);
        }
        $this->deleteHomepageCarouselImages(array_values(array_diff($oldHomepageImagePaths, $newHomepageImagePaths)));

        return redirect()->route('admin.marketing', ['section' => 'campaigns'])->with('status', 'Platform campaign updated. Existing orders and funding history are unchanged.');
    }

    private function validatedCampaign(Request $request, ?Voucher $campaign = null): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:80'],
            'benefit_scope' => ['required', Rule::in(['PRODUCT', 'SHIPPING'])],
            'discount_type' => ['required', Rule::in(['PERCENT', 'FIXED'])],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ];

        if (app(PlatformCampaignEligibility::class)->controlsReady()) {
            $rules += [
                'distribution_mode' => ['sometimes', Rule::in(['CODE', 'CLAIMABLE', 'AUTO'])],
                'target_mode' => ['sometimes', Rule::in(['ALL', 'CATEGORIES', 'PRODUCTS', 'SELLERS'])],
                'target_ids' => ['sometimes', 'array', 'max:500'],
                'target_ids.*' => ['integer', 'distinct', 'min:1'],
                'buyer_audience' => ['sometimes', Rule::in(['ALL', 'FIRST_TIME', 'EXISTING'])],
                'stack_with_seller' => ['sometimes', 'boolean'],
                'stack_with_shipping' => ['sometimes', 'boolean'],
                'campaign_status' => ['sometimes', Rule::in(['DRAFT', 'ACTIVE', 'PAUSED', 'ENDED'])],
            ];
        }

        if ($this->campaignBudgetSchemaReady()) {
            $rules['campaign_budget_total'] = ['required', 'numeric', 'min:0.01', 'max:9999999999.99'];
            $rules['campaign_budget_reason'] = ['required', 'string', 'max:500'];
        }

        if ($this->marketingMediaSchemaReady()) {
            $rules += $this->marketingMediaRules('campaign_image');
        }

        $homepageCarouselReady = $this->homepageCarouselSchemaReady();
        if ($homepageCarouselReady) {
            $rules += [
                'homepage_carousel_enabled' => ['sometimes', 'boolean'],
                'homepage_carousel_headline' => ['sometimes', 'nullable', 'string', 'max:150'],
                'homepage_carousel_description' => ['sometimes', 'nullable', 'string', 'max:280'],
                'homepage_carousel_cta_label' => ['sometimes', 'nullable', 'string', 'max:60'],
                'homepage_carousel_cta_destination' => ['sometimes', 'required', Rule::in(['EVENT', 'OFFERS'])],
                'homepage_carousel_priority' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
                'homepage_carousel_desktop_image' => ['sometimes', ...$this->marketingImageFileRules()],
                'homepage_carousel_mobile_image' => ['sometimes', ...$this->marketingImageFileRules()],
                'remove_homepage_carousel_desktop_image' => ['sometimes', 'boolean'],
                'remove_homepage_carousel_mobile_image' => ['sometimes', 'boolean'],
            ];
        }

        $data = $request->validate($rules);

        if ($homepageCarouselReady) {
            $enabled = array_key_exists('homepage_carousel_enabled', $data)
                ? filter_var($data['homepage_carousel_enabled'], FILTER_VALIDATE_BOOLEAN)
                : (bool) $campaign?->homepage_carousel_enabled;
            $desktopImageWillRemain = $request->hasFile('homepage_carousel_desktop_image')
                || ($campaign?->homepage_carousel_desktop_image_path
                    && ! $request->boolean('remove_homepage_carousel_desktop_image')
                    && Storage::disk('public')->exists($campaign->homepage_carousel_desktop_image_path));
            if ($enabled && ! $desktopImageWillRemain) {
                throw ValidationException::withMessages([
                    'homepage_carousel_desktop_image' => 'Add desktop promotional artwork before enabling this campaign in the Buyer Hero Carousel.',
                ]);
            }
        }

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && Carbon::parse($data['ends_at'])->lessThan(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'The campaign end time must be after its start time.']);
        }

        if ($data['discount_type'] === 'PERCENT' && (float) $data['discount_value'] > 99.99) {
            throw ValidationException::withMessages(['discount_value' => 'Percentage discounts must be 99.99% or less so item prices remain positive.']);
        }

        if (Schema::hasColumn('vouchers', 'requires_review')) {
            $data['requires_review'] = false;
            $data['review_reason'] = null;
        }

        return $data;
    }

    private function campaignControlAttributes(array $data, ?Voucher $campaign): array
    {
        if (! app(PlatformCampaignEligibility::class)->controlsReady()) {
            return $data;
        }
        $mode = $data['target_mode'] ?? $campaign?->target_mode ?? 'ALL';
        $existingIds = match ($mode) {
            'CATEGORIES' => $campaign?->target_category_ids,
            'PRODUCTS' => $campaign?->target_product_ids,
            'SELLERS' => $campaign?->target_seller_ids,
            default => [],
        };
        $ids = array_values(array_unique(array_map('intval', (array) ($data['target_ids'] ?? $existingIds ?? []))));
        if (($data['benefit_scope'] ?? $campaign?->benefit_scope) === 'SHIPPING' && $mode !== 'ALL') {
            throw ValidationException::withMessages(['target_mode' => 'Shipping campaigns apply to shipping fees only.']);
        }
        if ($mode !== 'ALL' && $ids === []) {
            throw ValidationException::withMessages(['target_ids' => 'Choose at least one eligible target.']);
        }
        if ($ids !== []) {
            $query = match ($mode) {
                'CATEGORIES' => Category::query()->where('is_active', true)->whereNull('archived_at'),
                'PRODUCTS' => Product::query()->where('status', 'ACTIVE')->whereNull('deleted_at'),
                'SELLERS' => SellerProfile::query()->where('status', 'ACTIVE'),
                default => null,
            };
            if (! $query || $query->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['target_ids' => 'One or more selected targets are unavailable.']);
            }
        }
        unset($data['target_ids']);
        $data['target_mode'] = $mode;
        foreach (['CATEGORIES' => 'target_category_ids', 'PRODUCTS' => 'target_product_ids', 'SELLERS' => 'target_seller_ids'] as $targetMode => $column) {
            $data[$column] = $mode === $targetMode ? $ids : null;
        }
        $data['distribution_mode'] ??= $campaign?->distribution_mode ?? 'CODE';
        $data['buyer_audience'] ??= $campaign?->buyer_audience ?? 'ALL';
        $data['stack_with_seller'] = filter_var($data['stack_with_seller'] ?? $campaign?->stack_with_seller ?? true, FILTER_VALIDATE_BOOLEAN);
        $data['stack_with_shipping'] = filter_var($data['stack_with_shipping'] ?? $campaign?->stack_with_shipping ?? true, FILTER_VALIDATE_BOOLEAN);
        $data['campaign_status'] ??= $campaign?->campaign_status ?? 'ACTIVE';
        $data['is_active'] = $data['campaign_status'] === 'ACTIVE';

        return $data;
    }

    public function campaignTargets(Request $request): JsonResponse
    {
        abort_unless(app(PlatformCampaignEligibility::class)->controlsReady(), 404);
        $data = $request->validate([
            'mode' => ['required', Rule::in(['CATEGORIES', 'PRODUCTS', 'SELLERS'])],
            'q' => ['nullable', 'string', 'max:80'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $term = trim((string) ($data['q'] ?? ''));
        $query = match ($data['mode']) {
            'CATEGORIES' => Category::query()->where('is_active', true)->whereNull('archived_at')->select(['id', 'name']),
            'PRODUCTS' => Product::query()->where('status', 'ACTIVE')->select(['id', 'name']),
            'SELLERS' => SellerProfile::query()->where('status', 'ACTIVE')->select(['id', 'business_name']),
        };
        $column = $data['mode'] === 'SELLERS' ? 'business_name' : 'name';
        $results = $query->when($term !== '', fn ($builder) => $builder->where($column, 'like', '%'.$term.'%'))
            ->orderBy($column)->orderBy('id')->paginate(20);

        return response()->json([
            'data' => $results->getCollection()->map(fn ($record): array => ['id' => (int) $record->id, 'label' => (string) $record->{$column}])->values(),
            'current_page' => $results->currentPage(),
            'last_page' => $results->lastPage(),
        ]);
    }

    public function toggleCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($this->campaignSchemaReady(), 404);
        abort_unless($voucher->seller_profile_id === null && $voucher->platform_code_key !== null, 404);

        return $this->setCampaignStatus($request, $voucher, $voucher->is_active ? 'PAUSED' : 'ACTIVE');
    }

    public function updateCampaignStatus(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($this->campaignSchemaReady(), 404);
        abort_unless($voucher->seller_profile_id === null && $voucher->platform_code_key !== null, 404);
        abort_unless(app(PlatformCampaignEligibility::class)->controlsReady(), 409);
        $data = $request->validate(['status' => ['required', Rule::in(['DRAFT', 'ACTIVE', 'PAUSED', 'ENDED'])]]);

        return $this->setCampaignStatus($request, $voucher, $data['status']);
    }

    private function setCampaignStatus(Request $request, Voucher $voucher, string $status): RedirectResponse
    {
        DB::transaction(function () use ($request, $voucher, $status): void {
            $locked = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($status === 'ACTIVE') {
                abort_unless(! $locked->requiresReview(), 409, 'This campaign needs review before activation.');
                abort_unless($this->campaignBudgetSchemaReady(), 409, 'Campaign budget finance fields are not installed.');
                abort_unless(app(PlatformCampaignBudgetService::class)->selectable($locked), 409, 'Allocate additional budget before activation.');
                abort_unless(! $locked->ends_at || $locked->ends_at->gte(now()), 409, 'Extend the campaign end date before activation.');
            }
            $before = ['status' => app(PlatformCampaignEligibility::class)->status($locked), 'is_active' => (bool) $locked->is_active];
            $attributes = ['is_active' => $status === 'ACTIVE'];
            if (app(PlatformCampaignEligibility::class)->controlsReady()) {
                $attributes['campaign_status'] = $status;
            }
            $locked->update($attributes);
            if (Schema::hasTable('finance_audit_events')) {
                FinanceAuditEvent::query()->create([
                    'event_type' => 'PLATFORM_CAMPAIGN_STATUS_CHANGED',
                    'auditable_type' => Voucher::class,
                    'auditable_id' => $locked->id,
                    'actor_user_id' => $request->user()->id,
                    'before_state' => $before,
                    'after_state' => ['status' => $status, 'is_active' => (bool) $locked->is_active],
                    'notes' => 'Admin campaign status action',
                ]);
            }
        });

        return redirect()->route('admin.marketing', ['section' => 'campaigns'])->with('status', 'Platform campaign status updated. Existing redemptions and orders are unchanged.');
    }

    public function storeAnnouncement(
        Request $request,
        AnnouncementNotificationPublisher $publisher,
        AnnouncementDestination $destinations,
    ): RedirectResponse
    {
        $data = $this->validatedAnnouncement($request, $destinations);
        $audienceTargetingReady = Schema::hasColumn('announcements', 'target_audience');
        $audience = $data['target_audience'] ?? 'ALL';
        if (! $audienceTargetingReady && $audience !== 'ALL') {
            throw ValidationException::withMessages([
                'target_audience' => 'Audience targeting is unavailable until the prepared announcement migration is applied. Choose All users.',
            ]);
        }

        $publishedAt = isset($data['published_at']) ? Carbon::parse($data['published_at']) : now();
        if (! empty($data['expires_at']) && Carbon::parse($data['expires_at'])->lessThanOrEqualTo($publishedAt)) {
            throw ValidationException::withMessages(['expires_at' => 'The expiration time must be later than the publish time.']);
        }

        $attributes = [
            'created_by_user_id' => $request->user()->id,
            'title' => $data['title'],
            'body' => $data['body'],
            'published_at' => $publishedAt,
            'expires_at' => $data['expires_at'] ?? null,
            'is_active' => true,
        ];
        if ($audienceTargetingReady) {
            $attributes['target_audience'] = $audience;
        }

        $newPath = null;
        if ($this->marketingMediaSchemaReady()) {
            $media = $this->announcementMediaAttributes($data, null);
            $attributes = array_merge($attributes, $media, [
                'show_popup' => $data['show_popup'] ?? true,
                'cta_label' => $data['cta_label'] ?? null,
                'cta_url' => $data['cta_url'] ?? null,
            ]);
            $newPath = $attributes['image_path'];
        }

        try {
            $announcement = DB::transaction(fn () => Announcement::query()->create($attributes));
        } catch (Throwable $exception) {
            if ($newPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        $deliveredCount = $publisher->publish($announcement);
        $status = $publishedAt->isFuture()
            ? 'Announcement saved and scheduled for notification delivery.'
            : "Announcement published to {$deliveredCount} active users.";

        return redirect()->route('admin.marketing', ['section' => 'announcements'])->with('status', $status);
    }

    public function updateAnnouncement(
        Request $request,
        Announcement $announcement,
        AnnouncementNotificationPublisher $publisher,
        AnnouncementDestination $destinations,
    ): RedirectResponse {
        $data = $this->validatedAnnouncement($request, $destinations);
        $audienceTargetingReady = Schema::hasColumn('announcements', 'target_audience');
        $audience = $data['target_audience'] ?? 'ALL';
        if (! $audienceTargetingReady && $audience !== 'ALL') {
            throw ValidationException::withMessages([
                'target_audience' => 'Audience targeting is unavailable until the prepared announcement migration is applied. Choose All users.',
            ]);
        }

        $publishedAt = filled($data['published_at'] ?? null)
            ? Carbon::parse($data['published_at'])
            : now();
        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;
        $validationStart = $publishedAt ?? now();
        if ($expiresAt && $expiresAt->lessThanOrEqualTo($validationStart)) {
            throw ValidationException::withMessages(['expires_at' => 'The expiration time must be later than the publish time.']);
        }

        $attributes = [
            'title' => $data['title'],
            'body' => $data['body'],
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
        ];
        if ($audienceTargetingReady) {
            $attributes['target_audience'] = $audience;
        }

        $oldPath = $announcement->image_path;
        $newPath = $oldPath;
        if ($this->marketingMediaSchemaReady()) {
            $attributes = array_merge($attributes, $this->announcementMediaAttributes($data, $oldPath), [
                'show_popup' => $data['show_popup'] ?? false,
                'cta_label' => $data['cta_label'] ?? null,
                'cta_url' => $data['cta_url'] ?? null,
            ]);
            $newPath = $attributes['image_path'];
        }

        try {
            DB::transaction(function () use ($announcement, $attributes): void {
                $announcement->update($attributes);
                Notification::query()
                    ->where('type', 'ANNOUNCEMENT')
                    ->where('reference_type', Announcement::class)
                    ->where('reference_id', $announcement->id)
                    ->update([
                        'title' => $attributes['title'],
                        'message' => $attributes['body'],
                        'updated_at' => now(),
                    ]);
            });
        } catch (Throwable $exception) {
            if ($newPath && $newPath !== $oldPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath) {
            $this->deleteMarketingImageIfUnused($oldPath);
        }

        $announcement->refresh();
        $deliveredCount = $publisher->publish($announcement);
        $status = $publishedAt?->isFuture()
            ? 'Announcement saved and scheduled for notification delivery.'
            : "Announcement updated. {$deliveredCount} additional active users were notified.";

        return redirect()->route('admin.marketing', ['section' => 'announcements'])->with('status', $status);
    }

    private function validatedAnnouncement(Request $request, AnnouncementDestination $destinations): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'target_audience' => ['nullable', 'string', Rule::in(['ALL', 'BUYERS', 'SELLERS', 'LOGISTICS', 'LOGISTICS_CENTERS', 'RIDERS'])],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ];

        if ($this->marketingMediaSchemaReady()) {
            $rules += $this->marketingMediaRules('announcement_image');
            $rules += [
                'cta_label' => ['nullable', 'string', 'max:80'],
                'cta_url' => ['nullable', 'string', 'max:500'],
                'show_popup' => ['sometimes', 'boolean'],
            ];
        }

        $data = $request->validate($rules);
        $hasCtaLabel = filled($data['cta_label'] ?? null);
        $hasCtaUrl = filled($data['cta_url'] ?? null);
        if ($hasCtaLabel !== $hasCtaUrl) {
            throw ValidationException::withMessages(['cta_url' => 'Enter both a CTA label and a destination, or leave both blank.']);
        }
        if ($hasCtaUrl && ! $destinations->resolve($data['cta_url'])) {
            throw ValidationException::withMessages(['cta_url' => 'Choose a page from the destination list.']);
        }

        return $data;
    }

    public function toggleAnnouncement(Announcement $announcement): RedirectResponse
    {
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return redirect()->route('admin.marketing', ['section' => 'announcements'])->with('status', 'Announcement status updated.');
    }

    private function marketingMediaRules(string $fileField): array
    {
        return [
            $fileField => $this->marketingImageFileRules(),
            'image_alt' => ['nullable', 'string', 'max:255'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    private function campaignMediaAttributes(array $data, ?string $existingPath): array
    {
        return $this->mediaAttributes($data, 'campaign_image', 'marketing/campaigns', $existingPath);
    }

    /** @return list<mixed> */
    private function marketingImageFileRules(): array
    {
        return [
            'nullable',
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:5120',
            static function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value instanceof UploadedFile && @getimagesize($value->getRealPath()) === false) {
                    $fail('The uploaded file must contain valid image data.');
                }
            },
        ];
    }

    /** @return array{homepage_carousel_desktop_image_path: ?string, homepage_carousel_mobile_image_path: ?string} */
    private function homepageCarouselMediaAttributes(array &$data, ?Voucher $existing): array
    {
        $images = [
            'homepage_carousel_desktop_image' => [
                'path' => 'homepage_carousel_desktop_image_path',
                'remove' => 'remove_homepage_carousel_desktop_image',
                'existing' => $existing?->homepage_carousel_desktop_image_path,
            ],
            'homepage_carousel_mobile_image' => [
                'path' => 'homepage_carousel_mobile_image_path',
                'remove' => 'remove_homepage_carousel_mobile_image',
                'existing' => $existing?->homepage_carousel_mobile_image_path,
            ],
        ];
        $attributes = [];

        foreach ($images as $field => $config) {
            $image = $data[$field] ?? null;
            $remove = filter_var($data[$config['remove']] ?? false, FILTER_VALIDATE_BOOLEAN);
            unset($data[$field], $data[$config['remove']]);

            $path = $config['existing'];
            if ($image instanceof UploadedFile) {
                $path = $image->store('marketing/platform-events', 'public');
                if (! is_string($path) || $path === '') {
                    throw new \RuntimeException('The uploaded homepage campaign artwork could not be stored.');
                }
            } elseif ($remove) {
                $path = null;
            }

            $attributes[$config['path']] = $path;
        }

        return $attributes;
    }

    /** @param list<string> $paths */
    private function deleteHomepageCarouselImages(array $paths): void
    {
        foreach (array_unique(array_filter($paths)) as $path) {
            if (! str_starts_with($path, 'marketing/platform-events/')) {
                continue;
            }

            $desktopStillUsed = Schema::hasColumn('vouchers', 'homepage_carousel_desktop_image_path')
                && DB::table('vouchers')->where('homepage_carousel_desktop_image_path', $path)->exists();
            $mobileStillUsed = Schema::hasColumn('vouchers', 'homepage_carousel_mobile_image_path')
                && DB::table('vouchers')->where('homepage_carousel_mobile_image_path', $path)->exists();
            if (! $desktopStillUsed && ! $mobileStillUsed) {
                Storage::disk('public')->delete($path);
            }
        }
    }

    private function announcementMediaAttributes(array $data, ?string $existingPath): array
    {
        return $this->mediaAttributes($data, 'announcement_image', 'marketing/announcements', $existingPath);
    }

    private function mediaAttributes(array $data, string $fileField, string $directory, ?string $existingPath): array
    {
        $image = $data[$fileField] ?? null;
        $removeImage = filter_var($data['remove_image'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($data[$fileField], $data['remove_image']);

        $imagePath = $existingPath;
        if ($image instanceof UploadedFile) {
            $imagePath = $image->store($directory, 'public');
            if (! is_string($imagePath) || $imagePath === '') {
                throw new \RuntimeException('The uploaded marketing image could not be stored.');
            }
        } elseif ($removeImage) {
            $imagePath = null;
        }

        return [
            'image_path' => $imagePath,
            'image_alt' => $imagePath ? ($data['image_alt'] ?? null) : null,
            'theme_color' => $data['theme_color'] ?? null,
        ];
    }

    private function deleteMarketingImageIfUnused(?string $path): void
    {
        if (! $path
            || (! str_starts_with($path, 'marketing/campaigns/') && ! str_starts_with($path, 'marketing/announcements/'))) {
            return;
        }

        $announcementUsesImage = DB::table('announcements')->where('image_path', $path)->exists();
        $campaignUsesImage = DB::table('vouchers')->where('image_path', $path)->exists();
        $homepageCarouselUsesImage = (Schema::hasColumn('vouchers', 'homepage_carousel_desktop_image_path')
                && DB::table('vouchers')->where('homepage_carousel_desktop_image_path', $path)->exists())
            || (Schema::hasColumn('vouchers', 'homepage_carousel_mobile_image_path')
                && DB::table('vouchers')->where('homepage_carousel_mobile_image_path', $path)->exists());

        if (! $announcementUsesImage && ! $campaignUsesImage && ! $homepageCarouselUsesImage) {
            Storage::disk('public')->delete($path);
        }
    }

    private function marketingMediaSchemaReady(): bool
    {
        foreach ([
            'announcements' => ['image_path', 'image_alt', 'theme_color', 'cta_label', 'cta_url', 'show_popup'],
            'vouchers' => ['image_path', 'image_alt', 'theme_color'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function campaignSchemaReady(): bool
    {
        return Schema::hasColumn('vouchers', 'seller_profile_id')
            && Schema::hasColumn('vouchers', 'platform_code_key');
    }

    private function campaignBudgetSchemaReady(): bool
    {
        return Schema::hasColumn('vouchers', 'campaign_budget_total')
            && Schema::hasColumn('seller_orders', 'seller_voucher_discount')
            && Schema::hasColumn('order_items', 'seller_voucher_discount')
            && Schema::hasColumn('financial_ledger_entries', 'voucher_id')
            && Schema::hasColumn('financial_ledger_entries', 'order_item_id');
    }

    private function homepageCarouselSchemaReady(): bool
    {
        foreach ([
            'homepage_carousel_enabled',
            'homepage_carousel_desktop_image_path',
            'homepage_carousel_mobile_image_path',
            'homepage_carousel_headline',
            'homepage_carousel_description',
            'homepage_carousel_cta_label',
            'homepage_carousel_cta_destination',
            'homepage_carousel_priority',
        ] as $column) {
            if (! Schema::hasColumn('vouchers', $column)) {
                return false;
            }
        }

        return true;
    }
}
