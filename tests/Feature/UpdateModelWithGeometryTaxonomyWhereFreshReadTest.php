<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Http\Clients\OsmfeaturesClient;
use Wm\WmPackage\Jobs\UpdateModelWithGeometryTaxonomyWhere;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

// oc:8742: il job delle località legge il modello a inizio job e salva dopo la chiamata HTTP a
// osmfeatures. Se nel frattempo UpdateUgcTrackDemStatsJob ha scritto le chiavi DEM di stats,
// il salvataggio non deve riportarle a null.

beforeEach(function () {
    config()->set('wm-package.ugc_track_max_accuracy_meters', 40.0);
    config()->set('wm-package.clients.osmfeatures.host', 'https://osmfeatures.test');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->track = UgcTrack::factory()->create([
        'user_id' => $this->user->id, 'app_id' => $this->app_->id,
        'properties' => ['name' => 'where', 'locations' => array_map(fn ($i) => [
            'time' => $i * 10_000, 'latitude' => 43.0 + $i * 0.00009, 'longitude' => 13.0, 'accuracy' => 5.0, 'altitude' => 100.0, 'speed' => 4.0,
        ], range(0, 10))],
    ]);
});

it('non cancella le chiavi DEM scritte da un altro job durante la chiamata a osmfeatures', function () {
    $trackId = $this->track->id;
    $job = new UpdateModelWithGeometryTaxonomyWhere($this->track->fresh());

    Http::fake(function () use ($trackId) {
        // Simula UpdateUgcTrackDemStatsJob che scrive mentre osmfeatures risponde.
        DB::update("UPDATE ugc_tracks SET properties = jsonb_set(properties, '{stats,ascent}', '313') WHERE id = ?", [$trackId]);

        return Http::response(['features' => [[
            'properties' => ['osmfeatures_id' => 'R42', 'osm_tags' => ['name' => 'Ancona', 'admin_level' => '8']],
        ]]]);
    });

    $job->handle(app(OsmfeaturesClient::class));

    $properties = $this->track->fresh()->properties;
    expect($properties['stats']['ascent'])->toBe(313);
    expect($properties['taxonomy_where'])->toHaveKey('R42');
});

it('non fa nulla se il modello è stato cancellato durante la chiamata a osmfeatures', function () {
    $trackId = $this->track->id;
    $job = new UpdateModelWithGeometryTaxonomyWhere($this->track->fresh());

    Http::fake(function () use ($trackId) {
        DB::delete('DELETE FROM ugc_tracks WHERE id = ?', [$trackId]);

        return Http::response(['features' => [[
            'properties' => ['osmfeatures_id' => 'R42', 'osm_tags' => ['name' => 'Ancona', 'admin_level' => '8']],
        ]]]);
    });

    $job->handle(app(OsmfeaturesClient::class));

    expect(UgcTrack::find($trackId))->toBeNull();
});
