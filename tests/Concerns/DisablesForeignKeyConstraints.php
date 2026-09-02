<?php

namespace Wm\WmPackage\Tests\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * FK constraints on the taxonomy pivot tables (taxonomy_theme_id, taxonomy_poi_type_id,
 * taxonomy_activity_id, ...) reference real local rows. Tests that insert pivot rows simulating
 * Geohub data — where the id is a Geohub-side id, not necessarily a locally-imported record's id
 * — need these constraints disabled for the duration of the test, restored in tearDown() so it
 * doesn't leak into other tests sharing the same connection.
 */
trait DisablesForeignKeyConstraints
{
    private function disableForeignKeyConstraints(): void
    {
        DB::statement('SET session_replication_role = replica');
    }

    private function restoreForeignKeyConstraints(): void
    {
        DB::statement('SET session_replication_role = DEFAULT');
    }
}
