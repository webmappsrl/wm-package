<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Wm\WmPackage\Exceptions\PrintCompilationException;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\XelatexCompiler;

uses(DatabaseTransactions::class);

beforeEach(function () {
    // Le view del package, non gli override dello shard in cui il package è montato
    // (resources/views/vendor/wm-package/print): qui si prova il template di default.
    app('view')->getFinder()->replaceNamespace('wm-package', [realpath(__DIR__.'/../../../resources/views')]);
    config(['wm-package.shard_name' => 'testshard']);
    config(['cache.stores.redis.driver' => 'array']);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);
    // Nessuna chiamata HTTP reale: ogni test registra le risposte che gli servono (un Http::fake()
    // senza argomenti qui risponderebbe 200 vuoto a tutto, prima degli stub dei singoli test).
    Http::preventStrayRequests();

    // Cattura di sorgente e allegati passati al compilatore, ispezionati durante la chiamata
    // perché la cartella degli allegati viene cancellata subito dopo.
    $this->compiled = null;
    $this->mock(XelatexCompiler::class, function ($mock) {
        $mock->shouldReceive('compile')->andReturnUsing(function (string $tex, array $files) {
            $info = [];
            foreach ($files as $name => $path) {
                $info[$name] = is_file($path) ? @getimagesize($path) : false;
            }
            $this->compiled = ['tex' => $tex, 'files' => $files, 'info' => $info];

            return '%PDF-fake';
        });
    });

    $this->printApp = App::factory()->createQuietly([
        'overlays_label' => 'Layers',
        'properties' => ['theme' => ['primary_color' => '#1A2B3C']],
    ]);
});

function printTrack(array $attributes = []): EcTrack
{
    return printSavedTrack(test()->printApp, array_merge([
        'name' => ['it' => 'Sentiero del Gennargentu'],
        'properties' => [
            'description' => ['it' => '<p>Descrizione <strong>italiana</strong></p>'],
            'distance' => 12.5,
            'ascent' => 800,
            'descent' => 750,
            'duration_forward' => 245,
        ],
    ], $attributes));
}

function printPhoto(EcTrack|EcPoi $model, string $fileName, string $mime, int $order): Media
{
    return Media::factory()->createQuietly([
        'model_type' => $model->getMorphClass(),
        'model_id' => $model->id,
        'collection_name' => 'default',
        'file_name' => $fileName,
        'mime_type' => $mime,
        'disk' => 'wmfe',
        'conversions_disk' => 'wmfe',
        'order_column' => $order,
        'app_id' => $model->app_id,
        // la colonna media.geometry è 3D sugli shard (la factory produce un punto 2D)
        'geometry' => DB::raw("ST_GeomFromText('POINT Z(9 40 0)', 4326)"),
    ]);
}

it('generate stores files/ec-track/{id}/print-it.pdf and returns its url', function () {
    $track = printTrack();

    $url = app(PrintService::class)->generate($track, 'it');

    $path = "/testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf";
    Storage::disk('wmfe')->assertExists($path);
    expect(Storage::disk('wmfe')->get($path))->toBe('%PDF-fake')
        ->and($url)->toContain("files/ec-track/{$track->id}/print-it.pdf")
        ->and($this->compiled['tex'])->toContain('Sentiero del Gennargentu')
        ->and($this->compiled['tex'])->toContain('\definecolor{wmprimary}{HTML}{1A2B3C}')
        ->and($this->compiled['tex'])->toContain('\textbf{italiana}')
        ->and($this->compiled['tex'])->toContain('12,5 km')
        ->and($this->compiled['files'])->toHaveKeys(['wm-print.sty', 'map.png']);

    // nessun file temporaneo lasciato nella cartella del modello
    expect(Storage::disk('wmfe')->files("/testshard/{$track->app_id}/files/ec-track/{$track->id}"))->toHaveCount(1);
});

it('generate stores the poi sheet under files/ec-poi', function () {
    $poi = EcPoi::factory()->createQuietly([
        'app_id' => $this->printApp->id,
        'name' => ['it' => 'Fontana di Sa Mela'],
        'properties' => [],
    ]);

    app(PrintService::class)->generate($poi, 'it');

    Storage::disk('wmfe')->assertExists("/testshard/{$poi->app_id}/files/ec-poi/{$poi->id}/print-it.pdf");
    expect($this->compiled['tex'])->toContain('Fontana di Sa Mela');
});

it('converts webp photos to jpeg resized to 2000 px and skips a photo answering 404', function () {
    $track = printTrack();
    $webp = (string) (new ImageManager(['driver' => 'gd']))->canvas(3000, 1500, '#ff0000')->encode('webp', 80);
    $jpeg = (string) (new ImageManager(['driver' => 'gd']))->canvas(800, 600, '#00ff00')->encode('jpg', 80);

    $first = printPhoto($track, 'big.webp', 'image/webp', 1);
    $missing = printPhoto($track, 'missing.jpg', 'image/jpeg', 2);
    $small = printPhoto($track, 'small.jpg', 'image/jpeg', 3);

    Http::fake([
        '*/big.webp' => Http::response($webp, 200, ['Content-Type' => 'image/webp']),
        '*/missing.jpg' => Http::response('not found', 404),
        '*/small.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    app(PrintService::class)->generate($track, 'it');

    $info = $this->compiled['info'];
    expect(array_keys($this->compiled['files']))->toContain('photo-1.jpg', 'photo-2.jpg')
        ->not->toContain('photo-3.jpg');
    expect($info['photo-1.jpg'][0])->toBe(2000)
        ->and($info['photo-1.jpg'][1])->toBe(1000)
        ->and($info['photo-1.jpg']['mime'])->toBe('image/jpeg')
        ->and($info['photo-2.jpg'][0])->toBe(800)
        ->and($info['photo-2.jpg']['mime'])->toBe('image/jpeg');
    expect($this->compiled['tex'])->toContain('{photo-1.jpg}')->toContain('{photo-2.jpg}');
});

it('uses the fallback text when a translated field is missing in the requested locale', function () {
    config(['app.fallback_locale' => 'en']);
    $track = printTrack();

    app(PrintService::class)->generate($track, 'de');

    expect($this->compiled['tex'])->toContain('Sentiero del Gennargentu')
        ->toContain('\textbf{italiana}')
        ->toContain('Länge');
    Storage::disk('wmfe')->assertExists("/testshard/{$track->app_id}/files/ec-track/{$track->id}/print-de.pdf");
});

it('does not overwrite the existing pdf and rethrows when the map service fails', function () {
    $track = printTrack();
    $path = "/testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf";
    Storage::disk('wmfe')->put($path, 'old pdf');

    $this->mock(MapRenderer::class, function ($mock) {
        $mock->shouldReceive('render')->andThrow(new PrintCompilationException('Servizio mappe giù'));
    });

    expect(fn () => app(PrintService::class)->generate($track, 'it'))
        ->toThrow(PrintCompilationException::class, 'Servizio mappe giù');
    expect(Storage::disk('wmfe')->get($path))->toBe('old pdf');
});

it('isFresh compares the file lastModified with updated_at', function () {
    $track = printTrack();
    $service = app(PrintService::class);

    expect($service->isFresh($track, 'it'))->toBeFalse();

    Storage::disk('wmfe')->put("/testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf", '%PDF');

    $track->updated_at = now()->subDay();
    expect($service->isFresh($track, 'it'))->toBeTrue();

    $track->updated_at = now()->addDay();
    expect($service->isFresh($track, 'it'))->toBeFalse();
});

it('existingLocales lists the languages of the print-*.pdf files', function () {
    $track = printTrack();
    $dir = "/testshard/{$track->app_id}/files/ec-track/{$track->id}";
    Storage::disk('wmfe')->put("{$dir}/print-it.pdf", '%PDF');
    Storage::disk('wmfe')->put("{$dir}/print-en.pdf", '%PDF');
    Storage::disk('wmfe')->put("{$dir}/accessibility.pdf", '%PDF');
    Storage::disk('wmfe')->put("{$dir}/print-fr.pdf.tmp-abc", '%PDF');
    Storage::disk('wmfe')->put("{$dir}/print-pt-br.pdf", '%PDF');
    Storage::disk('wmfe')->put("{$dir}/print-EN_x.pdf", '%PDF');

    expect(app(PrintService::class)->existingLocales($track))->toBe(['en', 'it', 'pt-br']);
});

it('rejects a locale that is not a language code before touching storage or the compiler', function (string $locale) {
    $track = printTrack();

    expect(fn () => app(PrintService::class)->generate($track, $locale))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $track->printFileName($locale))->toThrow(InvalidArgumentException::class)
        ->and(fn () => app(PrintService::class)->isFresh($track, $locale))->toThrow(InvalidArgumentException::class);
    expect($this->compiled)->toBeNull()
        ->and(Storage::disk('wmfe')->allFiles())->toBe([]);
})->with(['../x', 'it/../../y', 'IT', 'it_IT', '', 'it.pdf', "it\n"]);

it('accepts plain and regional language codes', function () {
    expect(printTrack()->printFileName('pt-br'))->toBe('print-pt-br');
});

it('skips a photo whose declared size or body exceeds the byte cap', function () {
    config(['wm-package.features.print.photos.max_bytes' => 1000]);
    $track = printTrack();
    $jpeg = (string) (new ImageManager(['driver' => 'gd']))->canvas(800, 600, '#00ff00')->encode('jpg', 90);
    expect(strlen($jpeg))->toBeGreaterThan(1000);

    printPhoto($track, 'declared.jpg', 'image/jpeg', 1);
    printPhoto($track, 'chunked.jpg', 'image/jpeg', 2);
    Http::fake([
        '*/declared.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg', 'Content-Length' => (string) strlen($jpeg)]),
        // nessun Content-Length: il limite scatta leggendo il corpo
        '*/chunked.jpg' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
    ]);
    Log::shouldReceive('channel')->with('print')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('warning')->twice()->withArgs(fn ($message) => str_contains($message, 'limite di 1000'));

    app(PrintService::class)->generate($track, 'it');

    expect($this->compiled['files'])->not->toHaveKey('photo-1.jpg')
        ->and($this->compiled['tex'])->not->toContain('photo-');
    Storage::disk('wmfe')->assertExists("/testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf");
});

it('skips a photo over the pixel cap without decoding it', function () {
    config(['wm-package.features.print.photos.max_pixels' => 100 * 100]);
    $track = printTrack();
    $big = (string) (new ImageManager(['driver' => 'gd']))->canvas(200, 200, '#00ff00')->encode('jpg', 80);
    $small = (string) (new ImageManager(['driver' => 'gd']))->canvas(50, 50, '#0000ff')->encode('jpg', 80);
    printPhoto($track, 'big.jpg', 'image/jpeg', 1);
    printPhoto($track, 'small.jpg', 'image/jpeg', 2);
    Http::fake([
        '*/big.jpg' => Http::response($big, 200),
        '*/small.jpg' => Http::response($small, 200),
    ]);

    app(PrintService::class)->generate($track, 'it');

    expect(array_keys($this->compiled['files']))->toContain('photo-1.jpg')->not->toContain('photo-2.jpg')
        ->and($this->compiled['info']['photo-1.jpg'][0])->toBe(50);
});

it('applies the EXIF orientation of the downloaded photo', function () {
    $track = printTrack();
    // JPEG 40x20 con EXIF Orientation = 6 (ruotare di 90°): dopo orientate() diventa 20x40
    $jpeg = printJpegWithOrientation(40, 20, 6);
    printPhoto($track, 'rotated.jpg', 'image/jpeg', 1);
    Http::fake(['*/rotated.jpg' => Http::response($jpeg, 200)]);

    app(PrintService::class)->generate($track, 'it');

    expect($this->compiled['info']['photo-1.jpg'][0])->toBe(20)
        ->and($this->compiled['info']['photo-1.jpg'][1])->toBe(40);
})->skip(! function_exists('exif_read_data'), 'estensione exif assente');

/**
 * JPEG con un segmento APP1 EXIF minimo che contiene solo il tag Orientation.
 */
function printJpegWithOrientation(int $width, int $height, int $orientation): string
{
    $jpeg = (string) (new ImageManager(['driver' => 'gd']))->canvas($width, $height, '#ff0000')->encode('jpg', 90);
    // TIFF little-endian: header, 1 entry (0x0112 SHORT 1 = orientation), next IFD 0
    $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1)
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
        .pack('V', 0);
    $payload = "Exif\0\0".$tiff;
    $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

    // dopo SOI (FFD8)
    return substr($jpeg, 0, 2).$app1.substr($jpeg, 2);
}

it('stops downloading photos when the total photo budget is spent, with a warning', function () {
    config(['wm-package.features.print.photos.total_timeout' => 10]);
    $track = printTrack();
    $jpeg = (string) (new ImageManager(['driver' => 'gd']))->canvas(80, 60, '#00ff00')->encode('jpg', 80);
    printPhoto($track, 'slow.jpg', 'image/jpeg', 1);
    printPhoto($track, 'late.jpg', 'image/jpeg', 2);

    $timeouts = [];
    Http::fake(function ($request, array $options) use ($jpeg, &$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;
        // La prima foto «dura» 11 s: supera la propria scadenza (si salta) e il budget di 10 s si
        // esaurisce, quindi la seconda non si chiede.
        Carbon::setTestNow(now()->addSeconds(11));

        return Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']);
    });
    Log::shouldReceive('channel')->with('print')->andReturnSelf();
    Log::shouldReceive('info');
    Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'oltre la scadenza'));
    Log::shouldReceive('warning')->once()->withArgs(fn ($message) => str_contains($message, 'Budget di 10 s'));

    try {
        app(PrintService::class)->generate($track, 'it');
    } finally {
        Carbon::setTestNow();
    }

    // Il timeout della richiesta non supera il budget residuo; nessuna foto entra nella scheda.
    expect($timeouts)->toBe([10])
        ->and(array_keys($this->compiled['files']))->not->toContain('photo-1.jpg');
    Storage::disk('wmfe')->assertExists("/testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf");
});

it('attaches only the package style wm-print, included once', function () {
    expect(app(PrintService::class)->styleFiles())->toHaveCount(1)
        ->and(app(PrintService::class)->styleFiles()['wm-print'])->toEndWith('resources/print/wm-print.sty');

    app(PrintService::class)->generate(printTrack(), 'it');

    $styles = array_values(array_filter(array_keys($this->compiled['files']), fn ($name) => str_ends_with($name, '.sty')));
    expect($styles)->toBe(['wm-print.sty'])
        ->and(substr_count($this->compiled['tex'], '\usepackage{wm-print}'))->toBe(1);
});
