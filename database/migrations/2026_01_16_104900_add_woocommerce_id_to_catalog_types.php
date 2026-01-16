<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('catalog_types', function (Blueprint $table) {
            $table->unsignedBigInteger('WooCommerceCategoryId')->nullable()->after('IdEstructura');
            $table->index('WooCommerceCategoryId');
        });
    }

    public function down()
    {
        Schema::table('catalog_types', function (Blueprint $table) {
            $table->dropIndex(['WooCommerceCategoryId']);
            $table->dropColumn('WooCommerceCategoryId');
        });
    }
};
