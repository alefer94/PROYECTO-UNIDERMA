<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('laboratories', function (Blueprint $table) {
            $table->string('CodLaboratorio')->primary(); // BD: C_C_LABORATORIO
            $table->string('NomLaboratorio'); // BD: C_T_NOMBRE
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('laboratories');
    }
};
