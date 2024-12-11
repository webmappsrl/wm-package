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
    public function it_can_download_file()
    {
        $content = 'test content';
        $fileName = 'test.txt';
        Storage::disk('public')->put($fileName, $content);

        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => $fileName]
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(200)
            ->assertHeader('content-type', 'text/plain; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename='.$fileName);
    }

    /** @test */
    public function it_returns_404_for_non_existent_file()
    {
        $signedUrl = URL::temporarySignedRoute(
            'download.export',
            now()->addMinutes(5),
            ['fileName' => 'non-existent.txt']
        );

        $response = $this->get($signedUrl);

        $response->assertStatus(404);
    }
}
