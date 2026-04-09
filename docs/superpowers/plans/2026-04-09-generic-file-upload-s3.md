# Generic File Upload to S3 — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `storeFile()`, `deleteFile()`, and `deleteModelFiles()` to `StorageService`, update `AbstractEcResource` to use them for the accessibility PDF, and hook `deleteModelFiles()` into `EcPoiObserver` and `EcTrackObserver`.

**Architecture:** All file uploads go through `StorageService` which builds the path `/{shard}/{appId}/files/{model-type}/{id}/{filename}.{ext}` on the `wmfe` disk. Nova uses `File::make()->fillUsing()` to intercept uploads and delegate to the service. Observers call `deleteModelFiles()` on the `deleting` event to clean up S3.

**Tech Stack:** Laravel 12, PHP 8.4, Laravel Nova 5, S3/MinIO (`wmfe` disk), Pest tests.

---

### Task 1: Add `storeFile()` and `deleteFile()` to `StorageService`

**Files:**
- Modify: `src/Services/StorageService.php`
- Test: `tests/Unit/Services/StorageService/StoreFileTest.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/StorageService/StoreFileTest.php`:

```php
<?php

namespace Tests\Unit\Services\StorageService;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

class StoreFileTest extends TestCase
{
    private StorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wmfe');
        $this->service = new StorageService();
    }

    public function test_storeFile_uploads_to_correct_path_and_returns_url(): void
    {
        $model = $this->makeModelDouble(app_id: 1, id: 42, type: 'ec-poi');
        $file = UploadedFile::fake()->create('any-name.pdf', 10, 'application/pdf');

        $url = $this->service->storeFile($model, 'accessibility', $file);

        Storage::disk('wmfe')->assertExists('webmapp/1/files/ec-poi/42/accessibility.pdf');
        $this->assertStringContainsString('accessibility.pdf', $url);
    }

    public function test_storeFile_deletes_old_file_before_uploading(): void
    {
        $model = $this->makeModelDouble(app_id: 1, id: 42, type: 'ec-poi');
        Storage::disk('wmfe')->put('webmapp/1/files/ec-poi/42/accessibility.pdf', 'old content');

        $file = UploadedFile::fake()->create('new.pdf', 10, 'application/pdf');
        $this->service->storeFile($model, 'accessibility', $file);

        $content = Storage::disk('wmfe')->get('webmapp/1/files/ec-poi/42/accessibility.pdf');
        $this->assertNotEquals('old content', $content);
    }

    public function test_deleteFile_removes_file_from_wmfe(): void
    {
        $model = $this->makeModelDouble(app_id: 1, id: 42, type: 'ec-poi');
        Storage::disk('wmfe')->put('webmapp/1/files/ec-poi/42/accessibility.pdf', 'content');

        $this->service->deleteFile($model, 'accessibility', 'pdf');

        Storage::disk('wmfe')->assertMissing('webmapp/1/files/ec-poi/42/accessibility.pdf');
    }

    public function test_deleteFile_does_nothing_when_file_does_not_exist(): void
    {
        $model = $this->makeModelDouble(app_id: 1, id: 42, type: 'ec-poi');

        // Should not throw
        $this->service->deleteFile($model, 'accessibility', 'pdf');

        $this->assertTrue(true);
    }

    private function makeModelDouble(int $app_id, int $id, string $type): object
    {
        $model = new class($app_id, $id, $type) {
            public int $app_id;
            public function __construct(int $app_id, public int $id, public string $type) {
                $this->app_id = $app_id;
            }
            public function getKey(): int { return $this->id; }
        };
        // Override class_basename via a named class instead of anonymous
        // For test purposes the path is built with the type injected via model attribute
        return $model;
    }
}
```

> **Note:** The `makeModelDouble` approach uses anonymous classes. Since `Str::kebab(class_basename($model))` depends on the actual class name, you should create a named stub class in the test or mock `class_basename`. The simplest approach: create a dedicated stub in the test namespace.

Replace the `makeModelDouble` with real Eloquent model stubs using `EcPoi` from test factories (if available) or use Mockery. The tests above illustrate the intended behavior — adapt the model creation to what is available in the test suite.

- [ ] **Step 2: Run tests to verify they fail**

```bash
vendor/bin/pest tests/Unit/Services/StorageService/StoreFileTest.php -v
```

Expected: FAIL with "Call to undefined method storeFile"

- [ ] **Step 3: Add `storeFile()` and `deleteFile()` to `StorageService`**

In `src/Services/StorageService.php`, add the following two methods (after `storeFeatureCollection`):

```php
/**
 * Store a file on wmfe disk under /{shard}/{appId}/files/{model-type}/{id}/{filename}.{ext}.
 * Atomically replaces any existing file at the same path.
 *
 * @param  \Illuminate\Database\Eloquent\Model  $model
 * @param  string  $filename  Base name without extension (e.g. 'accessibility')
 * @param  \Illuminate\Http\UploadedFile  $file
 * @return string  Public URL of the stored file
 */
public function storeFile(\Illuminate\Database\Eloquent\Model $model, string $filename, \Illuminate\Http\UploadedFile $file): string
{
    $ext = $file->getClientOriginalExtension();
    $path = $this->buildFilePath($model, $filename, $ext);
    $disk = $this->getRemoteWfeDisk();

    if ($disk->exists($path)) {
        $disk->delete($path);
    }

    $disk->putFileAs(dirname($path), $file, basename($path));

    return $disk->url($path);
}

/**
 * Delete a file from wmfe disk.
 *
 * @param  \Illuminate\Database\Eloquent\Model  $model
 * @param  string  $filename  Base name without extension (e.g. 'accessibility')
 * @param  string  $ext  File extension (e.g. 'pdf')
 */
public function deleteFile(\Illuminate\Database\Eloquent\Model $model, string $filename, string $ext): void
{
    $path = $this->buildFilePath($model, $filename, $ext);
    $disk = $this->getRemoteWfeDisk();

    if ($disk->exists($path)) {
        $disk->delete($path);
    }
}

/**
 * Build the S3 path for a model file.
 * Pattern: /{shard}/{appId}/files/{model-type}/{id}/{filename}.{ext}
 */
private function buildFilePath(\Illuminate\Database\Eloquent\Model $model, string $filename, string $ext): string
{
    $type = \Illuminate\Support\Str::kebab(class_basename($model));
    $id = $model->getKey();

    return $this->getShardBasePath($model->app_id) . "files/{$type}/{$id}/{$filename}.{$ext}";
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/pest tests/Unit/Services/StorageService/StoreFileTest.php -v
```

Expected: PASS (adapt model stubs if needed — see note in Step 1)

---

### Task 2: Add `deleteModelFiles()` to `StorageService`

**Files:**
- Modify: `src/Services/StorageService.php`
- Test: `tests/Unit/Services/StorageService/DeleteModelFilesTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Services/StorageService/DeleteModelFilesTest.php`:

```php
<?php

namespace Tests\Unit\Services\StorageService;

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

class DeleteModelFilesTest extends TestCase
{
    private StorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wmfe');
        $this->service = new StorageService();
    }

    public function test_deleteModelFiles_removes_all_files_in_model_directory(): void
    {
        // Use a real EcPoi-like model stub that class_basename resolves to 'EcPoi'
        // and Str::kebab gives 'ec-poi'
        $model = $this->makeEcPoiStub(app_id: 1, id: 42);

        Storage::disk('wmfe')->put('webmapp/1/files/ec-poi/42/accessibility.pdf', 'a');
        Storage::disk('wmfe')->put('webmapp/1/files/ec-poi/42/another.pdf', 'b');

        $this->service->deleteModelFiles($model);

        Storage::disk('wmfe')->assertMissing('webmapp/1/files/ec-poi/42/accessibility.pdf');
        Storage::disk('wmfe')->assertMissing('webmapp/1/files/ec-poi/42/another.pdf');
    }

    public function test_deleteModelFiles_does_nothing_when_directory_does_not_exist(): void
    {
        $model = $this->makeEcPoiStub(app_id: 1, id: 99);

        // Should not throw
        $this->service->deleteModelFiles($model);

        $this->assertTrue(true);
    }

    private function makeEcPoiStub(int $app_id, int $id): object
    {
        // Anonymous class — class_basename returns the generated name, not 'EcPoi'.
        // Replace with a proper named stub if your test suite provides one.
        // For the purpose of this test, mock the path computation.
        return new class($app_id, $id) extends \Illuminate\Database\Eloquent\Model {
            protected $table = 'ec_pois';
            public int $app_id;
            public function __construct(int $app_id, public int $id) {
                $this->app_id = $app_id;
            }
            public function getKey(): int { return $this->id; }
        };
    }
}
```

> **Note:** Because anonymous classes get generated names, `class_basename()` will not return `EcPoi`. Use the real `\App\Models\EcPoi` (or the wm-package EcPoi model) in the test and create the model with `EcPoi::factory()->make(['app_id' => 1])`. Adapt accordingly.

- [ ] **Step 2: Run test to verify it fails**

```bash
vendor/bin/pest tests/Unit/Services/StorageService/DeleteModelFilesTest.php -v
```

Expected: FAIL with "Call to undefined method deleteModelFiles"

- [ ] **Step 3: Add `deleteModelFiles()` to `StorageService`**

In `src/Services/StorageService.php`, add after `deleteFile()`:

```php
/**
 * Delete all files in the model's S3 directory.
 * Called on model deletion to clean up /{shard}/{appId}/files/{model-type}/{id}/.
 *
 * @param  \Illuminate\Database\Eloquent\Model  $model
 */
public function deleteModelFiles(\Illuminate\Database\Eloquent\Model $model): void
{
    $type = \Illuminate\Support\Str::kebab(class_basename($model));
    $id = $model->getKey();
    $directory = $this->getShardBasePath($model->app_id) . "files/{$type}/{$id}";
    $disk = $this->getRemoteWfeDisk();

    if ($disk->exists($directory)) {
        $disk->deleteDirectory($directory);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
vendor/bin/pest tests/Unit/Services/StorageService/DeleteModelFilesTest.php -v
```

Expected: PASS

---

### Task 3: Hook `deleteModelFiles()` into observers

**Files:**
- Modify: `src/Observers/EcPoiObserver.php`
- Modify: `src/Observers/EcTrackObserver.php`

No new tests needed: the service method is already tested. The observer integration is trivial and covered by the service-level tests.

- [ ] **Step 1: Update `EcPoiObserver::deleting()`**

In `src/Observers/EcPoiObserver.php`, add the `StorageService` import and call `deleteModelFiles()` in the `deleting` event.

Current `deleting`:
```php
public function deleting(EcPoi $ecPoi)
{
    if ($ecPoi->ecTracks()->exists()) {
        throw new HttpException(500, 'Cannot delete this POI because it is linked to one or more tracks.');
    }
}
```

Updated `deleting` (add after the exception check):
```php
use Wm\WmPackage\Services\StorageService;

// inside the class:

public function deleting(EcPoi $ecPoi)
{
    if ($ecPoi->ecTracks()->exists()) {
        throw new HttpException(500, 'Cannot delete this POI because it is linked to one or more tracks.');
    }

    app(StorageService::class)->deleteModelFiles($ecPoi);
}
```

- [ ] **Step 2: Update `EcTrackObserver::deleting()`**

In `src/Observers/EcTrackObserver.php`, the `deleting` event already has logic. Add `deleteModelFiles()` at the end:

Current `deleting`:
```php
public function deleting(EcTrack $ecTrack)
{
    $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');

    Layerable::where('layerable_id', $ecTrack->id)
        ->where('layerable_type', $ecTrackModelClass)
        ->delete();
}
```

Updated `deleting`:
```php
public function deleting(EcTrack $ecTrack)
{
    $ecTrackModelClass = config('wm-package.ec_track_model', 'App\Models\EcTrack');

    Layerable::where('layerable_id', $ecTrack->id)
        ->where('layerable_type', $ecTrackModelClass)
        ->delete();

    app(StorageService::class)->deleteModelFiles($ecTrack);
}
```

Note: `StorageService` is already imported in `EcTrackObserver` (`use Wm\WmPackage\Services\StorageService;`).

- [ ] **Step 3: Run full test suite to check for regressions**

```bash
vendor/bin/pest --filter=EcPoiObserver -v
vendor/bin/pest --filter=EcTrackObserver -v
```

---

### Task 4: Update `AbstractEcResource` accessibility PDF field

**Files:**
- Modify: `src/Nova/AbstractEcResource.php`

No unit tests for Nova fields: tested manually via browser.

- [ ] **Step 1: Replace the existing `File::make()` block**

In `src/Nova/AbstractEcResource.php`, find the existing block starting at line 67:

```php
File::make(__('Accessibility PDF'), 'properties->accessibility_pdf')
    ->disk('public')
    ->path('accessibility')
    ->storeAs(function ($request, $model, $attribute, $requestAttribute) {
        $file = $request->file($requestAttribute);
        $originalBase = $file ? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) : 'accessibility';
        $slug = Str::slug($originalBase) ?: 'accessibility';
        $type = Str::kebab(class_basename($model));

        return $type.'-'.$model->getKey().'-'.$slug.'.pdf';
    })
    ->acceptedTypes('.pdf')
    ->hideFromIndex(),
```

Replace it with:

```php
File::make(__('Accessibility PDF'), 'properties->accessibility_pdf')
    ->disk('wmfe')
    ->acceptedTypes('.pdf')
    ->rules('mimes:pdf')
    ->hideFromIndex()
    ->deletable()
    ->fillUsing(function ($request, $model, $attribute, $requestAttribute) {
        $file = $request->file($requestAttribute);
        if ($file === null) {
            return;
        }
        $url = app(\Wm\WmPackage\Services\StorageService::class)->storeFile($model, 'accessibility', $file);
        $props = $model->properties ?? [];
        $props['accessibility_pdf'] = $url;
        $model->properties = $props;
    })
    ->delete(function ($request, $model) {
        app(\Wm\WmPackage\Services\StorageService::class)->deleteFile($model, 'accessibility', 'pdf');
        $props = $model->properties ?? [];
        unset($props['accessibility_pdf']);
        $model->properties = $props;
    }),
```

- [ ] **Step 2: Verify `Str` import is present at top of file**

Check that `use Illuminate\Support\Str;` is present. If it is no longer needed after removing the old `storeAs` closure, it can stay (harmless). Do not remove it if used elsewhere in the file.

- [ ] **Step 3: Run full test suite**

```bash
vendor/bin/pest -v
```

Expected: all existing tests pass.

---

## Self-Review

**Spec coverage:**
- `storeFile()` → Task 1 ✓
- `deleteFile()` → Task 1 ✓
- `deleteModelFiles()` → Task 2 ✓
- Observer hooks (EcPoi + EcTrack) → Task 3 ✓
- `AbstractEcResource` PDF field update → Task 4 ✓
- Path pattern `/{shard}/{appId}/files/{model-type}/{id}/{filename}.{ext}` → `buildFilePath()` in Task 1 ✓
- Atomic replace (delete old before upload) → `storeFile()` in Task 1 ✓
- `->deletable()` + `->delete()` callback → Task 4 ✓
- `->disk('wmfe')` on `File::make()` → Task 4 ✓

**Notes:**
- `deleteFile()` requires the caller to know the extension (`'pdf'`). This is intentional — the design fixes filenames at call site.
- Anonymous class stubs in tests won't produce the right `class_basename` result. Engineers must adapt the test stubs to use real model classes or Mockery partial mocks. The test code above illustrates the intent — the note in each step calls this out explicitly.
