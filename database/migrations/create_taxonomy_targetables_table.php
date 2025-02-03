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
        Schema::create('taxonomy_targetables', function (Blueprint $table) {
            $table->integer('taxonomy_target_id');
            $table->string('taxonomy_targetable_type');
            $table->integer('taxonomy_targetable_id');
            $table->index(['taxonomy_targetable_type', 'taxonomy_targetable_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('taxonomy_targetables');
    }
};
