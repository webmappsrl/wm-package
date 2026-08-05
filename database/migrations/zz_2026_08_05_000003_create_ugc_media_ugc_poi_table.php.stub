<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ugc_media_ugc_poi', function (Blueprint $table) {
            $table->foreignId('ugc_media_id')->constrained('ugc_media')->cascadeOnDelete();
            $table->foreignId('ugc_poi_id')->constrained('ugc_pois')->cascadeOnDelete();
            $table->primary(['ugc_media_id', 'ugc_poi_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_media_ugc_poi');
    }
};
