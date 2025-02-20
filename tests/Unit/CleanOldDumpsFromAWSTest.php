<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

class CleanOldDumpsFromAWSTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Override "wmdumps" disk to use a fake local path
        config(['filesystems.disks.wmdumps' => [
            'driver' => 'local',
            'root' => storage_path('app/fake_wmdumps'),
        ]]);

        // Create test directory
        Storage::disk('wmdumps')->makeDirectory('test_dir');
    }

    protected function tearDown(): void
    {
        // Clean up test directory after each test
        Storage::disk('wmdumps')->deleteDirectory('test_dir');
    }

    public function test_clean_old_dumps_from_aws_removes_old_files()
    {
        $daysToKeep = 7;
        $storageService = new StorageService;

        $disk = Storage::disk('wmdumps');
        $directory = 'test_dir';

        $oldFileName = $directory.'/old_dump.sql.gz';
        $recentFileName = $directory.'/recent_dump.sql.gz';

        $disk->put($oldFileName, 'Old dump');
        $disk->put($recentFileName, 'Recent dump');

        // Set modification times:
        // - old file: 8 days ago (beyond threshold)
        // - recent file: today (within threshold)
        $oldTimestamp = Carbon::now()->subDays($daysToKeep + 1)->getTimestamp();
        $recentTimestamp = Carbon::now()->getTimestamp();

        // Get real file paths and modify timestamps using touch
        $oldFilePath = $disk->path($oldFileName);
        $recentFilePath = $disk->path($recentFileName);
        touch($oldFilePath, $oldTimestamp);
        touch($recentFilePath, $recentTimestamp);

        // Verify both files exist before cleaning
        $this->assertTrue($disk->exists($oldFileName));
        $this->assertTrue($disk->exists($recentFileName));

        // Run cleanup: function should only remove files that are too old
        $storageService->cleanOldDumpsFromAws($directory, $daysToKeep);

        $this->assertFalse($disk->exists($oldFileName));
        $this->assertTrue($disk->exists($recentFileName));
    }
}
