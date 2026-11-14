<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\SellerPickupRequest;
use App\Services\Fulfillment\SellerPickupRequestService;
use App\Services\Fulfillment\SellerPickupMatchingService;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SellerPickupRequestController extends Controller
{
    public function store(
        Request $request,
        SellerPickupRequestService $pickupRequests,
        SellerPickupMatchingService $matching,
        LogisticsSchemaReadiness $schema,
    ): RedirectResponse
    {
        abort_unless($schema->sellerPickupCenterSelectionReady(), 503, 'Seller Logistics selection is not available until the pickup-center migration is installed.');

        $automaticMatching = $schema->automaticSellerPickupMatchingReady();
        $manualCenterOverride = $automaticMatching && $request->boolean('manual_center_override');
        $data = $request->validate([
            'pickup_address_id' => ['required', 'integer', 'min:1'],
            'preferred_logistics_center_id' => [$automaticMatching && ! $manualCenterOverride ? 'nullable' : 'required', 'integer', 'min:1'],
            'preferred_destination_logistics_center_ids' => ['nullable', 'array'],
            'preferred_destination_logistics_center_ids.*' => ['required', 'integer', 'min:1'],
            'seller_selected_fallback' => ['nullable', 'boolean'],
            'manual_center_override' => ['nullable', 'boolean'],
            'retry_pickup_request_id' => ['nullable', 'integer', 'min:1'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:10'],
            'shipment_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'seller_order_ids' => ['required', 'array'],
            'seller_order_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $pickup = $automaticMatching
                ? $matching->createAutomaticRequest(
                    $request->user(),
                    (int) $data['pickup_address_id'],
                    array_map('intval', $data['shipment_ids']),
                    $data['seller_order_ids'],
                    filled($data['preferred_logistics_center_id'] ?? null)
                        ? (int) $data['preferred_logistics_center_id']
                        : null,
                    $data['preferred_destination_logistics_center_ids'] ?? [],
                    (bool) ($data['seller_selected_fallback'] ?? false),
                    $manualCenterOverride,
                    filled($data['retry_pickup_request_id'] ?? null)
                        ? (int) $data['retry_pickup_request_id']
                        : null,
                )
                : $pickupRequests->create(
                    $request->user(),
                    (int) $data['pickup_address_id'],
                    array_map('intval', $data['shipment_ids']),
                    $data['seller_order_ids'],
                    (int) $data['preferred_logistics_center_id'],
                );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['shipment_ids' => $exception->getMessage()])->withInput();
        }

        $message = $automaticMatching && $manualCenterOverride
            ? 'Pickup request #'.$pickup->id.' was retried with your selected Logistics center as the first choice.'
            : ($automaticMatching
            ? ($pickup->status === 'WAITING_FOR_RESPONSE'
                ? 'Pickup request #'.$pickup->id.' was sent to an eligible Logistics center and is waiting for acceptance.'
                : ((bool) $pickup->seller_selected_fallback
                    ? 'Pickup request #'.$pickup->id.' is pending while LIKHAE checks pickup Rider, inter-hub vehicle, and destination delivery capacity.'
                    : 'Pickup request #'.$pickup->id.' is pending while LIKHAE searches for a center with pickup coverage, a complete route, and available capacity.'))
            : 'Pickup request #'.$pickup->id.' created for '.$pickup->memberships->count().' parcel(s).');

        return redirect()->route('seller.logistics')->with('status', $message);
    }

    public function cancel(Request $request, SellerPickupRequest $pickupRequest, SellerPickupRequestService $pickupRequests): RedirectResponse
    {
        try {
            $pickupRequests->cancelBySeller($request->user(), $pickupRequest);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['pickup_request' => $exception->getMessage()]);
        }

        return back()->with('status', 'Pickup request #'.$pickupRequest->id.' cancelled. Its parcel history remains available.');
    }

    public function progress(
        Request $request,
        SellerPickupRequest $pickupRequest,
        SellerPickupRequestService $pickupRequests,
        SellerPickupMatchingService $matching,
    ): JsonResponse
    {
        $sellerId = (int) $request->user()->sellerProfile?->id;
        abort_unless($sellerId > 0, 403);
        abort_unless((int) $pickupRequest->seller_profile_id === $sellerId, 404);

        $matching->expireDueOffersForRequest((int) $pickupRequest->id);
        $pickupRequest->refresh();

        return response()->json($pickupRequests->progress($pickupRequest, $sellerId))
            ->header('Cache-Control', 'private, no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
