<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\Announcement;
use App\Models\Seller\Voucher;
use App\Services\Notifications\AnnouncementNotificationPublisher;
use App\Services\Marketplace\VoucherRedemptionCounter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminMarketingController extends Controller
{
    public function index(Request $request): View
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

        Voucher::query()->create(array_merge($data, [
            'seller_profile_id' => null,
            'platform_code_key' => $code,
            'code' => $code,
            'is_active' => true,
        ]));

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

        $voucher->update(array_merge($data, [
            'platform_code_key' => $code,
            'code' => $code,
        ]));

        return redirect()->route('admin.marketing', ['section' => 'campaigns'])->with('status', 'Platform campaign updated. Existing redemptions and orders are unchanged.');
    }

    private function validatedCampaign(Request $request): array
    {
        $data = $request->validate([
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
        ]);

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

    public function storeAnnouncement(Request $request, AnnouncementNotificationPublisher $publisher): RedirectResponse
    {
        $audienceTargetingReady = Schema::hasColumn('announcements', 'target_audience');
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'target_audience' => ['nullable', 'string', Rule::in(['ALL', 'BUYERS', 'SELLERS', 'LOGISTICS'])],
            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
        ]);

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

        $announcement = Announcement::query()->create($attributes);
        $deliveredCount = $publisher->publish($announcement);
        $status = $publishedAt->isFuture()
            ? 'Announcement saved and scheduled for notification delivery.'
            : "Announcement published to {$deliveredCount} active users.";

        return redirect()->route('admin.marketing', ['section' => 'announcements'])->with('status', $status);
    }

    public function toggleAnnouncement(Announcement $announcement): RedirectResponse
    {
        $announcement->update(['is_active' => ! $announcement->is_active]);

        return redirect()->route('admin.marketing', ['section' => 'announcements'])->with('status', 'Announcement status updated.');
    }

    private function campaignSchemaReady(): bool
    {
        return Schema::hasColumn('vouchers', 'seller_profile_id')
            && Schema::hasColumn('vouchers', 'platform_code_key');
    }
}
