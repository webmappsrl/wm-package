<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Fields\Heading;
use Wm\WmPackage\Jobs\MatchUgcTrackToEcTracksJob;
use Wm\WmPackage\Jobs\UpdateUgcTrackDemStatsJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Filters\UgcTrackLayerFilter;
use Wm\WmPackage\Nova\Support\UgcTrackMatchesHtml;
use Wm\WmPackage\Services\Models\UgcTrackMatchingService;

require_once __DIR__.'/Helpers.php';

beforeEach(function () {
    // Il dettaglio accoda il calcolo dove manca (oc:8773): job finto e lock Redis da zero
    // (lo store redis dei test è array, e i lock vivono nell'istanza del driver).
    Bus::fake([MatchUgcTrackToEcTracksJob::class, UpdateUgcTrackDemStatsJob::class]);
    app('cache')->forgetDriver('redis');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->layerA = Layer::factory()->createQuietly(['app_id' => $this->app_->id, 'name' => ['it' => 'Via degli Dei']]);
    $this->layerB = Layer::factory()->createQuietly(['app_id' => $this->app_->id, 'name' => ['it' => 'Via <b>Mater</b> Dei']]);
    $this->ec = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11 44, 11 44.018))');
    $this->ecFree = matchingEcTrack($this->app_->id, 'MULTILINESTRING((11.0005 44, 11.0005 44.009))');
    matchingAttachToLayer($this->layerA->id, $this->ec);
    matchingAttachToLayer($this->layerB->id, $this->ec);
    matchingAttachToLayer(999999, $this->ec); // riga orfana: layer inesistente (layerables non ha FK)
    $this->ugc = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((11.00025 44, 11.00025 44.009))');
    UgcTrackMatchingService::make()->matchUgcTrack($this->ugc);
});

it('mostra ogni layer con le EcTrack corrispondenti, il gruppo senza layer, e ignora il layer orfano', function () {
    $html = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect($html)->toContain('Via degli Dei');
    expect($html)->toContain('Via &lt;b&gt;Mater&lt;/b&gt; Dei');      // nome escapato
    expect($html)->toContain('3/6');                                    // segmenti corrispondenti / totali
    expect($html)->toContain(__('EcTracks crossed outside any layer'));
    expect($html)->not->toContain('999999');
    expect(substr_count($html, 'rel="noopener noreferrer"'))->toBeGreaterThan(0);
});

it('accanto a ogni layer mostra il valore aggregato su tutte le EcTrack del layer, anche quelle non toccate', function () {
    // EcTrack del layer A lontana dalla traccia: 6 segmenti non corrispondenti, che contano nel totale.
    $untouched = matchingEcTrack($this->app_->id, 'MULTILINESTRING((12 44, 12 44.018))');
    matchingAttachToLayer($this->layerA->id, $untouched);

    $html = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect($html)->toContain('Via degli Dei</a></strong> — 3/12 (25%)');
    expect($html)->toContain('Via &lt;b&gt;Mater&lt;/b&gt; Dei</a></strong> — 3/6 (50%)');
    // Il gruppo fuori da ogni layer non ha aggregato.
    expect($html)->toContain(e(__('EcTracks crossed outside any layer')).'</strong></p>');
});

it('i totali dei layer si leggono con un numero di query che non cresce con i layer', function () {
    $count = function () {
        $track = UgcTrack::find($this->ugc);
        DB::flushQueryLog();
        DB::enableQueryLog();
        UgcTrackMatchesHtml::render($track);
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $before = $count();
    foreach (range(1, 3) as $i) {
        $layer = Layer::factory()->createQuietly(['app_id' => $this->app_->id]);
        matchingAttachToLayer($layer->id, $this->ec);
        matchingAttachToLayer($layer->id, matchingEcTrack($this->app_->id, 'MULTILINESTRING((12 44, 12 44.018))'));
    }

    // Stato, parametri App, righe, layer, EcTrack, layerables, totali: una query ciascuno,
    // stato e parametri letti una volta sola.
    expect($before)->toBe(7);
    expect($count())->toBe($before);
});

it('all apertura di un dettaglio da ricalcolare legge stato e parametri una volta sola', function () {
    $track = UgcTrack::find(matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((12 45, 12 45.001))'));
    DB::flushQueryLog();
    DB::enableQueryLog();
    UgcTrackMatchesHtml::render($track);
    DB::disableQueryLog();

    expect(count(DB::getQueryLog()))->toBe(2);
    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $track->id);
});

it('quando il calcolo non è mai stato fatto lo accoda e dice di ricaricare', function () {
    $other = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((12 45, 12 45.001))');

    $html = UgcTrackMatchesHtml::render(UgcTrack::find($other));

    expect($html)->toContain(e(__('Matching in progress: reload the page shortly')));
    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $other);
});

it('quando il calcolo è fallito lo riaccoda, dice di ricaricare e mostra il motivo escapato', function () {
    UgcTrackMatchingService::make()->markFailed($this->ugc, 'timeout <b>PostGIS</b>');

    $html = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect($html)->toContain(e(__('Matching in progress: reload the page shortly')));
    expect($html)->toContain('timeout &lt;b&gt;PostGIS&lt;/b&gt;');
    expect($html)->not->toContain('Via degli Dei');
    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $this->ugc);
});

it('quando il calcolo è stato fatto con parametri diversi da quelli della App lo riaccoda', function () {
    $this->app_->properties = array_merge($this->app_->properties ?? [], [UgcTrackMatchingService::MAX_DISTANCE_KEY => 20]);
    $this->app_->saveQuietly();

    $html = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect($html)->toContain(e(__('Matching in progress: reload the page shortly')));
    Bus::assertDispatched(MatchUgcTrackToEcTracksJob::class, fn ($j) => $j->ugcTrackId === $this->ugc);
});

it('quando il calcolo è aggiornato non accoda nulla', function () {
    $html = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect($html)->not->toContain(e(__('Matching in progress: reload the page shortly')));
    expect(UgcTrack::find($this->ugc)->dispatchMatchingIfNeeded())->toBeFalse();
    Bus::assertNotDispatched(MatchUgcTrackToEcTracksJob::class);
});

it('due aperture di fila accodano un solo calcolo, finché il lock non scade', function () {
    $other = UgcTrack::find(matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((12 45, 12 45.001))'));

    expect($other->dispatchMatchingIfNeeded())->toBeTrue();
    expect($other->dispatchMatchingIfNeeded())->toBeFalse();
    Bus::assertDispatchedTimes(MatchUgcTrackToEcTracksJob::class, 1);

    $this->travel(UgcTrack::MATCHING_LOCK_SECONDS + 1)->seconds();
    expect($other->dispatchMatchingIfNeeded())->toBeTrue();
});

it('il filtro per layer trova le tracce che passano su una EcTrack del layer', function () {
    $far = matchingUgcTrack($this->user->id, $this->app_->id, 'MULTILINESTRING((12 45, 12 45.001))');
    UgcTrackMatchingService::make()->matchUgcTrack($far);

    $ids = (new UgcTrackLayerFilter)->apply(new Request, UgcTrack::query(), $this->layerA->id)->pluck('id')->all();

    expect($ids)->toBe([$this->ugc]);
});

it('il filtro non propone layer inesistenti', function () {
    $options = (new UgcTrackLayerFilter)->options(new Request);

    expect(array_values($options))->toContain($this->layerA->id);
    expect(array_values($options))->not->toContain(999999);
});

it('il filtro propone solo i layer attraversati da almeno una UgcTrack', function () {
    $layerNoUgc = Layer::factory()->createQuietly(['app_id' => $this->app_->id, 'name' => ['it' => 'Senza passaggi']]);
    $ecAlone = matchingEcTrack($this->app_->id, 'MULTILINESTRING((12 45, 12 45.01))');
    matchingAttachToLayer($layerNoUgc->id, $ecAlone);

    $options = array_values((new UgcTrackLayerFilter)->options(new Request));

    expect($options)->toContain($this->layerA->id);
    expect($options)->not->toContain($layerNoUgc->id);
});

it('il tab della risorsa Nova App espone i due parametri di corrispondenza con i limiti', function () {
    $fields = $this->callPrivateMethod(new Wm\WmPackage\Nova\App($this->app_), 'ugc_tab');

    expect(collect($fields)->first())->toBeInstanceOf(Heading::class);
    expect(collect($fields)->first()->asHtml)->toBeTrue();

    $byAttr = collect($fields)->filter(fn ($f) => ! $f instanceof Heading)->keyBy('attribute');
    expect($byAttr->keys()->all())->toBe([
        'properties->'.UgcTrackMatchingService::SEGMENT_LENGTH_KEY,
        'properties->'.UgcTrackMatchingService::MAX_DISTANCE_KEY,
    ]);
    expect($byAttr['properties->'.UgcTrackMatchingService::SEGMENT_LENGTH_KEY]->rules)->toContain('between:50,2000');
    expect($byAttr['properties->'.UgcTrackMatchingService::MAX_DISTANCE_KEY]->rules)->toContain('between:5,200');
});

it('una riga layerables duplicata non mostra due volte la stessa EcTrack nel layer', function () {
    $before = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));
    matchingAttachToLayer($this->layerA->id, $this->ec); // duplicato (layerables non ha unique)

    $after = UgcTrackMatchesHtml::render(UgcTrack::find($this->ugc));

    expect(substr_count($after, '<li>'))->toBe(substr_count($before, '<li>'));
});
