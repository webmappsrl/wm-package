<?php

namespace Wm\WmPackage\Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Wm\WmPackage\Commands\Concerns\InteractsWithWmPackageMigrationStubs;

class InteractsWithWmPackageMigrationStubsTest extends TestCase
{
    private object $subject;

    private string $fixtureDomainDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subject = new class
        {
            use InteractsWithWmPackageMigrationStubs;
        };

        // La fixture di un dominio opzionale vive solo per la durata del test:
        // non deve finire nell'artefatto che il package distribuisce ai consumer.
        $this->fixtureDomainDir = base_path('wm-package/database/migrations/fixture_domain');
        File::ensureDirectoryExists($this->fixtureDomainDir);
        File::put($this->fixtureDomainDir.'/create_fixture_domain_table.php.stub', <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wm_fixture_domain', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wm_fixture_domain');
    }
};
PHP);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureDomainDir);

        parent::tearDown();
    }

    public function test_stub_base_names_returns_at_least_one_real_stub(): void
    {
        $names = $this->subject->stubBaseNames();

        $this->assertNotEmpty($names);
        $this->assertContains('zz_2026_06_26_000001_add_editor_role', $names);
    }

    public function test_find_published_filename_for_stub_matches_by_suffix(): void
    {
        $found = $this->subject->findPublishedFilenameForStub('zz_2026_06_26_000001_add_editor_role');

        $this->assertNotNull($found);
        $this->assertStringEndsWith('zz_2026_06_26_000001_add_editor_role', $found);
    }

    public function test_find_published_filename_for_stub_returns_null_when_not_published(): void
    {
        $found = $this->subject->findPublishedFilenameForStub('zz_9999_99_99_999999_never_published');

        $this->assertNull($found);
    }

    /**
     * Sostituisce un test precedente che asseriva lo stato di `create_users_table`
     * nel consumer: quello stub e' stato pubblicato in forestas con oc:8492, e
     * l'asserzione dipendeva dallo schema dell'ambiente invece che dal codice.
     * La fixture di dominio non e' pubblicata da nessuna parte, quindi le tre
     * asserzioni valgono ovunque giri la suite.
     */
    public function test_unpublished_stub_is_reported_as_needing_publishing(): void
    {
        $identifier = 'fixture_domain/create_fixture_domain_table';

        $this->assertFalse($this->subject->isAppliedToDatabase($identifier));
        $this->assertTrue($this->subject->needsPublishing($identifier));
        $this->assertContains('manca tabella wm_fixture_domain', $this->subject->schemaGapsForStub($identifier));
    }

    public function test_root_stubs_are_unchanged_when_no_domain_is_enabled(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => false]]]);

        $names = $this->subject->stubBaseNames();

        $this->assertContains('zz_2026_06_26_000001_add_editor_role', $names);
        $this->assertSame(
            $names,
            array_values(array_filter($names, fn (string $n) => ! str_contains($n, '/'))),
            'A dominio spento nessun identificatore qualificato deve comparire.'
        );
    }

    public function test_enabled_domain_adds_only_its_own_stubs(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => false]]]);
        $before = $this->subject->stubBaseNames();

        config(['wm-package.features' => ['fixture_domain' => ['enabled' => true]]]);
        $after = $this->subject->stubBaseNames();

        $this->assertSame(
            ['fixture_domain/create_fixture_domain_table'],
            array_values(array_diff($after, $before)),
        );
    }

    public function test_extra_domains_are_added_even_when_disabled(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => false]]]);

        $names = $this->subject->stubBaseNames(['fixture_domain']);

        $this->assertContains('fixture_domain/create_fixture_domain_table', $names);
    }

    public function test_find_stub_path_resolves_a_qualified_identifier(): void
    {
        $path = $this->subject->findStubPath('fixture_domain/create_fixture_domain_table');

        $this->assertNotNull($path);
        $this->assertStringEndsWith(
            'database/migrations/fixture_domain/create_fixture_domain_table.php.stub',
            $path,
        );
    }

    public function test_base_name_from_identifier_strips_the_domain(): void
    {
        $this->assertSame(
            'create_fixture_domain_table',
            $this->subject->baseNameFromIdentifier('fixture_domain/create_fixture_domain_table'),
        );
        $this->assertSame(
            'create_users_table',
            $this->subject->baseNameFromIdentifier('create_users_table'),
        );
    }
}
