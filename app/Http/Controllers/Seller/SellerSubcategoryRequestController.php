<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\Category;
use App\Models\Seller\SellerProfile;
use App\Models\Seller\SellerSubcategoryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use RuntimeException;

class SellerSubcategoryRequestController extends Controller
{
    public function create(Request $request): View
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        $parentCategory = $seller->primaryCategory;
        if (! $parentCategory
            || $parentCategory->parent_id !== null
            || ! $parentCategory->is_active
            || $parentCategory->archived_at !== null) {
            $parentCategory = null;
        }

        $schemaReady = Schema::hasTable('seller_subcategory_requests');
        $requests = $schemaReady
            ? SellerSubcategoryRequest::query()
                ->where('seller_profile_id', $seller->id)
                ->with(['parentCategory', 'resolvedCategory.parent'])
                ->latest('id')
                ->limit(20)
                ->get()
            : collect();

        $existingSubcategories = $parentCategory
            ? $parentCategory->children()
                ->where('is_active', true)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get()
            : collect();

        return view('seller.subcategory-requests.create', compact(
            'seller', 'parentCategory', 'existingSubcategories', 'requests', 'schemaReady',
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $seller = $request->user()->sellerProfile;
        abort_unless($seller, 403);

        if (! Schema::hasTable('seller_subcategory_requests')) {
            return back()->withErrors(['subcategory_request' => 'Subcategory requests are not available until the request workflow migration is installed.']);
        }

        $parentCategoryId = (int) $seller->primary_category_id;
        $parentCategory = Category::query()
            ->whereKey($parentCategoryId)
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->whereNull('archived_at')
            ->first();
        if (! $parentCategory) {
            return back()->withErrors(['subcategory_request' => 'Your Seller account needs an active main category before it can request a subcategory.']);
        }

        $request->merge(['requested_name' => $this->normalizeName($request->input('requested_name'))]);
        $data = $request->validate([
            'requested_name' => ['required', 'string', 'max:150'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $created = DB::transaction(function () use ($seller, $parentCategory, $data): bool {
                $lockedSeller = SellerProfile::query()->whereKey($seller->id)->lockForUpdate()->firstOrFail();
                if ((int) $lockedSeller->primary_category_id !== (int) $parentCategory->id) {
                    throw new RuntimeException('Your store category changed. Reload the request page and submit again.');
                }
                $pendingDuplicate = SellerSubcategoryRequest::query()
                    ->where('seller_profile_id', $seller->id)
                    ->where('parent_category_id', $parentCategory->id)
                    ->where('status', SellerSubcategoryRequest::STATUS_PENDING)
                    ->whereRaw('LOWER(TRIM(requested_name)) = ?', [mb_strtolower($data['requested_name'])])
                    ->exists();

                if ($pendingDuplicate) {
                    return false;
                }

                SellerSubcategoryRequest::query()->create([
                    'seller_profile_id' => $seller->id,
                    'seller_business_name_snapshot' => $lockedSeller->business_name,
                    'parent_category_id' => $parentCategory->id,
                    'parent_category_name_snapshot' => $parentCategory->name,
                    'requested_name' => $data['requested_name'],
                    'reason' => trim($data['reason']),
                    'status' => SellerSubcategoryRequest::STATUS_PENDING,
                ]);

                return true;
            });
        } catch (RuntimeException $exception) {
            return back()->withErrors(['subcategory_request' => $exception->getMessage()])->withInput();
        }

        if (! $created) {
            return back()->withErrors(['requested_name' => 'You already have a pending request for this subcategory under your main category.'])->withInput();
        }

        return redirect()->route('seller.products.subcategory-requests.create')
            ->with('status', 'Your subcategory request was sent to Admin for review. No category was created.');
    }

    private function normalizeName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $name));

        return is_string($normalized) ? $normalized : trim((string) $name);
    }
}
