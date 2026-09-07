> Ticket: oc:8343

# Avatar: usare conversione media quadrata invece di forzare il CSS — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Far sì che `User::avatar_url` (wm-package) punti a un crop quadrato 150×150 generato in modo sincrono, invece dell'immagine originale non ritagliata, sia per gli avatar caricati manualmente sia per quelli scaricati da Gravatar — senza toccare Nova, wm-core o il repo principale camminiditalia.

**Architecture:** Una singola conversion Spatie Media Library (`Fit::Crop`, sincrona) registrata sulla collection `avatar` di `User`; l'accessor `avatar_url` la usa quando disponibile con fallback all'originale. `FetchGravatarAvatarJob` viene aggiornato per richiedere una fonte Gravatar sufficientemente grande (300px) e per salvare il file temporaneo con l'estensione corretta desunta dal `Content-Type` reale della risposta HTTP, invece di un'estensione `.jpg` hardcoded.

**Tech Stack:** Laravel, Spatie Media Library v11 (`Spatie\Image\Enums\Fit`), Symfony Mime (`Symfony\Component\Mime\MimeTypes`, già presente in vendor), Pest (test), Docker (`laravel-camminiditalia`).

## Global Constraints

- Unico repo da modificare: `wm-package` (submodule). Nessuna modifica al repo principale camminiditalia né a wm-core.
- Conversion self-contained su `User`, **non** riusare `MediaService::getMediaConversionNameByWidthAndHeight()`/`thumbnail_sizes` (dominio diverso: gallerie EcTrack/EcPoi).
- Generazione della conversion **sincrona** (`->nonQueued()`), non in coda — decisione presa dopo challenge adversariale (rischio Horizon/Redis noto in questo progetto + rischio caching client offline-first).
- Dimensione crop: **150×150** (valore deciso esplicitamente nello scrum di origine, già presente come esempio in `config('wm-package.services.image.thumbnail_sizes')` ma non riusato direttamente).
- Nessuna validazione dimensione minima upload, nessun face-detection, nessun backfill per avatar esistenti — tutti esplicitamente out of scope (vedi `overview.md`).
- Tutti i comandi di test vanno eseguiti dentro il container Docker: `docker exec laravel-camminiditalia php artisan test <path>`.
- Ogni comando `php artisan test` in questo piano specifica un path esplicito di file — la configurazione `phpunit.xml` di camminiditalia non include `wm-package/tests/` nelle sue testsuite di default, ma un path esplicito passato a `php artisan test` viene eseguito comunque (pattern già in uso in questo progetto per i test di wm-package).
- Commit convention: `fix(oc:8343): <descrizione>` — i comandi `git commit` in questo piano sono istruzioni testuali per il dev, **non vanno eseguiti automaticamente** durante l'implementazione.

---

### Task 1: Conversion quadrata sincrona + accessor `avatar_url` su `User`

**Files:**
- Modify: `wm-package/src/Models/User.php`
- Test: `wm-package/tests/Feature/UserAvatarMediaTest.php`

**Interfaces:**
- Produces: `User::AVATAR_CONVERSION_NAME` (string, `'avatar_150_150'` — nome derivato da `AVATAR_CONVERSION_SIZE`, stessa convenzione `{prefix}_{width}_{height}` di `MediaService::getMediaConversionNameByWidthAndHeight()` usata per le gallerie EcTrack/EcPoi, senza dipendere da quella classe), `User::AVATAR_CONVERSION_SIZE` (int, `150`), `User::registerMediaConversions(?Media $media = null): void`, `User::getAvatarUrlAttribute(): ?string` (comportamento aggiornato) — usati da Task 3 (`FetchGravatarAvatarJob`) per la dimensione richiesta a Gravatar.

- [ ] **Step 1: Scrivi i test che falliscono**

Apri `wm-package/tests/Feature/UserAvatarMediaTest.php` e aggiungi in coda (dopo l'ultimo `it(...)` esistente), aggiungendo anche l'import mancante in testa al file:

```php
use Illuminate\Support\Facades\Storage;
```

(già presente — nessuna modifica agli import necessaria per questo file, `UploadedFile` e `Storage` sono già importati).

Aggiungi questi due test:

```php
it('generates a 150x150 square avatar conversion on upload', function () {
    Storage::fake('wmfe');
    $user = makeUserForMedia();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 300))
        ->toMediaCollection('avatar');

    $media = $user->fresh()->getFirstMedia('avatar');
    expect($media->hasGeneratedConversion(User::AVATAR_CONVERSION_NAME))->toBeTrue();

    [$width, $height] = getimagesize($media->getPath(User::AVATAR_CONVERSION_NAME));
    expect($width)->toBe(User::AVATAR_CONVERSION_SIZE)
        ->and($height)->toBe(User::AVATAR_CONVERSION_SIZE);
});

it('exposes avatar_url pointing at the square conversion, not the original', function () {
    Storage::fake('wmfe');
    $user = makeUserForMedia();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 300))
        ->toMediaCollection('avatar');

    $fresh = $user->fresh();
    $media = $fresh->getFirstMedia('avatar');

    expect($fresh->avatar_url)->toBe($media->getUrl(User::AVATAR_CONVERSION_NAME))
        ->and($fresh->avatar_url)->not->toBe($media->getUrl());
});

it('falls back to the original media URL when the square conversion has not been generated', function () {
    Storage::fake('wmfe');
    $user = makeUserForMedia();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 300))
        ->toMediaCollection('avatar');

    // Simula una conversion non generata (es. motore immagine ha fallito su un
    // formato non supportato) senza dover produrre un file davvero corrotto:
    // azzerare direttamente il flag che hasGeneratedConversion() legge.
    $media = $user->fresh()->getFirstMedia('avatar');
    $media->generated_conversions = [];
    $media->save();

    expect($user->fresh()->avatar_url)->toBe($media->fresh()->getUrl());
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/UserAvatarMediaTest.php`
Expected: FAIL — `User::AVATAR_CONVERSION_NAME` e `User::AVATAR_CONVERSION_SIZE` non esistono ancora (errore "Undefined constant"), oppure `hasGeneratedConversion()` ritorna `false` (nessuna conversion registrata).

- [ ] **Step 3: Implementa il fix minimo in `User.php`**

In `wm-package/src/Models/User.php`, aggiungi l'import subito dopo gli altri `use` di Spatie (riga 16-17 attuali):

```php
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
```

Aggiungi due costanti pubbliche nella classe `User`, subito dopo la dichiarazione di `$guard_name` (riga 49 attuale):

```php
public const AVATAR_CONVERSION_SIZE = 150;

// Naming coerente con MediaService::getMediaConversionNameByWidthAndHeight()
// (usato per le gallerie EcTrack/EcPoi: "thumbnail_{width}_{height}") — stessa
// convenzione, dimensione nel nome, senza però dipendere da quella classe.
public const AVATAR_CONVERSION_NAME = 'avatar_'.self::AVATAR_CONVERSION_SIZE.'_'.self::AVATAR_CONVERSION_SIZE;
```

Sostituisci il blocco `registerMediaCollections()`/`getAvatarUrlAttribute()` attuale (righe 275-283):

```php
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->getFirstMediaUrl('avatar') ?: null;
    }
```

con:

```php
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('avatar')->singleFile();
    }

    /**
     * Crop quadrato per rendere l'avatar visivamente coerente (rotondo via CSS)
     * indipendentemente dall'aspect ratio della foto originale. Sincrono
     * (nonQueued): l'avatar deve essere corretto già nella risposta HTTP che
     * segue l'upload, senza dipendere dal worker Horizon.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this
            ->addMediaConversion(self::AVATAR_CONVERSION_NAME)
            ->fit(Fit::Crop, self::AVATAR_CONVERSION_SIZE, self::AVATAR_CONVERSION_SIZE)
            ->nonQueued();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        $media = $this->getFirstMedia('avatar');

        if (! $media) {
            return null;
        }

        if ($media->hasGeneratedConversion(self::AVATAR_CONVERSION_NAME)) {
            return $media->getUrl(self::AVATAR_CONVERSION_NAME);
        }

        // Rete di sicurezza: se la conversion non è generabile (es. formato
        // immagine non supportato dal motore GD/Imagick), il media resta
        // comunque salvato e servito — non ritagliato, ma non un URL rotto.
        return $media->getUrl();
    }
```

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/UserAvatarMediaTest.php`
Expected: PASS (tutti i test del file, inclusi i 4 preesistenti + i 3 nuovi).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Models/User.php wm-package/tests/Feature/UserAvatarMediaTest.php
git commit -m "fix(oc:8343): generate synchronous 150x150 square avatar conversion"
```

---

### Task 2: Fix dei test preesistenti rotti dalla generazione sincrona

**Contesto:** due test preesistenti simulano "l'utente ha già un avatar" con `addMediaFromString('fake-image-bytes')` — una stringa che non è un'immagine valida. Prima di Task 1, questo non aveva conseguenze perché nessuna conversion veniva generata. Dopo Task 1, `toMediaCollection('avatar')` tenta di generare `avatar_150_150` **in modo sincrono** dal contenuto reale del file: su bytes non validi, il motore immagine (GD/Imagick) lancia un'eccezione che fa fallire l'intero test in fase di Arrange, non di asserzione.

**Files:**
- Modify: `wm-package/tests/Feature/Nova/UserAvatarFieldTest.php`
- Modify: `wm-package/tests/Feature/WmBackfillGravatarAvatarsCommandTest.php`

**Interfaces:**
- Consumes: nessuna nuova interfaccia — solo sostituzione del contenuto media fittizio con un'immagine valida, stesso pattern già usato nei test gemelli dello stesso file (`UserAvatarMediaTest.php`, `FetchGravatarAvatarJobTest.php`).

- [ ] **Step 1: Esegui i due file di test e verifica che falliscano**

Run:
```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/UserAvatarFieldTest.php
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/WmBackfillGravatarAvatarsCommandTest.php
```
Expected: FAIL sul test `'shows the real avatar_url when the user has an uploaded/Gravatar-fetched avatar'` (primo file) e sul test `'dispatches FetchGravatarAvatarJob only for users without an existing avatar'` (secondo file) — entrambi lanciano un'eccezione durante `addMediaFromString('fake-image-bytes')->toMediaCollection('avatar')`.

- [ ] **Step 2: Fix `UserAvatarFieldTest.php`**

Aggiungi l'import mancante in testa al file (dopo `use Illuminate\Foundation\Testing\DatabaseTransactions;`):

```php
use Illuminate\Http\UploadedFile;
```

Sostituisci (righe 26-28 attuali):

```php
    $user->addMediaFromString('fake-image-bytes')
        ->usingFileName('avatar.jpg')
        ->toMediaCollection('avatar');
```

con:

```php
    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');
```

- [ ] **Step 3: Fix `WmBackfillGravatarAvatarsCommandTest.php`**

Aggiungi due import mancanti in testa al file (dopo `use Illuminate\Foundation\Testing\DatabaseTransactions;`):

```php
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Nel test `'dispatches FetchGravatarAvatarJob only for users without an existing avatar'`, aggiungi `Storage::fake('wmfe');` subito dopo `Queue::fake();` e sostituisci (righe 25-27 attuali):

```php
    $withAvatar->addMediaFromString('fake-image-bytes')
        ->usingFileName('avatar.jpg')
        ->toMediaCollection('avatar');
```

con:

```php
    $withAvatar->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');
```

- [ ] **Step 4: Esegui entrambi i file e verifica che passino**

Run:
```bash
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/Nova/UserAvatarFieldTest.php
docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/WmBackfillGravatarAvatarsCommandTest.php
```
Expected: PASS su entrambi i file, tutti i test.

- [ ] **Step 5: Commit**

```bash
git add wm-package/tests/Feature/Nova/UserAvatarFieldTest.php wm-package/tests/Feature/WmBackfillGravatarAvatarsCommandTest.php
git commit -m "fix(oc:8343): use a valid fake image instead of invalid bytes in avatar tests"
```

---

### Task 3: `FetchGravatarAvatarJob` — dimensione Gravatar e estensione corretta

**Files:**
- Modify: `wm-package/src/Jobs/FetchGravatarAvatarJob.php`
- Test: `wm-package/tests/Feature/FetchGravatarAvatarJobTest.php`

**Interfaces:**
- Consumes: `User::AVATAR_CONVERSION_SIZE` (da Task 1) per calcolare la dimensione richiesta a Gravatar (`AVATAR_CONVERSION_SIZE * 2`, per avere margine di qualità sul crop 150×150).

- [ ] **Step 1: Scrivi i test che falliscono**

Apri `wm-package/tests/Feature/FetchGravatarAvatarJobTest.php` e aggiungi in coda:

```php
it('requests an image from Gravatar at 2x the avatar conversion size to avoid a blurry crop', function () {
    Storage::fake('wmfe');
    Http::fake([
        'gravatar.com/*' => Http::response(file_get_contents(dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg'), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $user = makeUserWithAppForGravatarJob();

    (new FetchGravatarAvatarJob($user->id))->handle();

    Http::assertSent(fn ($request) => str_contains($request->url(), 's='.(User::AVATAR_CONVERSION_SIZE * 2)));
});

it('stores the Gravatar image with the extension matching its real Content-Type instead of a hardcoded .jpg', function () {
    Storage::fake('wmfe');
    Http::fake([
        'gravatar.com/*' => Http::response(file_get_contents(dirname(__DIR__).'/fixtures/avatar-with-gps-exif.jpg'), 200, ['Content-Type' => 'image/png']),
    ]);
    $user = makeUserWithAppForGravatarJob();

    (new FetchGravatarAvatarJob($user->id))->handle();

    $media = $user->fresh()->getFirstMedia('avatar');
    expect($media)->not->toBeNull()
        ->and($media->file_name)->toEndWith('.png');
});
```

- [ ] **Step 2: Esegui i test e verifica che falliscano**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/FetchGravatarAvatarJobTest.php`
Expected: FAIL sui due nuovi test — la request non contiene ancora `s=300` e il file viene salvato con `.jpg` indipendentemente dal Content-Type.

- [ ] **Step 3: Implementa il fix minimo in `FetchGravatarAvatarJob.php`**

Aggiungi l'import in testa al file, dopo `use Illuminate\Support\Facades\Http;`:

```php
use Symfony\Component\Mime\MimeTypes;
use Wm\WmPackage\Models\User;
```

(`Wm\WmPackage\Models\User` è già importato nel file — verifica che non ci sia un duplicato; se già presente, aggiungi solo `use Symfony\Component\Mime\MimeTypes;`).

Sostituisci la chiamata HTTP (righe 42-44 attuali):

```php
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get("https://www.gravatar.com/avatar/{$hash}", ['d' => '404']);
        } catch (ConnectionException $e) {
```

con:

```php
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get("https://www.gravatar.com/avatar/{$hash}", [
                    'd' => '404',
                    // 2x la dimensione della conversion avatar (User::AVATAR_CONVERSION_SIZE)
                    // per avere una fonte sufficientemente grande da croppare senza sfocatura.
                    's' => User::AVATAR_CONVERSION_SIZE * 2,
                ]);
        } catch (ConnectionException $e) {
```

Sostituisci la costruzione del path temporaneo (righe 72-73 attuali):

```php
        $tempPath = sys_get_temp_dir().'/gravatar_'.$this->userId.'_'.uniqid().'.jpg';
        file_put_contents($tempPath, $response->body());
```

con:

```php
        $contentType = strtok((string) $response->header('Content-Type'), ';');
        $extension = (new MimeTypes())->getExtensions($contentType)[0] ?? 'jpg';

        $tempPath = sys_get_temp_dir().'/gravatar_'.$this->userId.'_'.uniqid().'.'.$extension;
        file_put_contents($tempPath, $response->body());
```

- [ ] **Step 4: Esegui i test e verifica che passino**

Run: `docker exec laravel-camminiditalia php artisan test wm-package/tests/Feature/FetchGravatarAvatarJobTest.php`
Expected: PASS su tutti i test del file (i preesistenti restano verdi: `image/jpeg` risolve comunque a estensione `jpg`, stesso comportamento di prima).

- [ ] **Step 5: Commit**

```bash
git add wm-package/src/Jobs/FetchGravatarAvatarJob.php wm-package/tests/Feature/FetchGravatarAvatarJobTest.php
git commit -m "fix(oc:8343): request larger Gravatar image and use its real Content-Type extension"
```

---

### Task 4: Verifica di regressione completa

**Files:** nessuna modifica — solo esecuzione.

- [ ] **Step 1: Esegui tutti i file di test collegati all'avatar utente**

Run:
```bash
docker exec laravel-camminiditalia php artisan test \
  wm-package/tests/Feature/UserAvatarMediaTest.php \
  wm-package/tests/Feature/Nova/UserAvatarFieldTest.php \
  wm-package/tests/Feature/WmBackfillGravatarAvatarsCommandTest.php \
  wm-package/tests/Feature/FetchGravatarAvatarJobTest.php \
  wm-package/tests/Feature/AppAuthControllerUpdateProfileTest.php \
  wm-package/tests/Feature/AppAuthControllerMeProfileTest.php
```
Expected: PASS su tutti i file — in particolare `AppAuthControllerUpdateProfileTest.php` e `AppAuthControllerMeProfileTest.php` non vengono modificati da questo piano ma esercitano lo stesso accessor `avatar_url` tramite upload reale (`UploadedFile::fake()->image(...)`, già immagini valide) e devono continuare a passare senza intervento, confermando che il fix non introduce regressioni sul flusso di upload manuale.

Nessun commit per questo task (nessuna modifica al codice).
