<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Tests\TestCase;
use Wm\WmPackage\Exporters\ModelExporter;
use Maatwebsite\Excel\Facades\Excel;

class ExportTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @test */
    public function it_can_export_collection_to_excel()
    {
        $data = collect([
            ['id' => 1, 'name' => 'Test 1', 'active' => true],
            ['id' => 2, 'name' => 'Test 2', 'active' => false],
        ]);

        $columns = [
            'id' => 'ID',
            'name' => 'Nome',
            'active' => 'Attivo'
        ];

        $exporter = new ModelExporter($data, $columns);
        $fileName = 'test-export.xlsx';

        Excel::store($exporter, $fileName, 'public');

        Storage::disk('public')->assertExists($fileName);
    }
}
