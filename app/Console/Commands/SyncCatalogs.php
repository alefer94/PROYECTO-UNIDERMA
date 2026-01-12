<?php

namespace App\Console\Commands;

use App\Services\CatalogSyncService;
use Illuminate\Console\Command;

class SyncCatalogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:sync
                            {--negocio= : Código de negocio}
                            {--tipindex= : Tipo de índice}
                            {--codTipcat= : Código de tipo de catálogo}
                            {--codClasificador= : Código del clasificador}
                            {--codSubclasificador= : Código del subclasificador}
                            {--codCatalogo= : Código específico de catálogo}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza catálogos desde la API externa';

    /**
     * Execute the console command.
     */
    public function handle(CatalogSyncService $syncService)
    {
        $this->info('🔄 Iniciando sincronización de catálogos...');
        $this->newLine();

        // Construir parámetros del POST desde las opciones
        $params = $this->buildParams();

        // Mostrar parámetros si hay alguno
        if (! empty(array_filter($params))) {
            $this->info('📋 Parámetros de filtro:');
            foreach ($params as $key => $value) {
                if ($value !== null && $value !== '') {
                    $this->line("   • {$key}: {$value}");
                }
            }
            $this->newLine();
        }

        // Ejecutar sincronización
        $result = $syncService->sync($params);

        // Mostrar resultados
        if ($result['success']) {
            $this->info('✅ '.$result['message']);
            $this->newLine();

            if (isset($result['stats'])) {
                $this->displayStats($result['stats']);
            }

            return Command::SUCCESS;
        } else {
            $this->error('❌ '.$result['message']);

            return Command::FAILURE;
        }
    }

    /**
     * Construye los parámetros del POST desde las opciones del comando.
     */
    protected function buildParams(): array
    {
        $params = [];

        if ($this->option('negocio')) {
            $params['Negocio'] = $this->option('negocio');
        }

        if ($this->option('tipindex') !== null) {
            $params['TipIndex'] = (int) $this->option('tipindex');
        }

        if ($this->option('codTipcat')) {
            $params['CodTipcat'] = $this->option('codTipcat');
        }

        if ($this->option('codClasificador')) {
            $params['CodClasificador'] = $this->option('codClasificador');
        }

        if ($this->option('codSubclasificador')) {
            $params['CodSubclasificador'] = $this->option('codSubclasificador');
        }

        if ($this->option('codCatalogo')) {
            $params['CodCatalogo'] = $this->option('codCatalogo');
        }

        return $params;
    }

    /**
     * Muestra las estadísticas de la sincronización.
     */
    protected function displayStats(array $stats): void
    {
        $this->info('📊 Estadísticas:');

        if (isset($stats['inserted'])) {
            $this->line("   • Insertados: {$stats['inserted']}");
        }

        if (isset($stats['updated'])) {
            $this->line("   • Actualizados: {$stats['updated']}");
        }

        if (isset($stats['replaced'])) {
            $this->line("   • Reemplazados: {$stats['replaced']}");
        }

        if (isset($stats['skipped'])) {
            $this->line("   • Omitidos: {$stats['skipped']}");
        }

        if (isset($stats['total'])) {
            $this->line("   • Total procesados: {$stats['total']}");
        }

        $this->newLine();
    }
}
