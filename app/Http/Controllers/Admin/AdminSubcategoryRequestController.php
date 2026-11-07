<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Seller\Category;
use App\Models\Seller\SellerSubcategoryRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class AdminSubcategoryRequestController extends Controller
{
    public function decide(Request $request, SellerSubcategoryRequest $subcategoryRequest): RedirectResponse
    {
        abort_unless(Schema::hasTable('seller_subcategory_requests'), 503);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:link,reject'],
            'existing_category_id' => ['required_if:action,link', 'nullable', 'integer', 'min:1'],
            'resolution' => ['required', 'string', 'min:5', 'max:3000'],
        ]);

        try {
            DB::transaction(function () use ($request, $subcategoryRequest, $data): void {
                $lockedRequest = SellerSubcategoryRequest::query()
                    ->whereKey($subcategoryRequest->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($lockedRequest->status !== SellerSubcategoryRequest::STATUS_PENDING) {
                    throw new RuntimeException('This request has already been reviewed.');
                }

                $parent = $data['action'] === 'reject'
                    ? null
                    : Category::query()
                        ->whereKey($lockedRequest->parent_category_id)
                        ->whereNull('parent_id')
                        ->where('is_active', true)
                        ->whereNull('archived_at')
                        ->lockForUpdate()
                        ->first();
                if ($data['action'] !== 'reject' && ! $parent) {
                    throw new RuntimeException('The requested parent category is no longer active. Restore it before approving this request.');
                }

                $resolvedCategory = null;
                if ($data['action'] === 'link') {
                    $resolvedCategory = Category::query()
                        ->whereKey((int) $data['existing_category_id'])
                        ->where('parent_id', $parent->id)
                        ->where('is_active', true)
                        ->whereNull('archived_at')
                        ->first();
                    if (! $resolvedCategory) {
                        throw new RuntimeException('Choose an active subcategory under '.$parent->name.'.');
                    }
                }

                $lockedRequest->update([
                    'status' => $data['action'] === 'reject'
                        ? SellerSubcategoryRequest::STATUS_REJECTED
                        : SellerSubcategoryRequest::STATUS_APPROVED,
                    'resolved_category_id' => $resolvedCategory?->id,
                    'resolution' => trim($data['resolution']),
                    'reviewed_by_user_id' => $request->user()->id,
                    'reviewed_at' => now(),
                ]);
            });
        } catch (RuntimeException $exception) {
            return back()->withErrors(['subcategory_request' => $exception->getMessage()]);
        }

        return back()->with('status', $data['action'] === 'link'
            ? 'Request approved and linked to the existing subcategory. No category was created during review.'
            : 'Subcategory request rejected with the resolution recorded for the Seller.');
    }
}
