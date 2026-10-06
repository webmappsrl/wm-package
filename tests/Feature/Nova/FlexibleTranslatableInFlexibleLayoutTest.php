<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request as HttpRequest;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Laravel\Nova\Support\Fluent;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Models\TaxonomyPoiType;
use Wm\WmPackage\Nova\App as AppResource;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;
use Wm\WmPackage\Nova\Traits\HasConfigDetailPanel;

uses(TestCase::class, DatabaseTransactions::class);

/**
 * Giro completo del form Nova per i campi FlexibleTranslatable che stanno direttamente in un
 * layout Flexible (oc:8675). Più gruppi dello stesso layout condividono gli oggetti dei
 * sotto-campi per lingua (clone superficiale in Layout::cloneField), quindi il test passa
 * sempre dal json_encode dell'INTERO campo Flexible, meta `layouts` compreso, come la risposta
 * di Nova: chiamare jsonSerialize() sul solo gruppo non riproduce il bug.
 *
 * Le App non vengono salvate. Le attività e i tipi di POI usati dagli horizontal_scroll li crea il
 * test, con identifier nuovi e dentro DatabaseTransactions: il resolver tiene un item solo se la
 * tassonomia esiste, e il test non deve dipendere dai dati del DB dello shard su cui gira.
 */
function flexibleLayoutAppField(App $model, string $attribute): Flexible
{
    $resource = new AppResource($model);

    foreach ($resource->fields(NovaRequest::create('/')) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Flexible && $f->attribute === $attribute);
        if ($found) {
            return $found;
        }
    }

    throw new RuntimeException("{$attribute} field not found on App resource");
}

/**
 * Crea un'attività con un identifier che non esiste in nessuno shard e restituisce il `res` da
 * usare negli item degli horizontal_scroll activities.
 */
function flexibleLayoutActivity(string $name): string
{
    $identifier = 'test-activity-'.uniqid();
    TaxonomyActivity::create(['identifier' => $identifier, 'name' => ['it' => $name]]);

    return $identifier;
}

/**
 * Come flexibleLayoutActivity(), per gli item degli horizontal_scroll poi_types.
 */
function flexibleLayoutPoiType(string $name): string
{
    $identifier = 'test-poi-type-'.uniqid();
    TaxonomyPoiType::create(['identifier' => $identifier, 'name' => ['it' => $name]]);

    return 'poi_type_'.$identifier;
}

/**
 * App non salvata con gli attributi grezzi come se arrivassero dal database: i resolver
 * leggono getRawOriginal(), che su un modello appena costruito con new App([...]) è vuoto.
 */
function flexibleLayoutUnsavedApp(array $attributes): App
{
    return (new App)->setRawAttributes($attributes, true);
}

/**
 * Risolve il campo come per la pagina di edit e restituisce ciò che arriva al browser.
 *
 * Come UpdateViewResource::toArray(), lo stesso oggetto campo compare due volte nello stesso
 * JSON: in `fields` e, dentro il suo pannello, in `panels` (ResolvesFields::resolvePanelsFromFields()
 * lo mette in `meta['fields']` del Panel). La copia dei pannelli viene serializzata per seconda,
 * dopo il resolve(true) del template di layout: è quella che svuota i title anche con un solo
 * gruppo per layout, il caso del cliente. Si restituisce quella.
 */
function flexibleLayoutSerializeForEdit(Flexible $field, $resource): array
{
    $request = NovaRequest::create('/', 'GET', ['editing' => 'true', 'editMode' => 'update']);
    app()->instance(NovaRequest::class, $request);

    $field->resolve($resource);

    $response = json_decode(json_encode([
        'fields' => [$field],
        'panels' => [['fields' => [$field]]],
    ]), true);

    return $response['panels'][0]['fields'][0];
}

/**
 * Per ogni gruppo serializzato, i valori per lingua del campo FlexibleTranslatable che
 * scrive `$attribute` (es. "title", oppure "content" per un richText, il cui attributo esterno è
 * "content_it"), letti dai sotto-campi come li legge il componente Vue.
 *
 * @return array<int, array<string, mixed>>
 */
function flexibleLayoutValuesByGroup(array $serialized, string $attribute): array
{
    return collect($serialized['value'] ?? [])
        ->map(function (array $group) use ($attribute) {
            $translatable = collect($group['attributes'] ?? [])
                ->first(fn ($f) => ($f['component'] ?? null) === 'nova-tab-translatable'
                    && str_starts_with((string) ($f['attribute'] ?? ''), $attribute));

            return collect($translatable['fields'] ?? [])
                ->mapWithKeys(fn ($sub) => [$sub['locale'] => $sub['value']])
                ->all();
        })
        ->values()
        ->all();
}

/**
 * Trasforma i campi serializzati di un gruppo (o di una riga di Repeater) negli attributi che il
 * form invia: un FlexibleTranslatable manda una chiave piatta per ogni lingua
 * ("translations_title_it"), un Repeater manda le sue righe come {type, fields}.
 *
 * @param  array<int, array<string, mixed>>  $fields
 * @return array<string, mixed>
 */
function flexibleLayoutFormAttributes(array $fields): array
{
    $attributes = [];

    foreach ($fields as $field) {
        if (($field['component'] ?? null) === 'nova-tab-translatable') {
            foreach ($field['fields'] as $sub) {
                $attributes[$sub['attribute']] = $sub['value'];
            }
        } elseif (($field['component'] ?? null) === 'repeater-field') {
            $attributes[$field['attribute']] = collect($field['value'] ?? [])
                ->map(fn ($row) => ['type' => $row['type'], 'fields' => flexibleLayoutFormAttributes($row['fields'])])
                ->all();
        } else {
            $attributes[$field['attribute']] = $field['value'] ?? null;
        }
    }

    return $attributes;
}

/**
 * Invia il form come lo invia Nova partendo da ciò che il form ha ricevuto, e restituisce ciò che
 * il resolver ha scritto nell'attributo (anche un percorso JSON come `properties->config_detail`).
 * `$groups` permette di modificare i gruppi prima dell'invio (aggiungere, riordinare, cambiare
 * valori).
 *
 * @param  null|callable(array<int, array<string, mixed>>): array<int, array<string, mixed>>  $groups
 */
function flexibleLayoutSubmit(Flexible $field, array $serialized, $resource, ?callable $groups = null): array
{
    $payload = collect($serialized['value'])
        ->map(fn ($group) => [
            'layout' => $group['layout'],
            'key' => $group['key'],
            'attributes' => flexibleLayoutFormAttributes($group['attributes']),
        ])
        ->values()
        ->all();

    if ($groups !== null) {
        $payload = $groups($payload);
    }

    $request = NovaRequest::createFrom(
        HttpRequest::create('/', 'PUT', [$field->attribute => $payload]),
        new NovaRequest
    );

    $callback = $field->fill($request, $resource);
    if (is_callable($callback)) {
        $callback();
    }

    $stored = str_contains($field->attribute, '->')
        ? data_get($resource, str_replace('->', '.', $field->attribute))
        : $resource->getAttributes()[$field->attribute];

    // I resolver di wm-package scrivono una stringa JSON, quello predefinito di whitecube una
    // Collection di gruppi.
    return is_string($stored) ? json_decode($stored, true) : json_decode(json_encode($stored), true);
}

/**
 * Apre l'edit e salva senza toccare nulla, come l'admin che salva l'app per un'altra modifica.
 */
function flexibleLayoutSaveUntouched(string $attribute, string $stored, ?callable $groups = null): array
{
    $app = flexibleLayoutUnsavedApp([$attribute => $stored]);
    $serialized = flexibleLayoutSerializeForEdit(flexibleLayoutAppField($app, $attribute), $app);

    // Un'istanza nuova del campo per il salvataggio, come nella richiesta PUT di Nova, che non
    // eredita lo stato lasciato dal resolve della pagina di edit.
    return flexibleLayoutSubmit(flexibleLayoutAppField($app, $attribute), $serialized, $app, $groups);
}

it('espone al form il title di ciascun gruppo quando più gruppi usano FlexibleTranslatable', function () {
    $app = flexibleLayoutUnsavedApp(['config_home' => json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Benvenuti', 'en' => 'Welcome']],
        ['box_type' => 'title', 'title' => ['it' => 'Itinerari', 'en' => 'Routes']],
    ]])]);

    $titles = flexibleLayoutValuesByGroup(flexibleLayoutSerializeForEdit(flexibleLayoutAppField($app, 'config_home'), $app), 'title');

    expect($titles)->toHaveCount(2);
    expect($titles[0])->toMatchArray(['it' => 'Benvenuti', 'en' => 'Welcome']);
    expect($titles[1])->toMatchArray(['it' => 'Itinerari', 'en' => 'Routes']);
});

it('espone al form e salva il content rich text di ciascun gruppo', function () {
    $makeField = fn () => Flexible::make('Blocks', 'blocks')
        ->addLayout('Info', 'info', [FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')])]);

    $resource = new Fluent(['blocks' => json_encode([
        ['layout' => 'info', 'key' => 'group-uno', 'attributes' => ['content_it' => '<p>Uno</p>', 'content_en' => '<p>One</p>']],
        ['layout' => 'info', 'key' => 'group-due', 'attributes' => ['content_it' => '<p>Due</p>', 'content_en' => '<p>Two</p>']],
    ])]);

    $serialized = flexibleLayoutSerializeForEdit($makeField(), $resource);
    $contents = flexibleLayoutValuesByGroup($serialized, 'content');

    expect($contents[0])->toMatchArray(['it' => '<p>Uno</p>', 'en' => '<p>One</p>']);
    expect($contents[1])->toMatchArray(['it' => '<p>Due</p>', 'en' => '<p>Two</p>']);

    $stored = flexibleLayoutSubmit($makeField(), $serialized, $resource);

    expect(collect($stored)->map(fn ($group) => [$group['attributes']['content_it'], $group['attributes']['content_en']])->all())
        ->toBe([['<p>Uno</p>', '<p>One</p>'], ['<p>Due</p>', '<p>Two</p>']]);
});

it('lascia identici i title di title, slug ed external_url dopo un salvataggio senza modifiche', function () {
    $home = ['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Benvenuti', 'en' => 'Welcome']],
        ['box_type' => 'slug', 'title' => ['it' => 'Bici in comune', 'en' => 'Shared bikes'], 'slug' => 'project'],
        ['box_type' => 'external_url', 'title' => ['it' => 'Sito', 'en' => 'Website'], 'url' => 'https://example.com'],
    ]];

    $stored = flexibleLayoutSaveUntouched('config_home', json_encode($home));

    expect(collect($stored['HOME'])->pluck('title')->all())->toBe(collect($home['HOME'])->pluck('title')->all());
});

it('lascia identici i title degli horizontal_scroll activities e poi_types', function () {
    $cycling = flexibleLayoutActivity('In bici');
    $asphalt = flexibleLayoutActivity('Asfalto');
    $square = flexibleLayoutPoiType('Piazze');

    $home = ['HOME' => [
        ['box_type' => 'horizontal_scroll', 'item_type' => 'activities', 'title' => ['it' => 'Attività', 'en' => 'Activities'],
            'items' => [['title' => ['it' => 'In bici'], 'res' => $cycling, 'image_url' => 'https://example.com/a.jpg']]],
        ['box_type' => 'horizontal_scroll', 'item_type' => 'activities', 'title' => ['it' => 'Su strada', 'en' => 'On road'],
            'items' => [['title' => ['it' => 'Asfalto'], 'res' => $asphalt, 'image_url' => 'https://example.com/c.jpg']]],
        ['box_type' => 'horizontal_scroll', 'item_type' => 'poi_types', 'title' => ['it' => 'Luoghi', 'en' => 'Places'],
            'items' => [['title' => ['it' => 'Piazze'], 'res' => $square, 'image_url' => 'https://example.com/b.jpg']]],
    ]];

    $stored = flexibleLayoutSaveUntouched('config_home', json_encode($home));

    expect($stored['HOME'][0]['title'])->toBe(['it' => 'Attività', 'en' => 'Activities']);
    expect($stored['HOME'][1]['title'])->toBe(['it' => 'Su strada', 'en' => 'On road']);
    expect($stored['HOME'][2]['title'])->toBe(['it' => 'Luoghi', 'en' => 'Places']);
    expect(collect($stored['HOME'])->map(fn ($box) => array_column($box['items'], 'res'))->all())
        ->toBe([[$cycling], [$asphalt], [$square]]);
});

it('converte il title legacy stringa e tiene intatto il gruppo accanto', function () {
    $stored = flexibleLayoutSaveUntouched('config_home', json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => 'Itinera Romanica Plus'],
        ['box_type' => 'title', 'title' => ['it' => 'Itinerari', 'en' => 'Routes']],
    ]]));

    expect($stored['HOME'][0]['title'])->toBe(array_fill_keys(config('wm-tab-translatable.locales'), 'Itinera Romanica Plus'));
    expect($stored['HOME'][1]['title'])->toBe(['it' => 'Itinerari', 'en' => 'Routes']);
});

it('salva solo le lingue valorizzate', function () {
    $stored = flexibleLayoutSaveUntouched('config_home', json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Solo italiano']],
        ['box_type' => 'title', 'title' => ['it' => 'Altro', 'de' => 'Anderes']],
    ]]));

    expect($stored['HOME'][0]['title'])->toBe(['it' => 'Solo italiano']);
    expect($stored['HOME'][1]['title'])->toBe(['it' => 'Altro', 'de' => 'Anderes']);
});

it('salva senza title un gruppo nuovo lasciato vuoto, senza prendere quello di un vicino', function () {
    $stored = flexibleLayoutSaveUntouched('config_home', json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Benvenuti']],
        ['box_type' => 'title', 'title' => ['it' => 'Itinerari']],
    ]]), fn (array $groups) => [
        ['layout' => 'title', 'key' => 'nuovo-gruppo', 'attributes' => array_fill_keys(
            array_map(fn ($l) => "translations_title_{$l}", config('wm-tab-translatable.locales')), ''
        )],
        ...$groups,
    ]);

    expect($stored['HOME'])->toHaveCount(3);
    expect($stored['HOME'][0])->not->toHaveKey('title');
    expect($stored['HOME'][1]['title'])->toBe(['it' => 'Benvenuti']);
    expect($stored['HOME'][2]['title'])->toBe(['it' => 'Itinerari']);
});

it('tiene il title di ciascun gruppo quando i gruppi vengono riordinati', function () {
    $stored = flexibleLayoutSaveUntouched('config_home', json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Primo']],
        ['box_type' => 'title', 'title' => ['it' => 'Secondo']],
        ['box_type' => 'slug', 'title' => ['it' => 'Terzo'], 'slug' => 'project'],
    ]]), fn (array $groups) => array_reverse($groups));

    expect(collect($stored['HOME'])->map(fn ($box) => [$box['box_type'], $box['title']])->all())->toBe([
        ['slug', ['it' => 'Terzo']],
        ['title', ['it' => 'Secondo']],
        ['title', ['it' => 'Primo']],
    ]);
});

it('mantiene i due formati del title vuoto', function () {
    $empty = fn (array $groups) => array_map(function ($group) {
        foreach ($group['attributes'] as $key => $value) {
            if (str_starts_with($key, 'translations_title_')) {
                $group['attributes'][$key] = '';
            }
        }

        return $group;
    }, $groups);

    $cycling = flexibleLayoutActivity('In bici');

    $stored = flexibleLayoutSaveUntouched('config_home', json_encode(['HOME' => [
        ['box_type' => 'title', 'title' => ['it' => 'Da togliere']],
        ['box_type' => 'slug', 'title' => ['it' => 'Da togliere'], 'slug' => 'project'],
        ['box_type' => 'horizontal_scroll', 'item_type' => 'activities', 'title' => ['it' => 'Da togliere'],
            'items' => [['title' => ['it' => 'In bici'], 'res' => $cycling, 'image_url' => 'https://example.com/a.jpg']]],
    ]]), $empty);

    expect($stored['HOME'][0])->not->toHaveKey('title');
    expect($stored['HOME'][1])->not->toHaveKey('title');
    expect($stored['HOME'][2]['title'])->toBe([]);
});

it('lascia identiche le label degli overlays title', function () {
    $stored = flexibleLayoutSaveUntouched('config_overlays', json_encode(['OVERLAYS' => [
        ['box_type' => 'title', 'label' => ['it' => 'Percorsi', 'en' => 'Routes']],
        ['box_type' => 'title', 'label' => ['it' => 'Luoghi', 'en' => 'Places']],
    ]]));

    expect(collect($stored['OVERLAYS'])->pluck('label')->all())->toBe([
        ['it' => 'Percorsi', 'en' => 'Routes'],
        ['it' => 'Luoghi', 'en' => 'Places'],
    ]);
});

it('tiene i title di due item horizontal scroll dopo il giro del form', function () {
    $cycling = flexibleLayoutActivity('In bici');
    $asphalt = flexibleLayoutActivity('Su asfalto');

    $stored = flexibleLayoutSaveUntouched('config_home', json_encode(['HOME' => [
        ['box_type' => 'horizontal_scroll', 'item_type' => 'activities', 'title' => ['it' => 'Attività'], 'items' => [
            ['title' => ['it' => 'In bici', 'en' => 'By bike'], 'res' => $cycling, 'image_url' => 'https://example.com/a.jpg'],
            ['title' => ['it' => 'Su asfalto', 'en' => 'On asphalt'], 'res' => $asphalt, 'image_url' => 'https://example.com/b.jpg'],
        ]],
    ]]));

    $items = $stored['HOME'][0]['items'];

    expect(array_column($items, 'res'))->toBe([$cycling, $asphalt]);
    expect($items[0]['title'])->toMatchArray(['it' => 'In bici', 'en' => 'By bike']);
    expect($items[1]['title'])->toMatchArray(['it' => 'Su asfalto', 'en' => 'On asphalt']);
});

it('tiene title e content di due righe info box dopo il giro del form', function () {
    $field = (new class
    {
        use HasConfigDetailPanel;

        public function flexible(): Flexible
        {
            return collect($this->configDetailPanel()->data)->first(fn ($f) => $f instanceof Flexible);
        }
    })->flexible();

    $poi = new EcPoi(['properties' => ['config_detail' => [
        ['box_type' => 'info', 'items' => [
            ['title' => ['it' => 'Orari', 'en' => 'Hours'], 'content' => ['it' => '<p>Tutti i giorni</p>']],
            ['title' => ['it' => 'Accesso', 'en' => 'Access'], 'content' => ['it' => '<p>Gratuito</p>']],
        ]],
    ]]]);

    $stored = flexibleLayoutSubmit($field, flexibleLayoutSerializeForEdit($field, $poi), $poi);

    expect($stored[0]['items'])->toBe([
        ['title' => ['it' => 'Orari', 'en' => 'Hours'], 'content' => ['it' => '<p>Tutti i giorni</p>']],
        ['title' => ['it' => 'Accesso', 'en' => 'Access'], 'content' => ['it' => '<p>Gratuito</p>']],
    ]);
});
