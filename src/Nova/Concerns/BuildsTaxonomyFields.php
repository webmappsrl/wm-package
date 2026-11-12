<?php

namespace Wm\WmPackage\Nova\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Tabs\Tab;
use Laravel\Nova\Tabs\TabsGroup;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\Nova\Fields\Choice\Choice;
use Wm\WmPackage\Services\IconSvgService;

/**
 * Costruisce i campi delle tassonomie di classificazione (oc:8910).
 *
 * Lo usano due Resource con scopi diversi: quella di un contenuto, dove i termini scelti
 * dicono com\'è fatto, e quella di un Layer, dove dicono quali contenuti farvi entrare. Le
 * differenze fra i due usi passano da `taxonomyFieldsAreFilters()`: in modalità filtro ogni
 * campo è a scelta multipla — su un layer si filtra per «sentieri o cammini» anche se il tipo
 * percorso è a scelta singola — e le dipendenze fra tassonomie non si applicano, perché un
 * layer può filtrare per difficoltà senza dichiarare l\'attività corrispondente.
 */
trait BuildsTaxonomyFields
{
    /**
     * I campi delle tassonomie scelgono i contenuti (Layer) invece di descriverli (EC).
     */
    protected function taxonomyFieldsAreFilters(): bool
    {
        return false;
    }

    /**
     * La relazione su cui si scrivono i termini scelti.
     */
    protected function taxonomyTermsRelation(): string
    {
        return 'taxonomyTerms';
    }

    /**
     * La sezione delle classificazioni, con un tab per ogni gruppo dichiarato dai dati (oc:8910).
     *
     * La struttura non la scrive nessuno shard: i tab nascono da `properties->tab` dei
     * tassonomie, nell'ordine in cui le tassonomie compaiono (`sort_order`, poi `id`). Un
     * tassonomia nuova entra nel suo tab da sé, e spostarla è una modifica alla riga.
     *
     * Uno shard che deve aggiungere campi propri a un tab — punteggi, valori calcolati —
     * ridefinisce `taxonomyTabExtraFields()`.
     *
     * L'attributo distingue questo gruppo dagli altri della pagina: senza, Nova sovrapporrebbe
     * due `Tab::group` nella stessa scheda.
     */
    /**
     * La chiave della sezione, con cui uno shard la riconosce fra i campi ereditati.
     *
     * Serve a chi ricompone `fields()` scartando i `TabsGroup` del package per rimetterli a modo
     * suo: senza un modo di distinguerla, la sezione sparirebbe dalla scheda. Metodo e non
     * costante perché il PHP minimo del package è 8.1, dove le costanti nei trait non esistono.
     */
    public static function taxonomySectionKey(): string
    {
        return 'taxonomies';
    }

    /**
     * I campi ereditati senza i `TabsGroup` del package, ma con la sezione delle tassonomie.
     *
     * Serve a uno shard che sovrascrive `fields()` per ricomporre le sezioni a modo suo: il
     * filtro ovvio — scartare ogni `TabsGroup` — porta via anche le tassonomie, che il package
     * mette già nel punto giusto. Qui la sezione resta dov'è, le altre se ne vanno.
     *
     * @param  array<int, mixed>  $fields
     * @return array<int, mixed>
     */
    public function keepOnlyTaxonomySection(array $fields): array
    {
        return array_values(array_filter(
            $fields,
            fn ($field) => ! ($field instanceof TabsGroup)
                || ($field->attribute ?? null) === self::taxonomySectionKey()
        ));
    }

    /**
     * La sezione, come elemento di un array di campi: vuota se non c'è niente da mostrare.
     *
     * È la forma con cui la compongono le Resource EC del package, dove uno shard può non avere
     * nessuna tassonomia accesa: un `Tab::group` senza tab lascerebbe in pagina un'intestazione
     * che non apre niente.
     *
     * @return array<int, TabsGroup>
     */
    public function taxonomySectionFields(string $label = 'Taxonomies', string $default = 'General'): array
    {
        return $this->taxonomyTabs($default) === [] ? [] : [$this->makeTaxonomySection($label, $default)];
    }

    public function makeTaxonomySection(string $label = 'Taxonomies', string $default = 'General'): TabsGroup
    {
        return Tab::group(__($label), $this->taxonomyTabs($default), self::taxonomySectionKey());
    }

    /**
     * I tab della sezione: uno per ogni `properties->tab` dichiarato dalle tassonomie, più
     * quelli che lo shard aggiunge con `taxonomyTabExtraFields()`.
     *
     * @return array<int, Tab>
     */
    protected function taxonomyTabs(string $default = 'General'): array
    {
        $byTab = $this->makeTermFieldsByTab($default);

        foreach ($this->taxonomyTabExtraFields() as $tab => $extra) {
            $byTab[$tab] = [...($byTab[$tab] ?? []), ...$extra];
        }

        foreach ($this->makeComputedWheresField($default) as $tab => $extra) {
            $byTab[$tab] = [...($byTab[$tab] ?? []), ...$extra];
        }

        // Il tab di default per primo: è quello in cui finisce una tassonomia che non dice
        // niente, e chi apre la scheda si aspetta di trovarlo davanti. Gli altri seguono
        // nell'ordine in cui i loro tassonomie compaiono.
        $order = array_unique([$default, ...array_keys($byTab)]);

        $tabs = [];
        // La regola si vede dai campi, ma non si indovina: va scritta.
        if ($this->taxonomyFieldsAreFilters() && $byTab !== []) {
            // Nel tab che si apre per primo, che è quello di default, non il primo per
            // ordine di inserimento.
            $first = isset($byTab[$default]) ? $default : array_key_first($byTab);
            array_unshift($byTab[$first], Heading::make(
                '<p class="text-sm">'.e(__('A content enters this layer when it matches every filter set here; within a single filter, one of the chosen values is enough.')).'</p>'
            )->asHtml());
        }

        foreach ($order as $tab) {
            if (($byTab[$tab] ?? []) !== []) {
                $tabs[] = Tab::make(__($tab), $byTab[$tab]);
            }
        }

        return $tabs;
    }

    /**
     * Campi che uno shard aggiunge a un tab delle classificazioni, oltre alle tassonomie.
     *
     * La chiave è la stessa che le tassonomie scrivono in `properties->tab`. Un tab nominato qui
     * e da nessuna tassonomia esiste lo stesso: è il modo per avere un tab di soli campi propri.
     *
     * @return array<string, array<int, Field>>
     */
    protected function taxonomyTabExtraFields(): array
    {
        return [];
    }

    /**
     * Le zone del contenuto, in sola lettura (oc:8910).
     *
     * Sono una tassonomia come le altre e si leggono insieme alle altre, ma nessuno le sceglie:
     * le calcola `SyncModelTaxonomyWhereJob` dalla geometria. Su un layer le stesse zone sono
     * invece una scelta, e ci arrivano come tassonomia in tabella propria.
     *
     * Si leggono da `properties->taxonomy_where`, non dalla relazione: è lì che il calcolo le
     * scrive, con il nome già tradotto e la sorgente da cui vengono. La relazione
     * `taxonomy_whereables` per un contenuto resta vuota.
     *
     * Non compare dove le tassonomie sono filtri (i layer).
     *
     * @return array<string, array<int, Field>>
     */
    protected function makeComputedWheresField(string $default = 'General'): array
    {
        if ($this->taxonomyFieldsAreFilters()) {
            return [];
        }

        return [
            $default => [
                Text::make(__('Zones'), 'computed_taxonomy_wheres', fn () => self::wheresFromPropertiesAsHtml($this->resource))
                    ->asHtml()
                    ->exceptOnForms()
                    ->hideFromIndex()
                    ->help(__('Calculated from the geometry: they are not set by hand.')),
            ],
        ];
    }

    /**
     * I nomi delle zone scritte in `properties->taxonomy_where`, nella lingua corrente.
     *
     * Ogni voce è una mappa lingua => nome con in più `_source`, la sorgente da cui la zona
     * viene. Una voce senza nome nella lingua corrente ripiega sulla prima non vuota, come
     * fanno le altre tassonomie.
     */
    private static function wheresFromPropertiesAsHtml(mixed $resource): string
    {
        if (! $resource || ! $resource->exists) {
            return '—';
        }

        $entries = data_get($resource->properties, 'taxonomy_where');
        if (! is_array($entries) || $entries === []) {
            return '—';
        }

        $locale = app()->getLocale();
        $parts = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $parts[] = $entry;

                continue;
            }
            if (! is_array($entry)) {
                continue;
            }

            $name = $entry[$locale] ?? null;
            if (! is_string($name) || $name === '') {
                $name = collect($entry)->except('_source')->filter(fn ($v) => is_string($v) && $v !== '')->first();
            }
            if (is_string($name) && $name !== '') {
                $parts[] = $name;
            }
        }

        if ($parts === []) {
            return '—';
        }

        return implode('', array_map(
            fn (string $name) => '<span style="display:inline-flex;align-items:center;margin-right:.9em">'.e($name).'</span>',
            $parts
        ));
    }

    /**
     * I campi delle tassonomie, raggruppati per il tab in cui ciascuno dice di voler stare
     * (oc:8910).
     *
     * Il tab si dichiara nei dati, in `properties->tab` della tassonomia: una stringa che è anche
     * la chiave di traduzione dell'intestazione («Inclusivity», «Classifications»). Senza quella
     * chiave la tassonomia finisce nel tab di default. Così nessuno shard tiene un elenco di
     * identifier nel codice per decidere dove va cosa, e una tassonomia nuova si colloca da sé.
     *
     * Torna una mappa chiave-tab => campi, nell'ordine in cui le tassonomie compaiono: lo shard
     * costruisce i Tab e può aggiungere i propri campi dove vuole.
     *
     * @return array<string, array<int, Field>>
     */
    public function makeTermFieldsByTab(string $default = 'General'): array
    {
        // Su un Layer non si guarda a quale contenuto si applica una tassonomia: lì sono filtri
        // che scelgono i contenuti, e un layer può raccogliere sia tracce sia POI.
        $query = TaxonomyTerm::query()->taxonomies();
        $query = $this->taxonomyFieldsAreFilters()
            ? $query->forApp($this->resource->app_id ?? null)
            : $query->forAppAndModel($this->resource->app_id ?? null, class_basename($this->resource));

        $taxonomies = $query->orderBy('sort_order')->orderBy('id')->get();

        $byTab = [];
        foreach ($taxonomies as $taxonomy) {
            $tab = data_get($taxonomy->properties, 'tab');
            $tab = is_string($tab) && $tab !== '' ? $tab : $default;
            $byTab[$tab][] = $taxonomy->identifier;
        }

        $fields = array_map(fn (array $identifiers) => $this->makeTermFieldsForTaxonomies($identifiers), $byTab);

        // Le tassonomie che vivono ancora in tabelle proprie si presentano come le tassonomie, e
        // vanno in cima al loro tab: le Attività aprono il tab «Attività», e le scale di
        // difficoltà che dipendono da loro seguono.
        foreach ($this->makeLegacyTaxonomyFieldsByTab($default) as $tab => $legacy) {
            $fields[$tab] = [...$legacy, ...($fields[$tab] ?? [])];
        }

        return $fields;
    }

    /**
     * I campi delle tassonomie storiche, raggruppati per tab (oc:8910).
     *
     * Le descrive `config('wm-package.legacy_taxonomies')`: nome, tab e scelta singola, che per
     * una tassonomia sarebbero colonne. Quando una di queste confluirà in `taxonomy_terms`,
     * sparisce la sua voce dalla config e non cambia nient'altro.
     *
     * Sono fuori dalla relazione con l'app: quella relazione vale per le tassonomie, e queste
     * non sono tassonomie finché non lo diventano.
     *
     * @return array<string, array<int, Field>>
     */
    protected function makeLegacyTaxonomyFieldsByTab(string $default = 'General'): array
    {
        $byTab = [];

        foreach ($this->legacyTaxonomies() as $taxonomy) {
            $relation = $taxonomy['relation'] ?? null;
            $model = $taxonomy['model'] ?? null;
            if (! is_string($relation) || ! is_string($model) || ! method_exists($this->resource, $relation)) {
                continue;
            }

            // `method_exists` non dice a quale contenuto si applica una tassonomia storica: le
            // relazioni stanno tutte nel trait `TaxonomyAbleModel`, quindi anche una EcTrack
            // risponde a `taxonomyPoiTypes()` e i Tipi POI comparivano sulle tracce. I modelli
            // ammessi si dichiarano in `models`; senza quella chiave la tassonomia vale per
            // tutti. Su un Layer il vincolo non si applica: lì i campi sono filtri che scelgono
            // i contenuti, e un layer può raccogliere sia tracce sia POI (oc:8932).
            $models = (array) ($taxonomy['models'] ?? []);
            if (! $this->taxonomyFieldsAreFilters() && $models !== [] && ! in_array(class_basename($this->resource), $models, true)) {
                continue;
            }

            $tab = is_string($taxonomy['tab'] ?? null) && $taxonomy['tab'] !== '' ? $taxonomy['tab'] : $default;
            $label = __($taxonomy['label'] ?? $relation);
            $attribute = 'legacy_taxonomy_'.($taxonomy['key'] ?? $relation);
            // In modalità filtro la scelta è sempre multipla, come per le tassonomie del modello
            // unico: su un layer «sentieri o cammini» deve essere possibile anche se la
            // tassonomia, su un contenuto, è a scelta singola.
            $singleChoice = ! $this->taxonomyFieldsAreFilters() && (bool) ($taxonomy['single_choice'] ?? false);

            $rows = self::legacyTaxonomyRows($model, (array) ($taxonomy['hidden'] ?? []));
            $options = [];
            $icons = new IconSvgService;
            foreach ($rows as $row) {
                $option = ['value' => (string) $row->id, 'label' => self::taxonomyLabel($row)];
                $svg = self::iconSvg($icons, $row->icon ?? null);
                if ($svg !== null) {
                    $option['svg'] = $svg;
                }
                $options[] = $option;
            }

            $byTab[$tab][] = Text::make($label, $attribute, fn () => self::relationAsHtml($this->resource, $relation))
                ->asHtml()
                ->onlyOnDetail();

            $byTab[$tab][] = Choice::make($label, $attribute)
                ->options($options)
                ->multiple(! $singleChoice)
                ->searchPlaceholder(__('Search').'…')
                ->onlyOnForms()
                // La colonna va qualificata: la relazione fa un join e `id` sarebbe ambiguo.
                ->resolveUsing(fn ($value, $resource) => $resource->exists
                    ? $resource->{$relation}()->pluck($resource->{$relation}()->getRelated()->getTable().'.id')->implode(',')
                    : '')
                ->fillUsing(function (NovaRequest $request, $model, $attr, $requestAttribute) use ($relation, $options, $singleChoice) {
                    $selected = self::idsFromInput($request->input($requestAttribute));
                    $allowed = array_map(fn (array $o) => (int) $o['value'], $options);
                    $selected = array_values(array_intersect($selected, $allowed));

                    // Come per le tassonomie del modello unico: a scelta singola se ne tiene una
                    // sola, perché il vincolo sta nel campo del browser e non nel database.
                    if ($singleChoice) {
                        $selected = array_slice($selected, 0, 1);
                    }

                    // Dopo il salvataggio: su un contenuto nuovo l'id non esiste ancora.
                    return function () use ($model, $relation, $selected, $allowed) {
                        $current = $model->{$relation}()->pluck($model->{$relation}()->getRelated()->getTable().'.id')
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        // Si tocca solo ciò che il campo offriva: una riga nascosta — i tipi di
                        // percorso fra le attività, per dire — non è stata «deselezionata»
                        // dall'utente, semplicemente non gli è mai stata mostrata, e staccarla
                        // cancellerebbe un dato che nessuno ha chiesto di togliere (oc:8910).
                        $current = array_values(array_intersect($current, $allowed));

                        // Solo le differenze: ciò che resta non si tocca, così il pivot conserva
                        // i suoi dati — per le attività le durate avanti e indietro.
                        $removed = array_values(array_diff($current, $selected));
                        $added = array_values(array_diff($selected, $current));
                        $model->{$relation}()->detach($removed);
                        $model->{$relation}()->attach($added);
                        $model->unsetRelation($relation);

                        if ($removed !== [] || $added !== []) {
                            $this->afterTaxonomyFieldsSaved($model);
                        }
                    };
                });
        }

        return $byTab;
    }

    /**
     * Le tassonomie storiche da mostrare come tassonomie.
     *
     * Di base sono quelle della configurazione del package. Uno shard la ridefinisce quando deve
     * aggiungerne una propria o nascondere righe che in quella tassonomia non ci dovrebbero
     * stare: su Forestas, per esempio, i tipi di percorso finiti fra le attività.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function legacyTaxonomies(): array
    {
        return (array) config('wm-package.legacy_taxonomies', []);
    }

    /**
     * Le righe di una tassonomia storica, lette una volta sola per richiesta e solo nelle colonne
     * che servono a comporre le opzioni (oc:8974).
     *
     * Le costruzioni dei campi di una Resource sono tante: Nova le rifà per ogni voce di un
     * elenco di relazione, perché da lì ricava titolo e avatar della riga. Con `select *` ogni
     * giro rileggeva la tabella intera, geometrie comprese: su uno shard con le Zone popolate
     * (7.930 righe, 104 MB di cui 93 di `geometry`) il menu per associare un contenuto a un layer
     * impiegava due minuti e non si apriva.
     *
     * `icon` si chiede solo dove la colonna esiste: `taxonomy_wheres` non ce l'ha, e nominarla lì
     * è un errore SQL; ometterla su Attività e Tipi POI farebbe sparire le icone.
     *
     * La memoria sta nel container e non in una proprietà statica: così un runtime che tiene vivo
     * il processo fra due richieste non mostra tassonomie vecchie.
     *
     * @param  class-string<Model>  $model
     * @param  array<int, string>  $hidden
     * @return Collection<int, Model>
     */
    private static function legacyTaxonomyRows(string $model, array $hidden): Collection
    {
        // La chiave è una stringa e non una costante: PHP 8.1, che il package ancora supporta,
        // non ammette costanti dentro un trait.
        $store = 'wm-package.legacy-taxonomy-rows';

        $cache = app()->bound($store)
            ? app()->make($store)
            : tap(new \ArrayObject, fn ($fresh) => app()->instance($store, $fresh));

        $key = $model.'|'.implode(',', $hidden);

        if (! isset($cache[$key])) {
            $instance = new $model;
            $columns = ['id', 'name', 'identifier'];

            if (Schema::connection($instance->getConnectionName())->hasColumn($instance->getTable(), 'icon')) {
                $columns[] = 'icon';
            }

            $cache[$key] = $model::query()
                ->whereNotIn('identifier', $hidden)
                ->orderBy('id')
                ->get($columns);
        }

        return $cache[$key];
    }

    /**
     * Il nome di una riga di tassonomia storica, con lo stesso fallback dei termini.
     */
    private static function taxonomyLabel(mixed $row): string
    {
        $name = method_exists($row, 'getTranslation')
            ? $row->getTranslation('name', app()->getLocale(), true)
            : ($row->name ?? '');

        if (($name === '' || $name === null) && method_exists($row, 'getTranslations')) {
            $name = collect($row->getTranslations('name'))->filter()->first();
        }

        return (string) ($name ?: ($row->identifier ?? $row->id));
    }

    /**
     * I termini di una relazione, come HTML con l'icona accanto al nome.
     */
    private static function relationAsHtml(mixed $resource, string $relation): string
    {
        if (! $resource || ! $resource->exists) {
            return '—';
        }

        $icons = new IconSvgService;
        $parts = [];
        foreach ($resource->{$relation} as $row) {
            $svg = self::iconSvg($icons, $row->icon ?? null);
            $parts[] = '<span style="display:inline-flex;align-items:center;margin-right:.9em">'
                .($svg === null ? '' : '<svg viewBox="0 0 1024 1024" fill="currentColor" style="width:1.1em;height:1.1em;margin-right:.35em;flex-shrink:0">'.$svg.'</svg>')
                .e(self::taxonomyLabel($row)).'</span>';
        }

        return $parts === [] ? '—' : implode('', $parts);
    }

    /**
     * I path dell'icona, o null se non c'è o se il file delle icone dello shard non è leggibile.
     */
    private static function iconSvg(IconSvgService $icons, ?string $icon): ?string
    {
        if (! is_string($icon) || $icon === '') {
            return null;
        }

        try {
            $svg = $icons->getSvgByName($icon, wrapSvg: false);
        } catch (\Throwable $e) {
            return null;
        }

        return is_string($svg) && $svg !== '' ? $svg : null;
    }

    public function makeTermFieldsForTaxonomies(array $taxonomyIdentifiers): array
    {
        // Solo le tassonomie accese nell'app di questo contenuto (oc:8910). Un contenuto appena
        // aperto in creazione non ha ancora un'app: lì non si filtra, altrimenti il form
        // nascerebbe senza classificazioni.
        $taxonomies = TaxonomyTerm::query()
            ->taxonomies()
            ->forApp($this->resource->app_id ?? null)
            ->whereIn('identifier', $taxonomyIdentifiers)
            ->with('children')
            ->get()
            ->keyBy('identifier');

        $fields = [];
        foreach ($taxonomyIdentifiers as $identifier) {
            $taxonomy = $taxonomies->get($identifier);
            if (! $taxonomy) {
                continue;
            }

            // children() è già ordinata per sort_order e id.
            $options = $taxonomy->children
                ->mapWithKeys(fn (TaxonomyTerm $term) => [$term->id => self::termLabel($term)])
                ->all();
            $label = self::termLabel($taxonomy);
            $attribute = 'taxonomy_'.str_replace([':', '-'], '_', $identifier);

            // Un controllo solo per tutte le classificazioni, con l'icona del termine accanto al
            // nome (oc:8910). Il campo disegna le opzioni con la loro icona: Select e
            // Multiselect riducono ogni opzione a etichetta e valore e l'icona non ci passa.
            // Gli id vanno come stringhe: la ricerca del campo lavora su stringhe.
            // In modalità filtro la scelta è sempre multipla: su un layer «sentieri o cammini»
            // deve essere possibile anche se il tipo percorso è a scelta singola.
            $field = Choice::make($label, $attribute)
                ->options(self::termOptions($taxonomy))
                ->multiple($this->taxonomyFieldsAreFilters() || ! $taxonomy->single_choice)
                ->searchPlaceholder(__('Search').'…');

            // Sul dettaglio il campo del form mostrerebbe gli id: lì va un testo con l'icona e
            // il nome di ogni termine scelto.
            // Stesso attributo del campo del form, visibilità opposta: non si incontrano mai.
            $fields[] = Text::make($label, $attribute, function () use ($taxonomy) {
                return $this->termsAsHtml($this->resource, $taxonomy);
            })->asHtml()->onlyOnDetail();

            $fields[] = $field
                ->onlyOnForms()
                ->resolveUsing(function ($value, $resource) use ($taxonomy) {
                    // Il campo legge e scrive una stringa di id separati da virgola.
                    $ids = $resource->exists ? $this->chosenTermIds($resource, $taxonomy) : [];

                    return implode(',', $ids);
                })
                ->fillUsing(function (NovaRequest $request, $model, $attr, $requestAttribute) use ($taxonomy, $options) {
                    $selected = $request->input($requestAttribute);
                    if (is_string($selected)) {
                        // Il campo manda "12,13"; un POST a mano può mandare un JSON.
                        $decoded = json_decode($selected, true);
                        $selected = is_array($decoded) ? $decoded : explode(',', $selected);
                    }
                    // Solo id che sono davvero termini di questa tassonomia, e su una tassonomia
                    // a scelta singola uno solo: il campo nel browser lo impedisce già, ma una
                    // richiesta fuori dall'interfaccia no, e la cardinalità non ha un vincolo nel
                    // database. Senza il taglio, «la difficoltà di accessibilità è una sola per
                    // sentiero» — il punto che la call ha fissato — cadrebbe senza che niente lo
                    // segnali: `term()` mostrerebbe il primo per `sort_order` e il secondo grado
                    // resterebbe invisibile in Nova ma presente nell'indice (oc:8910).
                    $singleChoice = ! $this->taxonomyFieldsAreFilters() && $taxonomy->single_choice;

                    $selected = collect((array) $selected)
                        ->filter(fn ($id) => $id !== null && $id !== '' && array_key_exists((int) $id, $options))
                        ->map(fn ($id) => (int) $id)
                        ->unique()
                        ->when($singleChoice, fn ($ids) => $ids->take(1))
                        ->values()
                        ->all();

                    // Dopo il salvataggio: su un contenuto nuovo l'id non esiste ancora.
                    return function () use ($model, $taxonomy, $selected) {
                        $current = $model->{$this->taxonomyTermsRelation()}()
                            ->where('taxonomy_terms.parent_id', $taxonomy->id)
                            ->pluck('taxonomy_terms.id')
                            ->map(fn ($id) => (int) $id)
                            ->all();

                        $removed = array_values(array_diff($current, $selected));
                        $added = array_values(array_diff($selected, $current));
                        $model->{$this->taxonomyTermsRelation()}()->detach($removed);
                        $model->{$this->taxonomyTermsRelation()}()->attach($added);
                        $model->unsetRelation($this->taxonomyTermsRelation());

                        if ($removed !== [] || $added !== []) {
                            $this->afterTaxonomyFieldsSaved($model);
                        }
                    };
                });
        }

        // Le dipendenze descrivono un contenuto («la difficoltà MTB ha senso se fai MTB»): su un
        // filtro non valgono, un layer può cercare una difficoltà senza dichiarare l'attività.
        return $this->taxonomyFieldsAreFilters() ? $fields : self::applyTermDependencies($fields, $taxonomies);
    }

    /**
     * Cosa fare quando la scelta delle tassonomie è cambiata davvero.
     *
     * Sui contenuti non serve niente: gli observer dei pivot svegliano già i layer che filtrano
     * per quei termini. Su un Layer invece i filtri sono appena cambiati e il layer va
     * ripopolato — `LayerObserver` non se ne accorge, perché il modello non ha cambiato colonne.
     */
    protected function afterTaxonomyFieldsSaved(mixed $model): void
    {
        //
    }

    /**
     * Applica le dipendenze fra tassonomie dichiarate nei dati (oc:8910).
     *
     * Una tassonomia può dire, in `properties->depends_on`, che ha senso solo quando un'altra
     * tassonomia ha certi termini scelti:
     *
     *     {"depends_on": {"taxonomy": "route-type", "terms": ["route-type-itinerary"]}}
     *
     * Da lì vengono due cose: il campo si nasconde nel form quando la condizione è falsa, e il
     * salvataggio lo rifiuta — senza la seconda, un POST diretto scriverebbe la relazione lo stesso.
     * La regola sta sulla riga, non nel codice di uno shard.
     *
     * Condizione vera anche quando la tassonomia sorgente non ha ancora nessun termine scelto: in
     * compilazione il campo resta visibile, per non far sparire un dato che si sta inserendo.
     *
     * @param  array<int, Field>  $fields
     * @param  Collection<string, TaxonomyTerm>  $taxonomies
     * @return array<int, Field>
     */
    private static function applyTermDependencies(array $fields, $taxonomies): array
    {
        $attributeOf = fn (string $identifier): string => 'taxonomy_'.str_replace([':', '-'], '_', $identifier);

        foreach ($fields as $field) {
            if (! $field instanceof Choice) {
                continue;
            }

            $taxonomy = $taxonomies->first(fn (TaxonomyTerm $v) => $attributeOf($v->identifier) === $field->attribute);
            $rule = $taxonomy ? data_get($taxonomy->properties, 'depends_on') : null;
            if (! is_array($rule)) {
                continue;
            }

            // La sorgente è un altro tassonomia, oppure una tassonomia che vive in tabella
            // sua: «Difficoltà mountain bike» ha senso solo se fra le attività c'è la MTB.
            if (isset($rule['taxonomy'])) {
                $source = $taxonomies->get($rule['taxonomy']);
                if (! $source) {
                    // Senza la sorgente nello stesso form la dipendenza non è esprimibile: il
                    // campo resta visibile, e la validazione non avrebbe su cosa decidere.
                    continue;
                }
                $sourceAttribute = $attributeOf($source->identifier);
                $sourceLabel = self::termLabel($source);
                $wanted = TaxonomyTerm::query()
                    ->where('parent_id', $source->id)
                    ->whereIn('identifier', (array) ($rule['terms'] ?? []))
                    ->pluck('id')
                    ->all();
            } elseif (isset($rule['legacy'])) {
                $legacy = collect((array) config('wm-package.legacy_taxonomies', []))
                    ->first(fn (array $t) => ($t['key'] ?? null) === $rule['legacy']);
                if (! $legacy || ! is_string($legacy['model'] ?? null)) {
                    continue;
                }
                $sourceAttribute = 'legacy_taxonomy_'.$rule['legacy'];
                $sourceLabel = __($legacy['label'] ?? $rule['legacy']);
                $wanted = $legacy['model']::query()
                    ->whereIn('identifier', (array) ($rule['terms'] ?? []))
                    ->pluck('id')
                    ->all();
            } else {
                continue;
            }

            // Qui finisce ciò che parla di tassonomie: il resto lo sa fare il campo.
            $field->visibleWhen(
                $sourceAttribute,
                $wanted,
                fn () => __(':field does not apply with the current :source.', [
                    'field' => self::termLabel($taxonomy),
                    'source' => $sourceLabel,
                ])
            );
        }

        return $fields;
    }

    /**
     * Gli id scelti in un campo: una Select manda un id, un Multiselect un array o il suo JSON.
     *
     * @return list<int>
     */
    private static function idsFromInput(mixed $value): array
    {
        if (is_string($value)) {
            // Il campo manda "12,13"; un POST a mano può mandare un JSON. Senza lo split
            // "12,13" diventerebbe l'intero 12 e la seconda scelta sparirebbe in silenzio.
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        return collect((array) $value)
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * I termini di quella tassonomia già scelti su questo record.
     *
     * @return Collection<int, TaxonomyTerm>
     */
    private function chosenTerms(mixed $resource, TaxonomyTerm $taxonomy)
    {
        return $resource->{$this->taxonomyTermsRelation()}
            ->filter(fn (TaxonomyTerm $term) => (int) $term->parent_id === (int) $taxonomy->id)
            ->values();
    }

    /**
     * Gli id dei termini di quella tassonomia già scelti su questo record.
     *
     * Si legge dalla relazione e non da `terms()`: il Layer usa gli stessi campi ma non è un
     * contenuto, e quel metodo non ce l'ha.
     *
     * @return list<int>
     */
    private function chosenTermIds(mixed $resource, TaxonomyTerm $taxonomy): array
    {
        return $this->chosenTerms($resource, $taxonomy)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * I termini scelti di una tassonomia, come HTML con l'icona accanto al nome (oc:8910).
     *
     * L'SVG viene dal file delle icone dello shard, non da un dato scritto dall'utente; il nome
     * invece sì, e passa da `e()` perché finisce in una pagina.
     */
    private function termsAsHtml(mixed $resource, TaxonomyTerm $taxonomy): string
    {
        if (! $resource || ! $resource->exists) {
            return '—';
        }

        $icons = new IconSvgService;
        $parts = [];

        foreach ($this->chosenTerms($resource, $taxonomy) as $term) {
            $paths = self::iconSvg($icons, $term->icon);
            $svg = $paths === null
                ? ''
                : '<svg viewBox="0 0 1024 1024" fill="currentColor" style="width:1.1em;height:1.1em;margin-right:.35em;flex-shrink:0">'.$paths.'</svg>';
            $parts[] = '<span style="display:inline-flex;align-items:center;margin-right:.9em">'.$svg.e(self::termLabel($term)).'</span>';
        }

        return $parts === [] ? '—' : implode('', $parts);
    }

    /**
     * Le opzioni di una tassonomia nella forma che il campo si aspetta (oc:8910).
     *
     * `value` è una stringa perché la ricerca del campo la tratta come tale; `svg` sono i path
     * dell'icona del termine, senza il tag che li avvolge, perché il componente li inserisce
     * dentro un suo `<svg>`. Un termine senza icona non porta `svg`: il campo lascia lo spazio.
     *
     * @return array<int, array{value: string, label: string, svg?: string}>
     */
    private static function termOptions(TaxonomyTerm $taxonomy): array
    {
        $icons = new IconSvgService;
        $options = [];

        foreach ($taxonomy->children as $term) {
            $option = [
                'value' => (string) $term->id,
                'label' => self::termLabel($term),
            ];

            // Le icone vengono da un file dello shard: se manca, il termine resta senza.
            $svg = self::iconSvg($icons, $term->icon);
            if ($svg !== null) {
                $option['svg'] = $svg;
            }

            $options[] = $option;
        }

        return $options;
    }

    /**
     * Nome del termine nella lingua corrente; se manca, la prima traduzione disponibile e, in
     * ultimo, l'identifier: un'opzione senza etichetta non si può scegliere.
     */
    private static function termLabel(TaxonomyTerm $term): string
    {
        $name = $term->getTranslation('name', app()->getLocale(), true);
        if ($name === '' || $name === null) {
            $name = collect($term->getTranslations('name'))->filter()->first();
        }

        return (string) ($name ?: $term->identifier);
    }
}
