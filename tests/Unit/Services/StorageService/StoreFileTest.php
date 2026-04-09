<?php

namespace Tests\Unit\Services\StorageService;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

class StoreFileTest extends TestCase
{
    private StorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wmfe');
        $this->service = new StorageService;
    }

    public function test_store_file_uploads_to_correct_path_and_returns_url(): void
    {
        $model = $this->makeEcPoiStub(1, 42);
        $file = UploadedFile::fake()->create('any-name.pdf', 10, 'application/pdf');

        $url = $this->service->storeFile($model, 'accessibility', $file);

        Storage::disk('wmfe')->assertExists('/webmapp/1/files/ec-poi/42/accessibility.pdf');
        $this->assertStringContainsString('/webmapp/1/files/ec-poi/42/accessibility.pdf', $url);
    }

    public function test_store_file_replaces_existing_target_file(): void
    {
        $model = $this->makeEcPoiStub(1, 42);
        Storage::disk('wmfe')->put('/webmapp/1/files/ec-poi/42/accessibility.pdf', 'old content');
        $oldHash = md5((string) Storage::disk('wmfe')->get('/webmapp/1/files/ec-poi/42/accessibility.pdf'));

        $file = UploadedFile::fake()->create('new.pdf', 10, 'application/pdf');
        $this->service->storeFile($model, 'accessibility', $file);

        $newHash = md5((string) Storage::disk('wmfe')->get('/webmapp/1/files/ec-poi/42/accessibility.pdf'));
        $this->assertNotSame($oldHash, $newHash);
    }

    public function test_delete_file_removes_file_from_wmfe(): void
    {
        $model = $this->makeEcPoiStub(1, 42);
        Storage::disk('wmfe')->put('/webmapp/1/files/ec-poi/42/accessibility.pdf', 'content');

        $this->service->deleteFile($model, 'accessibility');

        Storage::disk('wmfe')->assertMissing('/webmapp/1/files/ec-poi/42/accessibility.pdf');
    }

    public function test_delete_file_does_nothing_when_file_does_not_exist(): void
    {
        $model = $this->makeEcPoiStub(1, 42);

        $this->service->deleteFile($model, 'accessibility');

        $this->assertTrue(true);
    }

    private function makeEcPoiStub(int $appId, int $id): EcPoi
    {
        $model = new EcPoi;
        $model->app_id = $appId;
        $model->id = $id;
        $model->properties = [];

        return $model;
    }
}
