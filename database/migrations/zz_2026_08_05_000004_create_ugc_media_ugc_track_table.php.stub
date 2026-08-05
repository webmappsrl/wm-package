<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ugc_media_ugc_track', function (Blueprint $table) {
            $table->foreignId('ugc_media_id')->constrained('ugc_media')->cascadeOnDelete();
            $table->foreignId('ugc_track_id')->constrained('ugc_tracks')->cascadeOnDelete();
            $table->primary(['ugc_media_id', 'ugc_track_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_media_ugc_track');
    }
};
