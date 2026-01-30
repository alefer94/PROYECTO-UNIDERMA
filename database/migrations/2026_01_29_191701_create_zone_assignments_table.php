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
        Schema::create('zone_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('codZonal'); // FK hacia zones
            $table->integer('item');
            $table->integer('tipo')->default(3);

            // Jerarquía territorial
            $table->char('region_code', 2);
            $table->char('province_code', 2);
            $table->char('district_code', 2);

            // FK hacia zones
            $table->foreign('codZonal')
                ->references('codZonal')->on('zones')
                ->onUpdate('cascade')
                ->onDelete('cascade');

            // FK hacia distritos (incluye provincia y región)
            $table->foreign(['region_code', 'province_code', 'district_code'])
                ->references(['region_code', 'province_code', 'code'])->on('districts')
                ->onUpdate('cascade')
                ->onDelete('restrict');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zone_assignments');
    }
};
