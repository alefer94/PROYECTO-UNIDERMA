<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('catalogs', function (Blueprint $table) {
            $table->id();

            // Códigos de clasificación
            $table->string('codCatalogo')->unique()->comment('Código Catálogo OSSAB');
            $table->string('codTipcat')->nullable()->comment('De Tabla TipCat');
            $table->string('codClasificador')->nullable()->comment('De Tabla CatCls');
            $table->string('codSubclasificador')->nullable()->comment('De Tabla ScatCls');

            // Información básica del producto
            $table->string('nombre')->nullable()->comment('Nombre del producto');
            $table->string('corta')->nullable()->comment('Nombre Corto');
            $table->text('descripcion')->nullable()->comment('Descripción completa');

            // Información del laboratorio
            $table->string('codLaboratorio')->nullable()->comment('De Tabla Laboratorio');
            $table->string('registro')->nullable()->comment('Reg. Sanitario');

            // Detalles del producto
            $table->string('presentacion')->nullable()->comment('Forma de Presentación');
            $table->text('composicion')->nullable()->comment('Composición');
            $table->text('bemeficios')->nullable()->comment('Beneficios');
            $table->text('modoUso')->nullable()->comment('Modo de Uso');
            $table->text('contraindicaciones')->nullable()->comment('Contraindicaciones');
            $table->text('advertencias')->nullable()->comment('Advertencias');
            $table->text('precauciones')->nullable()->comment('Precauciones');

            // Configuración
            $table->tinyInteger('tipReceta')->nullable()->comment('0:S/Rec 1:C/RecPres 2:C/RecRet');
            $table->tinyInteger('showModo')->default(0)->comment('1:ModUso 0:"Bajo Prescr. Médica"');

            // Precio y stock
            $table->decimal('precio', 9, 2)->default(0)->comment('Precio del producto');
            $table->unsignedInteger('stock')->default(0)->comment('Cantidad en stock');

            // Información adicional
            $table->string('home')->nullable()->comment('Ubicación de Fotos (URL)');
            $table->string('link')->nullable()->comment('Links Asociados');
            $table->string('pasCodTag')->nullable()->comment('Códigos de Tag Separados por ;');
            $table->tinyInteger('flgActivo')->default(1)->comment('0:Inactivo 1:Activo');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalogs');
    }
};
