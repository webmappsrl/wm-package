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
        Schema::create('ugc_pois', function (Blueprint $table) {
            $table->id('id');
            $table->jsonb('properties');
            $table->text('name')->default('');
            $table->point('geometry', 4326);
            $table->string('app_id', 100);
            $table->timestamps();

            $table->index('osmid');
            $table->index('app_id');
            $table->spatialIndex('geometry');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ugc_pois');
    }
};
