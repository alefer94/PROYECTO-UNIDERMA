<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('tag_subcategories', function (Blueprint $table) {
            $table->integer('IdSubClasificador')->primary(); // BD: N_I_FAMILIA
            $table->integer('IdClasificador'); // BD: N_I_GENERICO
            $table->string('Nombre'); // BD: C_T_NOMBRE
            $table->string('Corta')->nullable(); // BD: C_T_CORTA
            $table->boolean('FlgActivo')->default(true); // BD: FLG_ACTIVO
            $table->integer('Orden')->nullable(); // BD: N_I_ORDEN
            $table->timestamps();
            
            $table->foreign('IdClasificador')
                  ->references('IdClasificador')
                  ->on('tag_categories')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('tag_subcategories');
    }
};
