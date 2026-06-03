> Ticket: oc:7749

# Plan — Aggiungere create app

## Repo coinvolto
`wm-package` — tutte le modifiche sono nel package, nessuna modifica al repo principale.

---

## Step 1 — Fix FK in `Models/App.php`

**File:** `src/Models/App.php`

Correggere la relazione `author()` che inferisce `author_id` (inesistente) invece di usare la colonna reale `user_id`.

```php
// prima
public function author(): BelongsTo
{
    return $this->belongsTo(User::class);
}

// dopo
public function author(): BelongsTo
{
    return $this->belongsTo(User::class, 'user_id');
}
```

**Commit:** `fix(oc:7749): fix author() FK to use user_id in App model`

---

## Step 2 — Aggiungere `create()` a `AppPolicy`

**File:** `src/Policies/AppPolicy.php`

Aggiungere il metodo `create()` seguendo il pattern di `TilePolicy` (`$user->hasRole('Administrator')`).

```php
public function create(User $user): bool
{
    return $user->hasRole('Administrator');
}
```

**Commit:** `feat(oc:7749): add create() to AppPolicy — Administrator only`

---

## Step 3 — Registrare `AppPolicy` in `WmPackageServiceProvider`

**File:** `src/WmPackageServiceProvider.php`

Aggiungere la registrazione esplicita della policy nel blocco "Register policies" esistente (righe 112-121). Aggiungere i due `use` mancanti.

Import da aggiungere:
```php
use Illuminate\Support\Facades\Gate;
use Wm\WmPackage\Models\App as AppModel;
use Wm\WmPackage\Policies\AppPolicy;
```

Nel corpo di `boot()` dopo il commento "Register policies":
```php
Gate::policy(AppModel::class, AppPolicy::class);
```

**Commit:** `feat(oc:7749): register AppPolicy in WmPackageServiceProvider`

---

## Step 4 — `fieldsForCreate()` in `Nova/App.php`

**File:** `src/Nova/App.php`

Aggiungere il metodo `fieldsForCreate()` con i soli campi obbligatori. Aggiungere i due `use` mancanti in cima al file.

Import da aggiungere:
```php
use Laravel\Nova\Fields\BelongsTo;
use Illuminate\Validation\Rule;
use App\Nova\User as NovaUser;
```

Metodo da aggiungere dopo `fields()`:
```php
public function fieldsForCreate(NovaRequest $request): array
{
    return [
        Text::make('Name')
            ->rules('required'),
        Text::make(__('Customer Name'), 'customer_name')
            ->rules('required')
            ->help(__('Name of the customer or organization that owns this app.')),
        Text::make(__('Sku'), 'sku')
            ->rules('required', 'unique:apps,sku')
            ->help(__('Unique app identifier on the stores (App Store and Play Store).')),
        BelongsTo::make(__('Author'), 'author', NovaUser::class)
            ->nullable()
            ->searchable()
            ->help(__('User responsible for this app. Leave empty if not applicable.')),
    ];
}
```

> **Nota futura (follow-up):** valutare se pre-popolare `customer_name` con il valore di `name` per ridurre la digitazione ridondante.

**Commit:** `feat(oc:7749): add fieldsForCreate() to Nova App resource`

---

## Step 5 — Validazione unique su `sku` in edit

**File:** `src/Nova/App.php`

Nel metodo `app_release_data_tab()` (riga ~559), il campo `sku` esiste già. Aggiungere `updateRules` per validare l'unicità ignorando il record corrente.

```php
// prima
Text::make(__('Sku'), 'sku')
    ->required()
    ->help(__('App name on the stores (App Store and Playstore).')),

// dopo
Text::make(__('Sku'), 'sku')
    ->required()
    ->updateRules(Rule::unique('apps', 'sku')->ignore($this->resource->id ?? null))
    ->help(__('App name on the stores (App Store and Playstore).')),
```

**Commit:** `feat(oc:7749): add unique updateRules to sku field in Nova App`

---

## Step 6 — Default difensivi sui campi Nova in edit (da oc:7757)

**File:** `src/Nova/App.php`

Aggiungere `->default(...)` ai tre campi NOT NULL che in edit potrebbero essere salvati come `null` se svuotati dall'utente. Chiude le fix temporanee documentate in oc:7757.

**`default_language`** — metodo `languages_tab()` (~riga 468):
```php
// prima
Select::make(__('Default Language'), 'default_language')
    ->hideFromIndex()
    ->options($languages)
    ->displayUsingLabels()
    ->help(__('This is the default language displayed by the app.')),

// dopo
Select::make(__('Default Language'), 'default_language')
    ->hideFromIndex()
    ->options($languages)
    ->displayUsingLabels()
    ->default('it')
    ->rules('required')
    ->help(__('This is the default language displayed by the app.')),
```

**`start_end_icons_min_zoom`** — metodo `map_tab()` (~riga 1102):
```php
// prima
Number::make(__('start_end_icons_min_zoom'))
    ->min(10)->max(20)
    ->hideFromIndex()
    ->help(__('...')),

// dopo
Number::make(__('start_end_icons_min_zoom'))
    ->min(10)->max(20)
    ->default(10)
    ->rules('required')
    ->hideFromIndex()
    ->help(__('...')),
```

**`ref_on_track_min_zoom`** — metodo `map_tab()` (~riga 1110):
```php
// prima
Number::make(__('ref_on_track_min_zoom'))
    ->min(10)->max(20)

// dopo
Number::make(__('ref_on_track_min_zoom'))
    ->min(10)->max(20)
    ->default(10)
    ->rules('required')
```

**Commit:** `fix(oc:7749): add defaults and required rules to default_language, start_end_icons_min_zoom, ref_on_track_min_zoom`

---

## Checklist pre-PR

- [ ] Step 1: FK `author()` corretta
- [ ] Step 2: `AppPolicy::create()` aggiunto
- [ ] Step 3: Policy registrata in `WmPackageServiceProvider`
- [ ] Step 4: `fieldsForCreate()` aggiunto con 4 campi
- [ ] Step 5: `->updateRules(unique...)` su `sku` in edit
- [ ] Step 6: `->default()` e `->rules('required')` su `default_language`, `start_end_icons_min_zoom`, `ref_on_track_min_zoom`
- [ ] Test manuale: login come Administrator → compare pulsante "Create App" → form mostra solo i 4 campi → salvataggio senza errori NOT NULL
- [ ] Test manuale: login come non-Administrator → pulsante "Create App" non compare
- [ ] Test manuale: inserire sku duplicato → messaggio di validazione (non errore 500)
