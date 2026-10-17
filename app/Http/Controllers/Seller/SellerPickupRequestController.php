<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Seller\SellerPickupRequest;
use App\Services\Fulfillment\SellerPickupRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class SellerPickupRequestController extends Controller
{
    public function store(Request $request, SellerPickupRequestService $pickupRequests): RedirectResponse
    {
        $data = $request->validate([
            'pickup_address_id' => ['required', 'integer', 'min:1'],
            'shipment_ids' => ['required', 'array', 'min:1', 'max:10'],
            'shipment_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'seller_order_ids' => ['required', 'array'],
            'seller_order_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $pickup = $pickupRequests->create(
                $request->user(),
                (int) $data['pickup_address_id'],
                array_map('intval', $data['shipment_ids']),
                $data['seller_order_ids'],
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors(['shipment_ids' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('seller.logistics')
            ->with('status', 'Pickup request #'.$pickup->id.' created for '.$pickup->memberships->count().' parcel(s).');
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
