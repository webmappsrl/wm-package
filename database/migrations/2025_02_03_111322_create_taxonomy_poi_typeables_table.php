<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('taxonomy_poi_typeables', function (Blueprint $table) {
            $table->integer('taxonomy_poi_type_id');
            $table->string('taxonomy_poi_typeable_type');
            $table->integer('taxonomy_poi_typeable_id');

            $table->index(['taxonomy_poi_typeable_type', 'taxonomy_poi_typeable_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('taxonomy_poi_typeables');
    }
};
