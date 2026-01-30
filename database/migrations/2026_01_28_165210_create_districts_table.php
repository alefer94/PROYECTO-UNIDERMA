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
        Schema::create('districts', function (Blueprint $table) {
            $table->char('region_code', 2);
            $table->char('province_code', 2);
            $table->char('code', 2);
            $table->string('name', 100);

            $table->primary(['region_code', 'province_code', 'code']);
            $table->foreign(['region_code', 'province_code'])->references(['region_code', 'code'])->on('provinces')->onUpdate('cascade')->onDelete('restrict');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('districts');
    }
};
