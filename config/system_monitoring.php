<?php

return [
    // External probes are limited to the approved LIKHAE production hostname.
    'allowed_domains' => ['likhae.online'],
    'release' => env('APP_RELEASE'),
    'connect_timeout_seconds' => 2,
    'read_timeout_seconds' => 2,
    'database_timeout_seconds' => 2,
    // Mark certificates expiring within 30 days as warnings and within 7 days as urgent.
    'tls_warning_days' => 30,
    'tls_urgent_days' => 7,
    'queue_backlog_warning' => 100,
    'storage_warning_free_bytes' => 536870912,
    // Capacity thresholds apply only when PHP can read a genuine filesystem volume size.
    'storage_warning_percent' => 80,
    'storage_critical_percent' => 90,
    'storage_snapshot_cache_key' => 'likhae:system-health:storage:v1',
    'storage_snapshot_ttl_seconds' => 300,
    'storage_refresh_cooldown_seconds' => 60,
    'storage_scan_timeout_seconds' => 2,
    'storage_scan_max_entries' => 10000,
    'storage_table_metadata_timeout_seconds' => 2,
    'storage_table_page_size' => 25,
    'storage_log_inventory_max_files' => 100,
    // These bounded subdirectories are based on paths used by existing upload services.
    'storage_file_categories' => [
        'product_images' => ['label' => 'Product images', 'disk' => 'public', 'path' => 'sellers'],
        'store_branding' => ['label' => 'Store banners / logos', 'disk' => 'public', 'path' => 'seller-branding'],
        'profile_pictures' => ['label' => 'Profile pictures', 'disk' => 'public', 'path' => 'profile-photos'],
        'review_images' => ['label' => 'Review images', 'disk' => 'public', 'path' => 'reviews'],
        'delivery_proofs' => ['label' => 'Delivery proofs', 'disk' => 'public', 'path' => 'delivery-proofs'],
        'chat_attachments' => ['label' => 'Chat attachments', 'disk' => 'local', 'path' => 'message-attachments'],
        'compliance_documents' => ['label' => 'Compliance documents', 'disk' => 'local', 'path' => 'compliance'],
        'registration_documents' => ['label' => 'Registration documents', 'disk' => 'registrations', 'path' => 'registrations'],
        'return_refund_evidence' => ['label' => 'Return / refund evidence', 'disk' => 'local', 'path' => 'return-refund-evidence'],
        'refund_proofs' => ['label' => 'Refund proofs', 'disk' => 'local', 'path' => 'refund-proof'],
    ],
    'storage_cache_paths' => [],
    'scheduler_cache_prefix' => 'likhae:system-health:scheduler',
    'scheduler_heartbeat_retention_days' => 30,
    'log_max_files' => 8,
    'log_max_bytes_per_file' => 262144,
    'log_max_total_bytes' => 2097152,
    'log_max_entries' => 500,
];
