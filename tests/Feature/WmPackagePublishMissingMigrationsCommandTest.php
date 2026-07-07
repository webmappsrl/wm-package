<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WmPackagePublishMissingMigrationsCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $fakeStubPath;

    private string $baseName = 'zz_9999_99_99_999996_missing_publish_for_test';

    private string $conflictingPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStubPath = base_path("wm-package/database/migrations/{$this->baseName}.php.stub");
        $this->conflictingPath = database_path('migrations/0001_01_01_000000_'.$this->baseName.'.php');

        File::put($this->fakeStubPath, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('zz_9999_99_99_999996_missing_publish_for_test_flag')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('zz_9999_99_99_999996_missing_publish_for_test_flag');
        });
    }
};
PHP);

        File::put($this->conflictingPath, <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('wrong_suffix_only')->nullable();
        });
    }

    public function down(): void {}
};
PHP);
    }

    protected function tearDown(): void
    {
        File::delete($this->fakeStubPath);
        File::delete($this->conflictingPath);

        foreach (glob(database_path('migrations/*_'.$this->baseName.'.php')) ?: [] as $published) {
            if ($published !== $this->conflictingPath) {
                File::delete($published);
            }
        }

        foreach (glob(database_path('migrations/*_create_users_table.php')) ?: [] as $published) {
            if (! str_ends_with($published, '0001_01_01_000000_create_users_table.php')) {
                File::delete($published);
            }
        }

        if (Schema::hasColumn('users', 'zz_9999_99_99_999996_missing_publish_for_test_flag')) {
            Schema::table('users', function ($table) {
                $table->dropColumn('zz_9999_99_99_999996_missing_publish_for_test_flag');
            });
        }

        parent::tearDown();
    }

    public function test_dry_run_lists_stub_with_wrong_suffix_file_and_missing_db_columns(): void
    {
        $this->artisan('wm-package:publish-missing-migrations', ['--dry-run' => true])
            ->assertExitCode(1)
            ->expectsOutputToContain($this->baseName);
    }

    public function test_publishes_stub_even_when_suffix_file_has_different_content(): void
    {
        $this->artisan('wm-package:publish-migration', ['stub' => $this->baseName])
            ->assertExitCode(0)
            ->expectsOutputToContain('Pubblicata:');

        $matches = array_filter(
            glob(database_path('migrations/*_'.$this->baseName.'.php')) ?: [],
            fn (string $path) => $path !== $this->conflictingPath,
        );

        $this->assertCount(1, $matches);
        $this->assertStringContainsString(
            'zz_9999_99_99_999996_missing_publish_for_test_flag',
            file_get_contents(array_values($matches)[0]),
        );
    }

    public function test_publish_migration_publishes_create_users_table_despite_laravel_suffix_file(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'balance'));

        $this->artisan('wm-package:publish-migration', ['stub' => 'create_users_table'])
            ->assertExitCode(0)
            ->expectsOutputToContain('contenuto diverso')
            ->expectsOutputToContain('Pubblicata:');

        $matches = array_filter(
            glob(database_path('migrations/*_create_users_table.php')) ?: [],
            fn (string $path) => ! str_ends_with($path, '0001_01_01_000000_create_users_table.php'),
        );

        $this->assertCount(1, $matches);
        $this->assertStringContainsString('balance', file_get_contents(array_values($matches)[0]));
    }
}
