<?php

return [
    // Set GEOAPIFY_API_KEY in the Laravel server environment. Never put this
    // value in Flutter -- build-time defines are still extractable from apps.
    'api_key' => env('GEOAPIFY_API_KEY'),
];
