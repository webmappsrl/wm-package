<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

/** EcTrack con la geometria data in WKT 2D/3D (MULTILINESTRING), senza observer né catena di job. */
function matchingEcTrack(int $appId, string $wkt): int
{
    $track = EcTrack::withoutEvents(fn () => EcTrack::factory()->create(['app_id' => $appId]));
    DB::update('UPDATE ec_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);

    return $track->id;
}

/** UgcTrack con la geometria data, senza observer. */
function matchingUgcTrack(int $userId, int $appId, string $wkt): int
{
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create(['user_id' => $userId, 'app_id' => $appId, 'properties' => []]));
    DB::update('UPDATE ugc_tracks SET geometry = ST_Force3D(ST_GeomFromText(?, 4326))::geography WHERE id = ?', [$wkt, $track->id]);

    return $track->id;
}

/** Associa una EcTrack a un layer in layerables, con il morph type del modello configurato. */
function matchingAttachToLayer(int $layerId, int $ecTrackId): void
{
    DB::table('layerables')->insert([
        'layer_id' => $layerId,
        'layerable_id' => $ecTrackId,
        'layerable_type' => UgcTrackMatchingService::make()->ecTrackMorphClass(),
    ]);
}

/** Indici corrispondenti salvati per la coppia, o null se la riga non esiste. */
function matchedSegments(int $ugcTrackId, int $ecTrackId): ?array
{
    $row = DB::table('ugc_track_ec_track_matches')->where(['ugc_track_id' => $ugcTrackId, 'ec_track_id' => $ecTrackId])->first();

    return $row ? json_decode($row->matched_segments, true) : null;
}
