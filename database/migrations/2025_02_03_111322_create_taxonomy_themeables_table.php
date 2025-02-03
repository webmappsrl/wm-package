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
        Schema::create('taxonomy_themeables', function (Blueprint $table) {
            $table->integer('taxonomy_theme_id');
            $table->string('taxonomy_themeable_type');
            $table->integer('taxonomy_themeable_id');
            $table->index(['taxonomy_themeable_type', 'taxonomy_themeable_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('taxonomy_themeables');
    }
};
