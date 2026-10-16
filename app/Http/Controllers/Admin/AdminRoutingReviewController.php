<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsCenter;
use App\Models\Logistics\Shipment;
use App\Models\Logistics\ShipmentEvent;
use App\Services\Fulfillment\LogisticsSchemaReadiness;
use App\Services\Fulfillment\PsgcLogisticsRoutingService;
use App\Services\Fulfillment\ShipmentTransferService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class AdminRoutingReviewController extends Controller
{
    public function index(LogisticsSchemaReadiness $schema): View
    {
        abort_unless($schema->automaticRoutingReady(), 503, 'PSGC routing review requires the approved routing fields and transfer tables.');

        $shipments = Shipment::query()
            ->where(function ($query): void {
                $query->whereNull('origin_logistics_center_id')
                    ->orWhereNull('destination_logistics_center_id')
                    ->orWhereNull('service_area_id');
            })
            ->whereIn('current_status', ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'])
            ->with(['sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address', 'orderItem', 'events'])
            ->oldest('created_at')
            ->paginate(25);
        $centers = LogisticsCenter::query()->approvedActive()->with('address')->orderBy('business_name')->get();

        return view('Admin.routing-review.index', compact('shipments', 'centers'));
    }

    public function resolve(
        Request $request,
        Shipment $shipment,
        LogisticsSchemaReadiness $schema,
        PsgcLogisticsRoutingService $routing,
        ShipmentTransferService $transfers,
    ): RedirectResponse {
        abort_unless($schema->automaticRoutingReady(), 503, 'PSGC routing review requires the approved routing fields and transfer tables.');
        $data = $request->validate([
            'origin_logistics_center_id' => ['required', 'integer', 'exists:logistics_centers,id'],
            'destination_logistics_center_id' => ['required', 'integer', 'exists:logistics_centers,id'],
        ]);

        DB::transaction(function () use ($request, $shipment, $data, $schema, $routing, $transfers): void {
            $locked = Shipment::query()->whereKey($shipment->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->current_status, ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'], true)
                || ($locked->origin_logistics_center_id && $locked->destination_logistics_center_id && $locked->service_area_id)) {
                throw new RuntimeException('This parcel is no longer waiting for manual routing review.');
            }

            $locked->load(['sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address']);
            $seller = $locked->sellerOrder?->sellerProfile;
            $destinationAddress = $locked->sellerOrder?->order?->address;
            if (! $seller || ! $destinationAddress) {
                throw new RuntimeException('The parcel is missing its saved seller pickup or buyer delivery address.');
            }
            $route = $routing->resolveWithCenters(
                $seller,
                $destinationAddress,
                (int) $data['origin_logistics_center_id'],
                (int) $data['destination_logistics_center_id'],
            );
            $isCrossHub = (int) $route['origin']->id !== (int) $route['destination']->id;
            if ($isCrossHub && ! $schema->manifestReady()) {
                throw ValidationException::withMessages(['destination_logistics_center_id' => 'Cross-hub routing is blocked until manifest custody and approved vehicle-capacity fields are available.']);
            }
            if (($locked->logistics_center_id && (int) $locked->logistics_center_id !== (int) $route['origin']->id)
                || ($locked->origin_logistics_center_id && (int) $locked->origin_logistics_center_id !== (int) $route['origin']->id)
                || ($locked->destination_logistics_center_id && (int) $locked->destination_logistics_center_id !== (int) $route['destination']->id)
                || ($locked->service_area_id && (int) $locked->service_area_id !== (int) $route['destination_area']->service_area_id)) {
                throw ValidationException::withMessages(['origin_logistics_center_id' => 'This parcel already has a different hub or delivery area. Review its existing assignment before changing the route.']);
            }

            $originAddress = $route['origin']->address;
            $sellerAddress = $seller->businessAddress;
            $locked->update([
                'logistics_center_id' => $route['origin']->id,
                'origin_logistics_center_id' => $route['origin']->id,
                'destination_logistics_center_id' => $route['destination']->id,
                'service_area_id' => $route['destination_area']->service_area_id,
                'origin_province_code' => $originAddress->province_code,
                'origin_province_name' => $originAddress->province_name,
                'origin_municipality_code' => $originAddress->municipality_code,
                'origin_municipality_name' => $originAddress->municipality_name,
                'origin_barangay_code' => $sellerAddress->barangay_code,
                'origin_barangay_name' => $sellerAddress->barangay_name,
            ]);
            if ($isCrossHub) {
                $transfers->createPlannedLeg($locked->fresh(), $route['origin'], $route['destination']);
            }
            ShipmentEvent::query()->create([
                'shipment_id' => $locked->id,
                'status' => $locked->current_status,
                'actor_user_id' => $request->user()->id,
                'logistics_center_id' => $route['origin']->id,
                'rider_assignment_id' => null,
                'notes' => 'ROUTING_REVIEW_RESOLVED: Admin selected authorized origin hub '.$route['origin']->id.' and destination hub '.$route['destination']->id.' after PSGC coverage verification.',
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.routing-review')->with('status', 'Parcel routing was saved after PSGC coverage validation.');
    }
}
