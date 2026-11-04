<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Nova\Fields\Field;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Nova\EcTrack as EcTrackResource;

/**
 * Il campo delle TaxonomyWhere sulla scheda di un contenuto, in sola lettura (oc:8910).
 *
 * Il dato sta in `properties->taxonomy_where`, non nella relazione `taxonomyWheres()`: è lì che
 * lo scrive `GeometryComputationService::syncTaxonomyWhere()`, e un campo che leggesse il pivot
 * mostrerebbe sempre vuoto. Lo spostamento sul pivot è oc:8920.
 *
 * La sezione delle tassonomie la costruisce lo shard, non la Resource del package: qui si prova
 * il metodo che la popola.
 */
uses(DatabaseTransactions::class);

function ecTrackZonesField(EcTrack $track): ?Field
{
    $resource = new EcTrackResource($track);
    $reflection = new ReflectionMethod($resource, 'makeComputedWheresField');
    $reflection->setAccessible(true);

    return $reflection->invoke($resource, 'General')['General'][0] ?? null;
}

function ecTrackWithZones(array $taxonomyWhere): EcTrack
{
    $app = App::factory()->createQuietly();

    return EcTrack::factory()->createQuietly([
        'app_id' => $app->id,
        'osmid' => null,
        'properties' => $taxonomyWhere === [] ? [] : ['taxonomy_where' => $taxonomyWhere],
    ]);
}

it('mostra le TaxonomyWhere scritte nelle properties, nella lingua corrente', function () {
    $track = ecTrackWithZones([
        ['it' => 'Nuoro', 'en' => 'Nuoro province', '_source' => 'osmfeatures'],
        ['it' => 'Area T (Centrale)', 'en' => 'Area T', '_source' => 'geohub_conf_32'],
    ]);

    app()->setLocale('it');
    $field = ecTrackZonesField($track);
    $field->resolve($track);
    $italiano = strip_tags((string) $field->value);

    app()->setLocale('en');
    $field = ecTrackZonesField($track);
    $field->resolve($track);
    $inglese = strip_tags((string) $field->value);

    expect($italiano)->toContain('Area T (Centrale)')
        ->and($italiano)->toContain('Nuoro')
        ->and($inglese)->toContain('Area T')
        ->and($inglese)->toContain('Nuoro province');
});

it('ripiega sulla prima lingua valorizzata quando manca quella corrente', function () {
    $track = ecTrackWithZones([['en' => 'Only english', '_source' => 'osmfeatures']]);

    $field = ecTrackZonesField($track);
    $field->resolve($track);

    expect(strip_tags((string) $field->value))->toContain('Only english');
});

it('mostra un trattino quando il calcolo non ha trovato nulla', function () {
    $field = ecTrackZonesField($track = ecTrackWithZones([]));
    $field->resolve($track);

    expect(strip_tags((string) $field->value))->toBe('—');
});

it('non espone _source come se fosse un nome', function () {
    $track = ecTrackWithZones([['it' => 'Sardegna', '_source' => 'osmfeatures']]);

    $field = ecTrackZonesField($track);
    $field->resolve($track);

    expect(strip_tags((string) $field->value))->not->toContain('osmfeatures');
});

it('esiste solo in lettura: non compare nei form', function () {
    $field = ecTrackZonesField(ecTrackWithZones([]));

    expect($field->showOnUpdate)->toBeFalse()
        ->and($field->showOnCreation)->toBeFalse()
        ->and($field->showOnDetail)->toBeTrue();
});
