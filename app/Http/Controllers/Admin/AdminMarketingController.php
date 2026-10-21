<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Announcement;
use App\Models\Admin\Notification;
use App\Models\Seller\Voucher;
use App\Services\Notifications\AnnouncementDestination;
use App\Services\Notifications\AnnouncementNotificationPublisher;
use App\Services\Marketplace\VoucherRedemptionCounter;
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
    public function index(Request $request, AnnouncementDestination $destinations): View
    {
        $campaignsReady = $this->campaignSchemaReady();
        $campaigns = $campaignsReady
            ? Voucher::query()->whereNull('seller_profile_id')->latest()->paginate(15, ['*'], 'campaigns_page')->withQueryString()
            : Voucher::query()->whereRaw('1 = 0')->latest()->paginate(15, ['*'], 'campaigns_page')->withQueryString();
        $redemptionCounts = VoucherRedemptionCounter::countsFor($campaigns->getCollection());
        $campaigns->getCollection()->each(fn (Voucher $voucher) => $voucher->setAttribute(
            'redemption_count',
            $redemptionCounts->get($voucher->id, 0),
        ));

        return view('Admin.marketing.index', [
            'campaigns' => $campaigns,
            'campaignsReady' => $campaignsReady,
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
        if (! $this->campaignSchemaReady()) {
            return redirect()->route('admin.marketing')->with('status', 'Platform campaigns are read-only until the prepared voucher migration is applied.');
        }

        $data = $this->validatedCampaign($request);

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

        try {
            DB::transaction(fn () => Voucher::query()->create(array_merge($data, [
                'seller_profile_id' => null,
                'platform_code_key' => $code,
                'code' => $code,
                'is_active' => true,
            ])));
        } catch (Throwable $exception) {
            if ($newPath && $newPath !== $existingPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        return redirect()->route('admin.marketing', ['section' => 'campaigns'])->with('status', 'Platform voucher campaign created. It defines checkout discounts only; finance must review funding and settlement.');
    }

    public function updateCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($this->campaignSchemaReady(), 404);
        abort_unless($voucher->seller_profile_id === null && $voucher->platform_code_key !== null, 404);

        $data = $this->validatedCampaign($request);
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

        try {
            DB::transaction(fn () => $voucher->update(array_merge($data, [
                'platform_code_key' => $code,
                'code' => $code,
            ])));
        } catch (Throwable $exception) {
            if ($newPath && $newPath !== $oldPath) {
                Storage::disk('public')->delete($newPath);
            }

            throw $exception;
        }

        if ($oldPath && $oldPath !== $newPath) {
            $this->deleteMarketingImageIfUnused($oldPath);
        }

        return redirect()->route('admin.marketing', ['section' => 'campaigns'])->with('status', 'Platform campaign updated. Existing redemptions and orders are unchanged.');
    }

    private function validatedCampaign(Request $request): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:80'],
            'discount_type' => ['required', Rule::in(['PERCENT', 'FIXED'])],
            'discount_value' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'minimum_order_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'maximum_discount_amount' => ['nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ];

        if ($this->marketingMediaSchemaReady()) {
            $rules += $this->marketingMediaRules('campaign_image');
        }

        $data = $request->validate($rules);

        if (! empty($data['starts_at']) && ! empty($data['ends_at'])
            && Carbon::parse($data['ends_at'])->lessThan(Carbon::parse($data['starts_at']))) {
            throw ValidationException::withMessages(['ends_at' => 'The campaign end time must be after its start time.']);
        }

        if ($data['discount_type'] === 'PERCENT' && (float) $data['discount_value'] > 100) {
            throw ValidationException::withMessages(['discount_value' => 'Percentage discounts cannot exceed 100%.']);
        }

        return $data;
    }

    public function toggleCampaign(Request $request, Voucher $voucher): RedirectResponse
    {
        abort_unless($this->campaignSchemaReady(), 404);
        abort_unless($voucher->seller_profile_id === null && $voucher->platform_code_key !== null, 404);

        $voucher->update(['is_active' => ! $voucher->is_active]);

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
            $fileField => [
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
            ],
            'image_alt' => ['nullable', 'string', 'max:255'],
            'theme_color' => ['nullable', 'string', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }

    private function campaignMediaAttributes(array $data, ?string $existingPath): array
    {
        return $this->mediaAttributes($data, 'campaign_image', 'marketing/campaigns', $existingPath);
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

        if (! $announcementUsesImage && ! $campaignUsesImage) {
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
}
