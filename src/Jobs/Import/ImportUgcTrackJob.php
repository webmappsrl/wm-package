<?php

namespace Wm\WmPackage\Jobs\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ImportUgcTrackJob extends BaseUgcImportJob
{
    protected function getModelKey(): string
    {
        return 'ugc_track';
    }

    /**
     * Geohub stores ugc_tracks.geometry as a single LineStringZ, but the local column is
     * geography(MultiLineStringZ) — same shape mismatch as ec_tracks. ST_Multi() normalizes
     * a single line into a one-part multi-line, satisfying the column type either way.
     */
    protected function fillGeometry($rawGeometry)
    {
        if (empty($rawGeometry)) {
            return null;
        }

        if (preg_match('/^(0x)?[0-9a-fA-F]+$/', $rawGeometry)) {
            return DB::raw("ST_Multi(ST_Force3D(ST_GeomFromWKB(decode('{$rawGeometry}', 'hex'))))");
        }

        return DB::raw("ST_Multi(ST_Force3D(ST_GeomFromText('{$rawGeometry}')))");
    }

    protected function processDependencies(array $data, Model $model): void
    {
        //
    }
}
