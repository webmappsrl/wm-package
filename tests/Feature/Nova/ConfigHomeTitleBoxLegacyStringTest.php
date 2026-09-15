<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigHomeResolver;

uses(DatabaseTransactions::class);

/**
 * Bug scoperto durante l'uso reale post-import di oc:8488, sul box "title" della HOME:
 * Geohub salva config_home con il titolo come testo semplice ('title' => 'Itinera Romanica
 * Plus'), mentre il campo Nova per quel box (FlexibleTranslatable::simple(), oc:8349) si
 * aspetta il formato traducibile ({"it": "...", "en": "...", ...}). Senza normalizzare il
 * valore letto, il campo Nova non si popola correttamente e al primo salvataggio dell'app —
 * anche senza toccare questo box, perché Nova reinvia lo stato corrente di ogni box — il
 * titolo viene scartato: decodeTranslatableValue() (HasFlexibleTranslatableFields) fa
 * json_decode() su un testo semplice (non JSON valido), ottiene null, quindi [], e
 * buildGenericElement() lo omette dall'elemento finale. Stesso identico difetto che
 * buildLayerElement() già chiude per il box layer (vedi ConfigHomeLayerSelectGuardTest.php),
 * mai chiuso per gli altri box_type prima di questo fix.
 */
function getAttributesForItemForTest(array $item): array
{
    $resolver = new ConfigHomeResolver;
    $method = new ReflectionMethod($resolver, 'getAttributesForItem');
    $method->setAccessible(true);

    return $method->invoke($resolver, $item);
}

function buildGenericElementForTest(array $attributes, string $layoutName = 'title'): array
{
    $layout = new Layout('Title', $layoutName, [], 'test-key-0000002', $attributes);

    $resolver = new ConfigHomeResolver;
    $method = new ReflectionMethod($resolver, 'buildGenericElement');
    $method->setAccessible(true);

    return $method->invoke($resolver, $layout);
}

it('normalizes a legacy plain-string title into the translatable array shape the Nova field expects', function () {
    $attributes = getAttributesForItemForTest(['box_type' => 'title', 'title' => 'Itinera Romanica Plus']);

    expect($attributes['title'])->toBeArray()
        ->and($attributes['title'])->toBe(array_fill_keys(
            config('wm-tab-translatable.locales'),
            'Itinera Romanica Plus'
        ));
});

it('leaves an already-translatable title untouched', function () {
    $original = ['it' => 'Titolo', 'en' => 'Title'];

    $attributes = getAttributesForItemForTest(['box_type' => 'title', 'title' => $original]);

    expect($attributes['title'])->toBe($original);
});

it('does not touch a missing or empty title', function () {
    $withoutTitle = getAttributesForItemForTest(['box_type' => 'title']);
    $withEmptyTitle = getAttributesForItemForTest(['box_type' => 'title', 'title' => '']);

    expect($withoutTitle)->not->toHaveKey('title')
        ->and($withEmptyTitle['title'])->toBe('');
});

it('survives the full get-then-set round trip without being dropped, unlike before this fix', function () {
    // Simula esattamente il ciclo che Nova esegue: get() (via getAttributesForItem, già
    // testato sopra) idrata il Layout con cui l'admin lavora in UI, poi il salvataggio
    // richiama buildGenericElement() su quello stesso Layout — anche senza che l'admin
    // abbia toccato il box.
    $resolvedAttributes = getAttributesForItemForTest(['box_type' => 'title', 'title' => 'Itinera Romanica Plus']);

    $element = buildGenericElementForTest($resolvedAttributes);

    expect($element)->toHaveKey('title')
        ->and($element['title'])->not->toBeEmpty()
        ->and($element['title']['it'] ?? null)->toBe('Itinera Romanica Plus');
});

/**
 * Trovato in review (Finding 1): un titolo legacy può essere ANCHE una stringa JSON che
 * codifica già la forma traducibile — formato esplicitamente supportato dal docblock di
 * HasFlexibleTranslatableFields::decodeTranslatableValue(), non un'ipotesi. Il primo giro del
 * fix trattava qualunque stringa come testo grezzo, avvolgendo anche questo JSON letterale
 * come testo visibile in ogni lingua invece di decodificarlo — corretto provando prima
 * decodeTranslatableValue().
 */
it('decodes a legacy JSON-string title into its real per-locale values instead of wrapping the JSON literally', function () {
    $jsonEncoded = json_encode(['it' => 'Titolo Vero', 'en' => 'Real Title']);

    $attributes = getAttributesForItemForTest(['box_type' => 'title', 'title' => $jsonEncoded]);

    expect($attributes['title'])->toBe(['it' => 'Titolo Vero', 'en' => 'Real Title']);
});

/**
 * Trovato in review (Finding 2): la normalizzazione girava per OGNI box_type, incluso
 * "layer". Layer::getStringName() restituisce sempre una stringa semplice per costruzione
 * (non un formato legacy da correggere), e buildLayerElement() ha una garanzia propria,
 * introdotta in questo stesso ticket: quando il layer non risolve, PRESERVA l'id e il title
 * originali esatti (vedi ConfigHomeLayerSelectGuardTest.php) — normalizzare il title a monte
 * in getAttributesForItem() sostituiva quel valore preservato con la versione multi-lingua
 * appena creata, vanificando la garanzia. Il box layer va escluso da questa normalizzazione.
 */
it('does not touch the title for a layer box, which manages its own title separately', function () {
    $attributes = getAttributesForItemForTest(['box_type' => 'layer', 'layer' => 133, 'title' => 'Toscana']);

    expect($attributes['title'])->toBe('Toscana');
});
