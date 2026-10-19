<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

/**
 * Il tratto dritto lungo non vale come percorso (oc:8165).
 *
 * Quando il GPS smette di registrare, i punti tenuti restano uniti in un unico pezzo: fra
 * l'ultimo punto prima del buco e il primo dopo c'è un segmento dritto (scelta di oc:8719).
 * Per i buchi brevi è il comportamento giusto; per quelli lunghi quella linea attraversa tappe
 * intere senza che nessuno ci sia passato. Un tratto più lungo della lunghezza di segmento
 * marca quindi solo il primo e l'ultimo segmento che incontra.
 *
 * Riferimento per le geometrie: 0,0036° di latitudine ≈ 400 m, cioè un segmento.
 */
beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->service = UgcTrackMatchingService::make();
});

it('non marca i segmenti in mezzo a un tratto dritto lungo', function () {
    // Tappa dritta di ~2002 m: 6 segmenti (0..5).
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    // UGC track di due soli punti, agli estremi: un unico tratto di ~2002 m, cioè cinque volte
    // la lunghezza di segmento. Senza il limite marcherebbe tutti e sei i segmenti.
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    // I segmenti sono da 400 m esatti, quindi l'ultimo di una tappa da 2002 m è un moncone di
    // due metri: il capo del salto che ci cade dentro sta entro 50 m anche dal segmento 4, e in
    // quel punto la persona c'era davvero. Il mezzo del salto — 1, 2 e 3 — non si prende nulla.
    expect(matchedSegments($ugc, $ec))->toBe([0, 4, 5]);
});

it('marca tutti i segmenti quando i punti sono più fitti della lunghezza di segmento', function () {
    // Stessa tappa, stessa UGC track, ma con un punto ogni ~333 m: nessun tratto lungo,
    // comportamento identico a prima di oc:8165.
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.003, 11.00025 44.006, 11.00025 44.009, 11.00025 44.012, 11.00025 44.015, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $ec))->toBe([0, 1, 2, 3, 4, 5]);
});

it('applica il limite a ciascuna tappa attraversata dallo stesso tratto', function () {
    // Due tappe affiancate, entrambe lungo il tratto lungo: ciascuna tiene i suoi due estremi,
    // non due in tutto.
    $primo = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $secondo = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11.0005 44, 11.0005 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $primo))->toBe([0, 4, 5]);
    expect(matchedSegments($ugc, $secondo))->toBe([0, 4, 5]);
});

it('tiene i segmenti percorsi davvero prima e dopo il tratto lungo', function () {
    // La UGC track percorre per intero il primo tratto di tappa con punti fitti, poi salta
    // (GPS perso) e riprende alla fine: i segmenti iniziali restano tutti, quelli saltati no.
    // L'ultimo punto prima del salto sta a metà del terzo segmento, non sul suo confine: un
    // estremo esattamente sul confine fra due segmenti ne tocca uno dei due per arrotondamento,
    // e il test misurerebbe quello invece della regola.
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.003, 11.00025 44.006, 11.00025 44.0075, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    // 0,1,2 percorsi davvero; il salto da 44.0075 a 44.018 porta con sé solo i suoi capi (il 2
    // da dove parte, il 4 e il 5 dove arriva — vedi il moncone finale nel primo test).
    expect(matchedSegments($ugc, $ec))->toBe([0, 1, 2, 4, 5]);
});

it('non dà nulla a una tappa attraversata lontano dai due capi del tratto', function () {
    // Il mezzo del tratto non esiste: una tappa che la linea dritta taglia di traverso, lontana
    // da dove il segnale si è perso e da dove è tornato, non è stata percorsa da nessuno.
    $crossed = matchingEcTrack($this->app_->id, 'MULTILINESTRING((10.99 44.009, 11.01 44.009))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $crossed))->toBeNull();
});

it('dà i segmenti alle tappe che stanno ai due capi del tratto', function () {
    // Stessa camminata: qui le due tappe stanno dove il segnale si è perso e dove è tornato.
    $atStart = matchingEcTrack($this->app_->id, 'MULTILINESTRING((10.9995 44.0002, 11.0010 44.0002))');
    $atEnd = matchingEcTrack($this->app_->id, 'MULTILINESTRING((10.9995 44.0178, 11.0010 44.0178))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    expect(matchedSegments($ugc, $atStart))->not->toBeNull();
    expect(matchedSegments($ugc, $atEnd))->not->toBeNull();
});

it('registra comunque il calcolo e i parametri usati', function () {
    $ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.018))');

    $this->service->matchUgcTrack($ugc);

    $row = DB::table('ugc_track_ec_track_matches')->where('ugc_track_id', $ugc)->first();
    expect($row->total_segments)->toBe(6);
    expect($row->segment_length_meters)->toBe(400);
});
