<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API Endpoint URL
    |--------------------------------------------------------------------------
    |
    | La URL completa del endpoint de la API externa para sincronizar catálogos.
    | Ejemplo: 'https://api.example.com/catalogs/sync'
    |
    */
    'api_url' => env('CATALOG_SYNC_API_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | API Authentication
    |--------------------------------------------------------------------------
    |
    | Configuración de autenticación para la API.
    | Tipos soportados: 'none', 'bearer', 'api_key'
    |
    */
    'auth_type' => env('CATALOG_SYNC_AUTH_TYPE', 'none'),
    'auth_token' => env('CATALOG_SYNC_AUTH_TOKEN', ''),
    'api_key_header' => env('CATALOG_SYNC_API_KEY_HEADER', 'X-API-Key'),

    /*
    |--------------------------------------------------------------------------
    | Default POST Parameters
    |--------------------------------------------------------------------------
    |
    | Parámetros por defecto que se enviarán en el body del POST.
    | Estos pueden ser sobrescritos al llamar al servicio.
    |
    */
    'default_params' => [
        'Negocio' => env('CATALOG_SYNC_NEGOCIO', ''),
        'TipIndex' => env('CATALOG_SYNC_TIP_INDEX', 0),
        'CodTipcat' => env('CATALOG_SYNC_COD_TIPCAT', ''),
        'CodClasificador' => env('CATALOG_SYNC_COD_CLASIFICADOR', ''),
        'CodSubclasificador' => env('CATALOG_SYNC_COD_SUBCLASIFICADOR', ''),
        'CodCatalogo' => env('CATALOG_SYNC_COD_CATALOGO', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Strategy
    |--------------------------------------------------------------------------
    |
    | Estrategia de sincronización:
    | - 'upsert': Actualiza registros existentes e inserta nuevos (recomendado)
    | - 'insert': Solo inserta registros nuevos, ignora existentes
    | - 'replace': Elimina todos los registros y los reemplaza
    |
    */
    'sync_strategy' => env('CATALOG_SYNC_STRATEGY', 'upsert'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Settings
    |--------------------------------------------------------------------------
    |
    | Configuración de timeout y reintentos para las peticiones HTTP.
    |
    */
    'timeout' => env('CATALOG_SYNC_TIMEOUT', 30), // segundos
    'retry_times' => env('CATALOG_SYNC_RETRY_TIMES', 3),
    'retry_delay' => env('CATALOG_SYNC_RETRY_DELAY', 1000), // milisegundos

    /*
    |--------------------------------------------------------------------------
    | Field Mapping
    |--------------------------------------------------------------------------
    |
    | Mapeo de campos de la API a campos de la base de datos.
    | Formato: 'campo_api' => 'campo_db'
    |
    */
    'field_mapping' => [
        'codCatalogo' => 'codCatalogo',
        'codTipcat' => 'codTipcat',
        'codClasificador' => 'codClasificador',
        'codSubclasificador' => 'codSubclasificador',
        'nombre' => 'nombre',
        'corta' => 'corta',
        'descripcion' => 'descripcion',
        'codLaboratorio' => 'codLaboratorio',
        'registro' => 'registro',
        'presentacion' => 'presentacion',
        'composicion' => 'composicion',
        'bemeficios' => 'bemeficios',
        'modoUso' => 'modoUso',
        'contraindicaciones' => 'contraindicaciones',
        'advertencias' => 'advertencias',
        'precauciones' => 'precauciones',
        'tipReceta' => 'tipReceta',
        'showModo' => 'showModo',
        'precio' => 'precio',
        'stock' => 'stock',
        'home' => 'home',
        'link' => 'link',
        'pasCodTag' => 'pasCodTag',
        'flgActivo' => 'flgActivo',
    ],
];
