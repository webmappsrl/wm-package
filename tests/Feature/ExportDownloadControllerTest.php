<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Wm\WmPackage\Tests\TestCase;

class ExportDownloadControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    /** @test */
    public function it_downloads_existing_file()
    {
        // Create a test file
        $content = 'test file content';
        $fileName = 'test-file.xlsx';
        Storage::disk('public')->put($fileName, $content);

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->assertHeader('Content-Disposition', 'attachment; filename='.$fileName);
    }

    /** @test */
    public function it_deletes_file_after_download()
    {
        // Create a test file
        $content = 'test file content';
        $fileName = 'test-file.xlsx';
        Storage::disk('public')->put($fileName, $content);

        // Verify that the file exists before the download
        $this->assertTrue(Storage::disk('public')->exists($fileName));

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        $response = $this->get($signedUrl);

        // Force the deletion callback to run because the deletefileaftersend() is not working with tests (https://github.com/laravel/framework/issues/36286)
        $response->sendContent();

        $this->assertFalse(Storage::disk('public')->exists($fileName));
    }

    /** @test */
    public function it_returns_correct_mime_type()
    {
        $fileName = 'test-file.xlsx';
        Storage::disk('public')->put($fileName, 'Excel content');

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        $response = $this->get($signedUrl);

        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
