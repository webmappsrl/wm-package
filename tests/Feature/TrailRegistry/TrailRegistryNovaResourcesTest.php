<?php

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
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

it('non espone piu dal registro la action che sostituisce il numero', function () {
    $actions = collect((new TrailRegistryCodeResource($this->code))
        ->actions(NovaRequest::create('/')))
        ->map(fn ($a) => class_basename($a))
        ->all();

    expect($actions)->not->toContain('ReleaseTrailCode');
    // Si sostituisce dal dettaglio dell'istanza (oc:8569): il registro resta
    // il posto dove si guarda lo stato dei codici, non dove si cambiano.
    expect($actions)->not->toContain('ReplaceTrailCodeNumber');
});

it('espone dall istanza la action che sostituisce il numero', function () {
    $code = TrailRegistryCodeModel::find(makeCode(['number' => 83]));
    $application = TrailApplicationModel::find($code->trail_application_id);

    $actions = collect((new TrailApplicationResource($application))
        ->actions(NovaRequest::create('/')))
        ->map(fn ($a) => class_basename($a))
        ->all();

    expect($actions)->toContain('ReplaceTrailCodeNumber');
});

it('il canRun della sostituzione segue lo stato del codice attivo', function () {
    $request = NovaRequest::create('/');

    // Il canRun vive sulla Resource, non sull'Action: va preso da li',
    // esattamente come lo vede Nova.
    $actionFor = fn (TrailApplicationModel $application) => collect(
        (new TrailApplicationResource($application))->actions($request)
    )->first(fn ($a) => class_basename($a) === 'ReplaceTrailCodeNumber');

    // 1. Codice attivo Reserved -> il bottone compare.
    $reserved = TrailRegistryCodeModel::find(makeCode(['number' => 20]));
    $reservedApplication = TrailApplicationModel::find($reserved->trail_application_id);

    expect($actionFor($reservedApplication)->authorizedToRun($request, $reservedApplication))->toBeTrue();

    // 2. Codice attivo Assigned -> il bottone non compare: non e' piu' il
    // caso su cui la sostituzione ha senso.
    $assignedApplicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'office',
        'status' => 'approved',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    makeCode([
        'number' => 21,
        'status' => TrailCodeStatus::Assigned,
        'trail_application_id' => $assignedApplicationId,
    ]);
    $assignedApplication = TrailApplicationModel::find($assignedApplicationId);

    expect($actionFor($assignedApplication)->authorizedToRun($request, $assignedApplication))->toBeFalse();

    // 3. Nessun codice attivo -> il canRun usa la navigazione sicura e non
    // esplode.
    $withoutCodeApplicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'office',
        'status' => 'rejected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $withoutCodeApplication = TrailApplicationModel::find($withoutCodeApplicationId);

    expect($actionFor($withoutCodeApplication)->authorizedToRun($request, $withoutCodeApplication))->toBeFalse();
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

    // Solo le due action di istruttoria: la terza (ReplaceTrailCodeNumber) ha
    // un criterio diverso, legato allo stato del codice attivo, non a quello
    // dell'istanza — vedi il test dedicato piu' sotto.
    $instructoryActions = collect($actions)
        ->filter(fn ($a) => class_basename($a) !== 'ReplaceTrailCodeNumber');

    foreach ($instructoryActions as $action) {
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
