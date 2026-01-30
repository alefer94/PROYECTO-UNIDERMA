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
        Schema::create('zones', function (Blueprint $table) {
            $table->string('codZonal')->primary();
            $table->string('nombre');
            $table->string('corta')->nullable();
            $table->decimal('montoL', 10, 2)->nullable();
            $table->decimal('montoR', 10, 2)->nullable();
            $table->boolean('flgActivo')->default(true);
            $table->json('items')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
