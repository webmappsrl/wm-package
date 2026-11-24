<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Commands\PrintCleanupCommand;
use Wm\WmPackage\Jobs\Print\BuildPrintZipJob;
use Wm\WmPackage\Jobs\Print\GeneratePrintPdfJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\PrintStatus;
use Wm\WmPackage\Services\StorageService;

uses(DatabaseTransactions::class);

beforeEach(function () {
    config([
        'wm-package.shard_name' => 'testshard',
        'wm-package.ec_track_model' => EcTrack::class,
        'cache.stores.redis.driver' => 'array',
    ]);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);
    Cache::flush();

    $this->printApp = printApp([
        'available_languages' => json_encode(['it', 'en']),
        'default_language' => 'it',
    ]);
    $this->makeTracks = fn (int $n) => collect(range(1, $n))->map(
        fn () => EcTrack::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null])
    );
});

describe('route', function () {
    beforeEach(fn () => Bus::fake());

    it('registers the named routes with their throttle', function () {
        expect(Route::has('print.zip'))->toBeTrue()
            ->and(Route::has('print.zip.status'))->toBeTrue()
            ->and(Route::getRoutes()->getByName('print.zip')->gatherMiddleware())->toContain('throttle:5,1,print-zip')
            ->and(Route::getRoutes()->getByName('print.zip.status')->gatherMiddleware())->toContain('throttle:60,1,print-status');
    });

    it('answers 422 with a readable page above max_tracks', function () {
        config(['wm-package.print.zip.max_tracks' => 3]);
        $tracks = ($this->makeTracks)(4);

        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all(), 'lang' => 'it']))
            ->assertStatus(422)
            ->assertSee('il massimo è 3');
        Bus::assertNotDispatched(BuildPrintZipJob::class);
    });

    it('accepts exactly max_tracks ids and counts duplicates once', function () {
        config(['wm-package.print.zip.max_tracks' => 2]);
        $tracks = ($this->makeTracks)(2);
        $ids = [$tracks[0]->id, $tracks[0]->id, $tracks[1]->id];

        $this->get(route('print.zip', ['ids' => $ids, 'lang' => 'it']))->assertOk();
    });

    it('answers 422 without ids or with invalid ids', function (array $query) {
        $this->get(route('print.zip', $query))->assertStatus(422);
        Bus::assertNotDispatched(BuildPrintZipJob::class);
    })->with([[[]], [['ids' => []]], [['ids' => ['abc']]], [['ids' => 'x']]]);

    it('answers 404 when no track exists or the language is not common to the apps', function () {
        $this->get(route('print.zip', ['ids' => [999999999]]))->assertNotFound();

        $tracks = ($this->makeTracks)(1);
        $other = printApp(['available_languages' => json_encode(['it']), 'default_language' => 'it']);
        $tracks->push(EcTrack::factory()->createQuietly(['app_id' => $other->id, 'osmid' => null]));

        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all(), 'lang' => 'en']))->assertNotFound();
        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all(), 'lang' => '../x']))->assertNotFound();
        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all(), 'lang' => 'it']))->assertOk();
        Bus::assertDispatchedTimes(BuildPrintZipJob::class, 1);
    });

    it('leaves out the tracks of an app with print turned off', function () {
        $tracks = ($this->makeTracks)(1);
        $off = App::factory()->createQuietly(['available_languages' => json_encode(['it']), 'default_language' => 'it']);
        $hidden = EcTrack::factory()->createQuietly(['app_id' => $off->id, 'osmid' => null]);

        $this->get(route('print.zip', ['ids' => [$hidden->id]]))->assertNotFound();

        $this->get(route('print.zip', ['ids' => [$tracks->first()->id, $hidden->id], 'lang' => 'it']))->assertOk();
        Bus::assertDispatched(BuildPrintZipJob::class, fn ($job) => $job->ids === [(int) $tracks->first()->id]);
    });

    it('uses a shared language as default when the first app default is not shared', function () {
        $tracks = ($this->makeTracks)(1);
        $other = printApp(['available_languages' => json_encode(['en']), 'default_language' => 'en']);
        $tracks->push(EcTrack::factory()->createQuietly(['app_id' => $other->id, 'osmid' => null]));
        $this->printApp->update(['available_languages' => json_encode(['it', 'en']), 'default_language' => 'it']);

        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all()]))->assertOk();
        Bus::assertDispatched(BuildPrintZipJob::class, fn ($job) => $job->locale === 'en');

        $none = printApp(['available_languages' => json_encode(['de']), 'default_language' => 'de']);
        $tracks->push(EcTrack::factory()->createQuietly(['app_id' => $none->id, 'osmid' => null]));
        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all()]))->assertNotFound();
    });

    it('ignores missing ids when at least one track exists', function () {
        $tracks = ($this->makeTracks)(1);

        $this->get(route('print.zip', ['ids' => [$tracks[0]->id, 999999999], 'lang' => 'it']))->assertOk();

        Bus::assertDispatched(BuildPrintZipJob::class, fn ($job) => $job->ids === [$tracks[0]->id]);
    });

    it('shows the waiting page and dispatches one job for the same selection in any order', function () {
        $tracks = ($this->makeTracks)(2);
        $ids = $tracks->pluck('id')->all();

        $this->get(route('print.zip', ['ids' => $ids, 'lang' => 'en']))
            ->assertOk()
            ->assertSee('aria-busy="true"', false)
            ->assertSee('<noscript><meta http-equiv="refresh" content="15"></noscript>', false)
            ->assertSee('Preparing your PDF')
            // Lo ZIP si scarica: la pagina avvia il download, dice che si può chiudere e prova a chiudersi.
            ->assertSee('var download = true;', false)
            ->assertSee('Download again')
            ->assertSee('print\\/zip\\/status', false)
            ->assertSee('> 1920 * 1000', false);
        Bus::assertDispatched(BuildPrintZipJob::class, fn ($job) => $job->locale === 'en' && $job->ids === $ids);

        expect(BuildPrintZipJob::key(array_reverse($ids), 'en'))->toBe(BuildPrintZipJob::key($ids, 'en'))
            ->and(BuildPrintZipJob::key($ids, 'it'))->not->toBe(BuildPrintZipJob::key($ids, 'en'));
        expect((new BuildPrintZipJob($ids, 'en'))->uniqueId())->toBe((new BuildPrintZipJob(array_reverse($ids), 'en'))->uniqueId());
    });

    it('builds the zip of pois with type=ec-poi, with a key of its own', function () {
        config(['wm-package.ec_poi_model' => EcPoi::class]);
        $pois = collect(range(1, 2))->map(fn () => EcPoi::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null, 'properties' => []]));
        $ids = $pois->pluck('id')->sort()->values()->all();

        $this->get(route('print.zip', ['ids' => $ids, 'lang' => 'it', 'type' => 'ec-poi']))
            ->assertOk()
            ->assertSee('type=ec-poi', false);
        Bus::assertDispatched(BuildPrintZipJob::class, fn ($job) => $job->type === 'ec-poi' && $job->ids === $ids);

        // Le tracce tengono la chiave di prima; i POI con gli stessi id hanno la loro.
        expect(BuildPrintZipJob::key($ids, 'it', 'ec-track'))->toBe(BuildPrintZipJob::key($ids, 'it'))
            ->and(BuildPrintZipJob::key($ids, 'it', 'ec-poi'))->not->toBe(BuildPrintZipJob::key($ids, 'it'))
            ->and((new BuildPrintZipJob($ids, 'it', 'ec-poi'))->uniqueId())->toBe(BuildPrintZipJob::key($ids, 'it', 'ec-poi'));

        // Gli id di POI chiesti come tracce non esistono come tracce: 404, niente job.
        $this->get(route('print.zip', ['ids' => [999999999], 'lang' => 'it', 'type' => 'ec-poi']))->assertNotFound();
    });

    it('answers 404 for an unknown type', function () {
        $tracks = ($this->makeTracks)(1);

        $this->get(route('print.zip', ['ids' => $tracks->pluck('id')->all(), 'lang' => 'it', 'type' => 'layer']))->assertNotFound();
        Bus::assertNotDispatched(BuildPrintZipJob::class);
    });

    it('does not redispatch after a failure unless retry is requested', function () {
        $tracks = ($this->makeTracks)(1);
        $ids = $tracks->pluck('id')->all();
        PrintStatus::putZip(BuildPrintZipJob::key($ids, 'it'), ['status' => 'failed', 'message' => 'Boom']);

        $this->get(route('print.zip', ['ids' => $ids, 'lang' => 'it']))->assertOk()->assertSee('Boom');
        Bus::assertNotDispatched(BuildPrintZipJob::class);

        $this->get(route('print.zip', ['ids' => $ids, 'lang' => 'it', 'retry' => 1]))->assertOk();
        Bus::assertDispatchedTimes(BuildPrintZipJob::class, 1);
    });

    it('redirects to the zip when it is fresh and reports done on status', function () {
        $tracks = ($this->makeTracks)(2);
        $ids = $tracks->pluck('id')->all();
        $path = BuildPrintZipJob::path(BuildPrintZipJob::key($ids, 'it'), 'it');
        Storage::disk('wmfe')->put($path, 'zip');

        $this->get(route('print.zip', ['ids' => $ids, 'lang' => 'it']))
            ->assertRedirect('https://wmfe.test/'.$path);
        $this->getJson(route('print.zip.status', ['ids' => $ids, 'lang' => 'it']))
            ->assertOk()->assertJson(['status' => 'done', 'url' => 'https://wmfe.test/'.$path]);
        Bus::assertNotDispatched(BuildPrintZipJob::class);
    });

    it('reports queued, then the cached status', function () {
        $tracks = ($this->makeTracks)(1);
        $ids = $tracks->pluck('id')->all();
        $query = ['ids' => $ids, 'lang' => 'it'];

        $this->getJson(route('print.zip.status', $query))->assertJson(['status' => 'queued']);

        PrintStatus::putZip(BuildPrintZipJob::key($ids, 'it'), ['status' => 'failed', 'message' => 'x']);
        $this->getJson(route('print.zip.status', $query))->assertJson(['status' => 'failed', 'message' => 'x']);
        $this->getJson(route('print.zip.status'))->assertStatus(422);
    });
});

describe('job', function () {
    /** Job con attempts() e release() controllabili (fuori dalla coda non c'è un job sottostante). */
    function zipJob(array $ids, int $attempts = 1): BuildPrintZipJob
    {
        return new class($ids, 'it', $attempts) extends BuildPrintZipJob
        {
            public array $released = [];

            public function __construct(array $ids, string $locale, private int $fakeAttempts)
            {
                parent::__construct($ids, $locale);
            }

            public function attempts(): int
            {
                return $this->fakeAttempts;
            }

            public function release($delay = 0)
            {
                $this->released[] = $delay;
            }
        };
    }

    function zipMock(array $fresh, array $streams = []): PrintService
    {
        $service = Mockery::mock(PrintService::class);
        $service->shouldReceive('isFresh')->andReturnUsing(fn ($m) => in_array($m->id, $fresh, true));
        $service->shouldReceive('stream')->andReturnUsing(function () {
            $h = fopen('php://temp', 'w+');
            fwrite($h, '%PDF-body');
            rewind($h);

            return $h;
        });

        return $service;
    }

    it('dispatches only the missing sheets, releases, then assembles from streamed pdfs', function () {
        Bus::fake();
        [$fresh, $missing] = ($this->makeTracks)(2)->all();
        $storage = app(StorageService::class);

        $job = zipJob([$fresh->id, $missing->id]);
        $job->handle(zipMock([$fresh->id]), $storage);
        expect($job->released)->toBe([15]);

        Bus::assertDispatchedTimes(GeneratePrintPdfJob::class, 1);
        Bus::assertDispatched(GeneratePrintPdfJob::class, fn ($j) => $j->model->is($missing));
        Storage::disk('wmfe')->assertMissing(BuildPrintZipJob::path($job->uniqueId(), 'it'));

        // Tutte fresche: assembla.
        $job->handle(zipMock([$fresh->id, $missing->id]), $storage);

        $path = BuildPrintZipJob::path($job->uniqueId(), 'it');
        Storage::disk('wmfe')->assertExists($path);
        expect(Storage::disk('wmfe')->getVisibility($path))->toBe('public')
            ->and(PrintStatus::getZip($job->uniqueId()))->toBe(['status' => 'done', 'url' => 'https://wmfe.test/'.$path]);

        $local = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($local, Storage::disk('wmfe')->get($path));
        $zip = new ZipArchive;
        $zip->open($local);
        expect($zip->numFiles)->toBe(2);
        for ($i = 0; $i < 2; $i++) {
            expect($zip->getNameIndex($i))->toMatch('/^[a-z0-9-]+-\d+\.pdf$/')
                ->and($zip->getFromIndex($i))->toBe('%PDF-body');
        }
        $zip->close();
        unlink($local);
        expect(glob(storage_path('app/print-tmp/zip-*')))->toBe([]);
    });

    it('has short runs and a unique lock covering the whole wait', function () {
        config(['wm-package.print.zip.max_wait_seconds' => 1800]);
        $job = new BuildPrintZipJob([1], 'it');

        expect($job->timeout)->toBeLessThan(660)
            ->and($job->maxExceptions)->toBe(1)
            ->and($job->tries)->toBe(121)
            ->and($job->uniqueFor)->toBeGreaterThanOrEqual(1800);
    });

    it('re-dispatches a sheet whose status is done but no longer fresh, and leaves queued ones alone', function () {
        Bus::fake();
        [$stale, $queued] = ($this->makeTracks)(2)->all();
        PrintStatus::markDone($stale, 'it', 'https://x');
        PrintStatus::markQueued($queued, 'it');

        $job = zipJob([$stale->id, $queued->id], 2);
        $job->handle(zipMock([]), app(StorageService::class));

        expect($job->released)->toBe([15]);
        Bus::assertDispatchedTimes(GeneratePrintPdfJob::class, 1);
        Bus::assertDispatched(GeneratePrintPdfJob::class, fn ($j) => $j->model->is($stale));
    });

    it('fails the whole zip when a sheet generation failed, with the generic message', function () {
        Bus::fake();
        $tracks = ($this->makeTracks)(2);
        $storage = app(StorageService::class);
        PrintStatus::markFailed($tracks[0], 'it', 'x');

        $job = zipJob($tracks->pluck('id')->all(), 2);

        expect(fn () => $job->handle(zipMock([]), $storage))->toThrow(RuntimeException::class);
        expect($job->released)->toBe([]);
        $job->failed(new RuntimeException('/secret/path xelatex'));

        $status = PrintStatus::getZip($job->uniqueId());
        expect($status['status'])->toBe('failed')->and($status['message'])->not->toContain('secret');
        expect(Storage::disk('wmfe')->allFiles())->toBe([]);
    });

    it('stores the zip under the shard base path with a key that includes the shard', function () {
        $path = BuildPrintZipJob::path(BuildPrintZipJob::key([1, 2], 'it'), 'it');
        $base = ltrim(app(StorageService::class)->getShardBasePath(), '/');

        expect($base)->toBe('testshard/')
            ->and($path)->toStartWith('testshard/print-tmp/zip/')->toEndWith('-it.zip');

        $mine = BuildPrintZipJob::key([1, 2], 'it');
        config(['wm-package.shard_name' => 'othershard']);
        expect(BuildPrintZipJob::key([1, 2], 'it'))->not->toBe($mine)
            ->and(BuildPrintZipJob::path($mine, 'it'))->toStartWith('othershard/print-tmp/zip/');
    });

    it('takes the unique lock on the redis store', function () {
        expect((new BuildPrintZipJob([1], 'it'))->uniqueVia())->toBe(Cache::store('redis'));
    });

    it('names entries with an ascii slug of the name plus id', function () {
        $track = EcTrack::factory()->createQuietly(['app_id' => $this->printApp->id, 'osmid' => null, 'name' => ['it' => 'Sentiero àèì — «Più» ★']]);

        expect(BuildPrintZipJob::entryName($track, 'it'))->toBe('sentiero-aei-piu-'.$track->id.'.pdf');
        $track->setTranslation('name', 'it', '★★★')->saveQuietly();
        expect(BuildPrintZipJob::entryName($track->fresh(), 'it'))->toBe('track-'.$track->id.'.pdf');
    });
});

describe('cleanup command', function () {
    it('removes only this shard\'s print files older than 24 hours', function () {
        $disk = Storage::disk('wmfe');
        $mine = ['testshard/print-tmp/zip/old.zip', 'testshard/print-tmp/overview/old.pdf'];
        $kept = [
            'testshard/print-tmp/zip/new.zip',
            // stesso bucket, altro shard: la pulizia di testshard non lo tocca
            'othershard/print-tmp/zip/old.zip',
            'print-tmp/zip/old.zip',
            'testshard/other/old.zip',
        ];
        foreach ([...$mine, ...$kept] as $f) {
            $disk->put($f, 'x');
        }
        $old = time() - 25 * 3600;
        foreach ([...$mine, 'othershard/print-tmp/zip/old.zip', 'print-tmp/zip/old.zip', 'testshard/other/old.zip'] as $f) {
            touch($disk->path($f), $old);
        }

        // Registrato qui: senza il dominio acceso il package non lo registra, e `Application::starting()`
        // non scatta se la console è già partita (suite del package, Testbench).
        Artisan::registerCommand(app(PrintCleanupCommand::class));
        $this->artisan('wm:print-cleanup')->assertSuccessful();

        foreach ($mine as $f) {
            $disk->assertMissing($f);
        }
        foreach ($kept as $f) {
            $disk->assertExists($f);
        }
    });

    it('removes every orphaned local work directory older than 24 hours', function () {
        $old = time() - 25 * 3600;
        // Cartelle di ZIP, mappa d'insieme, foto della scheda e del compilatore (uuid senza prefisso).
        $stale = array_map(fn ($n) => storage_path('app/print-tmp/'.$n), ['zip-stale-test', 'overview-stale-test', 'assets-stale-test', '0f8e2a6c-stale-test']);
        $recent = storage_path('app/print-tmp/assets-recent-test');
        foreach ([...$stale, $recent] as $dir) {
            File::ensureDirectoryExists($dir);
            file_put_contents($dir.'/f.tex', 'x');
        }
        foreach ($stale as $dir) {
            touch($dir, $old);
        }

        // Registrato qui: senza il dominio acceso il package non lo registra, e `Application::starting()`
        // non scatta se la console è già partita (suite del package, Testbench).
        Artisan::registerCommand(app(PrintCleanupCommand::class));
        $this->artisan('wm:print-cleanup')->assertSuccessful();

        foreach ($stale as $dir) {
            expect(is_dir($dir))->toBeFalse();
        }
        expect(is_dir($recent))->toBeTrue();
        File::deleteDirectory($recent);
    });

    it('is registered on every shard', function () {
        expect(Artisan::all()['wm:print-cleanup'] ?? null)->toBeInstanceOf(PrintCleanupCommand::class);
    });
});
