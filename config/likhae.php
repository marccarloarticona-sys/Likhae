<?php

return [
    'seller_commission_rate' => (float) env('LIKHAE_SELLER_COMMISSION_RATE', 0.10),
    // Step 6/7 policy constants. Existing persisted values are never rewritten.
    'cod_only_new_orders' => true,
    'standard_shipping_fee' => 50.00,
    // Automatic regional estimates use regional-center distance bands. These
    // are estimates and do not represent a courier's route or tariff.
    'regional_shipping_distance_km_per_tier' => (float) env('LIKHAE_REGIONAL_SHIPPING_DISTANCE_KM_PER_TIER', 100),
    'regional_shipping_interisland_tier_surcharge' => (int) env('LIKHAE_REGIONAL_SHIPPING_INTERISLAND_TIER_SURCHARGE', 2),
    'regional_shipping_max_tiers' => 20,
    // Explicit admin-approved routes are required even when this is enabled.
    // Keep off until route coverage, schedule, and capacity have been reviewed.
    'smart_nearby_logistics_fallback' => (bool) env('LIKHAE_SMART_NEARBY_LOGISTICS_FALLBACK', false),
    'automatic_seller_pickup_matching' => (bool) env('LIKHAE_AUTOMATIC_SELLER_PICKUP_MATCHING', true),
    // Empty until the owner approves the physical source of cash refunds.
    'cash_refund_authorized_account_types' => [],
];
