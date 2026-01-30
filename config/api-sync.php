<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Sync Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for REST API synchronization services
    |
    */

    // Authentication
    'auth_type' => env('API_SYNC_AUTH_TYPE', 'bearer'), // bearer, api_key, none
    'auth_token' => env('API_SYNC_AUTH_TOKEN'),
    'api_key_header' => env('API_SYNC_API_KEY_HEADER', 'X-API-Key'),

    // HTTP Configuration
    'timeout' => env('API_SYNC_TIMEOUT'),
    'retry_times' => env('API_SYNC_RETRY_TIMES', 3),
    'retry_delay' => env('API_SYNC_RETRY_DELAY', 100),

    // WooCommerce Specific Settings
    'woocommerce_timeout' => env('WOOCOMMERCE_TIMEOUT', 120),
    'batch_size_with_images' => env('WOOCOMMERCE_BATCH_IMAGES', 20), // Increased from 1
    'batch_size_no_images' => env('WOOCOMMERCE_BATCH_NO_IMAGES', 50), // Increased from 20 to max

    // Default parameters (can be overridden by each service)
    'default_negocio' => env('API_SYNC_DEFAULT_NEGOCIO', 'OSSAB'),

    // API Endpoints
    'endpoints' => [
        'laboratories' => env('API_SYNC_URL_LABORATORIES'),
        'catalog_types' => env('API_SYNC_URL_CATALOG_TYPES'),
        'catalog_categories' => env('API_SYNC_URL_CATALOG_CATEGORIES'),
        'catalog_subcategories' => env('API_SYNC_URL_CATALOG_SUBCATEGORIES'),
        'tag_categories' => env('API_SYNC_URL_TAG_CATEGORIES'),
        'tag_subcategories' => env('API_SYNC_URL_TAG_SUBCATEGORIES'),
        'tags' => env('API_SYNC_URL_TAGS'),
        'products' => env('API_SYNC_URL_PRODUCTS'),
        'zones' => env('API_SYNC_URL_ZONES'),
    ],
];
