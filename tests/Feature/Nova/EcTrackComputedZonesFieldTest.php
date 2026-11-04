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

function ecTrackConZone(array $taxonomyWhere): EcTrack
{
    $app = App::factory()->createQuietly();

    return EcTrack::factory()->createQuietly([
        'app_id' => $app->id,
        'osmid' => null,
        'properties' => $taxonomyWhere === [] ? [] : ['taxonomy_where' => $taxonomyWhere],
    ]);
}

it('mostra le TaxonomyWhere scritte nelle properties, nella lingua corrente', function () {
    $track = ecTrackConZone([
        ['it' => 'Nuoro', 'en' => 'Nuoro province', '_source' => 'osmfeatures'],
        ['it' => 'Area T (Centrale)', 'en' => 'Area T', '_source' => 'geohub_conf_32'],
    ]);

    app()->setLocale('it');
    $campo = ecTrackZonesField($track);
    $campo->resolve($track);
    $italiano = strip_tags((string) $campo->value);

    app()->setLocale('en');
    $campo = ecTrackZonesField($track);
    $campo->resolve($track);
    $inglese = strip_tags((string) $campo->value);

    expect($italiano)->toContain('Area T (Centrale)')
        ->and($italiano)->toContain('Nuoro')
        ->and($inglese)->toContain('Area T')
        ->and($inglese)->toContain('Nuoro province');
});

it('ripiega sulla prima lingua valorizzata quando manca quella corrente', function () {
    $track = ecTrackConZone([['en' => 'Only english', '_source' => 'osmfeatures']]);

    $campo = ecTrackZonesField($track);
    $campo->resolve($track);

    expect(strip_tags((string) $campo->value))->toContain('Only english');
});

it('mostra un trattino quando il calcolo non ha trovato nulla', function () {
    $campo = ecTrackZonesField($track = ecTrackConZone([]));
    $campo->resolve($track);

    expect(strip_tags((string) $campo->value))->toBe('—');
});

it('non espone _source come se fosse un nome', function () {
    $track = ecTrackConZone([['it' => 'Sardegna', '_source' => 'osmfeatures']]);

    $campo = ecTrackZonesField($track);
    $campo->resolve($track);

    expect(strip_tags((string) $campo->value))->not->toContain('osmfeatures');
});

it('esiste solo in lettura: non compare nei form', function () {
    $campo = ecTrackZonesField(ecTrackConZone([]));

    expect($campo->showOnUpdate)->toBeFalse()
        ->and($campo->showOnCreation)->toBeFalse()
        ->and($campo->showOnDetail)->toBeTrue();
});
