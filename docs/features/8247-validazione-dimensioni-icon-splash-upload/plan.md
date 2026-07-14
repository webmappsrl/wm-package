> Ticket: oc:8247

# Piano implementativo — Validazione dimensioni minime icon/splash screen

Riferimento: `docs/features/8247-validazione-dimensioni-icon-splash-upload/overview.md` (approvato).

Tutti i commit usano lo scope `oc:8247`. I comandi `git commit`/`git push` elencati sotto sono istruzioni testuali per il developer — non vengono eseguiti automaticamente durante l'esecuzione del piano.

---

## Step 1 — `singleFile()` sulle collection media

**File:** `wm-package/src/Models/App.php`, metodo `registerMediaCollections()` (righe 569-576)

```php
public function registerMediaCollections(): void
{
    $this->addMediaCollection('icon')->singleFile();
    $this->addMediaCollection('icon_small')->singleFile();
    $this->addMediaCollection('splash')->singleFile();
    $this->addMediaCollection('my_paths');
    $this->addMediaCollection('my_downloads');
}
```

`my_paths`/`my_downloads` restano invariate (out of scope, overview §Out of scope).

**Verifica manuale post-modifica:** con `Storage::fake('wmfe')`, caricare due file in sequenza sulla stessa collection (`icon`) e verificare che il secondo sostituisca il primo (`getMedia('icon')->count() === 1`) — comportamento nativo di Spatie `singleFile()`, da confermare in test (Step 4).

**Commit:** `feat(oc:8247): mark icon/icon_small/splash as singleFile media collections`

---

## Step 2 — Messaggi di validazione custom

**File nuovi:**
- `wm-package/resources/lang/it/validation.php`
- `wm-package/resources/lang/en/validation.php`

Le soglie sono hardcoded nel messaggio (Laravel non sostituisce placeholder tipo `:min_width` per la rule `dimensions` — nessun `replaceDimensions` nel replacer, verificato in `vendor/laravel/framework/.../Concerns/ReplacesAttributes.php`).

`wm-package/resources/lang/it/validation.php`:
```php
<?php

return [
    'custom' => [
        'icon' => [
            'dimensions' => "L'icona deve essere almeno 1024×1024px e quadrata (proporzioni 1:1).",
        ],
        'splash' => [
            'dimensions' => 'Lo splash screen deve essere almeno 2732×2732px e quadrato (proporzioni 1:1).',
        ],
    ],
];
```

`wm-package/resources/lang/en/validation.php`:
```php
<?php

return [
    'custom' => [
        'icon' => [
            'dimensions' => 'The icon must be at least 1024×1024px and square (1:1 ratio).',
        ],
        'splash' => [
            'dimensions' => 'The splash screen must be at least 2732×2732px and square (1:1 ratio).',
        ],
    ],
];
```

**File modificato:** `wm-package/src/WmPackageServiceProvider.php` — accanto alla riga esistente `$this->loadJsonTranslationsFrom(__DIR__.'/../resources/lang');` (riga 92), aggiungere:

```php
$this->loadTranslationsFrom(__DIR__.'/../resources/lang');
```

Senza namespace: `loadTranslationsFrom(path, null)` chiama `Translator::addPath()`, che fa il merge (`array_replace_recursive`) del gruppo `validation` del package con quello dell'app consumer — necessario perché il resolver Laravel dei messaggi custom (`FormatsMessages::getMessage`) cerca la chiave `validation.custom.{attribute}.{rule}` nel gruppo di default, non in un namespace.

**Commit:** `feat(oc:8247): add custom validation messages for icon/splash dimensions`

---

## Step 3 — Rule di validazione sui campi Nova

**File:** `wm-package/src/Nova/App.php`, metodo `app_release_data_tab()` (righe ~644-655)

```php
Images::make(__('Icon'), 'icon')
    ->singleMediaRules(['image', 'mimes:png', 'dimensions:min_width=1024,min_height=1024,ratio=1/1'])
    ->help(__('Required size is :widthx:heightpx', ['width' => 1024, 'height' => 1024]))
    ->hideFromIndex(),
Images::make(__('Splash image'), 'splash')
    ->singleMediaRules(['image', 'mimes:png', 'dimensions:min_width=2732,min_height=2732,ratio=1/1'])
    ->help(__('Required size is :widthx:heightpx', ['width' => 2732, 'height' => 2732]))
    ->hideFromIndex(),
Images::make(__('Icon small'), 'icon_small')
    ->help(__('Required size is :widthx:heightpx', ['width' => 512, 'height' => 512]))
    ->hideFromIndex(),
```

**Punti critici da non invertire (vedi overview §Rischi):**
- Usare `->singleMediaRules([...])`, **mai** `->rules([...])` — quest'ultimo valida l'intero array della collection (non un singolo file), e la rule `dimensions` fallisce sempre in quel contesto, bloccando ogni salvataggio del form anche senza toccare icon/splash.
- `icon_small` non riceve `singleMediaRules` (out of scope — nessuna pipeline di build nativa lo consuma).
- Non rimuovere le righe commentate originali finché il nuovo comportamento non è verificato in Step 5 — utile come riferimento diff.

**Commit:** `feat(oc:8247): validate icon and splash minimum dimensions and aspect ratio on upload`

---

## Step 4 — Test automatizzati

**File nuovo:** `wm-package/tests/Feature/Nova/AppIconSplashDimensionsValidationTest.php`

Convenzioni: Pest, `Tests\TestCase` + `DatabaseTransactions` (pattern di `tests/Feature/AppMyPathsMyDownloadsTest.php` e `tests/Feature/Nova/FeatureCollectionUploadTest.php`), `Storage::fake('wmfe')`, `App::factory()->createQuietly()`.

Per invocare la validazione del field Nova senza passare dall'endpoint HTTP completo, estrarre il field `Images` dalla resource (stesso pattern di `featureCollectionFileField()` in `FeatureCollectionUploadTest.php`) e chiamarne `fill()` con una `NovaRequest` costruita con `UploadedFile::fake()->image(...)`.

Casi da coprire:

1. **Icon troppo piccola viene rifiutata** — `UploadedFile::fake()->image('icon.png', 512, 512)` → `fill()` lancia `ValidationException` con messaggio "L'icona deve essere almeno 1024×1024px e quadrata (proporzioni 1:1)."
2. **Icon con ratio non quadrato viene rifiutata** — `UploadedFile::fake()->image('icon.png', 1024, 2048)` → `ValidationException`
3. **Icon valida viene accettata e allegata** — `UploadedFile::fake()->image('icon.png', 1024, 1024)` → nessuna eccezione, `$app->fresh()->getFirstMedia('icon')` non null
4. **Splash troppo piccolo viene rifiutato** — `UploadedFile::fake()->image('splash.png', 1920, 1920)` (soglia storicamente citata nel ticket oc:8246, utile come regressione esplicita) → `ValidationException` con messaggio "Lo splash screen deve essere almeno 2732×2732px..."
5. **Splash valido viene accettato** — `UploadedFile::fake()->image('splash.png', 2732, 2732)`
6. **`icon_small` non applica alcuna validazione dimensionale** — upload di un file 100×100 su `icon_small` non lancia eccezioni
7. **`singleFile()` sostituisce il media precedente** — due upload sequenziali validi su `icon` → `getMedia('icon')->count() === 1` dopo il secondo
8. **Messaggio di errore in inglese** — impostare `App::setLocale('en')` prima del test 1, verificare il messaggio inglese

Se `fill()` sul field non è sufficiente a triggerare la validazione (dipende da come Nova invoca `fillAttributeFromRequest` internamente — verificare durante l'esecuzione se serve invece passare dall'endpoint reale `PUT /nova-api/apps/{id}` con multipart, pattern già presente in `FeatureCollectionUploadTest.php` righe 86-99), adattare l'approccio e documentare la deviazione in `notes.md`.

**Comando:** `docker exec laravel-camminiditalia bash -c "cd wm-package && vendor/bin/pest tests/Feature/Nova/AppIconSplashDimensionsValidationTest.php"`

**Commit:** `test(oc:8247): cover icon/splash dimension validation and singleFile behavior`

---

## Step 5 — Verifica manuale in Nova

1. Aprire il container (`composer run dev` già attivo, container `laravel-camminiditalia` up)
2. Accedere a Nova, aprire una risorsa `App` esistente, tab "App release data"
3. Caricare un PNG 512×512 sul campo Icon → verificare che il salvataggio fallisca con il messaggio custom italiano
4. Caricare un PNG 1024×1024 quadrato → verificare salvataggio riuscito
5. Salvare il form senza toccare icon/splash (campi già valorizzati) → verificare che il salvataggio **non fallisca** (regressione critica identificata in Fase: challenge)
6. Ripetere il caso 3 con lo splash (soglia 2732×2732)

Documentare l'esito in `notes.md`.

---

## Riepilogo file toccati

| File | Tipo | Step |
|---|---|---|
| `wm-package/src/Models/App.php` | modifica | 1 |
| `wm-package/resources/lang/it/validation.php` | nuovo | 2 |
| `wm-package/resources/lang/en/validation.php` | nuovo | 2 |
| `wm-package/src/WmPackageServiceProvider.php` | modifica | 2 |
| `wm-package/src/Nova/App.php` | modifica | 3 |
| `wm-package/tests/Feature/Nova/AppIconSplashDimensionsValidationTest.php` | nuovo | 4 |
