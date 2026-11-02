<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\App\AppConfigService;

uses(DatabaseTransactions::class);

// Il filtro "tipo di POI" della config deve elencare i tipi dei POI mostrati sulla mappa
// (app_id + global), non quelli del proprietario dell'App (oc:8911).
// Helper come closure su $this, non funzioni globali: due file Pest che dichiarano la stessa
// funzione fermano l'intera suite con "Cannot redeclare".

beforeEach(function () {
    $this->owner = User::factory()->createQuietly();
    $this->manager = User::factory()->createQuietly();
    // Non $this->app: è l'applicazione di Testbench.
    $this->poiApp = App::factory()->createQuietly(['user_id' => $this->owner->id, 'filter_poi_type' => true]);
    $this->otherApp = App::factory()->createQuietly(['user_id' => $this->owner->id]);

    $this->makePoi = fn (App $app, User $user, bool $global): EcPoi => EcPoi::factory()->createQuietly([
        'app_id' => $app->id,
        'user_id' => $user->id,
        'global' => $global,
        'osmid' => null,
        'properties' => [],
    ]);

    $this->makePoiType = fn (string $name): TaxonomyPoiType => TaxonomyPoiType::create([
        'identifier' => 'oc8911-'.uniqid(),
        'name' => ['it' => $name],
    ]);

    // Insert diretto sul pivot, non attach(): l'attach scatena TaxonomyPoiTypeablesObserver
    // (icone su S3 e job), mentre qui interessa solo la config.
    $this->attachType = function (EcPoi $poi, TaxonomyPoiType $type): void {
        DB::table('taxonomy_poi_typeables')->insert([
            'taxonomy_poi_type_id' => $type->id,
            'taxonomy_poi_typeable_type' => (new EcPoi)->getMorphClass(),
            'taxonomy_poi_typeable_id' => $poi->id,
        ]);
    };

    $this->poiTypeOptions = fn (App $app, string $key = 'poi_types'): array => (new AppConfigService($app->fresh()))->config()['MAP']['filters'][$key]['options'];
});

it('globalEcPois returns only global ec pois of the app', function () {
    $global = ($this->makePoi)($this->poiApp, $this->manager, true);
    ($this->makePoi)($this->poiApp, $this->manager, false);
    ($this->makePoi)($this->otherApp, $this->manager, true);

    expect($this->poiApp->globalEcPois()->pluck('id')->all())->toBe([$global->id]);
});

it('getAllPoisGeojson contains only the global ec pois of the app', function () {
    $global = ($this->makePoi)($this->poiApp, $this->manager, true);
    ($this->makePoi)($this->poiApp, $this->manager, false);
    ($this->makePoi)($this->otherApp, $this->manager, true);

    // Restituisce la lista delle feature, non una FeatureCollection.
    $features = $this->poiApp->getAllPoisGeojson();

    expect(collect($features)->pluck('properties.id')->all())->toBe([$global->id]);
});

it('includes poi types of global app pois owned by another user', function () {
    $type = ($this->makePoiType)('Area tende');
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->manager, true), $type);

    $expected = 'poi_type_'.$type->identifier;
    expect(collect(($this->poiTypeOptions)($this->poiApp))->pluck('identifier')->all())->toContain($expected)
        ->and(collect(($this->poiTypeOptions)($this->poiApp, 'poi_type'))->pluck('identifier')->all())->toContain($expected);
});

it('excludes poi types used only by non global pois', function () {
    $type = ($this->makePoiType)('Solo non globale');
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->owner, false), $type);

    expect(collect(($this->poiTypeOptions)($this->poiApp))->pluck('id')->all())->not->toContain($type->id);
});

it('excludes poi types used only by pois of another app', function () {
    $type = ($this->makePoiType)('Altra app');
    ($this->attachType)(($this->makePoi)($this->otherApp, $this->owner, true), $type);

    expect(collect(($this->poiTypeOptions)($this->poiApp))->pluck('id')->all())->not->toContain($type->id);
});

it('lists a poi type once when several pois share it', function () {
    $type = ($this->makePoiType)('Condiviso');
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->manager, true), $type);
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->owner, true), $type);
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->manager, false), $type);

    expect(collect(($this->poiTypeOptions)($this->poiApp))->where('id', $type->id))->toHaveCount(1);
});

it('keeps option shape and name ordering', function () {
    $z = ($this->makePoiType)('Zzz ristorazione');
    $a = ($this->makePoiType)('Aaa struttura');
    $poi = ($this->makePoi)($this->poiApp, $this->manager, true);
    ($this->attachType)($poi, $z);
    ($this->attachType)($poi, $a);

    $options = ($this->poiTypeOptions)($this->poiApp);

    expect($options)->toHaveCount(2)
        ->and(array_keys($options[0]))->toBe(['identifier', 'name', 'id', 'icon_name'])
        ->and($options[0]['id'])->toBe($a->id)
        ->and($options[0]['name'])->toBe(['it' => 'Aaa struttura'])
        ->and($options[1]['id'])->toBe($z->id);
});

it('returns empty options when the app has no global pois', function () {
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->owner, false), ($this->makePoiType)('Nascosto'));

    expect(($this->poiTypeOptions)($this->poiApp))->toBe([]);
});

it('omits poi types filter when filter_poi_type is off', function () {
    $this->poiApp->forceFill(['filter_poi_type' => false])->saveQuietly();
    ($this->attachType)(($this->makePoi)($this->poiApp, $this->manager, true), ($this->makePoiType)('Spento'));

    $filters = (new AppConfigService($this->poiApp->fresh()))->config()['MAP']['filters'] ?? [];

    expect($filters)->not->toHaveKey('poi_types');
});
