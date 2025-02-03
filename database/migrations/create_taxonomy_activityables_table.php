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
        Schema::create('taxonomy_activityables', function (Blueprint $table) {
            $table->integer('taxonomy_activity_id');
            $table->morphs('taxonomy_activityable');
            $table->integer('duration_forward')->nullable()->default(0);
            $table->integer('duration_backward')->nullable()->default(0);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('taxonomy_activityables');
    }
};
