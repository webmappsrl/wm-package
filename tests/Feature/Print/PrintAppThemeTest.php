<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Print\Contracts\MapRenderer;
use Wm\WmPackage\Services\Print\MapRenderRequest;
use Wm\WmPackage\Services\Print\PrintService;
use Wm\WmPackage\Services\Print\XelatexCompiler;

uses(DatabaseTransactions::class);

beforeEach(function () {
    app('view')->getFinder()->replaceNamespace('wm-package', [realpath(__DIR__.'/../../../resources/views')]);
    config(['wm-package.shard_name' => 'testshard', 'cache.stores.redis.driver' => 'array']);
    Storage::fake('wmfe', ['url' => 'https://wmfe.test']);
    Http::preventStrayRequests();
});

function themeApp(array $theme): App
{
    return App::factory()->createQuietly(['properties' => ['theme' => $theme]]);
}

function themeTrack(App $app): EcTrack
{
    return EcTrack::factory()->createQuietly([
        'app_id' => $app->id, 'osmid' => null,
        'name' => ['it' => 'Sentiero del tema'], 'properties' => ['distance' => 5],
    ]);
}

function themeCaptureTex(): void
{
    test()->compiled = null;
    test()->mock(XelatexCompiler::class, function ($mock) {
        $mock->shouldReceive('compile')->andReturnUsing(function (string $tex, array $files) {
            test()->compiled = ['tex' => $tex, 'files' => array_keys($files)];

            return '%PDF-fake';
        });
    });
}

it('defines the roles from the app theme colors', function () {
    themeCaptureTex();
    $app = themeApp(['primary_color' => '#1A2B3C', 'secondary_color' => '#AABBCC', 'tertiary_color' => '#DDEEFF']);

    app(PrintService::class)->generate(themeTrack($app), 'it');

    expect($this->compiled['tex'])
        ->toContain('\definecolor{wmprimary}{HTML}{1A2B3C}')
        ->toContain('\definecolor{wmaccent}{HTML}{AABBCC}')
        ->toContain('\definecolor{wmrule}{HTML}{DDEEFF}');
    // I ruoli si definiscono prima di \WmDefineRoles, che dà il ripiego solo ai ruoli ancora indefiniti.
    expect(strpos($this->compiled['tex'], '{wmrule}{HTML}'))->toBeLessThan(strpos($this->compiled['tex'], '\WmDefineRoles'));
});

it('keeps today defaults when secondary and tertiary colors are empty or invalid', function () {
    themeCaptureTex();
    $app = themeApp(['primary_color' => '#1A2B3C', 'secondary_color' => '', 'tertiary_color' => 'red}\input{x']);

    app(PrintService::class)->generate(themeTrack($app), 'it');

    expect($this->compiled['tex'])->not->toContain('{wmaccent}{HTML}')->not->toContain('{wmrule}{HTML}')
        ->not->toContain('\input{x');
});

it('guards the theme fonts with IfFontExistsTF', function () {
    themeCaptureTex();
    $app = themeApp(['font_family_content' => 'Work Sans', 'font_family_header' => 'Playfair Display-Bold']);

    app(PrintService::class)->generate(themeTrack($app), 'it');

    expect($this->compiled['tex'])
        ->toContain('\IfFontExistsTF{Work Sans}{\setmainfont{Work Sans}\setsansfont{Work Sans}}{}')
        ->toContain('\IfFontExistsTF{Playfair Display-Bold}{');
});

it('ignores font names that are not letters, digits, spaces or hyphens', function () {
    themeCaptureTex();
    $app = themeApp(['font_family_content' => 'Evil}\input{/etc/passwd}', 'font_family_header' => "A\nB"]);

    app(PrintService::class)->generate(themeTrack($app), 'it');

    expect($this->compiled['tex'])->not->toContain('IfFontExistsTF{Evil')->not->toContain('passwd')
        ->not->toContain('IfFontExistsTF{A');
});

it('asks the renderer for the configured map size, 174x98 by default, and passes it to the view', function () {
    themeCaptureTex();
    $sizes = [];
    $this->mock(MapRenderer::class, function ($mock) use (&$sizes) {
        $mock->shouldReceive('render')->andReturnUsing(function (MapRenderRequest $r) use (&$sizes) {
            $sizes[] = [$r->widthMm, $r->heightMm];

            return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        });
    });
    $track = themeTrack(themeApp([]));
    config(['wm-package.features.print.map.width_mm' => 174, 'wm-package.features.print.map.height_mm' => 98]);

    app(PrintService::class)->generate($track, 'it');
    expect($sizes[0])->toBe([174.0, 98.0])->and($this->compiled['tex'])->toContain('height=98mm');

    config(['wm-package.features.print.map.width_mm' => 180, 'wm-package.features.print.map.height_mm' => 240]);
    app(PrintService::class)->generate($track, 'it');
    expect($sizes[1])->toBe([180.0, 240.0])->and($this->compiled['tex'])->toContain('height=240mm');
});

it('compiles a real pdf with a missing font, using the theme rule color', function () {
    config(['wm-package.features.print.deps_version' => null, 'wm-package.features.print.map_service.driver' => 'fake']);
    $app = themeApp(['primary_color' => '#1565C0', 'tertiary_color' => '#FF0000',
        'font_family_content' => 'Font Inesistente XYZ', 'font_family_header' => 'Altro Font Mancante']);
    $track = themeTrack($app);

    app(PrintService::class)->generate($track, 'it');
    $pdf = Storage::disk('wmfe')->get("testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf");
    $content = $pdf;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $s) {
            $content .= (string) @gzuncompress($s);
        }
    }

    expect(substr($pdf, 0, 4))->toBe('%PDF')
        ->and(preg_match('/\b1 0 0 rg\b/', $content))->toBe(1);
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('compiles a real pdf with an installed header font', function () {
    config(['wm-package.features.print.deps_version' => null, 'wm-package.features.print.map_service.driver' => 'fake']);
    $app = themeApp(['font_family_content' => 'Latin Modern Roman', 'font_family_header' => 'Latin Modern Roman']);
    $track = themeTrack($app);

    app(PrintService::class)->generate($track, 'it');
    $pdf = Storage::disk('wmfe')->get("testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf");

    expect(substr($pdf, 0, 4))->toBe('%PDF');
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('puts the header font in the section titles too and in the document', function () {
    themeCaptureTex();
    app(PrintService::class)->generate(themeTrack(themeApp(['font_family_header' => 'Latin Modern Roman'])), 'it');

    expect($this->compiled['tex'])->toContain('\newfontfamily\WmHeaderFontFamily{Latin Modern Roman}')
        ->toContain('\renewcommand{\WmHeaderFont}{\WmHeaderFontFamily}');
    $sty = file_get_contents(__DIR__.'/../../../resources/print/wm-print.sty');
    expect($sty)->toMatch('/\\\\newcommand\{\\\\TitoloScheda\}\[1\]\{\{[^\n]*\\\\WmHeaderFont/')
        ->toMatch('/\\\\newcommand\{\\\\TitoloSezione\}\[1\]\{[^\n]*\\\\WmHeaderFont/');
});

it('compiles with the header font applied to the titles', function () {
    config(['wm-package.features.print.deps_version' => null, 'wm-package.features.print.map_service.driver' => 'fake']);
    $track = themeTrack(themeApp(['font_family_header' => 'Latin Modern Roman']));

    app(PrintService::class)->generate($track, 'it');
    $pdf = Storage::disk('wmfe')->get("testshard/{$track->app_id}/files/ec-track/{$track->id}/print-it.pdf");
    $names = $pdf;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $s) {
            $names .= (string) @gzuncompress($s);
        }
    }

    expect(preg_match('/LMRoman\d+-Bold/', $names))->toBe(1);
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('falls back to 174x98 when the configured map size is zero or negative', function () {
    config(['wm-package.features.print.map.width_mm' => 0, 'wm-package.features.print.map.height_mm' => -5]);
    expect(app(PrintService::class)->mapSize())->toBe([174.0, 98.0]);
});

it('rejects a theme color with a trailing newline', function () {
    expect(sanitizeHexColor("#AABBCC\n", '#000000'))->toBe('#000000')->and(sanitizeHexColor('#AABBCC', '#000000'))->toBe('#AABBCC');
});
