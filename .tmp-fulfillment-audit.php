<?php

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'shipments_by_status='.json_encode(DB::table('shipments')->selectRaw('current_status, COUNT(*) AS total')->groupBy('current_status')->pluck('total', 'current_status')->all()).PHP_EOL;
echo 'unrouted_open_parcels='.DB::table('shipments')
    ->whereIn('current_status', ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'])
    ->where(fn ($query) => $query->whereNull('origin_logistics_center_id')->orWhereNull('destination_logistics_center_id'))
    ->count().PHP_EOL;
echo 'approved_active_hubs='.DB::table('logistics_centers')->where('status', 'ACTIVE')->whereNotNull('approved_at')->count().PHP_EOL;
echo 'approved_hubs_by_municipality='.json_encode(DB::table('logistics_centers')
    ->join('addresses', 'addresses.id', '=', 'logistics_centers.address_id')
    ->where('logistics_centers.status', 'ACTIVE')->whereNotNull('logistics_centers.approved_at')
    ->selectRaw('addresses.municipality_code, COUNT(*) AS total')
    ->groupBy('addresses.municipality_code')
    ->pluck('total', 'municipality_code')->all()).PHP_EOL;
echo 'unrouted_open_by_route='.json_encode(DB::table('shipments')
    ->join('seller_orders', 'seller_orders.id', '=', 'shipments.seller_order_id')
    ->join('seller_profiles', 'seller_profiles.id', '=', 'seller_orders.seller_profile_id')
    ->leftJoin('addresses AS pickup_address', 'pickup_address.id', '=', 'seller_profiles.business_address_id')
    ->join('order_addresses', 'order_addresses.order_id', '=', 'seller_orders.order_id')
    ->whereIn('shipments.current_status', ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'])
    ->where(fn ($query) => $query->whereNull('shipments.origin_logistics_center_id')->orWhereNull('shipments.destination_logistics_center_id'))
    ->selectRaw('shipments.current_status, pickup_address.municipality_code AS pickup_municipality, order_addresses.municipality_code AS destination_municipality, COUNT(*) AS total')
    ->groupBy('shipments.current_status', 'pickup_address.municipality_code', 'order_addresses.municipality_code')
    ->get()->all()).PHP_EOL;
echo 'hubs='.json_encode(DB::table('logistics_centers')
    ->join('addresses', 'addresses.id', '=', 'logistics_centers.address_id')
    ->where('logistics_centers.status', 'ACTIVE')->whereNotNull('logistics_centers.approved_at')
    ->select('logistics_centers.id', 'logistics_centers.code', 'logistics_centers.business_name', 'addresses.municipality_code')
    ->orderBy('logistics_centers.id')->get()->all()).PHP_EOL;
echo 'riders_by_hub_vehicle='.json_encode(DB::table('rider_profiles')
    ->selectRaw('logistics_center_id, vehicle_type, status, COUNT(*) AS total')
    ->groupBy('logistics_center_id', 'vehicle_type', 'status')
    ->get()->all()).PHP_EOL;
echo 'active_rider_areas_by_hub='.json_encode(DB::table('rider_area_assignments')
    ->join('rider_profiles', 'rider_profiles.id', '=', 'rider_area_assignments.rider_profile_id')
    ->where('rider_area_assignments.is_active', true)
    ->selectRaw('rider_profiles.logistics_center_id, COUNT(*) AS total')
    ->groupBy('rider_profiles.logistics_center_id')->pluck('total', 'logistics_center_id')->all()).PHP_EOL;
$unrouted = App\Models\Logistics\Shipment::query()
    ->whereIn('current_status', ['PLACED', 'CONFIRMED', 'PREPARING', 'READY_FOR_PICKUP'])
    ->where(fn ($query) => $query->whereNull('origin_logistics_center_id')->orWhereNull('destination_logistics_center_id'))
    ->with('sellerOrder.sellerProfile.businessAddress', 'sellerOrder.order.address')->get();
foreach ($unrouted as $shipment) {
    $addresses = [
        'origin' => $shipment->sellerOrder?->sellerProfile?->businessAddress,
        'destination' => $shipment->sellerOrder?->order?->address,
    ];
    $coverage = [];
    foreach ($addresses as $side => $address) {
        $coverage[$side] = $address ? DB::table('service_area_locations')
            ->join('service_areas', 'service_areas.id', '=', 'service_area_locations.service_area_id')
            ->join('logistics_centers', 'logistics_centers.id', '=', 'service_areas.logistics_center_id')
            ->where('service_area_locations.province_code', $address->province_code)
            ->where('service_area_locations.municipality_code', $address->municipality_code)
            ->where('service_area_locations.barangay_code', $address->barangay_code)
            ->where('service_areas.is_active', true)
            ->where('logistics_centers.status', 'ACTIVE')
            ->whereNotNull('logistics_centers.approved_at')
            ->orderBy('logistics_centers.id')
            ->pluck('logistics_centers.id')->all() : [];
    }
    echo 'unrouted_coverage='.json_encode([
        'shipment_id' => $shipment->id,
        'status' => $shipment->current_status,
        'current_hub' => $shipment->logistics_center_id,
        'origin_hub' => $shipment->origin_logistics_center_id,
        'destination_hub' => $shipment->destination_logistics_center_id,
        'service_area' => $shipment->service_area_id,
        'origin_barangay' => $addresses['origin']?->barangay_code,
        'destination_barangay' => $addresses['destination']?->barangay_code,
        'coverage' => $coverage,
    ]).PHP_EOL;
}
