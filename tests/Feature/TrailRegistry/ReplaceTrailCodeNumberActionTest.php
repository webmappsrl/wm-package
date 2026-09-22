<?php

use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ReplaceTrailCodeNumber;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

// Non chiamarla emptyActionFields(): quel nome e' gia' definito come
// funzione globale in ApproveTrailApplicationTest.php:47, e Pest carica
// tutti i file della suite nello stesso processo.
function actionFieldsFor(array $values): ActionFields
{
    return new ActionFields(collect($values), collect([]));
}

it('popola il primo campo con i numeri non saturi del settore', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $request = NovaRequest::create('/', 'GET', ['resourceId' => $code->trail_application_id]);

    $options = (new ReplaceTrailCodeNumber)->numberOptions($request);

    expect($options)->toHaveCount(100)
        ->and($options[13])->toBe('513');
});

it('non popola nulla se l istanza non ha un codice attivo', function () {
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'office',
        'status' => 'under_review',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $request = NovaRequest::create('/', 'GET', ['resourceId' => $applicationId]);

    expect((new ReplaceTrailCodeNumber)->numberOptions($request))->toBe([]);
});

it('non scambia l id dell istanza per l id del codice', function () {
    // Istanza 1 con codice 7: se l'action facesse find() sull'id grezzo,
    // leggerebbe il codice 1 — un settore che non c'entra niente.
    for ($i = 0; $i < 6; $i++) {
        makeCode(['number' => 90 + $i, 'sector' => '6', 'status' => TrailCodeStatus::Assigned]);
    }
    $code = TrailRegistryCode::find(makeCode(['number' => 83, 'sector' => '5']));

    expect($code->id)->not->toBe($code->trail_application_id);

    $request = NovaRequest::create('/', 'GET', ['resourceId' => $code->trail_application_id]);
    $options = (new ReplaceTrailCodeNumber)->numberOptions($request);

    // Le etichette portano il settore 5, quello dell'istanza giusta.
    expect($options[13])->toBe('513');
});

it('offre la variante zero e le lettere per un numero libero', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $request = NovaRequest::create('/', 'GET', ['resourceId' => $code->trail_application_id]);

    $options = (new ReplaceTrailCodeNumber)->variantOptions($request, 13);

    expect($options['0'])->toBe(__('nessuna variante'))
        ->and($options)->toHaveKey('A');
});

it('sostituisce il numero dell istanza', function () {
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $application = TrailApplication::find($code->trail_application_id);

    (new ReplaceTrailCodeNumber)->handle(
        actionFieldsFor(['number' => '13', 'variant' => 'A']),
        collect([$application]),
    );

    expect($application->fresh()->activeCode->code)->toBe('ZNUB513A')
        ->and($code->fresh()->status)->toBe(TrailCodeStatus::Released);
});

it('nega quando l istanza non ha un codice attivo', function () {
    $applicationId = DB::table('trail_applications')->insertGetId([
        'user_id' => makeTrailRegistryTestUser(),
        'source' => 'office',
        'status' => 'rejected',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = (new ReplaceTrailCodeNumber)->handle(
        actionFieldsFor(['number' => '13', 'variant' => '0']),
        collect([TrailApplication::find($applicationId)]),
    );

    // In questa versione di Nova ArrayAccess::offsetGet('danger') restituisce
    // l'oggetto Message (Stringable), non una stringa: il cast riporta il
    // test all'intento del brief senza cambiare l'Action.
    expect((string) ($result['danger'] ?? ''))->not->toBe('');
});

it('popola la variante quando la richiesta porta resources invece di resourceId', function () {
    // La PATCH che Nova manda al cambio del primo campo identifica i model
    // con "resources", non con "resourceId" (quello arriva solo
    // all'apertura del modale). E' il caso che oggi risulta rotto.
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $request = NovaRequest::create('/', 'GET', ['resources' => [$code->trail_application_id]]);

    $options = (new ReplaceTrailCodeNumber)->variantOptions($request, 13);

    expect($options['0'])->toBe(__('nessuna variante'))
        ->and($options)->toHaveKey('A');
});

it('non popola nulla quando resources vale la stringa all', function () {
    $request = NovaRequest::create('/', 'GET', ['resources' => 'all']);

    expect((new ReplaceTrailCodeNumber)->numberOptions($request))->toBe([]);
});

it('nega quando la combinazione e gia occupata', function () {
    makeCode(['number' => 13, 'variant' => 'A', 'status' => TrailCodeStatus::Assigned]);
    $code = TrailRegistryCode::find(makeCode(['number' => 83]));
    $application = TrailApplication::find($code->trail_application_id);

    $result = (new ReplaceTrailCodeNumber)->handle(
        actionFieldsFor(['number' => '13', 'variant' => 'A']),
        collect([$application]),
    );

    // Vedi nota sul cast nel test precedente.
    expect((string) ($result['danger'] ?? ''))->not->toBe('')
        ->and($code->fresh()->status)->toBe(TrailCodeStatus::Reserved);
});

it('offre per primi i numeri vicini al sentiero in esame', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    foreach ([11, 12, 13] as $number) {
        makeCode([
            'number' => $number,
            'status' => TrailCodeStatus::Assigned,
            'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.001 1.001 0)',
        ]);
    }

    // Il codice su cui si apre l'Action: sta accanto al cluster 11-13.
    $codeId = makeCode([
        'number' => 70,
        'status' => TrailCodeStatus::Assigned,
        'geometry_wkt' => 'LINESTRING Z (1 1 0, 1.0015 1.0015 0)',
    ]);

    $code = TrailRegistryCode::query()->findOrFail($codeId);

    $wkt = DB::selectOne(<<<'SQL'
        SELECT ST_AsText(COALESCE(t.geometry, a.geometry)) AS wkt
        FROM trail_registry_codes c
        LEFT JOIN ec_tracks t ON t.id = c.ec_track_id
        LEFT JOIN trail_applications a ON a.id = c.trail_application_id
        WHERE c.id = ?
    SQL, [$code->id])->wkt;

    $numbers = app(TrailRegistryService::class)
        ->numbersWithAvailableVariants($code->fullCode, $wkt, $code->id);

    // Senza escludere il codice in esame (il 70), il servizio farebbe
    // cluster con se stesso e in testa uscirebbero i suoi adiacenti (69, 71),
    // coprendo il vero cluster vicino. Escludendolo, in testa esce un numero
    // del cluster 11-13 — la zona dove il sentiero passa davvero — perche' un
    // numero occupato con lettera libera concorre per vicinanza come gli
    // altri.
    expect($numbers[0])->toBeIn([11, 12, 13])
        ->and($numbers[0])->not->toBe(69)
        ->and($numbers[0])->not->toBe(71);
});
