<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Spatie\DbDumper\Compressors\GzipCompressor;
use Spatie\DbDumper\Databases\PostgreSql;
use Wm\WmPackage\Services\StorageService;
use Wm\WmPackage\Tests\TestCase;

class DumpDbToAWSTest extends TestCase
{
    protected $backupsPath;

    protected function setUp(): void
    {
        parent::setUp();

        // Configure test environment
        config(['app.name' => 'TestApp']);
        config(['database.default' => 'testing']);
        config(['database.connections.testing' => [
            'driver' => 'pgsql',
            'database' => 'testdb',
            'username' => 'user',
            'password' => 'secret',
            'host' => 'localhost',
            'port' => 5432,
        ]]);

        // Set backup directory (storage_path('app/backups'))
        $this->backupsPath = storage_path('app/backups');
        if (File::exists($this->backupsPath)) {
            File::deleteDirectory($this->backupsPath);
        }
    }

    protected function tearDown(): void
    {
        if (File::exists($this->backupsPath)) {
            File::deleteDirectory($this->backupsPath);
        }
        Mockery::close();
    }

    public function test_dump_db_to_aws_success()
    {
        // Create PostgreSql mock to simulate dump creation
        $postgreMock = Mockery::mock('alias:'.PostgreSql::class);
        $postgreMock->shouldReceive('create')
            ->once()
            ->andReturnSelf();
        $postgreMock->shouldReceive('setDumpBinaryPath')->andReturnSelf();
        $postgreMock->shouldReceive('setDbName')->andReturnSelf();
        $postgreMock->shouldReceive('setUserName')->andReturnSelf();
        $postgreMock->shouldReceive('setPassword')->andReturnSelf();
        $postgreMock->shouldReceive('setHost')->andReturnSelf();
        $postgreMock->shouldReceive('setPort')->andReturnSelf();
        $postgreMock->shouldReceive('useCompressor')
            ->with(Mockery::type(GzipCompressor::class))
            ->andReturnSelf();
        $postgreMock->shouldReceive('dumpToFile')
            ->once()
            ->with(Mockery::on(function ($localPath) {
                return is_string($localPath) && ! empty($localPath);
            }))
            ->andReturnUsing(function ($localPath) {
                // Simulate dump creation by writing dummy content to file
                file_put_contents($localPath, 'fake dump');

                return null; //  Added return null to match the return type
            });

        // Create StorageService mock to simulate AWS upload
        $storageServiceMock = Mockery::mock(StorageService::class);
        $remotePrefix = config('app.name').'/';
        $remotePath = $remotePrefix.'fake_dump.sql.gz'; // Construct the expected remote path
        $storageServiceMock->shouldReceive('storeDbDumpToAws')
            ->once()
            ->with(Mockery::on(function ($argRemotePath) use ($remotePrefix) {
                return strpos($argRemotePath, $remotePrefix) === 0;
            }), 'fake dump')
            ->andReturn(true);
        // Simulate old dumps cleanup behavior
        $storageServiceMock->shouldReceive('cleanOldDumpsFromAws')
            ->once()
            ->with(config('app.name'))
            ->andReturnNull();

        $this->app->instance(StorageService::class, $storageServiceMock);

        // Execute command
        $exitCode = Artisan::call('db:dump_to_aws');
        $this->assertEquals(0, $exitCode);

        // Verify output contains expected messages
        $output = Artisan::output();
        $this->assertStringContainsString('Dump created locally:', $output);
        $this->assertStringContainsString('Dump uploaded correctly to AWS:', $output);

        // Verify local file was deleted (unlink is called after upload)
        $this->assertEmpty(File::files($this->backupsPath), 'Dump file should not exist in local directory after upload.');
    }

    public function test_dump_db_to_aws_fails_on_dump_creation()
    {
        // Simulate exception during dump creation (dumpToFile fails)
        $postgreMock = Mockery::mock('alias:'.PostgreSql::class);
        $postgreMock->shouldReceive('create')
            ->once()
            ->andReturnSelf();
        $postgreMock->shouldReceive('setDumpBinaryPath')->andReturnSelf();
        $postgreMock->shouldReceive('setDbName')->andReturnSelf();
        $postgreMock->shouldReceive('setUserName')->andReturnSelf();
        $postgreMock->shouldReceive('setPassword')->andReturnSelf();
        $postgreMock->shouldReceive('setHost')->andReturnSelf();
        $postgreMock->shouldReceive('setPort')->andReturnSelf();
        $postgreMock->shouldReceive('useCompressor')
            ->with(Mockery::type(GzipCompressor::class))
            ->andReturnSelf();
        $postgreMock->shouldReceive('dumpToFile')
            ->once()
            ->andThrow(new \Exception('Simulated dump failure'));

        $exitCode = Artisan::call('db:dump_to_aws');
        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Error creating the database dump:', $output);
    }

    public function test_dump_db_to_aws_fails_on_empty_dump_file()
    {
        // Simulate dump creation that generates an empty file
        $postgreMock = Mockery::mock('alias:'.PostgreSql::class);
        $postgreMock->shouldReceive('create')
            ->once()
            ->andReturnSelf();
        $postgreMock->shouldReceive('setDumpBinaryPath')->andReturnSelf();
        $postgreMock->shouldReceive('setDbName')->andReturnSelf();
        $postgreMock->shouldReceive('setUserName')->andReturnSelf();
        $postgreMock->shouldReceive('setPassword')->andReturnSelf();
        $postgreMock->shouldReceive('setHost')->andReturnSelf();
        $postgreMock->shouldReceive('setPort')->andReturnSelf();
        $postgreMock->shouldReceive('useCompressor')
            ->with(Mockery::type(GzipCompressor::class))
            ->andReturnSelf();
        $postgreMock->shouldReceive('dumpToFile')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturnUsing(function ($localPath) {
                // Write empty file (invalid dump)
                file_put_contents($localPath, '');
            });

        $exitCode = Artisan::call('db:dump_to_aws');
        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Unable to read the generated dump', $output);
    }

    public function test_dump_db_to_aws_fails_on_upload()
    {
        // Simulate successful dump creation
        $postgreMock = Mockery::mock('alias:'.PostgreSql::class);
        $postgreMock->shouldReceive('create')
            ->once()
            ->andReturnSelf();
        $postgreMock->shouldReceive('setDumpBinaryPath')->andReturnSelf();
        $postgreMock->shouldReceive('setDbName')->andReturnSelf();
        $postgreMock->shouldReceive('setUserName')->andReturnSelf();
        $postgreMock->shouldReceive('setPassword')->andReturnSelf();
        $postgreMock->shouldReceive('setHost')->andReturnSelf();
        $postgreMock->shouldReceive('setPort')->andReturnSelf();
        $postgreMock->shouldReceive('useCompressor')
            ->with(Mockery::type(GzipCompressor::class))
            ->andReturnSelf();
        $postgreMock->shouldReceive('dumpToFile')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturnUsing(function ($localPath) {
                file_put_contents($localPath, 'fake dump content');
            });

        // Simulate AWS upload error by making storeDbDumpToAws fail
        $storageServiceMock = Mockery::mock(StorageService::class);
        $storageServiceMock->shouldReceive('storeDbDumpToAws')
            ->once()
            ->andThrow(new \Exception('Simulated upload failure'));
        // cleanOldDumpsFromAws is not called when upload fails
        $this->app->instance(StorageService::class, $storageServiceMock);

        $exitCode = Artisan::call('db:dump_to_aws');
        $this->assertEquals(1, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Error uploading the database dump to AWS:', $output);

        // Local file should remain in case of upload error
        $this->assertNotEmpty(File::files($this->backupsPath), 'Dump file should exist in local directory if upload fails.');
    }
}
