> Ticket: oc:8163

# Profilo utente: nome, cognome e avatar (wm-package) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Estendere `User` e `AppAuthController` in wm-package per supportare cognome (`surname`), avatar caricato dall'utente e avatar auto-popolato da Gravatar al signup.

**Architecture:** Colonna `surname` opzionale su `users` + Spatie Media Library (`HasMedia`/`InteractsWithMedia`) per l'avatar, esposti via l'endpoint esistente `POST /api/auth/user` (`AppAuthController::update`) e `POST /api/auth/me`. Un job asincrono (`Jobs\Abstract\BaseJob`) prova a popolare l'avatar da Gravatar al signup.

**Tech Stack:** Laravel, Spatie Media Library (`spatie/laravel-medialibrary` ^11.12), Intervention Image (`intervention/image` ^2.5, driver GD), Pest (test), disco media `wmfe` (S3).

## Global Constraints

- Nessun commit/branch automatico: i comandi `git commit` nei task sono istruzioni testuali per lo sviluppatore, non azioni da eseguire in autonomia.
- Commit convention: `feat(oc:8163): ...` / `fix(oc:8163): ...`.
- `surname` e `avatar` sono sempre opzionali (`sometimes`) — nessun breaking change per gli shard che non hanno ancora pubblicato la migration (vedi piano `camminiditalia`).
- Verificato in Fase: challenge — tutti e 5 gli shard (maphub, osm2cai2, carg, camminiditalia, forestas) hanno già la tabella `media` di Spatie migrata: nessun rischio di tabella mancante per `HasMedia`.
- Strip EXIF (incluso GPS) obbligatorio per le foto caricate dall'utente, non per l'avatar Gravatar.
- Test in Pest, pattern `uses(TestCase::class, DatabaseTransactions::class)` (vedi `tests/Feature/LayerLogoMediaTest.php`, `tests/Feature/GenerateFeatureCollectionJobTest.php` come riferimento).

---

### Task 1: Migration stub — colonna `surname`

**Files:**
- Create: `database/migrations/zz_2026_07_27_000001_add_surname_to_users_table.php.stub`
- Test: `tests/Feature/AddSurnameToUsersMigrationTest.php`

**Interfaces:**
- Produces: colonna `users.surname` (string, nullable) — consumata dal Task 2 (`User::$fillable`).

- [ ] **Step 1: Scrivi lo stub di migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('surname')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('surname');
        });
    }
};
```

- [ ] **Step 2: Scrivi il test che verifica lo stub sia pubblicabile e applicabile**

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, DatabaseTransactions::class);

it('users table has a nullable surname column after migration', function () {
    expect(Schema::hasColumn('users', 'surname'))->toBeTrue();

    $column = Schema::getConnection()->getDoctrineColumn('users', 'surname');
    expect($column->getNotnull())->toBeFalse();
});
```

Nota: questo test verifica lo stato dello schema nel DB di test di wm-package (dove lo stub è già applicato via `Workbench`, pattern standard di package Laravel testing) — non lo stato di produzione di `camminiditalia` (task separato nel piano `camminiditalia`).

- [ ] **Step 3: Esegui il test per verificare che fallisca**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AddSurnameToUsersMigrationTest.php`
Expected: FAIL — `surname` non esiste ancora nello schema di test.

- [ ] **Step 4: Assicurati che lo stub venga raccolto dal workbench di test di wm-package**

Verifica in `testbench.yaml` o equivalente che `database/migrations/*.php.stub` sia già incluso nel path delle migration di test (pattern esistente per gli altri stub, es. `create_media_table.php.stub`) — se lo stub non viene eseguito automaticamente nel testbench, aggiungilo esplicitamente al workbench come le altre migration esistenti.

- [ ] **Step 5: Esegui il test per verificare che passi**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AddSurnameToUsersMigrationTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add database/migrations/zz_2026_07_27_000001_add_surname_to_users_table.php.stub tests/Feature/AddSurnameToUsersMigrationTest.php
git commit -m "feat(oc:8163): add publishable migration stub for users.surname"
```

---

### Task 2: `User` model — `surname`, `HasMedia`, collection `avatar`

**Files:**
- Modify: `src/Models/User.php`
- Test: `tests/Feature/UserAvatarMediaTest.php`

**Interfaces:**
- Consumes: colonna `users.surname` (Task 1).
- Produces: `User::$fillable` include `surname`; `User implements HasMedia`, `use InteractsWithMedia`; `registerMediaCollections()` con `avatar` (`singleFile()`); accessor `getAvatarUrlAttribute(): ?string` aggiunto a `$appends` — consumato dal Task 3 (`AppAuthController::update`) e implicitamente da `me()` (nessuna modifica al controller prevista, vedi Task 4).

- [ ] **Step 1: Scrivi il test fallente per `surname` fillable**

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

it('allows mass assignment of surname', function () {
    $user = User::factory()->create(['surname' => 'Rossi']);

    expect($user->fresh()->surname)->toBe('Rossi');
});

it('registers an avatar media collection as single file', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');
    $user->addMedia(UploadedFile::fake()->image('avatar-2.jpg', 400, 400))
        ->toMediaCollection('avatar');

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('exposes avatar_url when an avatar is attached', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create();

    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))
        ->toMediaCollection('avatar');

    $fresh = $user->fresh();
    expect($fresh->avatar_url)->toBeString()
        ->and($fresh->toArray()['avatar_url'])->toBe($fresh->avatar_url);
});

it('returns null for avatar_url when no avatar is attached', function () {
    $user = User::factory()->create();

    expect($user->fresh()->avatar_url)->toBeNull()
        ->and($user->fresh()->toArray()['avatar_url'])->toBeNull();
});
```

Aggiungi in testa al file gli `use` mancanti: `Illuminate\Http\UploadedFile`, `Illuminate\Support\Facades\Storage`.

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Run: `cd wm-package && vendor/bin/pest tests/Feature/UserAvatarMediaTest.php`
Expected: FAIL — `surname` non è fillable, `User` non implementa `HasMedia`.

- [ ] **Step 3: Modifica `User.php`**

In `src/Models/User.php`, aggiungi gli import:

```php
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
```

Cambia la dichiarazione della classe:

```php
class User extends Authenticatable implements JWTSubject, HasMedia
{
    use Favoriteability, HasApiTokens, HasPackageFactory, HasRoles, Impersonatable, InteractsWithMedia, Notifiable;
```

Aggiungi `surname` a `$fillable`:

```php
protected $fillable = [
    'name',
    'surname',
    'email',
    'password',
    'app_id',
    'properties',
];
```

Aggiungi `avatar_url` a `$appends` (accanto a `geopass`):

```php
protected $appends = ['geopass', 'avatar_url'];
```

Aggiungi il metodo di registrazione della collection e l'accessor (stesso pattern di `Layer::registerMediaCollections()`/`getLogoImageAttribute()`):

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

- [ ] **Step 4: Esegui i test per verificare che passino**

Run: `cd wm-package && vendor/bin/pest tests/Feature/UserAvatarMediaTest.php`
Expected: PASS

- [ ] **Step 5: Esegui l'intera suite di wm-package per verificare l'assenza di conflitti con i trait esistenti**

Run: `cd wm-package && vendor/bin/pest`
Expected: PASS — nessuna regressione su `Favoriteability`, `HasApiTokens`, `HasPackageFactory`, `HasRoles`, `Impersonatable`, `Notifiable` (emerso come rischio in Fase: challenge). Se un test esistente fallisce per conflitto di metodo con `InteractsWithMedia`, documentalo in `notes.md` del piano prima di procedere.

- [ ] **Step 6: Commit**

```bash
git add src/Models/User.php tests/Feature/UserAvatarMediaTest.php
git commit -m "feat(oc:8163): add surname field and avatar media collection to User"
```

---

### Task 3: `AppAuthController::update` — accetta `surname` e `avatar` (con strip EXIF)

**Files:**
- Modify: `src/Http/Controllers/Api/AppAuthController.php`
- Test: `tests/Feature/AppAuthControllerUpdateProfileTest.php`

**Interfaces:**
- Consumes: `User::$fillable` con `surname` (Task 2), collection media `avatar` (Task 2).
- Produces: `POST /api/auth/user` accetta `surname` (string, sometimes) e `avatar` (file immagine, sometimes) nel body multipart; risposta include `surname`/`avatar_url` aggiornati (via `toArray()`, nessuna modifica a `filterUserPrivacyByAppId` necessaria perché opera sull'array già completo).

- [ ] **Step 1: Scrivi i test falliti**

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

function actingAsUserWithToken(User $user): string
{
    return auth('api')->login($user);
}

it('updates surname via POST /api/auth/user', function () {
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/user', ['surname' => 'Bianchi']);

    $response->assertStatus(200)->assertJson(['surname' => 'Bianchi']);
    expect($user->fresh()->surname)->toBe('Bianchi');
});

it('uploads and stores avatar via POST /api/auth/user', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg', 400, 400),
        ]);

    $response->assertStatus(200);
    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
    expect($response->json('avatar_url'))->toBeString();
});

it('replaces previous avatar on second upload (singleFile)', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => UploadedFile::fake()->image('a1.jpg', 400, 400)]);
    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => UploadedFile::fake()->image('a2.jpg', 400, 400)]);

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('rejects surname longer than 255 characters', function () {
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/user', ['surname' => str_repeat('a', 256)]);

    $response->assertStatus(400);
});

it('strips EXIF GPS metadata from uploaded avatar', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create();
    $token = actingAsUserWithToken($user);

    $exifImagePath = base_path('tests/fixtures/avatar-with-gps-exif.jpg');
    $uploaded = new UploadedFile($exifImagePath, 'avatar.jpg', 'image/jpeg', null, true);

    $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->post('/api/auth/user', ['avatar' => $uploaded]);

    $storedPath = $user->fresh()->getFirstMedia('avatar')->getPath();
    $exif = @exif_read_data($storedPath);

    expect($exif === false || ! isset($exif['GPSLatitude']))->toBeTrue();
});
```

Crea la fixture `tests/fixtures/avatar-with-gps-exif.jpg` (una qualsiasi immagine JPEG con blocco EXIF GPS — puoi generarla una tantum con `exiftool -GPSLatitude=45.0 -GPSLongitude=11.0 tests/fixtures/avatar-with-gps-exif.jpg` da un'immagine placeholder, oppure scaricare un sample pubblico di test EXIF GPS e committarlo nel repo).

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppAuthControllerUpdateProfileTest.php`
Expected: FAIL — `AppAuthController::update` non valida/non gestisce ancora `surname`/`avatar`.

- [ ] **Step 3: Modifica `AppAuthController::update`**

Aggiungi l'import in testa al file:

```php
use Intervention\Image\Facades\Image;
```

Nel metodo `update()`, estendi l'array di validazione (dentro `array_merge([...], $this->getPrivacyRules())`):

```php
'surname' => 'sometimes|string|max:255',
'avatar' => 'sometimes|file|image|max:5120',
```

Estendi l'array messaggi corrispondente:

```php
'surname.string' => 'Il campo cognome deve essere una stringa.',
'surname.max' => 'Il campo cognome non può superare i 255 caratteri.',
'avatar.image' => 'Il file caricato deve essere un\'immagine.',
'avatar.max' => 'L\'immagine non può superare i 5MB.',
```

Nel corpo del metodo, dopo la lettura di `$properties`/`$privacy`, aggiungi:

```php
$surname = $request->input('surname');
if ($surname) {
    $updateData['surname'] = $surname;
}
```

Dopo il blocco `if (! empty($updateData)) { $user->update($updateData); }`, aggiungi la gestione dell'avatar (va dopo, perché `addMediaFromRequest` opera sul model già salvato):

```php
if ($request->hasFile('avatar')) {
    $strippedPath = $this->stripExifFromUploadedImage($request->file('avatar'));
    $user->addMedia($strippedPath)
        ->usingFileName($request->file('avatar')->hashName())
        ->toMediaCollection('avatar');
}
```

Aggiungi il metodo privato di supporto (in fondo alla classe, accanto agli altri metodi privati):

```php
/**
 * Re-encode the uploaded image via GD (Intervention Image) to strip all EXIF
 * metadata, including GPS coordinates. orientate() bakes the correct rotation
 * in before the re-encode discards the EXIF orientation tag.
 *
 * @param  \Illuminate\Http\UploadedFile  $file
 * @return string absolute path to a stripped temp copy
 */
private function stripExifFromUploadedImage($file): string
{
    $strippedPath = sys_get_temp_dir().'/'.Str::random(20).'.'.$file->getClientOriginalExtension();

    Image::make($file->getRealPath())
        ->orientate()
        ->save($strippedPath);

    return $strippedPath;
}
```

- [ ] **Step 4: Esegui i test per verificare che passino**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppAuthControllerUpdateProfileTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Http/Controllers/Api/AppAuthController.php tests/Feature/AppAuthControllerUpdateProfileTest.php tests/fixtures/avatar-with-gps-exif.jpg
git commit -m "feat(oc:8163): accept surname and avatar upload in AppAuthController::update, strip EXIF"
```

---

### Task 4: `AppAuthController::me` — verifica esposizione `surname`/`avatar_url`

**Files:**
- Test: `tests/Feature/AppAuthControllerMeProfileTest.php`

**Interfaces:**
- Consumes: `User::$appends` con `avatar_url` (Task 2), `surname` fillable (Task 2).
- Produces: conferma che `POST /api/auth/me` include `surname` e `avatar_url` senza modifiche al controller (Eloquent li espone già via `toArray()`/`$appends`) — se il test fallisce, indica che serve un intervento esplicito nel controller (vedi Step 3).

- [ ] **Step 1: Scrivi il test**

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

it('includes surname and avatar_url (null) in me() response', function () {
    $user = User::factory()->create(['surname' => 'Verdi']);
    $token = auth('api')->login($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/me');

    $response->assertStatus(200)
        ->assertJson(['surname' => 'Verdi', 'avatar_url' => null]);
});

it('includes avatar_url in me() response when an avatar is attached', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create();
    $user->addMedia(UploadedFile::fake()->image('avatar.jpg', 400, 400))->toMediaCollection('avatar');
    $token = auth('api')->login($user);

    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/auth/me');

    $response->assertStatus(200);
    expect($response->json('avatar_url'))->toBeString();
});
```

- [ ] **Step 2: Esegui il test**

Run: `cd wm-package && vendor/bin/pest tests/Feature/AppAuthControllerMeProfileTest.php`
Expected: PASS senza alcuna modifica al controller — se FALLISCE, `me()` filtra i campi da qualche parte (verifica `filterUserPrivacyByAppId`/`toAppArray`, che oggi operano su `$user->toArray()` completo); in quel caso aggiungi esplicitamente `surname`/`avatar_url` all'array ritornato da quei metodi prima di procedere.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/AppAuthControllerMeProfileTest.php
git commit -m "test(oc:8163): verify me() exposes surname and avatar_url"
```

---

### Task 5: Job asincrono Gravatar al signup

**Files:**
- Create: `src/Jobs/FetchGravatarAvatarJob.php`
- Modify: `src/Http/Controllers/Api/AppAuthController.php` (metodo `createUser()`)
- Test: `tests/Feature/FetchGravatarAvatarJobTest.php`

**Interfaces:**
- Consumes: `User` model con collection media `avatar` (Task 2).
- Produces: `FetchGravatarAvatarJob::dispatch(int $userId)` — dispatchato da `AppAuthController::createUser()` dopo `$user->save()`.

- [ ] **Step 1: Scrivi i test falliti**

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

it('dispatches FetchGravatarAvatarJob on signup', function () {
    Queue::fake();

    $this->postJson('/api/auth/signup', [
        'email' => 'gravatar-test@example.com',
        'password' => 'password123',
        'name' => 'Gravatar Test',
    ]);

    Queue::assertPushed(FetchGravatarAvatarJob::class);
});

it('saves the avatar when Gravatar responds with a real image (200)', function () {
    Storage::fake('wmfe');
    Http::fake([
        'gravatar.com/*' => Http::response(file_get_contents(base_path('tests/fixtures/avatar-with-gps-exif.jpg')), 200, ['Content-Type' => 'image/jpeg']),
    ]);
    $user = User::factory()->create();

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('does not save an avatar when Gravatar responds 404 (no avatar)', function () {
    Http::fake(['gravatar.com/*' => Http::response('', 404)]);
    $user = User::factory()->create();

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(0);
});

it('logs a distinct failure and does not save an avatar on rate-limit (429)', function () {
    Http::fake(['gravatar.com/*' => Http::response('', 429)]);
    $user = User::factory()->create();

    (new FetchGravatarAvatarJob($user->id))->handle();

    expect($user->fresh()->getMedia('avatar'))->toHaveCount(0);
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Run: `cd wm-package && vendor/bin/pest tests/Feature/FetchGravatarAvatarJobTest.php`
Expected: FAIL — la classe `FetchGravatarAvatarJob` non esiste ancora.

- [ ] **Step 3: Crea il job**

```php
<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Jobs\Abstract\BaseJob;
use Wm\WmPackage\Models\User;

class FetchGravatarAvatarJob extends BaseJob
{
    public function __construct(public int $userId) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        if (! $user) {
            $this->logInfo("User {$this->userId} not found, skipping Gravatar fetch");

            return;
        }

        $hash = md5(strtolower(trim($user->email)));
        $response = Http::get("https://www.gravatar.com/avatar/{$hash}", ['d' => '404']);

        if ($response->status() === 404) {
            $this->logInfo("No real Gravatar for user {$this->userId} (404)");

            return;
        }

        if (! $response->successful()) {
            $this->logError("Gravatar fetch failed for user {$this->userId} with status {$response->status()} (not treated as 'no avatar')");

            return;
        }

        $tempPath = sys_get_temp_dir().'/gravatar_'.$this->userId.'_'.uniqid().'.jpg';
        file_put_contents($tempPath, $response->body());

        $user->addMedia($tempPath)->toMediaCollection('avatar');
    }

    protected function getRedisLockKey(): string
    {
        return 'fetch_gravatar_avatar:'.$this->userId;
    }

    protected function getLogChannel(): string
    {
        return config('logging.default');
    }
}
```

- [ ] **Step 4: Dispatcha il job da `createUser()`**

In `AppAuthController::createUser()`, dopo `$user->save();` e prima del `return $user;`:

```php
FetchGravatarAvatarJob::dispatch($user->id);
```

Aggiungi l'import in testa al file:

```php
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
```

- [ ] **Step 5: Esegui i test per verificare che passino**

Run: `cd wm-package && vendor/bin/pest tests/Feature/FetchGravatarAvatarJobTest.php`
Expected: PASS

- [ ] **Step 6: Esegui l'intera suite di wm-package**

Run: `cd wm-package && vendor/bin/pest`
Expected: PASS — nessuna regressione sui test di signup esistenti (`tests/Api/AuthTest.php.txt` è disabilitato/non eseguito, ma verifica comunque manualmente che `test_user_can_signup` in stile Pest, se presente altrove, non si rompa per il nuovo dispatch).

- [ ] **Step 7: Commit**

```bash
git add src/Jobs/FetchGravatarAvatarJob.php src/Http/Controllers/Api/AppAuthController.php tests/Feature/FetchGravatarAvatarJobTest.php
git commit -m "feat(oc:8163): fetch Gravatar avatar asynchronously on signup"
```

---

### Task 6: Comando di backfill avatar Gravatar per utenti esistenti

**Aggiunto post-hoc su richiesta esplicita dell'utente** (non nell'overview/piano originale): il job Gravatar (Task 5) gira solo al signup di nuovi utenti — per chi era già registrato prima del deploy serve un comando esplicito che lo dispatchi retroattivamente.

**Files:**
- Create: `src/Commands/WmBackfillGravatarAvatarsCommand.php`
- Modify: `src/WmPackageServiceProvider.php` (registrazione comando)
- Test: `tests/Feature/WmBackfillGravatarAvatarsCommandTest.php`

**Interfaces:**
- Consumes: `FetchGravatarAvatarJob(int $userId, ?int $appId = null)` (Task 5, già esteso con `appId` durante la final review).
- Produces: comando artisan `wm:backfill-gravatar-avatars` — nessuna interfaccia consumata da altri task.

- [ ] **Step 1: Scrivi i test falliti**

```php
<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\User;

uses(TestCase::class, DatabaseTransactions::class);

it('dispatches FetchGravatarAvatarJob only for users without an existing avatar', function () {
    Queue::fake();
    $app = App::factory()->createQuietly();
    $withAvatar = User::factory()->create(['app_id' => $app->id]);
    $withAvatar->addMediaFromString('fake-image-bytes')
        ->usingFileName('avatar.jpg')
        ->toMediaCollection('avatar');
    $withoutAvatar = User::factory()->create(['app_id' => $app->id]);

    $this->artisan('wm:backfill-gravatar-avatars')->assertExitCode(0);

    Queue::assertPushed(FetchGravatarAvatarJob::class, function ($job) use ($withoutAvatar) {
        return $job->userId === $withoutAvatar->id;
    });
    Queue::assertNotPushed(FetchGravatarAvatarJob::class, function ($job) use ($withAvatar) {
        return $job->userId === $withAvatar->id;
    });
});

it('filters by --app-id when provided', function () {
    Queue::fake();
    $appA = App::factory()->createQuietly();
    $appB = App::factory()->createQuietly();
    $userA = User::factory()->create(['app_id' => $appA->id]);
    $userB = User::factory()->create(['app_id' => $appB->id]);

    $this->artisan('wm:backfill-gravatar-avatars', ['--app-id' => $appA->id])->assertExitCode(0);

    Queue::assertPushed(FetchGravatarAvatarJob::class, function ($job) use ($userA) {
        return $job->userId === $userA->id;
    });
    Queue::assertNotPushed(FetchGravatarAvatarJob::class, function ($job) use ($userB) {
        return $job->userId === $userB->id;
    });
});

it('reports zero users to process without error', function () {
    Queue::fake();

    $this->artisan('wm:backfill-gravatar-avatars', ['--app-id' => 999999])->assertExitCode(0);

    Queue::assertNotPushed(FetchGravatarAvatarJob::class);
});
```

- [ ] **Step 2: Esegui i test per verificare che falliscano**

Run (via bootstrap camminiditalia, stesso pattern usato in tutti i task precedenti):
```bash
docker run --rm --network camminiditalia_default \
  -v /Users/peco/Documents/BackEnd/camminiditalia:/var/www/html/camminiditalia \
  -w /var/www/html/camminiditalia -e DB_HOST=postgres-camminiditalia -e DB_CONNECTION=pgsql \
  wm-phpfpm:8.4 vendor/bin/pest wm-package/tests/Feature/WmBackfillGravatarAvatarsCommandTest.php
```
Expected: FAIL — il comando `wm:backfill-gravatar-avatars` non esiste ancora.

- [ ] **Step 3: Crea il comando**

Segui il pattern di `WmSyncUgcTaxonomyWhereCommand` (query chunked + progress bar + dispatch):

```php
<?php

namespace Wm\WmPackage\Commands;

use Illuminate\Console\Command;
use Wm\WmPackage\Jobs\FetchGravatarAvatarJob;
use Wm\WmPackage\Models\User;

class WmBackfillGravatarAvatarsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'wm:backfill-gravatar-avatars
                            {--app-id= : Limita agli utenti con questo app_id}
                            {--chunk=500 : Dimensione chunk per la query}';

    /**
     * @var string
     */
    protected $description = 'Accoda FetchGravatarAvatarJob per gli utenti esistenti che non hanno ancora un avatar (nessun effetto su chi ne ha già uno, caricato o da Gravatar).';

    public function handle(): int
    {
        $appId = $this->option('app-id');
        $chunk = max(1, (int) $this->option('chunk'));

        $query = User::query()->whereDoesntHave('media', function ($q) {
            $q->where('collection_name', 'avatar');
        });

        if ($appId !== null && $appId !== '') {
            $query->where('app_id', $appId);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('Nessun utente senza avatar da elaborare.');

            return self::SUCCESS;
        }

        $this->info("Utenti da elaborare: {$total}.");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->orderBy('id')->chunkById($chunk, function ($users) use ($bar): void {
            foreach ($users as $user) {
                FetchGravatarAvatarJob::dispatch($user->id, $user->app_id);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Completato: {$total} job accodati.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Registra il comando in `WmPackageServiceProvider.php`**

Aggiungi l'import in testa al file:

```php
use Wm\WmPackage\Commands\WmBackfillGravatarAvatarsCommand;
```

Aggiungi alla lista `->hasCommands([...])` (accanto a `WmSyncUgcTaxonomyWhereCommand::class`):

```php
WmBackfillGravatarAvatarsCommand::class,
```

- [ ] **Step 5: Esegui i test per verificare che passino**

Run: stesso comando dello Step 2.
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add src/Commands/WmBackfillGravatarAvatarsCommand.php src/WmPackageServiceProvider.php tests/Feature/WmBackfillGravatarAvatarsCommandTest.php
git commit -m "feat(oc:8163): add backfill command for existing users' Gravatar avatars"
```

---

### Task 7: Campo Nova — avatar reale con fallback su Gravatar

**Aggiunto post-hoc su richiesta esplicita dell'utente** (esplicitamente out-of-scope nell'overview originale: "Visibilità del profilo ad altri utenti o nel backoffice Nova"). L'utente ha chiesto di poter vedere in Nova l'avatar caricato/impostato dal nuovo sistema, con fallback sul Gravatar live se non presente — oggi `AbstractUserResource` mostra solo `Gravatar::make()` (sempre e solo il Gravatar live, mai il nuovo `avatar_url`).

**Files:**
- Create: `src/Nova/Fields/UserAvatar.php`
- Modify: `src/Nova/AbstractUserResource.php`
- Test: `tests/Feature/Nova/UserAvatarFieldTest.php`

**Interfaces:**
- Consumes: `User::avatar_url` accessor (Task 2).
- Produces: campo Nova `UserAvatar` — nessuna interfaccia consumata da altri task.

- [ ] **Step 1: Scrivi il test fallente**

Segui il pattern di `tests/Feature/Nova/LayerLogoFieldValidationTest.php` per costruire/risolvere un field da una Resource:

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Tests\TestCase;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Fields\UserAvatar;

uses(TestCase::class, DatabaseTransactions::class);

function resolveUserAvatarValue(User $user): string
{
    $field = UserAvatar::make();
    $request = NovaRequest::create('/');
    $field->resolveForDisplay($user);

    return $field->value;
}

it('shows the real avatar_url when the user has an uploaded/Gravatar-fetched avatar', function () {
    Storage::fake('wmfe');
    $user = User::factory()->create(['email' => 'nova-avatar-test@example.com']);
    $user->addMediaFromString('fake-image-bytes')
        ->usingFileName('avatar.jpg')
        ->toMediaCollection('avatar');

    $value = resolveUserAvatarValue($user->fresh());

    expect($value)->toBe($user->fresh()->avatar_url)
        ->and($value)->not->toContain('gravatar.com');
});

it('falls back to the Gravatar URL computed from the email when no avatar is set', function () {
    $user = User::factory()->create(['email' => 'nova-fallback-test@example.com']);

    $value = resolveUserAvatarValue($user);

    $expectedHash = md5(strtolower('nova-fallback-test@example.com'));
    expect($value)->toBe("https://www.gravatar.com/avatar/{$expectedHash}?s=300");
});
```

- [ ] **Step 2: Esegui il test per verificare che fallisca**

```bash
docker run --rm --network camminiditalia_default \
  -v /Users/peco/Documents/BackEnd/camminiditalia:/var/www/html/camminiditalia \
  -w /var/www/html/camminiditalia -e DB_HOST=postgres-camminiditalia -e DB_CONNECTION=pgsql \
  wm-phpfpm:8.4 vendor/bin/pest wm-package/tests/Feature/Nova/UserAvatarFieldTest.php
```
Expected: FAIL — la classe `Wm\WmPackage\Nova\Fields\UserAvatar` non esiste ancora.

- [ ] **Step 3: Crea il campo Nova**

Stesso pattern di `Laravel\Nova\Fields\Gravatar` (letto da `vendor/laravel/nova/src/Fields/Gravatar.php`), ma con fallback su `avatar_url`:

```php
<?php

namespace Wm\WmPackage\Nova\Fields;

use Laravel\Nova\Fields\Avatar;
use Laravel\Nova\Fields\Unfillable;
use Laravel\Nova\Nova;

/**
 * @method static static make(\Stringable|string|null $name = null, string $attribute = 'email')
 */
class UserAvatar extends Avatar implements Unfillable
{
    public function __construct($name = null, string $attribute = 'email')
    {
        parent::__construct($name ?? Nova::__('Avatar'), $attribute);

        $this->exceptOnForms()
            ->disableDownload();
    }

    /**
     * Mostra l'avatar reale dell'utente (upload manuale o Gravatar già scaricato al
     * signup, Task 5) se presente; altrimenti calcola al volo lo stesso URL Gravatar
     * live usato da `Laravel\Nova\Fields\Gravatar` (nessuna richiesta di rete qui,
     * solo la formula dell'URL — il browser scarica l'immagine, non il backend).
     */
    protected function resolveAttribute($resource, string $attribute): string
    {
        $avatarUrl = $resource->avatar_url ?? null;

        $callback = fn () => $avatarUrl ?: (
            'https://www.gravatar.com/avatar/'.md5(strtolower((string) data_get($resource, $attribute))).'?s=300'
        );

        $this->preview($callback)->thumbnail($callback);

        return $callback();
    }
}
```

- [ ] **Step 4: Sostituisci `Gravatar::make()` in `AbstractUserResource.php`**

Rimuovi l'import:
```php
use Laravel\Nova\Fields\Gravatar;
```

Aggiungi:
```php
use Wm\WmPackage\Nova\Fields\UserAvatar;
```

Nel metodo `fields()`, sostituisci:
```php
Gravatar::make()->maxWidth(50),
```
con:
```php
UserAvatar::make()->maxWidth(50),
```

- [ ] **Step 5: Esegui il test per verificare che passi**

Run: stesso comando dello Step 2.
Expected: PASS

- [ ] **Step 6: Esegui l'intera suite dei test Nova esistenti per verificare l'assenza di regressioni**

```bash
docker run --rm --network camminiditalia_default \
  -v /Users/peco/Documents/BackEnd/camminiditalia:/var/www/html/camminiditalia \
  -w /var/www/html/camminiditalia -e DB_HOST=postgres-camminiditalia -e DB_CONNECTION=pgsql \
  wm-phpfpm:8.4 vendor/bin/pest wm-package/tests/Feature/Nova/AbstractUserResourceImpersonateTest.php wm-package/tests/Feature/Nova/AbstractUserResourceRoleGuardTest.php
```
Expected: PASS — la sostituzione del campo avatar non deve impattare i test di impersonation/ruoli su `AbstractUserResource`.

- [ ] **Step 7: Commit**

```bash
git add src/Nova/Fields/UserAvatar.php src/Nova/AbstractUserResource.php tests/Feature/Nova/UserAvatarFieldTest.php
git commit -m "feat(oc:8163): show real avatar with Gravatar fallback in Nova user resource"
```

---

## Self-Review Checklist (compilata dall'autore del piano)

- **Spec coverage:** migration stub (Task 1), `User` model + media collection (Task 2), `update()` con EXIF strip (Task 3), `me()` verificato (Task 4), job Gravatar con distinzione errori (Task 5) — tutti i requisiti di `overview.md` sono coperti. GDPR/verifica legale resta esplicitamente **fuori da questo piano tecnico** (azione non-di-codice, da tracciare separatamente prima del deploy in produzione). Task 6/7 aggiunti post-hoc su richiesta esplicita dell'utente durante l'execution, non erano nell'overview originale (che anzi escludeva esplicitamente Nova) — annotare in notes.md come deviazione dal piano approvato.
- **Placeholder scan:** nessun TODO/placeholder: ogni step ha codice completo.
- **Type consistency:** `FetchGravatarAvatarJob(int $userId)` costruttore coerente in Task 5 Step 3 e Step 4 (dispatch con `$user->id`); `getAvatarUrlAttribute()`/`avatar_url` naming coerente tra Task 2, 3, 4, 6, 7. Task 6 assume la firma estesa `FetchGravatarAvatarJob(int $userId, ?int $appId = null)` introdotta durante la final review del Task 5 (fix per l'`app_id` hardcoded) — verificare che corrisponda esattamente prima di implementare.

## Execution Handoff

Piano salvato in `docs/features/8163-profilo-utente-nome-cognome-avatar/plan.md`. Due opzioni di esecuzione:

**1. Subagent-Driven (consigliato)** — un subagente fresco per task, review tra un task e l'altro, iterazione rapida.

**2. Inline Execution** — esecuzione in questa sessione con `executing-plans`, batch execution con checkpoint.

Quale preferisci?
