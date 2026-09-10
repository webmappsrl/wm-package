<?php

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication as TrailApplicationModel;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode as TrailRegistryCodeModel;
use Wm\WmPackage\TrailRegistry\Nova\CodeHistoryRenderer;
use Wm\WmPackage\TrailRegistry\Nova\TrailApplication as TrailApplicationResource;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryCode as TrailRegistryCodeResource;

beforeEach(function () {
    runTrailRegistryStubs();

    $this->code = TrailRegistryCodeModel::findOrFail(makeCode());
});

it('non permette di creare o modificare un codice a mano', function () {
    $resource = new TrailRegistryCodeResource($this->code);
    $request = NovaRequest::create('/');

    expect($resource->authorizedToUpdate($request))->toBeFalse();
    expect($resource->authorizedToDelete($request))->toBeFalse();
    expect(TrailRegistryCodeResource::authorizedToCreate($request))->toBeFalse();
});

it('mostra nel registro le cinque colonne decise', function () {
    $fields = collect((new TrailRegistryCodeResource($this->code))
        ->fields(NovaRequest::create('/')))
        ->map(fn ($f) => $f->name)
        ->all();

    expect($fields)->toContain('Codice', 'Denominazione', 'Stato', 'Istanza', 'Sentiero');
});

it('non espone una action che libera un numero dal registro', function () {
    $actions = collect((new TrailRegistryCodeResource($this->code))
        ->actions(NovaRequest::create('/')))
        ->map(fn ($a) => class_basename($a))
        ->all();

    expect($actions)->not->toContain('ReleaseTrailCode');
    expect($actions)->toContain('ReplaceTrailCodeNumber');
});

it('filtra il registro per provincia, area, settore e stato', function () {
    $filters = collect((new TrailRegistryCodeResource($this->code))
        ->filters(NovaRequest::create('/')))
        ->map(fn ($f) => class_basename($f))
        ->all();

    expect($filters)->toContain(
        'TrailCodeProvinceFilter',
        'TrailCodeAreaFilter',
        'TrailCodeSectorFilter',
        'TrailCodeStatusFilter',
    );
});

it('non permette di modificare un istanza in questo ciclo', function () {
    $resource = new TrailApplicationResource(
        TrailApplicationModel::factory()->create()
    );

    expect($resource->authorizedToUpdate(NovaRequest::create('/')))->toBeFalse();
});

it('mostra nell elenco delle istanze le sei colonne decise', function () {
    $resource = new TrailApplicationResource(TrailApplicationModel::factory()->create());

    $fields = collect($resource->fields(NovaRequest::create('/')))
        ->map(fn ($f) => $f->name)
        ->all();

    expect($fields)->toContain(
        'Denominazione',
        'Codice',
        'Stato istruttoria',
        'Provenienza',
        'Inserita da',
        'Presentata il',
    );
});

it('la storia di un codice senza passaggi non e una tabella vuota', function () {
    expect(CodeHistoryRenderer::render($this->code))
        ->toContain('Nessun passaggio registrato');
});

it('offre le action di istruttoria solo sulle istanze in istruttoria', function () {
    $request = NovaRequest::create('/');

    $underReview = TrailApplicationModel::factory()->create([
        'status' => TrailApplicationStatus::UnderReview,
    ]);
    $approved = TrailApplicationModel::factory()->create([
        'status' => TrailApplicationStatus::Approved,
    ]);

    $actions = (new TrailApplicationResource($underReview))->actions($request);

    expect(collect($actions)->map(fn ($a) => class_basename($a))->all())
        ->toContain('ApproveTrailApplication', 'RejectTrailApplication');

    foreach ($actions as $action) {
        expect($action->authorizedToRun($request, $underReview))->toBeTrue();
        expect($action->authorizedToRun($request, $approved))->toBeFalse();
    }
});

it('non permette di cancellare un istanza', function () {
    $resource = new TrailApplicationResource(TrailApplicationModel::factory()->create());

    expect($resource->authorizedToDelete(NovaRequest::create('/')))->toBeFalse();
});

it('non cerca il registro su una colonna intera', function () {
    // $search = ['number'] costruirebbe un ilike su integer: errore 500 in
    // PostgreSQL.
    expect(TrailRegistryCodeResource::$search)->toBe([]);
});
