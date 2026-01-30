<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    $commands = [
        'api:sync laboratories', // Se obtienen los laboratorios
        'api:sync catalog-types', // Se obtienen los tipos de catalogo
        'api:sync catalog-categories', // Se obtienen las categorias del catalogo
        'api:sync catalog-subcategories', // Se obtienen las subcategorias del catalogo
        'api:sync tag-categories', // Se obtienen las categorias de los tags
        'api:sync tag-subcategories', // Se obtienen las subcategorias de los tags
        'api:sync tags', // Se obtienen los tags

        // 'api:sync products', // Falta adaptar a los nuevos datos del API, como la API da error 500, no se pudo adaptar.

        'ftp:sync', // Se obtienen los archivos del FTP
        'woocommerce:sync-categories', // Sincroniza las categorias, entre ellas se encuetra: Tipos de catalogo, Tags y laboratorios

        // 'woocommerce:sync-catalog-tags', // Sincronizacion antigua, no se usa.
        // 'woocommerce:sync-catalog-categories', // Sincronizacion antigua, no se usa.
        // 'woocommerce:sync-laboratories', // Sincronizacion antigua, no se usa.

        // 'woocommerce:sync-products', // Falta adaptar a los nuevos datos del API, como la API da error 500, no se pudo adaptar.
    ];

    foreach ($commands as $command) {
        Artisan::call($command);
    }
})
// ->dailyAt('00:00') // se ejecuta a las 00:00
    ->everyMinute() // se ejecuta cada minuto, con fines de pruebas
    ->name('Sincronizacion Diaria') // nombre de la tarea
    ->withoutOverlapping() // evita que se ejecute si ya esta en ejecucion
    ->onSuccess(function () {
        Log::info('Sincronización diaria completada');
    })
    ->onFailure(function () {
        Log::error('Sincronización diaria fallida');
    });

// Ejecute: php artisan schedule:run
// Para probar la sincronizacion programada
