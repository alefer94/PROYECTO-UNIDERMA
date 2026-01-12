<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::command('catalog:sync')->everyMinute()->withoutOverlapping()->onSuccess(function (){
//     Log::info('Sincronizacion automatica completada');
// })->onFailure(function (){
//     Log::into('Sincronizacion automatica fallida');
// });
