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
        $data = $request->validate([
            'pickup_address_id' => ['required', 'integer', 'min:1'],
            'preferred_logistics_center_id' => [$automaticMatching ? 'nullable' : 'required', 'integer', 'min:1'],
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

        $message = $automaticMatching
            ? ($pickup->status === 'WAITING_FOR_RESPONSE'
                ? 'Pickup request #'.$pickup->id.' was sent to your preferred center or the next eligible center and is waiting for acceptance.'
                : 'Pickup request #'.$pickup->id.' is pending while LIKHAE searches for a center with pickup coverage, a complete route, and available capacity.')
            : 'Pickup request #'.$pickup->id.' created for '.$pickup->memberships->count().' parcel(s).';

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

    public function progress(Request $request, SellerPickupRequest $pickupRequest, SellerPickupRequestService $pickupRequests): JsonResponse
    {
        $sellerId = (int) $request->user()->sellerProfile?->id;
        abort_unless($sellerId > 0, 403);

        return response()->json($pickupRequests->progress($pickupRequest, $sellerId));
    }
}
