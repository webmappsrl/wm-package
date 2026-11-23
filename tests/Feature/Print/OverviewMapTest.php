<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Print\BuildOverviewMapJob;
use Wm\WmPackage\Jobs\Print\BuildPrintZipJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\MapRenderRequest;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;
use Wm\WmPackage\Services\Print\XelatexCompiler;

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    // Le view del package, non gli override dello shard in cui il package è montato
    // (resources/views/vendor/wm-package/print): qui si prova il template di default.
    app('view')->getFinder()->replaceNamespace('wm-package', [realpath(__DIR__.'/../../../resources/views')]);
    config([
        'wm-package.shard_name' => 'testshard',
        'wm-package.ec_track_model' => EcTrack::class,
        'wm-package.features.print.overview_map.max_scale' => 50000,
        'cache.stores.redis.driver' => 'array',
    ]);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);
    Cache::flush();
    Route::group([], __DIR__.'/../../../routes/domains/print.php');
    app('router')->getRoutes()->refreshNameLookups();

    $this->printApp = App::factory()->createQuietly([
        'available_languages' => json_encode(['it', 'en']),
        'default_language' => 'it',
    ]);
    /** Traccia con geometria nota: linea da (lon, lat) a (lon + $d, lat + $d). */
    $this->makeTrack = function (float $lon, float $lat, float $d, array $attrs = [], ?float $dy = null) {
        $track = EcTrack::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null] + $attrs);
        $wkt = sprintf('MULTILINESTRING Z((%F %F 0,%F %F 0))', $lon, $lat, $lon + $d, $lat + ($dy ?? $d));
        DB::statement('UPDATE ec_tracks SET geometry = ST_GeomFromText(?, 4326) WHERE id = ?', [$wkt, $track->id]);

        return $track;
    };
});

describe('scale', function () {
    it('computes the denominator on a known bbox', function () {
        // 0,1° x 0,1° attorno a lat 40: altezza 11.119,5 m, larghezza 8.518 m (cos 40°).
        // Su 200x170 mm il limite e' l'altezza: 11119.5 / 0.17 = 65.409 (larghezza: 8518 / 0.2 = 42.590).
        $scale = PrintService::overviewScale([8.95, 39.95, 9.05, 40.05], 200.0, 170.0);
        expect($scale)->toBeGreaterThanOrEqual(65409)->toBeLessThanOrEqual(65410);

        // Lato largo: 1° di lon all'equatore (111.195 m) su 200 mm = 555.975; altezza minima.
        expect(PrintService::overviewScale([0.0, 0.0, 1.0, 0.01], 200.0, 170.0))->toBeGreaterThanOrEqual(555974)->toBeLessThanOrEqual(555976);

        // Le dimensioni del controllo e del rendering sono le stesse costanti.
        expect(BuildOverviewMapJob::MAP_WIDTH_MM)->toBe(PrintService::OVERVIEW_MAP_WIDTH_MM)
            ->and(BuildOverviewMapJob::MAP_HEIGHT_MM)->toBe(PrintService::OVERVIEW_MAP_HEIGHT_MM)
            ->and([PrintService::OVERVIEW_MAP_WIDTH_MM, PrintService::OVERVIEW_MAP_HEIGHT_MM])->toBe([200.0, 170.0]);
    });

    it('reads the bbox of the tracks from PostGIS', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $b = ($this->makeTrack)(9.02, 40.02, 0.01);

        $bbox = app(PrintService::class)->tracksBbox(EcTrack::class, [$a->id, $b->id]);

        expect($bbox[0])->toEqualWithDelta(9.0, 1e-6)
            ->and($bbox[1])->toEqualWithDelta(40.0, 1e-6)
            ->and($bbox[2])->toEqualWithDelta(9.03, 1e-6)
            ->and($bbox[3])->toEqualWithDelta(40.03, 1e-6);
    });
});

describe('route', function () {
    beforeEach(fn () => Bus::fake());

    it('registers the named routes with their throttle', function () {
        expect(Route::getRoutes()->getByName('print.overview-map')->gatherMiddleware())->toContain('throttle:5,1,print-overview')
            ->and(Route::getRoutes()->getByName('print.overview-map.status')->gatherMiddleware())->toContain('throttle:60,1,print-status');
    });

    it('dispatches one job and shows the waiting page within the scale cap', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $b = ($this->makeTrack)(9.02, 40.02, 0.01);
        $ids = [$a->id, $b->id];

        $this->get(route('print.overview-map', ['ids' => array_reverse($ids), 'lang' => 'en']))
            ->assertOk()
            ->assertSee('aria-busy="true"', false)
            ->assertSee('print\\/overview-map\\/status', false);

        Bus::assertDispatchedTimes(BuildOverviewMapJob::class, 1);
        Bus::assertDispatched(BuildOverviewMapJob::class, fn ($j) => $j->ids === $ids && $j->locale === 'en');
        expect(PrintStatus::getOverview(BuildOverviewMapJob::key($ids, 'en')))->toBe(['status' => 'queued'])
            ->and(PrintStatus::getZip(BuildOverviewMapJob::key($ids, 'en')))->toBeNull();
    });

    it('answers 422 with the reduce-the-selection message above the cap and dispatches nothing', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $b = ($this->makeTrack)(9.5, 40.5, 0.01);

        $this->get(route('print.overview-map', ['ids' => [$a->id, $b->id], 'lang' => 'en']))
            ->assertStatus(422)
            ->assertSee('Reduce the selection')
            ->assertSee('1:50.000');
        $this->get(route('print.overview-map', ['ids' => [$a->id, $b->id], 'lang' => 'it']))
            ->assertStatus(422)->assertSee('Riduci la selezione');
        Bus::assertNotDispatched(BuildOverviewMapJob::class);

        // Un solo sentiero piccolo sta nel limite.
        $this->get(route('print.overview-map', ['ids' => [$a->id], 'lang' => 'it']))->assertOk();
    });

    it('splits the cap exactly at 1:50.000 on the height side at lat 40', function (float $d, bool $ok) {
        // Altezza: d·111.195 m / 0,17 m = 50.000 per d = 0,07644°.
        $t = ($this->makeTrack)(9.0, 40.0, $d);

        $response = $this->get(route('print.overview-map', ['ids' => [$t->id], 'lang' => 'it']));
        $ok ? $response->assertOk() : $response->assertStatus(422);
        $ok ? Bus::assertDispatched(BuildOverviewMapJob::class) : Bus::assertNotDispatched(BuildOverviewMapJob::class);
    })->with([[0.0764, true], [0.0765, false]]);

    it('splits the cap exactly at 1:50.000 on the width side for a wide low bbox at lat 40', function (float $dx, bool $ok) {
        // Larghezza: dx·111.195·cos(40°) m / 0,2 m = 50.000 per dx = 0,11739°; altezza 0,005° (scala ~1:3.270).
        $t = ($this->makeTrack)(9.0, 39.995, $dx, [], 0.005);

        $response = $this->get(route('print.overview-map', ['ids' => [$t->id], 'lang' => 'it']));
        $ok ? $response->assertOk() : $response->assertStatus(422);
        $ok ? Bus::assertDispatched(BuildOverviewMapJob::class) : Bus::assertNotDispatched(BuildOverviewMapJob::class);
    })->with([[0.1173, true], [0.1175, false]]);

    it('applies the same id and language validation as the zip', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);

        $this->get(route('print.overview-map'))->assertStatus(422);
        $this->get(route('print.overview-map', ['ids' => ['abc']]))->assertStatus(422);
        $this->get(route('print.overview-map', ['ids' => [999999999]]))->assertNotFound();
        $this->get(route('print.overview-map', ['ids' => [$a->id], 'lang' => 'de']))->assertNotFound();
        Bus::assertNotDispatched(BuildOverviewMapJob::class);
    });

    it('redirects to the pdf when fresh and reports done on status, ignoring the zip file', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $ids = [$a->id];
        $path = BuildOverviewMapJob::path(BuildOverviewMapJob::key($ids, 'it'), 'it');
        Storage::disk('wmfe')->put($path, 'pdf');

        $this->get(route('print.overview-map', ['ids' => $ids, 'lang' => 'it']))->assertRedirect('https://wmfe.test/'.$path);
        $this->getJson(route('print.overview-map.status', ['ids' => $ids, 'lang' => 'it']))
            ->assertOk()->assertJson(['status' => 'done', 'url' => 'https://wmfe.test/'.$path]);
        expect($path)->toStartWith('testshard/print-tmp/overview/')->toEndWith('.pdf')
            ->and($path)->not->toBe(BuildPrintZipJob::path(BuildOverviewMapJob::key($ids, 'it'), 'it'));
        Bus::assertNotDispatched(BuildOverviewMapJob::class);
    });

    it('does not redirect to a pdf older than 24 hours and dispatches', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $ids = [$a->id];
        $path = BuildOverviewMapJob::path(BuildOverviewMapJob::key($ids, 'it'), 'it');
        Storage::disk('wmfe')->put($path, 'pdf');
        touch(Storage::disk('wmfe')->path($path), time() - 25 * 3600);

        $this->get(route('print.overview-map', ['ids' => $ids, 'lang' => 'it']))
            ->assertOk()->assertSee('aria-busy="true"', false);
        Bus::assertDispatched(BuildOverviewMapJob::class, fn ($j) => $j->ids === $ids);
    });

    it('does not redirect to a pdf older than the track and dispatches', function () {
        // Altra traccia, quindi altra chiave e altro lock di unicità.
        $a = ($this->makeTrack)(9.1, 40.1, 0.01);
        $ids = [$a->id];
        $path = BuildOverviewMapJob::path(BuildOverviewMapJob::key($ids, 'it'), 'it');
        Storage::disk('wmfe')->put($path, 'pdf');
        touch(Storage::disk('wmfe')->path($path), time() - 60);
        DB::table('ec_tracks')->where('id', $a->id)->update(['updated_at' => now()->addMinute()]);

        $this->get(route('print.overview-map', ['ids' => $ids, 'lang' => 'it']))
            ->assertOk()->assertSee('aria-busy="true"', false);
        Bus::assertDispatched(BuildOverviewMapJob::class, fn ($j) => $j->ids === $ids);

        // Controprova: con il file piu' recente della traccia si reindirizza.
        touch(Storage::disk('wmfe')->path($path), time() + 120);
        $this->get(route('print.overview-map', ['ids' => $ids, 'lang' => 'it']))->assertRedirect('https://wmfe.test/'.$path);
    });

    it('does not redispatch after a failure unless retry is requested, and reports statuses', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $ids = [$a->id];
        $query = ['ids' => $ids, 'lang' => 'it'];

        $this->getJson(route('print.overview-map.status', $query))->assertJson(['status' => 'queued']);

        PrintStatus::putOverview(BuildOverviewMapJob::key($ids, 'it'), ['status' => 'failed', 'message' => 'Boom']);
        $this->getJson(route('print.overview-map.status', $query))->assertJson(['status' => 'failed', 'message' => 'Boom']);
        $this->get(route('print.overview-map', $query))->assertOk()->assertSee('Boom');
        Bus::assertNotDispatched(BuildOverviewMapJob::class);

        $this->get(route('print.overview-map', $query + ['retry' => 1]))->assertOk();
        Bus::assertDispatchedTimes(BuildOverviewMapJob::class, 1);
        $this->getJson(route('print.overview-map.status'))->assertStatus(422);
    });
});

describe('job', function () {
    it('is a single short run, unique on ids and language', function () {
        $job = new BuildOverviewMapJob([2, 1], 'it');

        expect($job->maxExceptions)->toBe(1)
            ->and($job->timeout)->toBeLessThan(600)
            ->and($job->uniqueId())->toBe(sha1('testshard|1,2|it'))
            ->and((new BuildOverviewMapJob([1, 2], 'it'))->uniqueId())->toBe($job->uniqueId())
            ->and((new BuildOverviewMapJob([1, 2], 'en'))->uniqueId())->not->toBe($job->uniqueId());
    });

    it('stores the pdf under the shard base path, with a key per shard and the lock on redis', function () {
        $key = BuildOverviewMapJob::key([1, 2], 'it');

        expect(BuildOverviewMapJob::path($key, 'it'))->toStartWith('testshard/print-tmp/overview/')->toEndWith('-it.pdf')
            ->and((new BuildOverviewMapJob([1], 'it'))->uniqueVia())->toBe(Cache::store('redis'));

        config(['wm-package.shard_name' => 'othershard']);
        expect(BuildOverviewMapJob::key([1, 2], 'it'))->not->toBe($key);
    });

    it('renders one feature per track with ref and name, compiles landscape and uploads public', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01, ['name' => ['it' => 'Sentiero & Uno'], 'properties' => ['ref' => 'A_1']]);
        $b = ($this->makeTrack)(9.02, 40.02, 0.01, ['name' => ['it' => 'Due'], 'properties' => []]);

        $request = null;
        $renderer = Mockery::mock(MapRenderer::class);
        $renderer->shouldReceive('render')->once()->andReturnUsing(function (MapRenderRequest $r) use (&$request) {
            $request = $r;

            return 'PNG';
        });
        $tex = null;
        $compiler = Mockery::mock(XelatexCompiler::class);
        $compiler->shouldReceive('compile')->once()->andReturnUsing(function ($source, $files) use (&$tex) {
            $tex = $source;
            expect($files)->toHaveKeys(['wm-print.sty', 'map.png']);

            return '%PDF-overview';
        });

        $job = new BuildOverviewMapJob([$a->id, $b->id], 'it');
        $job->handle(app(PrintService::class), $renderer, $compiler, app(Wm\WmPackage\Services\StorageService::class));

        expect($request->shard)->toBe('testshard')
            ->and($request->widthMm)->toBe(200.0)
            ->and($request->heightMm)->toBe(170.0)
            ->and($request->featureCollection['features'])->toHaveCount(2)
            ->and($request->featureCollection['features'][0]['properties'])->toBe(['id' => $a->id, 'ref' => 'A_1', 'name' => 'Sentiero & Uno'])
            ->and($request->featureCollection['features'][1]['properties']['ref'])->toBe('')
            ->and($request->featureCollection['features'][0]['geometry']['type'])->toBe('MultiLineString');

        expect($tex)->toContain('landscape')
            ->not->toContain('\newpage')
            ->not->toContain('\clearpage')
            ->toContain('\begin{minipage}[t]{200mm}')
            ->toContain('\begin{minipage}[t]{72mm}')
            ->toContain('\raggedright')
            ->toContain('width=200mm,height=170mm')
            ->toContain('\textbf{A\_1}')
            ->toContain('Sentiero \& Uno')
            ->toContain('Mappa d')
            ->not->toMatch('/&(amp|lt|gt|quot|#\d+);/');

        $path = BuildOverviewMapJob::path($job->uniqueId(), 'it');
        Storage::disk('wmfe')->assertExists($path);
        expect(Storage::disk('wmfe')->getVisibility($path))->toBe('public')
            ->and(PrintStatus::getOverview($job->uniqueId()))->toBe(['status' => 'done', 'url' => 'https://wmfe.test/'.$path]);
        expect(glob(storage_path('app/print-tmp/overview-*')))->toBe([]);
    });

    it('fails with the generic message and no file when the renderer throws', function () {
        $a = ($this->makeTrack)(9.0, 40.0, 0.01);
        $renderer = Mockery::mock(MapRenderer::class);
        $renderer->shouldReceive('render')->andThrow(new RuntimeException('/secret/path'));
        $compiler = Mockery::mock(XelatexCompiler::class);
        $compiler->shouldNotReceive('compile');

        $job = new BuildOverviewMapJob([$a->id], 'it');
        expect(fn () => $job->handle(app(PrintService::class), $renderer, $compiler, app(Wm\WmPackage\Services\StorageService::class)))
            ->toThrow(RuntimeException::class);
        $job->failed(new RuntimeException('/secret/path xelatex'));

        $status = PrintStatus::getOverview($job->uniqueId());
        expect($status['status'])->toBe('failed')->and($status['message'])->not->toContain('secret');
        expect(Storage::disk('wmfe')->allFiles())->toBe([]);
    });
});
