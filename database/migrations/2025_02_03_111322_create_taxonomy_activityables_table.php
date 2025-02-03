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
            $table->string('taxonomy_activityable_type');
            $table->integer('taxonomy_activityable_id');
            $table->integer('duration_forward')->nullable()->default(0);
            $table->integer('duration_backward')->nullable()->default(0);

            $table->index(['taxonomy_activityable_id', 'taxonomy_activityable_type']);
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
