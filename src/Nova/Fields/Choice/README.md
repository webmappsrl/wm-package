# choice — la select di opzioni con icone

Componente Nova di `Wm\WmPackage\Nova\Fields\Choice`: una select, singola o multipla, che
disegna l'icona accanto all'etichetta e può nascondersi in base a un altro campo.

Nasce come copia del componente di `IconSelect`, con due aggiunte che lì mancavano:

- **annuncio del cambio di valore** (`emitFieldValueChange`): senza, i campi dichiarati con
  `dependsOn()` non si aggiornano mai, perché Nova ricostruisce i dipendenti solo quando il
  campo sorgente comunica il nuovo valore;
- **segnaposto al posto dell'icona mancante**: dentro uno stesso elenco convivono opzioni con e
  senza icona, e senza segnaposto le righe non stanno in colonna.

## Compilare

Serve `laravel-nova-devtool`, che sta nel repository privato di Nova: le credenziali sono quelle
di `auth.json` nella root dello shard, e Composer le prende da `COMPOSER_AUTH`. Il `composer.json`
qui vincola `laravel/nova` a `5.7.6`: la licenza non copre le release più recenti, e senza vincolo
l'installazione muore con un HTTP 402. Si lancia nel container, che ha PHP 8.4 — sull'host, a 7.4,
`nova-devtool` non è nemmeno installabile.

```bash
cd src/Nova/Fields/Choice
composer install        # porta laravel/nova-devtool in vendor/
npm install
npm run prod            # scrive dist/
```

Due trappole del repo, già applicate qui e da non perdere:

- `externals: { vue: 'Vue' }` nel `webpack.mix.js`, altrimenti il bundle include una seconda
  copia di Vue e il campo non renderizza — nessun errore, il tab appare vuoto (oc:7546);
- `webpack` pinnato **esatto** a `5.103.0`: la 5.111 non compila, perché `laravel-mix@6` cerca
  `webpack/lib/SizeFormatHelpers`, rimosso in mezzo (oc:8568).

## Com'è messo insieme

Tutto il campo sta in questa cartella: `Choice.php`, `FieldServiceProvider.php`, i sorgenti Vue e
il `dist/`, che è versionato come per gli altri campi. Cartella e namespace combaciano
(`Wm\WmPackage\Nova\Fields\Choice`), quindi la regola PSR-4 di base del package basta da sola e
in `composer.json` non c'è nessuna voce dedicata — come per `TrackColor`.

Il provider è registrato in `src/WmPackageServiceProvider.php` e `Choice::$component` vale
`choice`. Lo usa anche `IconSelect`, che estende `Choice` e non dichiara un componente suo: il
vecchio bundle `icon-select` resta compilato ma non è più quello che disegna i campi.

## Il selettore nei filtri (oc:8988)

Il selettore del campo (ricerca, chip, dropdown con le icone) sta in `ChoicePicker.vue`, che usano
sia `FormField.vue` sia `FilterField.vue`, il componente `choice-filter` dei filtri Nova costruiti
su `Wm\WmPackage\Nova\Filters\ChoiceFilter`. Nel menu dei filtri il dropdown sta nel flusso
invece che sovrapposto: il menu scorre ed è basso, e un dropdown sovrapposto resterebbe tagliato.

I filtri per i termini di una tassonomia, uno per tassonomia, in `filters()` di una Resource:

```php
use Wm\WmPackage\Nova\Filters\TaxonomyTermFilter;

public function filters(NovaRequest $request): array
{
    return [
        new TaxonomyTermFilter('route-type', multiple: true),  // valori in OR
        new TaxonomyTermFilter('validation-status'),            // scelta singola
    ];
}
```

Un filtro con opzioni proprie, che non vengono da `taxonomy_terms`, estende `ChoiceFilter`:

```php
use Illuminate\Contracts\Database\Eloquent\Builder;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Filters\ChoiceFilter;

class RegionFilter extends ChoiceFilter
{
    public function __construct()
    {
        $this->multiple()->searchPlaceholder(__('Search'));
    }

    protected function choiceOptions(NovaRequest $request): array
    {
        // {value, label, svg?}: un value intero va bene, ChoiceFilter lo converte in stringa.
        return Region::query()->orderBy('name')->get()
            ->map(fn (Region $region) => ['value' => $region->id, 'label' => $region->name])
            ->all();
    }

    protected function applyChoice(NovaRequest $request, Builder $query, string|array $value): Builder
    {
        // In multiple arriva un array di stringhe, mai vuoto.
        return $query->whereIn('region_id', array_map('intval', $value));
    }
}
```

La chiave del filtro resta quella di Nova, il nome della classe, a meno che la sottoclasse non la
ridefinisca: `TaxonomyTermFilter` la deriva dall'identifier, perché più istanze sulla stessa
Resource devono restare distinte.
