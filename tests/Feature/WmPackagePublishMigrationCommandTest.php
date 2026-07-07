<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class WmPackagePublishMigrationCommandTest extends TestCase
{
    use DatabaseTransactions;

    private string $fakeStubPath;

    private string $baseName = 'zz_9999_99_99_999998_publish_for_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeStubPath = base_path("wm-package/database/migrations/{$this->baseName}.php.stub");
        File::put($this->fakeStubPath, "<?php\nreturn new class extends \Illuminate\Database\Migrations\Migration {\n    public function up(): void {}\n    public function down(): void {}\n};\n");
    }

    protected function tearDown(): void
    {
        File::delete($this->fakeStubPath);

        foreach (glob(database_path('migrations/*_'.$this->baseName.'.php')) ?: [] as $published) {
            File::delete($published);
        }

        parent::tearDown();
    }

    public function test_publishes_a_specific_stub(): void
    {
        $this->artisan('wm-package:publish-migration', ['stub' => $this->baseName])
            ->assertExitCode(0);

        $matches = glob(database_path('migrations/*_'.$this->baseName.'.php'));

        $this->assertNotEmpty($matches);
    }

    public function test_fails_for_unknown_stub(): void
    {
        $this->artisan('wm-package:publish-migration', ['stub' => 'not_a_real_stub'])
            ->assertExitCode(1);
    }
}
