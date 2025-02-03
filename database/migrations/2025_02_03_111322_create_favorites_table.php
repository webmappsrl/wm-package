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
        Schema::create('favorites', function (Blueprint $table) {
            $table->id('id');
            $table->integer('user_id');
            $table->string('favoriteable_type');
            $table->bigInteger('favoriteable_id');
            $table->timestamps();

            $table->index(['favoriteable_type', 'favoriteable_id']);
            $table->unique(['user_id', 'favoriteable_id', 'favoriteable_type']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('favorites');
    }
};
