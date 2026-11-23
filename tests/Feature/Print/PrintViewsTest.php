<?php

use Wm\WmPackage\Services\Print\LatexText;
use Wm\WmPackage\Services\Print\XelatexCompiler;

beforeEach(function () {
    // Le view del package, non gli override dello shard in cui il package è montato
    // (resources/views/vendor/wm-package/print): qui si prova il template di default.
    app('view')->getFinder()->replaceNamespace('wm-package', [realpath(__DIR__.'/../../../resources/views')]);
});

function printLabels(string $locale): array
{
    app()->setLocale($locale);
    $keys = ['Length', 'Ascent', 'Descent', 'Duration', 'Difficulty', 'Description', 'Map', 'Photos', 'Points of interest', '© OpenStreetMap contributors, Webmapp'];

    return collect($keys)->mapWithKeys(fn ($k) => [$k => __($k)])->all();
}

function printVars(array $override = []): array
{
    $locale = $override['locale'] ?? 'it';

    return array_merge([
        'model' => null,
        'locale' => $locale,
        'name' => LatexText::escape('Sentiero 50% & co'),
        'descriptionLatex' => 'Una descrizione.',
        'stats' => [['label' => 'Lunghezza', 'value' => '12 km']],
        'mapFile' => 'map.png',
        'photos' => ['photo1.jpg'],
        'relatedPois' => [['name' => 'Fontana', 'typeLabel' => 'Acqua']],
        'primaryColor' => '2e7d32',
        'styleFiles' => ['wm-print'],
        'labels' => printLabels($locale),
    ], $override);
}

it('renders the track view with style, color, escaped name and footer', function () {
    $tex = view('wm-package::print.ec-track', printVars())->render();

    expect($tex)->toContain('\usepackage{wm-print}')
        ->toContain('\definecolor{wmprimary}{HTML}{2E7D32}')
        ->toContain('Sentiero 50\% \& co')
        ->toContain('© OpenStreetMap e contributori, Webmapp')
        ->toContain('Lunghezza')
        ->toContain('\includegraphics')
        ->toContain('Fontana')
        ->not->toMatch('/&(amp|lt|gt|quot|#\d+);/');
});

it('renders the poi view without related pois', function () {
    $tex = view('wm-package::print.ec-poi', printVars(['relatedPois' => null]))->render();

    expect($tex)->toContain('\usepackage{wm-print}')->not->toContain('Fontana')->not->toMatch('/&(amp|lt|gt|quot|#\d+);/');
});

it('omits map, photos and empty sections when data is missing', function (string $view) {
    $tex = view($view, printVars(['mapFile' => null, 'photos' => [], 'descriptionLatex' => '', 'relatedPois' => [], 'stats' => []]))->render();

    expect($tex)->not->toContain('\includegraphics')
        ->not->toContain('Descrizione')
        ->not->toContain('Mappa')
        ->not->toContain('Foto')
        ->not->toContain('Punti di interesse')
        ->not->toContain('\begin{Tabella}');
})->with(['wm-package::print.ec-track', 'wm-package::print.ec-poi']);

it('uses labels of the requested language', function () {
    $it = view('wm-package::print.ec-track', printVars(['stats' => [['label' => printLabels('it')['Length'], 'value' => '1']]]))->render();
    expect($it)->toContain('Lunghezza');

    $en = view('wm-package::print.ec-track', printVars(['locale' => 'en', 'stats' => [['label' => printLabels('en')['Length'], 'value' => '1']]]))->render();
    expect($en)->toContain('Length')->toContain('© OpenStreetMap contributors, Webmapp')->not->toContain('Lunghezza');
});

it('compiles the track sheet with xelatex', function () {
    $sty = realpath(__DIR__.'/../../../resources/print/wm-print.sty');
    $tex = view('wm-package::print.ec-track', printVars(['mapFile' => null, 'photos' => []]))->render();

    $pdf = (new XelatexCompiler)->compile($tex, ['wm-print.sty' => $sty]);

    expect(substr($pdf, 0, 4))->toBe('%PDF');
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('includes every style in order, the package one first', function (string $view) {
    $vars = printVars(['styleFiles' => ['wm-print', 'forestas-print']]);
    $vars += ['legend' => [], 'mapWidthMm' => 200, 'mapHeightMm' => 170];
    $vars['labels'] += ['Overview map' => 'Mappa', 'Legend' => 'Legenda'];
    $tex = view($view, $vars)->render();

    expect(strpos($tex, '\usepackage{wm-print}'))->toBeInt()
        ->toBeLessThan(strpos($tex, '\usepackage{forestas-print}'));
})->with(['wm-package::print.ec-track', 'wm-package::print.ec-poi', 'wm-package::print.overview-map']);

it('colors by role: no direct wmprimary, wmgold or wmgray in the views', function (string $view) {
    $vars = printVars() + ['legend' => [['ref' => 'A1', 'name' => 'Sentiero']], 'mapWidthMm' => 200, 'mapHeightMm' => 170];
    $vars['labels'] += ['Overview map' => 'Mappa', 'Legend' => 'Legenda'];
    $tex = view($view, $vars)->render();

    // Tolta la definizione di wmprimary dal colore dell'App, i colori di base non compaiono più.
    $body = preg_replace('/\\\\definecolor\{wmprimary\}[^\n]*\n/', '', $tex);

    expect($body)->not->toContain('{wmprimary}')->not->toContain('{wmgold}')->not->toContain('{wmgray}')
        ->toContain('\WmDefineRoles');
    // La definizione di wmprimary dal colore dell'App precede la chiamata che deriva i ruoli.
    expect(strpos($tex, '\definecolor{wmprimary}'))->toBeLessThan(strpos($tex, '\WmDefineRoles'));
})->with(['wm-package::print.ec-track', 'wm-package::print.ec-poi', 'wm-package::print.overview-map']);

it('uses the roles in the macros of wm-print, not the base colors', function () {
    $sty = file_get_contents(__DIR__.'/../../../resources/print/wm-print.sty');
    // Fuori dalla sezione che definisce i colori e i ruoli, la grafica non usa i colori di base.
    $macros = substr($sty, strpos($sty, '\\AtBeginDocument{\\WmDefineRoles'));

    expect($macros)->not->toMatch('/\{(wmprimary|wmgold|wmgray)\}/')
        ->toContain('{wmtitle}')->toContain('{wmaccent}')->toContain('{wmrule}')
        ->toContain('{wmmuted}')->toContain('{wmline}')->toContain('{wmtext}');
});

/** Contenuto del PDF con gli stream decompressi: per cercare gli operatori colore. */
function printPdfContent(string $pdf): string
{
    $text = $pdf;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $stream) {
            $plain = @gzuncompress($stream);
            if ($plain !== false) {
                $text .= $plain;
            }
        }
    }

    return $text;
}

/** Vero se il contenuto usa il colore di riempimento esadecimale dato (tolleranza sugli arrotondamenti del PDF). */
function printUsesColor(string $content, string $hex): bool
{
    $want = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    preg_match_all('/([0-9.]+) ([0-9.]+) ([0-9.]+) rg\b/', $content, $m, PREG_SET_ORDER);
    foreach ($m as $c) {
        if (abs($c[1] * 255 - $want[0]) < 2 && abs($c[2] * 255 - $want[1]) < 2 && abs($c[3] * 255 - $want[2]) < 2) {
            return true;
        }
    }

    return false;
}

function printCompileWithStyle(?string $themeSty, string $view = 'wm-package::print.ec-track'): string
{
    $dir = sys_get_temp_dir().'/wm-print-roles-'.uniqid();
    mkdir($dir);
    $files = ['wm-print.sty' => realpath(__DIR__.'/../../../resources/print/wm-print.sty')];
    $styles = ['wm-print'];
    if ($themeSty !== null) {
        file_put_contents($dir.'/theme-roles.sty', $themeSty);
        $files['theme-roles.sty'] = $dir.'/theme-roles.sty';
        $styles[] = 'theme-roles';
    }
    $tex = view($view, printVars(['mapFile' => null, 'photos' => [], 'styleFiles' => $styles, 'primaryColor' => '1565C0']))->render();

    try {
        return (new XelatexCompiler)->compile($tex, $files);
    } finally {
        @unlink($dir.'/theme-roles.sty');
        @rmdir($dir);
    }
}

it('compiles without a theme: title from the app primary color, rule gold, footer muted gray', function () {
    $content = printPdfContent(printCompileWithStyle(null));

    // Colore dell'App diverso dal ripiego di wm-print (2E7D32): se la view lo ignorasse, mancherebbe.
    expect(printUsesColor($content, '1565C0'))->toBeTrue()
        ->and(printUsesColor($content, '2E7D32'))->toBeFalse()
        ->and(printUsesColor($content, 'C9A227'))->toBeTrue()
        ->and(printUsesColor($content, '666666'))->toBeTrue();
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('compiles with a theme style that defines roles and keeps them', function () {
    $content = printPdfContent(printCompileWithStyle(
        "\\ProvidesPackage{theme-roles}\n\\RequirePackage{xcolor}\n"
        ."\\definecolor{theme-rule}{HTML}{FF0000}\n\\colorlet{wmrule}{theme-rule}\n"
        ."\\definecolor{theme-muted}{HTML}{00AA00}\n\\colorlet{wmmuted}{theme-muted}\n\\endinput\n"
    ));

    expect(substr($content, 0, 4))->toBe('%PDF')
        ->and(printUsesColor($content, 'FF0000'))->toBeTrue()
        ->and(printUsesColor($content, 'C9A227'))->toBeFalse()
        // il credito del piè di pagina passa da wmmuted: vince lo stile, non il grigio di ripiego
        ->and(printUsesColor($content, '00AA00'))->toBeTrue()
        ->and(printUsesColor($content, '666666'))->toBeFalse()
        // wmtitle non definito dallo stile: segue wmprimary dell'App
        ->and(printUsesColor($content, '1565C0'))->toBeTrue()
        ->and(printUsesColor($content, '2E7D32'))->toBeFalse();
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

it('falls back to the roles at begin document for a view that does not call WmDefineRoles', function () {
    // View minimale di uno shard: niente \WmDefineRoles, solo wmprimary e una macro di wm-print.
    $tex = "\\documentclass{article}\n\\usepackage{wm-print}\n\\definecolor{wmprimary}{HTML}{1565C0}\n"
        ."\\begin{document}\n\\TitoloScheda{Titolo}\n\\FilettoOro\n\\end{document}\n";
    $pdf = (new XelatexCompiler)->compile($tex, ['wm-print.sty' => realpath(__DIR__.'/../../../resources/print/wm-print.sty')]);
    $content = printPdfContent($pdf);

    expect(printUsesColor($content, '1565C0'))->toBeTrue()
        ->and(printUsesColor($content, '2E7D32'))->toBeFalse()
        ->and(printUsesColor($content, 'C9A227'))->toBeTrue();
})->skip(! is_executable('/usr/bin/xelatex'), 'xelatex non installato');

/** Nomi dei font incorporati nel PDF: dagli stream (anche compressi con zlib) le voci /FontName e /BaseFont. */
function printPdfFontNames(string $pdf): string
{
    $text = $pdf;
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m)) {
        foreach ($m[1] as $stream) {
            $plain = @gzuncompress($stream);
            if ($plain !== false) {
                $text .= $plain;
            }
        }
    }
    preg_match_all('#/(?:FontName|BaseFont)\s*/([A-Za-z0-9+_-]+)#', $text, $names);

    return implode(' ', array_unique($names[1]));
}
