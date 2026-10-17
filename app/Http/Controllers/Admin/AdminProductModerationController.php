<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin\ProductModerationScan;
use App\Models\Seller\Product;
use App\Services\Moderation\ProductModerationProviderException;
use App\Services\Moderation\ProductModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminProductModerationController extends Controller
{
    public function rescan(Request $request, Product $product, ProductModerationService $moderation): RedirectResponse
    {
        abort_unless(Schema::hasTable('product_moderation_scans'), 503, 'Product moderation storage is not ready.');
        abort_unless(in_array($product->status, ['ACTIVE', 'SUSPENDED'], true), 409, 'Only published or held listings can be rescanned.');

        $minutes = max(1, (int) config('product_moderation.queue.manual_rescan_cooldown_minutes', 10));
        $key = 'product-moderation-manual-rescan:'.$product->id;
        if (! Cache::add($key, true, now()->addMinutes($minutes))) {
            return back()->withErrors(['moderation' => 'A manual scan was requested recently. Try again after the cooldown.']);
        }

        try {
            $scan = $moderation->queueManual($product, $request->user());
        } catch (ProductModerationProviderException $exception) {
            Cache::forget($key);

            $message = $exception->failureCode === 'AI_PRODUCT_UNAVAILABLE'
                ? 'This listing is no longer available to scan. Refresh and try again.'
                : 'Product moderation AI is not enabled or configured for the selected primary provider and backup.';

            return back()->withErrors(['moderation' => $message]);
        } catch (\Throwable $exception) {
            Cache::forget($key);
            throw $exception;
        }

        return back()->with('status', 'Manual moderation scan #'.$scan->id.' was queued.');
    }

    public function review(Request $request, Product $product, ProductModerationScan $scan, ProductModerationService $moderation): RedirectResponse
    {
        abort_unless((int) $scan->product_id === (int) $product->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['FALSE_POSITIVE', 'CONFIRMED_POLICY_VIOLATION', 'REQUEST_SELLER_EDIT'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        try {
            $moderation->recordHumanReview($scan, $request->user(), $data['decision'], trim($data['reason']));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            throw $exception;
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['moderation' => $exception->getMessage()]);
        }

        return back()->with('status', 'Human moderation decision recorded.');
    }

    public function reviewAppeal(Request $request, Product $product, ProductModerationScan $scan, ProductModerationService $moderation): RedirectResponse
    {
        abort_unless((int) $scan->product_id === (int) $product->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'DENIED', 'REQUEST_CLARIFICATION'])],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $moderation->recordHumanReview($scan, $request->user(), $data['decision'], trim($data['reason']), true);

        return back()->with('status', 'Appeal decision recorded.');
    }

    public function appealEvidence(Product $product, ProductModerationScan $scan)
    {
        abort_unless((int) $scan->product_id === (int) $product->id, 404);
        abort_unless($scan->appeal_evidence_path && Storage::disk('local')->exists($scan->appeal_evidence_path), 404);

        return Storage::disk('local')->download(
            $scan->appeal_evidence_path,
            $scan->appeal_evidence_original_name ?: 'product-moderation-appeal-evidence',
            array_filter(['Content-Type' => $scan->appeal_evidence_mime]),
        );
    }
}
