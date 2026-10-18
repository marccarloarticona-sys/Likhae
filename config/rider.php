<?php

return [
    // Offers are also expired synchronously when a rider attempts to accept.
    // The scheduled cleanup updates stale records even when nobody opens them.
    'assignment_offer_minutes' => max(1, (int) env('RIDER_ASSIGNMENT_OFFER_MINUTES', 15)),
    'distinct_declines_before_unassigned' => 3,
];
