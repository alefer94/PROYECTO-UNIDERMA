<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->string('CodClasificador')->primary(); // BD: C_C_GENERICO
            $table->string('CodTipcat'); // BD: C_C_TIPCAT
            $table->string('Nombre'); // BD: C_T_NOMBRE
            $table->timestamps();
            
            $table->foreign('CodTipcat')
                  ->references('Tipcat')
                  ->on('catalog_types')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('catalog_categories');
    }
};
