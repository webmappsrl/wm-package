<?php

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;
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
    $request = richiestaConRuolo('Editor');

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

it('non permette di modificare un istanza non in istruttoria', function () {
    // La regola completa (solo in istruttoria, solo i nove valori manuali
    // del tab DEM) e' testata in TrailApplicationDemTabTest (oc:8571).
    $resource = new TrailApplicationResource(
        TrailApplicationModel::factory()->create(['status' => TrailApplicationStatus::Approved])
    );

    expect($resource->authorizedToUpdate(NovaRequest::create('/')))->toBeFalse();
});

it('mostra nell elenco delle istanze le sei colonne decise', function () {
    $resource = new TrailApplicationResource(TrailApplicationModel::factory()->create());

    // indexFields() applica fieldsForIndex() solo su una richiesta che Nova
    // riconosce come "resource index" (isResourceIndexRequest()): un
    // NovaRequest generico non basta.
    $request = ResourceIndexRequest::create('/');

    $fields = $resource->indexFields($request)
        ->map(fn ($f) => $f->name)
        ->values()
        ->all();

    expect($fields)->toBe([
        'Denominazione',
        'Codice',
        'Stato istruttoria',
        'Provenienza',
        'Inserita da',
        'Presentata il',
    ]);
});

it('la storia di un codice senza passaggi non e una tabella vuota', function () {
    expect(CodeHistoryRenderer::render($this->code))
        ->toContain('Nessun passaggio registrato');
});

it('offre le action di istruttoria solo sulle istanze in istruttoria', function () {
    $request = richiestaConRuolo('Editor');

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

it('il canRun delle tre action segue il ruolo oltre allo stato', function (string $role, bool $atteso) {
    $request = richiestaConRuolo($role);

    // makeCode() crea un codice Reserved con la sua istanza in istruttoria:
    // per questa istanza tutte e tre le action sono applicabili per stato,
    // quindi l'unica differenza fra i casi e' il ruolo (oc:8700).
    $application = TrailApplicationModel::find(TrailRegistryCodeModel::find(makeCode(['number' => 30]))->trail_application_id);

    $actions = (new TrailApplicationResource($application))->actions($request);

    expect(collect($actions)->map(fn ($a) => class_basename($a))->all())
        ->toBe(['ApproveTrailApplication', 'RejectTrailApplication', 'ReplaceTrailCodeNumber']);

    foreach ($actions as $action) {
        expect($action->authorizedToRun($request, $application))->toBe($atteso, class_basename($action));
    }
})->with([
    'Validator' => ['Validator', false],
    'Editor' => ['Editor', true],
]);

it('offre approva e respingi solo dal dettaglio, non dall elenco', function () {
    $application = TrailApplicationModel::factory()->create([
        'status' => TrailApplicationStatus::UnderReview,
    ]);

    // Si verificano le azioni che la Resource restituisce, non la proprieta':
    // un ->showOnIndex() aggiunto in actions() la scavalcherebbe (oc:8567).
    $actions = collect((new TrailApplicationResource($application))->actions(NovaRequest::create('/')))
        ->filter(fn ($a) => in_array(class_basename($a), ['ApproveTrailApplication', 'RejectTrailApplication'], true));

    expect($actions)->toHaveCount(2);

    foreach ($actions as $action) {
        // Ne' nel menu della selezione multipla, ne' nel menu della singola
        // riga: la richiesta e' «solo aprendo l'istanza» (oc:8567).
        expect($action->shownOnIndex())->toBeFalse()
            ->and($action->shownOnTableRow())->toBeFalse()
            ->and($action->shownOnDetail())->toBeTrue();
    }
});

it('mostra la motivazione del respingimento solo sulle istanze respinte', function () {
    $request = NovaRequest::create('/');

    $reasonField = fn (TrailApplicationModel $application) => collect((new TrailApplicationResource($application))->fields($request))
        ->first(fn ($f) => $f->attribute === 'rejection_reason');

    $rejected = TrailApplicationModel::factory()->create([
        'status' => TrailApplicationStatus::Rejected,
        'rejection_reason' => 'Tracciato sovrapposto al sentiero 105',
    ]);
    $underReview = TrailApplicationModel::factory()->create([
        'status' => TrailApplicationStatus::UnderReview,
    ]);

    expect($reasonField($rejected)->authorizedToSee($request))->toBeTrue()
        ->and($reasonField($rejected)->isShownOnIndex($request, $rejected))->toBeFalse()
        ->and($reasonField($underReview)->authorizedToSee($request))->toBeFalse();
});

it('ha le chiavi nuove delle azioni di istruttoria in it.json ed en.json', function () {
    $keys = [
        'Approve',
        'Reject',
        'Applications approved.',
        'Applications rejected.',
        'Applications approved: :approved. Skipped because not under review: :refused.',
        'Applications rejected: :rejected. Skipped because not under review: :refused.',
        'No application approved: only applications under review can be approved.',
        'No application rejected: only applications under review can be rejected.',
        'Rejection reason',
        'Visible to the applicant: explain what to correct in the new application.',
    ];

    foreach (['it', 'en'] as $locale) {
        $translations = json_decode(
            file_get_contents(__DIR__."/../../../resources/lang/{$locale}.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        foreach ($keys as $key) {
            expect($translations)->toHaveKey($key);
        }
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
