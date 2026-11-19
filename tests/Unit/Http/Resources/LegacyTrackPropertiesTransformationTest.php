<?php

declare(strict_types=1);

require_once __DIR__.'/../../../Concerns/InsertsGeometryFixtures.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Http\Resources\EcTrackPropertiesBuilder;
use Wm\WmPackage\Http\Resources\TrackVersions\LegacyTrackPropertiesTransformation;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyWhere;
use Wm\WmPackage\Tests\Concerns\InsertsGeometryFixtures;

/**
 * oc:8997 — la trasformazione che riporta le properties dalla forma corrente a quella senza
 * numero, rimettendo le tre forme vecchie delle tassonomie. È il modulo di versione del metodo
 * di Stripe: si cancella intero il giorno che la versione senza numero esce di scena.
 */
uses(DatabaseTransactions::class, InsertsGeometryFixtures::class);

beforeEach(function () {
    config(['wm-package.shard_name' => 'test-shard']);
    Storage::fake('wmfe');
});

function transformationTrack(bool $withTaxonomies): EcTrack
{
    $app = App::factory()->createQuietly();
    $properties = [];

    if ($withTaxonomies) {
        $where = TaxonomyWhere::create(['name' => ['it' => 'Zona '.uniqid()]]);
        $properties['taxonomy_where'] = [(string) $where->id => ['name' => ['it' => 'Zona'], 'admin_level' => 8]];
    }

    $track = EcTrack::find(test()->insertEcTrackWithGeometry($app, $properties));

    if ($withTaxonomies) {
        $track->taxonomyWheres()->attach(TaxonomyWhere::query()->latest('id')->first()->id);
        $track->taxonomyActivities()->attach(
            TaxonomyActivity::create(['name' => ['it' => 'Attività '.uniqid()]])->id
        );
    }

    return $track->fresh();
}

it('rimette le tre forme vecchie delle tassonomie', function () {
    $track = transformationTrack(true);
    $builder = new EcTrackPropertiesBuilder($track);

    $legacy = LegacyTrackPropertiesTransformation::apply($builder->current(), $builder->rawContext(), $track);

    expect($legacy)->toHaveKey('taxonomy')
        ->and($legacy)->toHaveKey('taxonomy_where')
        ->and($legacy)->toHaveKey('taxonomyWheres');
});

it('non inventa chiavi che la traccia non ha', function () {
    $track = transformationTrack(false);
    $builder = new EcTrackPropertiesBuilder($track);

    $legacy = LegacyTrackPropertiesTransformation::apply($builder->current(), $builder->rawContext(), $track);

    expect($legacy)->not->toHaveKey('taxonomy_where')
        ->and($legacy)->not->toHaveKey('taxonomy');
});

it('lascia intatta ogni altra chiave', function () {
    $track = transformationTrack(true);
    $builder = new EcTrackPropertiesBuilder($track);
    $current = $builder->current();

    $legacy = LegacyTrackPropertiesTransformation::apply($current, $builder->rawContext(), $track);

    expect(array_diff_key($legacy, array_flip(['taxonomy', 'taxonomy_where', 'taxonomyWheres'])))
        ->toEqual($current);
});
