<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\Track\UpdateEcTrackDemJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\RejectTrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();

    // EcTrackObserver mette in coda UpdateEcTrack3DDemJob, che chiama davvero
    // un servizio esterno; App::factory()->create() farebbe scattare
    // AppObserver::saved() (shard_name non configurato in questo ambiente).
    Bus::fake();
    // La sequenza si riavvia da 1 perche' MediaObserver, quando il modello
    // padre non ha una colonna `app_id` (e trail_applications non ce l'ha),
    // ripiega sull'app 1: senza un'App con quell'id l'insert del media viola
    // la chiave esterna. Riguarda solo l'ambiente di test.
    DB::statement('ALTER SEQUENCE apps_id_seq RESTART WITH 1');
    // Le conversioni media di GeometryModel passano da StorageService, che
    // pretende uno shard: in questo ambiente non e' configurato e il getter
    // tipizzato solleva.
    config(['wm-package.shard_name' => 'wm_package_testing']);
    App::factory()->createQuietly();

    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $this->application = TrailApplication::factory()->create(['name' => 'Domanda del monte']);
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $this->application->id]
    );
    $this->application->refresh();

    $this->code = app(TrailRegistryService::class)->reserve($this->application);
});

function emptyActionFields(): ActionFields
{
    return new ActionFields(collect(), collect());
}

it('approvando crea il sentiero, gli attacca la geometria e assegna il codice', function () {
    $before = EcTrack::count();

    (new ApproveTrailApplication)->handle(
        emptyActionFields(),
        collect([$this->application->fresh()]),
    );

    expect(EcTrack::count())->toBe($before + 1);

    $track = EcTrack::latest('id')->first();
    expect($track->name)->toBe('Domanda del monte');

    $wkt = DB::selectOne('SELECT ST_AsText(geometry::geometry) AS wkt FROM ec_tracks WHERE id = ?', [$track->id])->wkt;
    expect($wkt)->toContain('MULTILINESTRING');

    $code = $this->code->fresh();
    expect($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id)
        ->and($code->code)->toBe('ZNUB500')
        ->and($code->trail_application_id)->toBe($this->application->id);

    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Approved);
});

it('approvando due volte non crea due sentieri', function () {
    $action = new ApproveTrailApplication;

    $action->handle(emptyActionFields(), collect([$this->application->fresh()]));
    $before = EcTrack::count();
    $action->handle(emptyActionFields(), collect([$this->application->fresh()]));

    expect(EcTrack::count())->toBe($before);
});

it('attacca al sentiero il tipo configurato dal consumer', function () {
    // Niente factory: HasPackageFactory non ne risolve una per
    // TaxonomyActivity in questo ambiente (vedi CLAUDE.md del package).
    // Insert diretto: TaxonomyActivity::create() farebbe scattare
    // TaxonomyObserver, che scrive su storage (shard_name non configurato in
    // questo ambiente); e HasPackageFactory non risolve una factory per
    // questo modello (vedi CLAUDE.md del package).
    $activityId = DB::table('taxonomy_activities')->insertGetId([
        'name' => json_encode(['it' => 'Sentiero']),
        'identifier' => 'hiking-trail',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $activity = TaxonomyActivity::findOrFail($activityId);
    config(['wm-package.features.trail_registry.trail_type_identifier' => 'hiking-trail']);
    // TaxonomyActivityablesObserver, sull'insert in pivot, costruisce
    // AppIconsService, che legge lo shard: in questo ambiente non e'
    // configurato e il getter tipizzato solleva.
    config(['wm-package.shard_name' => 'wm_package_testing']);
    // ...e da li' arriva al disco S3 reale per leggere icons.json.
    Storage::fake('wmfe');

    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    $track = EcTrack::latest('id')->first();

    expect($track->taxonomyActivities()->pluck('taxonomy_activities.id')->all())->toContain($activity->id);
});

it('approva comunque se la tassonomia del tipo non e configurata', function () {
    config(['wm-package.features.trail_registry.trail_type_identifier' => 'inesistente']);

    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Approved);
});

it('respingendo libera il numero con la causa nella storia', function () {
    (new RejectTrailApplication)->handle(
        emptyActionFields(),
        collect([$this->application->fresh()]),
    );

    $code = $this->code->fresh();

    expect($code->status)->toBe(TrailCodeStatus::Released);
    expect($code->events->last()->reason)->toBe('application_rejected');
    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Rejected);
});

it('non respinge un istanza gia approvata: il numero assegnato non torna libero', function () {
    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    $track = EcTrack::latest('id')->first();

    (new RejectTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    $code = $this->code->fresh();

    expect($code->status)->toBe(TrailCodeStatus::Assigned)
        ->and($code->ec_track_id)->toBe($track->id);
    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Approved);
    expect($code->events->pluck('reason')->all())->not->toContain('application_rejected');

    // Il numero non torna proponibile a una seconda domanda.
    expect(app(TrailRegistryService::class)->availableNumbers('ZNUB5'))->not->toContain(0);
});

it('non approva un istanza gia respinta', function () {
    (new RejectTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    $before = EcTrack::count();

    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    expect(EcTrack::count())->toBe($before);
    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Rejected);
});

it('mette in coda la catena degli observer per il sentiero creato', function () {
    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$this->application->fresh()]));

    // La catena e' quella di EcTrackService::createDataChain(), che l'INSERT
    // diretto non fa scattare: senza, il sentiero nasce senza quota,
    // dislivelli, pendenze, tassonomie territoriali ne indice di ricerca.
    // Sotto Bus::fake() si verifica che venga MESSA IN CODA, non eseguita.
    Bus::assertDispatched(UpdateEcTrackDemJob::class);
});

it('carica un allegato su un istanza e lo salva col model_type reale', function () {
    Storage::fake('public');

    $application = $this->application->fresh();
    $media = $application->addMediaFromString('contenuto')
        ->usingFileName('allegato.txt')
        ->toMediaCollection('default', 'public');

    expect($media->exists)->toBeTrue()
        ->and($media->model_type)->toBe(TrailApplication::class);

    expect(Storage::disk('public')->exists($media->getPathRelativeToRoot()))->toBeTrue();
});

it('approvando copia gli allegati sul sentiero, file compreso', function () {
    Storage::fake('public');

    $application = $this->application->fresh();
    $application->addMediaFromString('contenuto')
        ->usingFileName('allegato.txt')
        ->toMediaCollection('default', 'public');

    (new ApproveTrailApplication)->handle(emptyActionFields(), collect([$application->fresh()]));

    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Approved);
    expect($this->code->fresh()->status)->toBe(TrailCodeStatus::Assigned);

    $track = EcTrack::latest('id')->first();
    $copied = $track->getMedia('default');

    // L'allegato deve ARRIVARE: la sola assenza di eccezioni passerebbe anche
    // con la copia rotta.
    expect($copied)->toHaveCount(1);
    expect($copied->first()->file_name)->toBe('allegato.txt');

    $path = $copied->first()->getPathRelativeToRoot();
    expect(Storage::disk('public')->exists($path))->toBeTrue();
    expect(Storage::disk('public')->get($path))->toBe('contenuto');

    // L'originale resta all'istanza: la copia non e' uno spostamento.
    expect($application->fresh()->getMedia('default'))->toHaveCount(1);
});
