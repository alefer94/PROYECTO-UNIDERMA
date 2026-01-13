<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('catalog_subcategories', function (Blueprint $table) {
            $table->string('CodSubClasificador')->primary(); // BD: C_C_FAMILIA
            $table->string('CodTipcat'); // BD: C_C_TIPCAT
            $table->string('CodClasificador'); // BD: C_C_GENERICO
            $table->string('Nombre'); // BD: C_T_NOMBRE
            $table->timestamps();
            
            $table->foreign('CodTipcat')
                  ->references('Tipcat')
                  ->on('catalog_types')
                  ->onDelete('cascade');
                  
            $table->foreign('CodClasificador')
                  ->references('CodClasificador')
                  ->on('catalog_categories')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('catalog_subcategories');
    }
};
