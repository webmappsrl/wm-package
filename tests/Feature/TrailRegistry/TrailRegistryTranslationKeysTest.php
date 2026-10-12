<?php

use Wm\WmPackage\TrailRegistry\Nova\MapLegendRenderer;

/**
 * Le chiavi di __() del Catasto Sentieri sono in inglese, come nel resto del
 * package, e la voce italiana riporta esattamente il testo che l'operatore
 * leggeva quando la chiave era italiana (oc:8672).
 *
 * L'elenco atteso e' stato ricavato dal codice PRIMA della conversione (la
 * «Tabella delle chiavi» di docs/features/8672-*): non va mai rigenerato da
 * resources/lang/it.json, altrimenti il test confronterebbe il file con se'
 * stesso. I test che usano __('chiave') da entrambi i lati non proteggono il
 * testo italiano, e la CI gira con APP_LOCALE=en: la garanzia sta qui.
 *
 * I percorsi dello scope sono quelli del ticket. Allargarli a tutto src/ e'
 * un lavoro a parte: le chiavi gia' inglesi ma senza voce vengono solo
 * elencate su STDERR, non verificate.
 */
function oc8672ScopePaths(): array
{
    return [
        'src/TrailRegistry',
        'src/WmPackageServiceProvider.php',
        'src/Nova/EcPoi.php',
        'src/Nova/TaxonomyWhere.php',
        'src/Nova/Actions/Concerns/HasTaxonomyWhereImportHelpers.php',
    ];
}

/**
 * Chiave inglese => testo italiano di oggi.
 *
 * @return array<string, string>
 */
function oc8672ExpectedKeys(): array
{
    return [
        'already imported' => 'già importata',
        'POI types associated with this point of interest' => 'Tipologie di POI associate a questo punto di interesse',
        'Properties' => 'Proprietà',
        'Sector' => 'Settore',
        'from the geometry' => 'dalla geometria',
        'declared by the code' => 'dichiarato dal codice',
        'chosen' => 'scelto',
        'Trail' => 'Sentiero',
        'Application' => 'Istanza',
        'Applications' => 'Istanze',
        'Replace number' => 'Sostituisci numero',
        'This application has no active code to replace.' => 'Questa istanza non ha un codice attivo da sostituire.',
        'Number replaced.' => 'Numero sostituito.',
        'Number' => 'Numero',
        'Variant' => 'Variante',
        'no variant' => 'nessuna variante',
        'Code' => 'Codice',
        'Already assigned to' => 'Già assegnato a',
        'Code in the data' => 'Codice nei dati',
        'Code from the geometry' => 'Codice dalla geometria',
        'Same track as' => 'Stessa traccia di',
        'First free number in the sector' => 'Primo libero nel settore',
        'Type' => 'Tipo',
        'Context' => 'Contesto',
        'Open on :platform' => 'Apri su :platform',
        'Open the record on the source platform' => 'Apri la scheda sulla piattaforma di origine',
        'missing' => 'assente',
        'Sector the track falls in' => 'Settore in cui la traccia ricade',
        'Other sectors: those crossed and the one declared by the code' => 'Altri settori: quelli attraversati e quello dichiarato dal codice',
        'Other sectors crossed, with the percentage of the route' => 'Altri settori attraversati, con la percentuale di percorso',
        'Elevation profile below the map: of the trail' => 'Profilo altimetrico sotto la mappa: del sentiero',
        'Elevation profile below the map: of the application track' => 'Profilo altimetrico sotto la mappa: della traccia dell\'istanza',
        'No geometry to show for this code.' => 'Nessuna geometria da mostrare per questo codice.',
        'Sector the prefix comes from' => 'Settore da cui viene il prefisso',
        'Other trails in the sector, with number and variant' => 'Altri sentieri del settore, con numero e variante',
        'Trail the code is assigned to' => 'Sentiero a cui il codice e\' assegnato',
        'Application track the code originated from' => 'Traccia dell\'istanza da cui il codice e\' nato',
        'Number of this code' => 'Numero di questo codice',
        'Released number: no longer belongs to this application' => 'Numero liberato: non appartiene piu\' a questa istanza',
        'Number of a validated trail' => 'Numero di un sentiero validato',
        'Number proposed by another application' => 'Numero proposto da un\'altra istanza',
        'Designation' => 'Denominazione',
        'Review status' => 'Stato istruttoria',
        'Source' => 'Provenienza',
        'Entered by' => 'Inserita da',
        'Submitted on' => 'Presentata il',
        'Map' => 'Mappa',
        'Legend' => 'Legenda',
        'Uploaded GPX/GeoJSON file' => 'File GPX/GeoJSON caricato',
        'Details' => 'Dettagli',
        'Geometry (GPX or GeoJSON)' => 'Geometria (GPX o GeoJSON)',
        'The trail route: a GPX (track or route) or a GeoJSON with one or more lines.' => 'Il tracciato del sentiero: un GPX (traccia o rotta) oppure un GeoJSON con una o piu\' linee.',
        'anomaly' => 'anomalia',
        'Anomalies' => 'Anomalie',
        'Anomaly' => 'Anomalia',
        'Detail' => 'Dettaglio',
        'Linked trail' => 'Sentiero collegato',
        'Detected on' => 'Rilevata il',
        'Code already assigned' => 'Codice già assegnato',
        'Sector mismatch' => 'Settore discordante',
        'Duplicate geometry' => 'Geometria duplicata',
        'Unreadable code' => 'Codice illeggibile',
        'Outside any sector' => 'Fuori da ogni settore',
        'What these rows are' => 'Che cosa sono queste righe',
        'Code registry' => 'Registro dei codici',
        'Registry code' => 'Codice del registro',
        'Status' => 'Stato',
        'Region' => 'Regione',
        'Province' => 'Provincia',
        'Reference sector' => 'Settore di riferimento',
        'Status change history' => 'Storia dei cambi di stato',
        'These are the trails that <strong>did not get a number</strong>, and the reason why. Only codes with no doubt pending enter the code registry: everything still unresolved is here, and while it is here that trail has no number.' => 'Sono i sentieri che <strong>non hanno ottenuto un numero</strong>, e il motivo per cui non l’hanno ottenuto. Nel registro dei codici entrano solo i codici su cui non pende alcun dubbio: tutto ciò che resta irrisolto sta qui, e finché sta qui quel sentiero è senza numero.',
        'Corrections <strong>are not made from this screen</strong>: the data belongs to the source platform. Each trail name carries two links — the <strong>name</strong> opens its record here, the <strong>icon</strong> next to it the one on the source platform: correct it there, and the next import removes the row on its own, assigning the number if it has meanwhile become assignable.' => 'Le correzioni <strong>non si fanno da questa schermata</strong>: il dato appartiene alla piattaforma di origine. Ogni nome di sentiero porta due collegamenti — il <strong>nome</strong> apre la sua scheda qui, l’<strong>icona</strong> accanto quella sulla piattaforma di origine: si corregge lì, e l’importazione successiva fa sparire la riga da sé, assegnando il numero se nel frattempo è diventato assegnabile.',
        'The <strong>Trail</strong> column always shows the one left without a number; the <strong>Detail</strong> shows why, next to the other side of the problem — a trail when someone else already has that code or the track is shared, a code when the problem is in the data.' => 'Nella colonna <strong>Sentiero</strong> c’è sempre quello rimasto senza numero; nel <strong>Dettaglio</strong> il perché, con accanto l’altro termine del problema — un sentiero quando quel codice ce l’ha già qualcun altro o la traccia è condivisa, un codice quando il problema sta nel dato.',
        'Another trail already has that code: one of the two must be corrected at the source.' => 'Quel codice ce l’ha già un altro sentiero: uno dei due va corretto alla fonte.',
        'The sector written in the code is not the one the track actually falls in. Next to it, the code the platform would compose from the geometry.' => 'Il settore scritto nel codice non è quello in cui la traccia ricade davvero. Accanto, il codice che la piattaforma comporrebbe dalla geometria.',
        'Two or more trails with the exact same track. Until it is known which one is right, none of them gets a number.' => 'Due o più sentieri con la stessa identica traccia. Finché non si sa quale sia quello buono, nessuno di loro prende un numero.',
        'No valid number can be derived from the code field. Next to it, the first free number in the sector: it is a hint, not a decision — the right number is the one on the signage in the field.' => 'Dal campo del codice non si ricava un numero valido. Accanto, il primo numero libero del settore: è un’indicazione, non una decisione — il numero giusto è quello sulla segnaletica in campo.',
        'The geometry does not fall in any sector, so there is no prefix to compose the code with.' => 'La geometria non ricade in alcun settore, quindi non esiste un prefisso con cui comporre il codice.',
        'Trail registry' => 'Catasto',
    ];
}

function oc8672PackagePath(string $relative = ''): string
{
    return dirname(__DIR__, 3).($relative === '' ? '' : '/'.$relative);
}

/**
 * @return array<string, string>
 */
function oc8672Lang(string $locale): array
{
    return json_decode(
        file_get_contents(oc8672PackagePath("resources/lang/{$locale}.json")),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @return array<int, string>
 */
function oc8672ScopeFiles(): array
{
    $files = [];

    foreach (oc8672ScopePaths() as $path) {
        $absolute = oc8672PackagePath($path);

        if (is_file($absolute)) {
            $files[] = $absolute;

            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Le chiamate __() di un file, lette con il tokenizer e non con una regex:
 * cosi' sono corrette anche le chiamate su piu' righe e gli apici con escape.
 *
 * @return array{literal: array<int, array{0: string, 1: int, 2: string}>, dynamic: array<int, array{0: string, 1: int}>}
 */
function oc8672ExtractKeys(string $file): array
{
    $tokens = array_values(array_filter(
        token_get_all(file_get_contents($file)),
        fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $relative = str_replace(oc8672PackagePath().'/', '', $file);
    $found = ['literal' => [], 'dynamic' => []];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== '__' || ($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        $previous = $tokens[$i - 1] ?? null;

        if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
            continue;
        }

        $argument = $tokens[$i + 2] ?? null;
        $after = $tokens[$i + 3] ?? null;

        if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING && in_array($after, [',', ')'], true)) {
            $found['literal'][] = [$relative, $token[2], oc8672Unquote($argument[1])];
        } else {
            $found['dynamic'][] = [$relative, $token[2]];
        }
    }

    return $found;
}

function oc8672Unquote(string $literal): string
{
    $body = substr($literal, 1, -1);

    return $literal[0] === "'"
        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
        : stripcslashes($body);
}

/**
 * @return array{literal: array<int, array{0: string, 1: int, 2: string}>, dynamic: array<int, array{0: string, 1: int}>}
 */
function oc8672ScopeKeys(): array
{
    $all = ['literal' => [], 'dynamic' => []];

    foreach (oc8672ScopeFiles() as $file) {
        $found = oc8672ExtractKeys($file);
        $all['literal'] = [...$all['literal'], ...$found['literal']];
        $all['dynamic'] = [...$all['dynamic'], ...$found['dynamic']];
    }

    return $all;
}

it('ha in en.json e it.json ogni chiave convertita, con il testo italiano di oggi', function () {
    $en = oc8672Lang('en');
    $it = oc8672Lang('it');
    $wrong = [];

    foreach (oc8672ExpectedKeys() as $key => $italian) {
        if (($en[$key] ?? null) !== $key) {
            $wrong[] = "en.json: «{$key}»";
        }

        if (($it[$key] ?? null) !== $italian) {
            $wrong[] = "it.json: «{$key}» (atteso «{$italian}», trovato «".($it[$key] ?? 'nessuna voce').'»)';
        }
    }

    expect($wrong)->toBe([]);
});

it('non usa piu\' nessuna delle chiavi italiane convertite nei percorsi dello scope', function () {
    $italian = array_flip(oc8672ExpectedKeys());

    $left = collect(oc8672ScopeKeys()['literal'])
        ->filter(fn (array $call) => isset($italian[$call[2]]))
        ->map(fn (array $call) => "{$call[0]}:{$call[1]} «{$call[2]}»")
        ->values()
        ->all();

    expect($left)->toBe([]);
});

it('usa chiavi convertite nelle etichette delle legende passate a __($label)', function () {
    $expected = oc8672ExpectedKeys();
    $labels = [
        ...array_column((new ReflectionClassConstant(MapLegendRenderer::class, 'ENTRIES'))->getValue(), 2),
        ...array_column((new ReflectionClassConstant(MapLegendRenderer::class, 'SIGNS'))->getValue(), 3),
    ];

    expect(array_values(array_filter($labels, fn (string $label) => ! isset($expected[$label]))))->toBe([]);
});

it('elenca le chiavi gia\' inglesi senza voce e le chiamate dinamiche, senza verificarle', function () {
    $expected = oc8672ExpectedKeys();
    $en = oc8672Lang('en');
    $keys = oc8672ScopeKeys();

    $unlisted = collect($keys['literal'])
        ->reject(fn (array $call) => isset($expected[$call[2]]) || isset($en[$call[2]]))
        ->map(fn (array $call) => "  {$call[0]}:{$call[1]} «{$call[2]}»");
    $dynamic = collect($keys['dynamic'])->map(fn (array $call) => "  {$call[0]}:{$call[1]}");

    fwrite(STDERR, "\nChiavi letterali senza voce, non verificate (oc:8672):\n".$unlisted->implode("\n")
        ."\nChiamate __() con chiave dinamica, non verificate:\n".$dynamic->implode("\n")."\n");

    expect($keys['literal'])->not->toBeEmpty();
});
