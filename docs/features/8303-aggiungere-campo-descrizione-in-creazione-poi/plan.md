> Ticket: oc:8303

# Aggiungere campo descrizione in creazione POI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rendere il campo "Descrizione" di `EcPoi`/`EcTrack` sempre visibile in Nova (creazione e modifica), spostandolo dal pannello dinamico "Proprietà" (nascosto quando vuoto) a un campo statico traducibile nel tab Details > Info.

**Architecture:** Il campo `description` (`properties->description`, traducibile, editor Tiptap) viene dichiarato direttamente in `getInfoTabFields()` di `EcPoi` e `EcTrack` — stesso pattern già usato per `Contact email` (EcPoi) e `Not Accessible Message` (EcTrack), che sono sempre visibili perché non passano dal meccanismo `PropertiesPanel::makeWithModel()` gated da `hasDataForPath()`. La entry `description` viene rimossa dagli schema config dinamici (`wm-ec-poi-schema.php`, `wm-ec-track-schema.php`) per evitare un doppio editor sullo stesso dato in modifica.

**Tech Stack:** Laravel Nova 5, `Marshmallow\Tiptap\Tiptap` (editor rich-text), `Kongulov\NovaTabTranslatable\NovaTabTranslatable` (wrapper multilingua), PHPUnit (`Wm\WmPackage\Tests\TestCase`).

## Global Constraints

- Toolbar Tiptap identica a `PropertiesPanel::tiptapButtons()`/`App::tiptapButtons()`: `['heading', '|', 'bold', 'italic', 'underline', '|', 'bulletList', 'orderedList', '|', 'link', 'image', '|', 'textAlign', '|', 'horizontalRule', '|', 'editHtml']`
- Nessuna modifica a `Layer` né al pannello dinamico "Proprietà" (`PropertiesPanel::makeWithModel()`/`hasDataForPath()`) — fuori scope
- Nessuna modifica agli altri campi dello schema `EcTrack` (`excerpt`, `ref`, `from`, `to`, `geohub_id`, `taxonomy_where`)
- Test in `wm-package/tests/` devono estendere `Wm\WmPackage\Tests\TestCase` (non `Tests\TestCase` del repo principale)
- I test PHPUnit richiedono il container `laravel-camminiditalia` (Docker) attivo — verificare con `docker ps` prima di eseguire `php artisan test` o l'equivalente suite del package; se il daemon Docker non è attivo, avviarlo prima di procedere con gli step di verifica

---

### Task 1: Campo Descrizione statico su EcPoi

**Files:**
- Modify: `wm-package/src/Nova/AbstractEcResource.php` — nuovo metodo protetto `tiptapButtons()`
- Modify: `wm-package/src/Nova/EcPoi.php:1-82` — import + campo in `getInfoTabFields()`
- Test: `wm-package/tests/Unit/EcDescriptionTiptapTest.php`

**Interfaces:**
- Consumes: nessuna dipendenza da task precedenti (primo task)
- Produces: `AbstractEcResource::tiptapButtons(): array` — riusato da Task 2 (`EcTrack`); `EcPoi::getInfoTabFields(): array` ora include un campo `NovaTabTranslatable` che avvolge un `Tiptap` con `attribute === 'properties->description'`

- [ ] **Step 1: Scrivi il test che fallisce**

Apri `wm-package/tests/Unit/EcDescriptionTiptapTest.php` e aggiungi in cima i nuovi import, poi il nuovo metodo di test in coda alla classe:

```php
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Marshmallow\Tiptap\Tiptap;
use Wm\WmPackage\Nova\EcPoi as NovaEcPoi;
```

```php
    public function test_ec_poi_info_tab_exposes_translatable_tiptap_description_field(): void
    {
        $ecPoi = new NovaEcPoi(new EcPoi());

        $translatable = collect($ecPoi->getInfoTabFields())
            ->first(fn ($field) => $field instanceof NovaTabTranslatable);

        $this->assertNotNull($translatable);

        $tiptapField = $translatable->originalFields[0];
        $this->assertInstanceOf(Tiptap::class, $tiptapField);
        $this->assertSame('properties->description', $tiptapField->attribute);
    }
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_ec_poi_info_tab_exposes_translatable_tiptap_description_field`

Expected: FAIL — `$translatable` è `null` (nessun campo `NovaTabTranslatable` in `getInfoTabFields()` di `EcPoi` oggi)

- [ ] **Step 3: Aggiungi il metodo condiviso `tiptapButtons()` in `AbstractEcResource`**

In `wm-package/src/Nova/AbstractEcResource.php`, subito dopo la chiusura del metodo `fields()` (dopo la riga con `Images::make('Image', 'default'),\n        ];\n    }`), aggiungi:

```php
    /**
     * Shared Tiptap toolbar configuration for EC resources (EcPoi, EcTrack).
     *
     * Same button set as PropertiesPanel::tiptapButtons() / App::tiptapButtons(),
     * kept in sync manually (no shared base class between Nova\Fields\PropertiesPanel and Nova resources).
     */
    protected function tiptapButtons(): array
    {
        return [
            'heading',
            '|',
            'bold',
            'italic',
            'underline',
            '|',
            'bulletList',
            'orderedList',
            '|',
            'link',
            'image',
            '|',
            'textAlign',
            '|',
            'horizontalRule',
            '|',
            'editHtml',
        ];
    }
```

- [ ] **Step 4: Aggiungi il campo `description` in `EcPoi::getInfoTabFields()`**

In `wm-package/src/Nova/EcPoi.php`, aggiungi due import dopo `use Laravel\Nova\Tabs\Tab;` (riga 12):

```php
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Marshmallow\Tiptap\Tiptap;
```

Poi modifica `getInfoTabFields()` (righe 71-82) aggiungendo il nuovo campo come primo elemento dell'array restituito:

```php
    public function getInfoTabFields(): array
    {
        return [
            NovaTabTranslatable::make([
                Tiptap::make(__('Description'), 'properties->description')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
            ]),
            Text::make(__('Contact email'), 'properties->contact_email'),
            Text::make(__('Contact phone'), 'properties->contact_phone'),
            Text::make(__('Opening hours'), 'properties->opening_hours'),
            Text::make(__('Locality'), 'properties->addr_locality'),
            Text::make(__('House number'), 'properties->addr_housenumber'),
            KeyValue::make(__('Related URL'), 'properties->related_url'),
            Text::make(__('Complete address'), 'properties->addr_complete'),
        ];
    }
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_ec_poi_info_tab_exposes_translatable_tiptap_description_field`

Expected: PASS

- [ ] **Step 6: Commit**

```bash
git -C wm-package add src/Nova/AbstractEcResource.php src/Nova/EcPoi.php tests/Unit/EcDescriptionTiptapTest.php
git -C wm-package commit -m "feat(oc:8303): add static translatable description field to EcPoi Info tab"
```

---

### Task 2: Campo Descrizione statico su EcTrack

**Files:**
- Modify: `wm-package/src/Nova/EcTrack.php:1-129` — import + campo in `getInfoTabFields()`
- Test: `wm-package/tests/Unit/EcDescriptionTiptapTest.php`

**Interfaces:**
- Consumes: `AbstractEcResource::tiptapButtons(): array` (Task 1) — `EcTrack extends AbstractEcResource`, quindi disponibile come `$this->tiptapButtons()`
- Produces: `EcTrack::getInfoTabFields(): array` ora include un secondo campo `NovaTabTranslatable` (oltre a quello esistente per `not_accessible_message`) che avvolge un `Tiptap` con `attribute === 'properties->description'`

- [ ] **Step 1: Scrivi il test che fallisce**

Aggiungi l'import in cima a `wm-package/tests/Unit/EcDescriptionTiptapTest.php`:

```php
use Wm\WmPackage\Nova\EcTrack as NovaEcTrack;
```

Poi aggiungi il metodo di test:

```php
    public function test_ec_track_info_tab_exposes_translatable_tiptap_description_field(): void
    {
        $ecTrack = new NovaEcTrack(new EcTrack());

        $translatable = collect($ecTrack->getInfoTabFields())
            ->filter(fn ($field) => $field instanceof NovaTabTranslatable)
            ->first(function ($field) {
                return collect($field->originalFields)
                    ->contains(fn ($inner) => $inner->attribute === 'properties->description');
            });

        $this->assertNotNull($translatable);

        $tiptapField = collect($translatable->originalFields)
            ->first(fn ($inner) => $inner->attribute === 'properties->description');
        $this->assertInstanceOf(Tiptap::class, $tiptapField);
    }
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_ec_track_info_tab_exposes_translatable_tiptap_description_field`

Expected: FAIL — nessun campo `NovaTabTranslatable` in `getInfoTabFields()` di `EcTrack` avvolge un campo con `attribute === 'properties->description'` (l'unico presente oggi è `not_accessible_message`)

- [ ] **Step 3: Aggiungi il campo `description` in `EcTrack::getInfoTabFields()`**

In `wm-package/src/Nova/EcTrack.php`, aggiungi l'import dopo `use Laravel\Nova\Fields\Textarea;` (riga 9):

```php
use Marshmallow\Tiptap\Tiptap;
```

(`NovaTabTranslatable` è già importato alla riga 5.)

Poi modifica `getInfoTabFields()` (righe 119-129) aggiungendo il nuovo campo come primo elemento:

```php
    public function getInfoTabFields(): array
    {
        return [
            NovaTabTranslatable::make([
                Tiptap::make(__('Description'), 'properties->description')
                    ->buttons($this->tiptapButtons())
                    ->headingLevels([2, 3, 4]),
            ]),
            Boolean::make('Not Accessible', 'properties->not_accessible')
                ->help('Enable this option to indicate that the track is not accessible. The reason can be specified below.'),
            NovaTabTranslatable::make([
                Textarea::make(__('Not Accessible Message'), 'properties->not_accessible_message'),
            ]),

        ];
    }
```

- [ ] **Step 4: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_ec_track_info_tab_exposes_translatable_tiptap_description_field`

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git -C wm-package add src/Nova/EcTrack.php tests/Unit/EcDescriptionTiptapTest.php
git -C wm-package commit -m "feat(oc:8303): add static translatable description field to EcTrack Info tab"
```

---

### Task 3: Rimozione di `description` dagli schema dinamici e aggiornamento test esistente

**Files:**
- Modify: `wm-package/config/wm-ec-poi-schema.php`
- Modify: `wm-package/config/wm-ec-track-schema.php`
- Modify: `wm-package/tests/Unit/EcDescriptionTiptapTest.php` — sostituisce `test_wm_schemas_expose_description_as_translatable_tiptap_for_track_poi_and_layer`

**Interfaces:**
- Consumes: nessuno (modifica config + test, indipendente da Task 1/2 a livello di codice, ma va eseguito dopo perché il test esistente asserisce lo stato "prima" della rimozione)
- Produces: `config('wm-ec-poi-schema.properties.fields')` → `[]`; `config('wm-ec-track-schema.properties.fields')` → array senza l'entry `description` (restano `excerpt`, `ref`, `from`, `to`, `geohub_id`, `taxonomy_where`); `config('wm-layer-schema.properties.fields')` invariato (contiene ancora `description`)

- [ ] **Step 1: Aggiorna il test esistente perché rifletta il nuovo stato atteso**

In `wm-package/tests/Unit/EcDescriptionTiptapTest.php`, sostituisci il metodo `test_wm_schemas_expose_description_as_translatable_tiptap_for_track_poi_and_layer` con:

```php
    public function test_wm_schemas_no_longer_expose_description_for_track_and_poi_but_still_for_layer(): void
    {
        $trackDesc = collect(config('wm-ec-track-schema.properties.fields'))->firstWhere('name', 'description');
        $poiDesc = collect(config('wm-ec-poi-schema.properties.fields'))->firstWhere('name', 'description');
        $layerDesc = collect(config('wm-layer-schema.properties.fields'))->firstWhere('name', 'description');

        $this->assertNull($trackDesc);
        $this->assertNull($poiDesc);
        $this->assertSame('tiptap', $layerDesc['type']);
        $this->assertTrue($layerDesc['translatable']);
    }
```

- [ ] **Step 2: Esegui il test e verifica che fallisca**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_wm_schemas_no_longer_expose_description_for_track_and_poi_but_still_for_layer`

Expected: FAIL — `$trackDesc` e `$poiDesc` non sono ancora `null` (le config non sono state modificate)

- [ ] **Step 3: Rimuovi `description` da `wm-ec-poi-schema.php`**

Sostituisci l'intero contenuto di `wm-package/config/wm-ec-poi-schema.php` con:

```php
<?php

return [
    'properties' => [
        'label' => [
            'it' => 'Proprietà POI',
            'en' => 'POI Properties',
        ],
        'fields' => [],
    ],
];
```

- [ ] **Step 4: Rimuovi `description` da `wm-ec-track-schema.php`**

In `wm-package/config/wm-ec-track-schema.php`, elimina il primo blocco dell'array `fields` (la entry `description`, righe 10-19 del file attuale):

```php
            [
                'name' => 'description',
                'type' => 'tiptap',
                'required' => false,
                'translatable' => true,
                'label' => [
                    'it' => 'Descrizione',
                    'en' => 'Description',
                ],
            ],
```

Il file risultante deve iniziare l'array `fields` direttamente con la entry `excerpt`:

```php
<?php

return [
    'properties' => [
        'label' => [
            'it' => 'Proprietà Layer',
            'en' => 'Layer Properties',
        ],
        'fields' => [
            [
                'name' => 'excerpt',
                'type' => 'textarea',
                'required' => false,
                'translatable' => true,
                'label' => [
                    'it' => 'Excerpt',
                    'en' => 'Excerpt',
                ],
            ],
            [
                'name' => 'ref',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Ref',
                    'en' => 'Ref',
                ],
            ],
            [
                'name' => 'from',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'da',
                    'en' => 'From',
                ],
            ],
            [
                'name' => 'to',
                'type' => 'text',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'a',
                    'en' => 'To',
                ],
            ],
            [
                'name' => 'geohub_id',
                'type' => 'number',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Geohub ID',
                    'en' => 'Geohub ID',
                ],
                'help' => 'This field is automatically generated',
                'readonly' => true,
            ],
            [
                'name' => 'taxonomy_where',
                'type' => 'json',
                'required' => false,
                'translatable' => false,
                'label' => [
                    'it' => 'Tassonomia Dove',
                    'en' => 'Taxonomy Where',
                ],
            ],
        ],
    ],
];
```

- [ ] **Step 5: Esegui il test e verifica che passi**

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_wm_schemas_no_longer_expose_description_for_track_and_poi_but_still_for_layer`

Expected: PASS

- [ ] **Step 6: Verifica che il pannello dinamico "Proprietà POI" non generi errori con schema a fields vuoto**

Aggiungi questo test in `wm-package/tests/Unit/EcDescriptionTiptapTest.php`:

```php
    public function test_properties_panel_does_not_error_when_ec_poi_schema_has_no_fields(): void
    {
        $app = AppModel::factory()->createQuietly();
        $geojson = json_encode([
            'type' => 'Point',
            'coordinates' => [9.0, 40.0, 10.0],
        ]);

        $poi = EcPoi::query()->createQuietly([
            'app_id' => $app->id,
            'name' => ['it' => 'POI test panel vuoto', 'en' => 'POI test empty panel'],
            'geometry' => DB::raw("ST_GeomFromGeoJSON('{$geojson}')"),
            'properties' => ['contact_email' => 'test@example.com'],
        ]);

        $panel = PropertiesPanel::makeWithModel(__('Properties'), 'properties', $poi, true);

        $this->assertIsArray($panel->fields);
    }
```

Run: `docker exec laravel-camminiditalia php artisan test --filter=test_properties_panel_does_not_error_when_ec_poi_schema_has_no_fields`

Expected: PASS — il pannello viene creato senza eccezioni; `fields` è un array (vuoto, dato che `wm-ec-poi-schema.php` non definisce più campi)

- [ ] **Step 7: Esegui l'intera suite di test del file per verificare l'assenza di regressioni**

Run: `docker exec laravel-camminiditalia php artisan test --filter=EcDescriptionTiptapTest`

Expected: PASS su tutti i metodi (i round-trip test su `properties->description` restano validi: la rimozione dallo schema Nova non tocca il campo `$translatable` del modello Eloquent)

- [ ] **Step 8: Commit**

```bash
git -C wm-package add config/wm-ec-poi-schema.php config/wm-ec-track-schema.php tests/Unit/EcDescriptionTiptapTest.php
git -C wm-package commit -m "fix(oc:8303): remove description from dynamic EcPoi/EcTrack property schemas"
```

---

## Self-Review

**Copertura overview.md:**
- Campo `description` statico in `EcPoi::getInfoTabFields()` → Task 1
- Campo `description` statico in `EcTrack::getInfoTabFields()` → Task 2
- Rimozione entry `description` da `wm-ec-poi-schema.php`/`wm-ec-track-schema.php` → Task 3, Step 3-4
- Nessuna modifica a `Layer` → verificato: nessun task tocca `Layer.php` o `wm-layer-schema.php`; Task 3 include un assert esplicito che `wm-layer-schema` resta invariato
- Nessuna modifica agli altri campi schema `EcTrack` (`excerpt`, `ref`, `from`, `to`, `geohub_id`, `taxonomy_where`) → verificato: Task 3 Step 4 li riscrive identici, solo `description` rimossa
- Rischio "pannello orfano dopo rimozione schema" → Task 3, Step 6 (test dedicato)
- Rischio "doppio editor se la rimozione viene dimenticata" → mitigato per costruzione: Task 3 è nello stesso plan e non opzionale, con test che fallisce (Step 2) finché la rimozione non è fatta

**Placeholder scan:** nessun "TBD"/"handle edge cases" generico; ogni step ha codice completo e comando di verifica eseguibile.

**Coerenza tipi/nomi:** `tiptapButtons()` definito in `AbstractEcResource` (Task 1, Step 3) e riusato identico via `$this->tiptapButtons()` in `EcPoi` (Task 1, Step 4) ed `EcTrack` (Task 2, Step 3) — stessa firma `protected function tiptapButtons(): array`. Attributo `properties->description` usato in modo consistente in tutti i test e i field.
