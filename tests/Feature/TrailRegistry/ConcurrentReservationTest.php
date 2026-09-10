<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Wm\WmPackage\TrailRegistry\Enums\TrailCodeStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryCode;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

beforeEach(function () {
    runTrailRegistryStubs();
});

it('non assegna lo stesso numero a due richieste, e la seconda ne ottiene uno diverso', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $service = app(TrailRegistryService::class);

    // Si simula la finestra fra il «guarda se esiste» e lo «scrivi»: la prima
    // riga viene inserita di soppiatto dopo che propose() ha giа' deciso, come
    // farebbe un'altra richiesta arrivata nello stesso istante.
    $applications = TrailApplication::factory()->count(2)->create();

    foreach ($applications as $application) {
        DB::statement(
            'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
            ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
        );
    }

    $first = $service->reserve($applications[0]->refresh());
    $second = $service->reserve($applications[1]->refresh());

    expect($first->code)->not->toBe($second->code);
    expect(TrailRegistryCode::count())->toBe(2);
});

it('riprova quando perde la corsa sul vincolo, invece di propagare l errore', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $application = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );

    $realService = app(TrailRegistryService::class);

    // Il perdente della corsa: leggiamo il numero che propose() darebbe oggi,
    // poi lo occupiamo con una scrittura esplicita — come farebbe un'altra
    // richiesta concorrente nella finestra fra "guarda se esiste" e
    // "scrivi". reserve() (senza proposta esplicita, quindi con il proprio
    // ciclo di ritentativo interno) deve scoprire la violazione di unicita'
    // e riprovare con il numero successivo, senza propagare l'errore.
    //
    // Non passiamo qui il candidato occupato come $proposal esplicito a
    // reserve(): quel parametro e' un impegno del chiamante su un numero
    // preciso (vedi docblock di reserve()) e non ritenta piu' — a ragion
    // veduta, per non dirottare mai silenziosamente un numero scelto a mano.
    // Per riprodurre la corsa restiamo nel flusso automatico, sostituendo
    // temporaneamente propose() con una versione che restituisce una volta
    // il candidato ormai stantio (gia' occupato da makeCode) e poi delega al
    // servizio reale.
    $staleProposal = $realService->propose('MULTILINESTRING Z ((1 1 0, 2 2 0))');

    makeCode([
        'number' => $staleProposal['number'],
        'variant' => $staleProposal['variant'],
        'status' => TrailCodeStatus::Reserved,
    ]);

    $service = new class($staleProposal, $realService) extends TrailRegistryService
    {
        private bool $consumed = false;

        public function __construct(
            private array $staleProposal,
            private TrailRegistryService $delegate,
        ) {}

        public function propose(string $geometryWkt): array
        {
            if (! $this->consumed) {
                $this->consumed = true;

                return $this->staleProposal;
            }

            return $this->delegate->propose($geometryWkt);
        }
    };

    $code = $service->reserve($application->refresh());

    // Non ha ricevuto un errore: ha preso il numero successivo libero.
    expect($code->number)->toBe($staleProposal['number'] + 1);
});

it('rifiuta una proposta esplicita gia occupata invece di dirottare su un numero diverso', function () {
    makeSector('ZNUB5', 'POLYGON((0 0, 0 10, 10 10, 10 0, 0 0))');

    $application = TrailApplication::factory()->create();
    DB::statement(
        'UPDATE trail_applications SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?',
        ['MULTILINESTRING Z ((1 1 0, 2 2 0))', $application->id]
    );

    $service = app(TrailRegistryService::class);
    $proposal = $service->propose('MULTILINESTRING Z ((1 1 0, 2 2 0))');

    makeCode([
        'number' => $proposal['number'],
        'variant' => $proposal['variant'],
        'status' => TrailCodeStatus::Reserved,
    ]);

    // Una proposta esplicita e' un impegno del chiamante su un numero
    // preciso: se e' occupata la chiamata fallisce, non ripiega in
    // automatico su un numero diverso.
    $service->reserve($application->refresh(), $proposal);
})->throws(QueryException::class);
