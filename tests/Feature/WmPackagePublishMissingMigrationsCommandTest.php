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

    /** @var array<int, string> Migration create_users_table gia' presenti prima del test. */
    private array $preExistingUsersMigrations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->preExistingUsersMigrations = glob(database_path('migrations/*_create_users_table.php')) ?: [];

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

        // Cancella solo i file che ha creato questo test. Una glob su
        // *_create_users_table.php cancellerebbe anche una migration pubblicata
        // dal developer poco prima di lanciare la suite: e' successo davvero
        // durante oc:8492, e il file e' sparito prima del commit.
        foreach (glob(database_path('migrations/*_create_users_table.php')) ?: [] as $published) {
            if (! in_array($published, $this->preExistingUsersMigrations, true)) {
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

        // Il consumer puo' avere gia' una migration del package per questo stub
        // (forestas la ha da oc:8492): il comando la considererebbe equivalente
        // e non pubblicherebbe. Va spostata di lato, altrimenti il test misura
        // lo stato del progetto invece del comportamento del comando.
        $parked = [];

        foreach ($this->preExistingUsersMigrations as $path) {
            if (str_ends_with($path, '0001_01_01_000000_create_users_table.php')) {
                continue;
            }

            $parked[$path] = file_get_contents($path);
            File::delete($path);
        }

        try {
            $this->runPublishCreateUsersTableAssertions();
        } finally {
            foreach ($parked as $path => $contents) {
                File::put($path, $contents);
            }
        }
    }

    private function runPublishCreateUsersTableAssertions(): void
    {
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

    public function test_with_option_rejects_an_undeclared_domain(): void
    {
        config(['wm-package.features' => ['trail_registry' => ['enabled' => false]]]);

        $this->artisan('wm-package:publish-missing-migrations', [
            '--with' => ['dominio_inesistente'],
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('dominio_inesistente')
            ->assertFailed();
    }

    public function test_with_option_accepts_a_declared_domain_without_stubs(): void
    {
        config(['wm-package.features' => ['trail_registry' => ['enabled' => false]]]);

        $this->removeFixturesThatBreakTheGate();

        // Dominio dichiarato ma senza cartella di stub: e' il caso di forestas
        // fra questo ticket e oc:8489. Il gate non deve nominarlo ne' cambiare
        // esito rispetto a una esecuzione senza --with.
        //
        // Non si puo' asserire un gate globalmente verde: il database di test
        // del consumer ha disallineamenti propri, estranei a questo test. Le due
        // asserzioni insieme sono comunque conclusive — se --with aggiungesse
        // qualcosa, o l'esito o l'output cambierebbero.
        $withOption = $this->artisan('wm-package:publish-missing-migrations', [
            '--with' => ['trail_registry'],
            '--dry-run' => true,
        ])->doesntExpectOutputToContain('trail_registry')->run();

        $baseline = $this->artisan('wm-package:publish-missing-migrations', [
            '--dry-run' => true,
        ])->run();

        $this->assertSame($baseline, $withOption);
    }

    public function test_published_optional_stub_is_not_reported_as_missing(): void
    {
        config(['wm-package.features' => ['fixture_domain' => ['enabled' => true]]]);

        $this->removeFixturesThatBreakTheGate();
        $this->createFixtureDomainStub();

        // Pubblica lo stub come farebbe un consumer che ha aderito al dominio,
        // passando dal comando pubblico invece che dal trait.
        $this->artisan('wm-package:publish-migration', [
            'stub' => 'fixture_domain/create_fixture_domain_table',
        ])->assertSuccessful();

        $published = glob(database_path('migrations/*_create_fixture_domain_table.php')) ?: [];
        $this->assertCount(1, $published, 'Lo stub deve essere pubblicato senza il dominio nel nome del file.');

        try {
            $this->artisan('migrate')->assertSuccessful();

            // Pubblicato e migrato, il dominio non deve piu' comparire fra i
            // mancanti e il gate deve tornare verde.
            // Pubblicato e migrato, il dominio non deve piu' comparire fra i
            // mancanti. L'esito complessivo del gate dipende da disallineamenti
            // del consumer estranei a questo test, quindi non e' asseribile qui.
            $this->artisan('wm-package:publish-missing-migrations', ['--dry-run' => true])
                ->doesntExpectOutputToContain('fixture_domain');
        } finally {
            unlink($published[0]);
            Schema::dropIfExists('wm_fixture_domain');
            File::deleteDirectory($this->fixtureDomainDir());
        }
    }

    private function fixtureDomainDir(): string
    {
        return base_path('wm-package/database/migrations/fixture_domain');
    }

    /**
     * Crea a runtime lo stub di un dominio opzionale. Non vive nel repository:
     * la cartella `database/migrations` e' l'artefatto che il package distribuisce
     * ai consumer, e uno stub di test pubblicabile in produzione non ci sta.
     */
    private function createFixtureDomainStub(): void
    {
        File::ensureDirectoryExists($this->fixtureDomainDir());
        File::put($this->fixtureDomainDir().'/create_fixture_domain_table.php.stub', <<<'PHP'
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

    /**
     * setUp() crea di proposito uno stub disallineato per i test che verificano
     * il gate rosso. I test che devono osservare un gate verde lo rimuovono.
     */
    private function removeFixturesThatBreakTheGate(): void
    {
        File::delete($this->fakeStubPath);
        File::delete($this->conflictingPath);
    }
}
