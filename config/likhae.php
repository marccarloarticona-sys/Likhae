<?php

return [
    'seller_commission_rate' => (float) env('LIKHAE_SELLER_COMMISSION_RATE', 0.10),
    // Step 6/7 policy constants. Existing persisted values are never rewritten.
    'cod_only_new_orders' => true,
    'standard_shipping_fee' => 50.00,
    'logistics_shipping_allocation' => 20.00,
    'rider_shipping_allocation' => 30.00,
    // Temporary owner-approved demo fallback. Set LIKHAE_ALLOW_AMBIGUOUS_ROUTING=false
    // after checking to restore strict one-hub-per-municipality enforcement.
    'allow_ambiguous_routing' => (bool) env('LIKHAE_ALLOW_AMBIGUOUS_ROUTING', true),
    // Empty until the owner approves the physical source of cash refunds.
    'cash_refund_authorized_account_types' => [],
];
