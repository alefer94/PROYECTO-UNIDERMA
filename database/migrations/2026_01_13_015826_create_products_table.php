<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('products', function (Blueprint $table) {
            // Primary Key
            $table->string('CodCatalogo')->primary(); // BD: codCatalogo

            // Foreign Keys
            $table->string('CodTipcat')->nullable(); // BD: codTipcat
            $table->string('CodClasificador')->nullable(); // BD: codClasificador
            $table->string('CodSubclasificador')->nullable(); // BD: codSubclasificador
            $table->string('CodLaboratorio')->nullable(); // BD: codLaboratorio

            // Product Information
            $table->string('Nombre'); // BD: nombre
            $table->string('Corta')->nullable(); // BD: corta
            $table->text('Descripcion')->nullable(); // BD: descripcion

            // Registration & Presentation
            $table->string('Registro')->nullable(); // BD: registro
            $table->string('Presentacion')->nullable(); // BD: presentacion

            // Medical Information
            $table->text('Composicion')->nullable(); // BD: composicion
            $table->text('Bemeficios')->nullable(); // BD: bemeficios (typo del API)
            $table->text('ModoUso')->nullable(); // BD: modoUso
            $table->text('Contraindicaciones')->nullable(); // BD: contraindicaciones
            $table->text('Advertencias')->nullable(); // BD: advertencias
            $table->text('Precauciones')->nullable(); // BD: precauciones

            // Prescription & Display
            $table->integer('TipReceta')->default(0); // BD: tipReceta (0:S/Rec 1:C/RecPres 2:C/RecRet)
            $table->integer('ShowModo')->default(0); // BD: showModo (1:ModUso 0:"Bajo Prescr. Médica")

            // Pricing & Stock
            $table->decimal('Precio', 9, 2)->default(0); // BD: precio
            $table->integer('Stock')->default(0); // BD: stock

            // Media & Links
            $table->string('Home')->nullable(); // BD: home (Ubicación de Fotos URL)
            $table->string('Link')->nullable(); // BD: link (Links Asociados)

            // Tags
            $table->string('PasCodTag')->nullable(); // BD: pasCodTag (Codigos de Tag Separados por ;)

            // Status
            $table->boolean('FlgLanzamiento')->default(true); // BD: flg de nuevo lanzamiento (0:antiguo lanzado 1:nuevo lanzado)

            // Status
            $table->boolean('FlgActivo')->default(true); // BD: flgActivo (0:inactivo 1:activo)

            $table->timestamps();

            // Foreign Key Constraints
            $table->foreign('CodTipcat')
                ->references('Tipcat')
                ->on('catalog_types')
                ->onDelete('set null');

            $table->foreign('CodClasificador')
                ->references('CodClasificador')
                ->on('catalog_categories')
                ->onDelete('set null');

            $table->foreign('CodSubclasificador')
                ->references('CodSubClasificador')
                ->on('catalog_subcategories')
                ->onDelete('set null');

            $table->foreign('CodLaboratorio')
                ->references('CodLaboratorio')
                ->on('laboratories')
                ->onDelete('set null');
        });
    }

    public function down()
    {
        Schema::dropIfExists('products');
    }
};
