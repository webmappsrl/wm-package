<?php

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Exceptions\InvalidTrailApplicationTransitionException;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

// Approvazione e respingimento come operazioni del service: le usano l'Action
// di Nova e lo strumento di prova del canale dello shard. I test dell'Action
// (ApproveTrailApplicationTest) coprono il resto del comportamento.

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

it('approva una domanda in istruttoria: crea il sentiero e assegna il codice', function () {
    $track = app(TrailRegistryService::class)->approve($this->application->fresh());

    expect($track)->toBeInstanceOf(EcTrack::class);
    expect($this->application->fresh()->status)->toBe(TrailApplicationStatus::Approved);
    expect($this->code->fresh()->status)->toBe(TrailCodeStatus::Assigned)
        ->and($this->code->fresh()->ec_track_id)->toBe($track->id);
});

it('aggiorna lo stato anche sull istanza passata', function () {
    $application = $this->application->fresh();

    app(TrailRegistryService::class)->approve($application);

    expect($application->status)->toBe(TrailApplicationStatus::Approved);
});

it('non approva due volte: la seconda volta solleva e non crea un altro sentiero', function () {
    $service = app(TrailRegistryService::class);
    $service->approve($this->application->fresh());
    $tracks = EcTrack::count();

    expect(fn () => $service->approve($this->application->fresh()))
        ->toThrow(InvalidTrailApplicationTransitionException::class);
    expect(EcTrack::count())->toBe($tracks);
});

it('non approva una domanda ancora in compilazione', function () {
    DB::table('trail_applications')->where('id', $this->application->id)
        ->update(['status' => TrailApplicationStatus::UnderCompilation->value]);

    expect(fn () => app(TrailRegistryService::class)->approve($this->application->fresh()))
        ->toThrow(InvalidTrailApplicationTransitionException::class);
    expect($this->code->fresh()->status)->toBe(TrailCodeStatus::Reserved);
});

it('respinge una domanda in istruttoria: libera il numero e salva il motivo', function () {
    $application = $this->application->fresh();

    app(TrailRegistryService::class)->reject($application, 'Tracciato sovrapposto al sentiero 105');

    $fresh = $this->application->fresh();
    expect($fresh->status)->toBe(TrailApplicationStatus::Rejected)
        ->and($fresh->rejection_reason)->toBe('Tracciato sovrapposto al sentiero 105')
        ->and($application->rejection_reason)->toBe('Tracciato sovrapposto al sentiero 105');
    expect($this->code->fresh()->status)->toBe(TrailCodeStatus::Released);
    expect($this->code->fresh()->events->last()->reason)->toBe('application_rejected');
});

it('non respinge una domanda gia approvata: il numero assegnato resta al sentiero', function () {
    $service = app(TrailRegistryService::class);
    $track = $service->approve($this->application->fresh());

    expect(fn () => $service->reject($this->application->fresh(), 'motivo'))
        ->toThrow(InvalidTrailApplicationTransitionException::class);
    expect($this->code->fresh()->status)->toBe(TrailCodeStatus::Assigned)
        ->and($this->code->fresh()->ec_track_id)->toBe($track->id);
    expect($this->application->fresh()->rejection_reason)->toBeNull();
});
