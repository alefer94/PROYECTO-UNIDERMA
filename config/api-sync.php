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
    'timeout' => env('API_SYNC_TIMEOUT', 30),
    'retry_times' => env('API_SYNC_RETRY_TIMES', 3),
    'retry_delay' => env('API_SYNC_RETRY_DELAY', 100),
    
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
    ],
];
