<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Tests\TestCase;

class DownloadDbFromAWSTest extends TestCase
{
    protected $wmdumpsRoot;
    protected $backupsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake database configuration
        config([
            'database.default' => 'testing',
            'database.connections.testing' => [
                'driver'   => 'pgsql',
                'database' => 'test',
                'host'     => '127.0.0.1',
                'username' => 'root',
                'password' => '',
            ],
        ]);

        // Imposta il disco 'wmdumps' su un percorso locale fittizio
        $this->wmdumpsRoot = storage_path('app/fake_wmdumps');
        config(['filesystems.disks.wmdumps' => [
            'driver' => 'local',
            'root'   => $this->wmdumpsRoot,
        ]]);

        // Clean up the fake directory if it exists and recreate it
        if (is_dir($this->wmdumpsRoot)) {
            File::deleteDirectory($this->wmdumpsRoot);
        }
        mkdir($this->wmdumpsRoot, 0755, true);

        // Set up the backups path where the downloaded dump will be written
        $this->backupsPath = storage_path('app/backups');
        if (is_dir($this->backupsPath)) {
            File::deleteDirectory($this->backupsPath);
        }
        mkdir($this->backupsPath, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temporary directories
        File::deleteDirectory($this->wmdumpsRoot);
        File::deleteDirectory($this->backupsPath);
    }

    public function testDownloadAndSkipImport()
    {
        // Simulate the existence of a dump in the wmdumps/testApp directory
        $appName = 'testApp';
        $dumpFileName = 'dump_' . Carbon::now()->format('Y_m_d') . '.sql.gz';
        $fullDumpPath = $appName . '/' . $dumpFileName;

        // Create the fake directory on the wmdumps disk
        Storage::disk('wmdumps')->makeDirectory($appName);

        // Insert a dump file with fake content
        $dumpContent = 'Database dump content';
        Storage::disk('wmdumps')->put($fullDumpPath, $dumpContent);

        // Execute the command, simulating a "no" response when asked about import
        $this->artisan('db:download_from_aws', ['appName' => $appName])
            ->expectsConfirmation('Do you want to import the downloaded database dump?', 'no')
            ->expectsOutput("Database import skipped.")
            ->assertExitCode(0);

        // Verify that the dump file was written to the backups folder
        $backupFile = $this->backupsPath . '/' . basename($fullDumpPath);
        $this->assertFileExists($backupFile);

        // Check that the file content is correct
        $this->assertEquals($dumpContent, file_get_contents($backupFile));
    }

    public function testNoDumpsFound()
    {
        // Simulate the case where no dumps exist for the specified app
        $appName = 'appInesistente';
        // Make sure the wmdumps/appInesistente directory doesn't exist
        Storage::disk('wmdumps')->deleteDirectory($appName);

        $this->artisan('db:download_from_aws', ['appName' => $appName])
            ->expectsOutput("No dumps found in AWS path: wmdumps/" . $appName)
            ->assertExitCode(1);
    }
}
