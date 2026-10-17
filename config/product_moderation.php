<?php

return [
    'enabled' => env('AI_PRODUCT_MODERATION_ENABLED', false),
    'provider' => env('AI_PRODUCT_PROVIDER', 'cloudflare'),
    'fallback' => [
        'provider' => env('AI_PRODUCT_FALLBACK_PROVIDER', 'gemini'),
        'enabled' => env('AI_PRODUCT_FALLBACK_ENABLED', true),
    ],
    'policy_version' => env('AI_PRODUCT_POLICY_VERSION', 'likhae-2026-10-v1'),

    'models' => [
        'primary' => env('AI_PRODUCT_PRIMARY_MODEL', '@cf/cloudflare/clef-flash'),
        'object' => env('AI_PRODUCT_OBJECT_MODEL', '@cf/facebook/detr-resnet-50'),
        'secondary' => env('AI_PRODUCT_SECONDARY_MODEL', '@cf/google/gemma-4-26b-a4b-it'),
        'use_secondary' => env('AI_PRODUCT_SECONDARY_ENABLED', false),
    ],

    // Keep the taxonomy in one trusted server-side location for prompts and UI labels.
    'categories' => [
        'SAFE' => 'Ordinary consumer product',
        'POSSIBLE_FIREARM' => 'Possible firearm',
        'FIREARM' => 'Firearm',
        'AMMUNITION' => 'Ammunition',
        'FIREARM_COMPONENT' => 'Firearm component',
        'EXPLOSIVE' => 'Explosive',
        'EXPLOSIVE_COMPONENT' => 'Explosive component',
        'POSSIBLE_ILLEGAL_DRUG' => 'Possible restricted substance',
        'ILLEGAL_DRUG' => 'Possible prohibited substance',
        'CONTROLLED_SUBSTANCE' => 'Controlled substance',
        'DRUG_PARAPHERNALIA' => 'Drug paraphernalia',
        'PROHIBITED_WEAPON' => 'Prohibited weapon',
        'RESTRICTED_WEAPON' => 'Restricted weapon',
        'COUNTERFEIT_OR_SUSPICIOUS_BRAND' => 'Counterfeit or suspicious brand',
        'HAZARDOUS_OR_RESTRICTED_MATERIAL' => 'Hazardous or restricted material',
        'OTHER_PROHIBITED_PRODUCT' => 'Other possible prohibited product',
        'UNCERTAIN' => 'Uncertain',
    ],

    'image_categories' => [
        'ORDINARY_PRODUCT' => 'Ordinary consumer product',
        'REAL_FIREARM' => 'Real firearm',
        'GUN_LIKE_OBJECT' => 'Gun-like object',
        'AMMUNITION' => 'Ammunition',
        'EXPLOSIVE' => 'Explosive or explosive device',
        'POSSIBLE_SUBSTANCE' => 'Possible substance requiring review',
        'DRUG_PARAPHERNALIA' => 'Drug paraphernalia',
        'KNIFE_OR_WEAPON' => 'Knife or weapon',
        'TOY_OR_REPLICA' => 'Toy or harmless imitation',
        'UNKNOWN' => 'Unknown or uncertain',
    ],

    'risk' => [
        'medium' => 0.30,
        'high' => 0.65,
        'critical' => 0.86,
        'critical_agreement' => 0.65,
        'mismatch_boost' => 0.20,
    ],

    'images' => [
        'max_per_scan' => 10,
        'max_bytes' => 5_242_880,
        'max_pixels' => 40_000_000,
        'max_dimension' => 1280,
        'max_clef_images_per_request' => 4,
        'max_gemini_images_per_request' => 2,
    ],

    'http' => [
        'connect_timeout_seconds' => 4,
        'timeout_seconds' => 25,
    ],

    'queue' => [
        'max_attempts' => 3,
        'backoff_seconds' => [60, 300],
        'manual_rescan_cooldown_minutes' => 10,
        'quota_deferred_retry_minutes' => 60,
    ],
];
