<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->string('CodCatalogo'); // BD: product_id
            $table->integer('IdTag'); // BD: tag_id
            $table->boolean('FlgActivo')->default(true); // BD: FLG_ACTIVO
            $table->timestamps();
            
            $table->unique(['CodCatalogo', 'IdTag']);
            
            $table->foreign('CodCatalogo')
                  ->references('CodCatalogo')
                  ->on('products')
                  ->onDelete('cascade');
                  
            $table->foreign('IdTag')
                  ->references('IdTag')
                  ->on('tags')
                  ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_tag');
    }
};
