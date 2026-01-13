<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('catalog_types', function (Blueprint $table) {
            $table->string('Tipcat')->primary(); // BD: C_C_TIPCAT
            $table->string('Nombre'); // BD: C_T_NOMBRE
            $table->integer('IdEstructura')->nullable(); // BD: N_I_ESTRUCTURA
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('catalog_types');
    }
};
