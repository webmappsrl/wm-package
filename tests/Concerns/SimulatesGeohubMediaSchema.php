<?php

namespace Wm\WmPackage\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scaffolding fix (not production code): GeohubImportService::getEcMediaIdsForApp() (pre-existing,
 * reused unmodified by the taxonomy-scoping feature, oc:8094) is written against Geohub's own
 * schema — a "feature_image" column on ec_tracks plus the ec_media/ec_track_layer/ec_media_ec_track
 * tables — none of which exist locally, since Maphub never mirrors that part of the Geohub schema.
 * Any test exercising a code path that calls getEcMediaIdsForApp() against the "geohub" connection
 * shared with the local DB (via SharesGeohubConnectionWithLocal) needs this simulated schema.
 * Created here (transactionally, so DatabaseTransactions rolls it back after each test) instead of
 * as a real migration, since it mirrors Geohub-only schema with no equivalent in the local app.
 */
trait SimulatesGeohubMediaSchema
{
    private function simulateGeohubMediaSchema(): void
    {
        if (! Schema::hasColumn('ec_tracks', 'feature_image')) {
            Schema::table('ec_tracks', function (Blueprint $table) {
                $table->unsignedBigInteger('feature_image')->nullable();
            });
        }

        if (! Schema::hasTable('ec_media')) {
            Schema::create('ec_media', function (Blueprint $table) {
                $table->id();
                $table->jsonb('name')->nullable();
                $table->string('url')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ec_track_layer')) {
            Schema::create('ec_track_layer', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ec_track_id');
                $table->unsignedBigInteger('layer_id');
            });
        }

        if (! Schema::hasTable('ec_media_ec_track')) {
            Schema::create('ec_media_ec_track', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ec_media_id');
                $table->unsignedBigInteger('ec_track_id');
            });
        }
    }
}
