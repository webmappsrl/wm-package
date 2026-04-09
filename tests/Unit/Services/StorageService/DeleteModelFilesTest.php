<?php

namespace Tests\Unit\Services\StorageService;

use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

class DeleteModelFilesTest extends TestCase
{
    private StorageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('wmfe');
        $this->service = new StorageService;
    }

    public function test_delete_model_files_removes_all_files_in_model_directory(): void
    {
        $model = $this->makeEcPoiStub(1, 42);
        Storage::disk('wmfe')->put('/webmapp/1/files/ec-poi/42/accessibility.pdf', 'a');
        Storage::disk('wmfe')->put('/webmapp/1/files/ec-poi/42/another.pdf', 'b');

        $this->service->deleteModelFiles($model);

        Storage::disk('wmfe')->assertMissing('/webmapp/1/files/ec-poi/42/accessibility.pdf');
        Storage::disk('wmfe')->assertMissing('/webmapp/1/files/ec-poi/42/another.pdf');
    }

    public function test_delete_model_files_does_nothing_when_directory_does_not_exist(): void
    {
        $model = $this->makeEcPoiStub(1, 99);

        $this->service->deleteModelFiles($model);

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
