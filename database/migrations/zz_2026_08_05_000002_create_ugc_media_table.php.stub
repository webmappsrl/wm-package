<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ugc_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_id')->constrained('apps')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('name')->default('');
            $table->geography('geometry', 'point')->nullable();
            $table->string('relative_url');
            $table->jsonb('properties')->default('{}');
            $table->timestamps();

            $table->index('app_id');
            $table->index('user_id');
            $table->spatialIndex('geometry');
        });

        DB::statement("CREATE INDEX ugc_media_geohub_id_index ON ugc_media ((properties->>'geohub_id'));");
    }

    public function down(): void
    {
        Schema::dropIfExists('ugc_media');
    }
};
