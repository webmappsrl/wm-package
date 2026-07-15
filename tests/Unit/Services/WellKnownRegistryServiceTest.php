<?php

namespace Tests\Unit\Services;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\WellKnownRegistryService;
use Wm\WmPackage\Tests\TestCase;

class WellKnownRegistryServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['wm-package.shard_name' => 'test_shard']);
        config(['wm-package.deep_link.apple_team_id' => 'TEAM1234X']);

        Storage::fake('well_known_registry');
        Storage::fake('pois');
        Storage::fake('wmfe');
        Storage::fake('conf');
    }

    private function makeApp(array $overrides = []): App
    {
        return App::factory()->create(array_merge([
            'sku' => 'it.webmapp.testapp',
            'properties' => ['android_cert_sha256' => str_repeat('AB:', 31).'CD'],
        ], $overrides));
    }

    private function addAppEntry(App $app): void
    {
        WellKnownRegistryService::make()->addAppEntry($app->sku, $app->properties['android_cert_sha256'] ?? null);
    }

    /** @test */
    public function adding_an_app_creates_both_well_known_files_from_scratch()
    {
        $app = $this->makeApp();

        $this->addAppEntry($app);

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertSame('TEAM1234X.it.webmapp.testapp', $apple['applinks']['details'][0]['appID']);
        $this->assertSame(['*'], $apple['applinks']['details'][0]['paths']);
    }

    /** @test */
    public function a_custom_per_app_team_id_overrides_the_default()
    {
        $app = $this->makeApp();

        WellKnownRegistryService::make()->addAppEntry($app->sku, null, 'CUSTOMTEAM1');

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertSame('CUSTOMTEAM1.it.webmapp.testapp', $apple['applinks']['details'][0]['appID']);
    }

    /** @test */
    public function adding_an_app_preserves_existing_entries_of_other_apps()
    {
        Storage::disk('well_known_registry')->put('apple-app-site-association', json_encode([
            'applinks' => ['apps' => [], 'details' => [
                ['appID' => 'TEAM1234X.it.webmapp.otherapp', 'paths' => ['/map*']],
            ]],
        ]));
        Storage::disk('well_known_registry')->put('assetlinks.json', json_encode([
            ['relation' => ['delegate_permission/common.handle_all_urls'], 'target' => [
                'namespace' => 'android_app', 'package_name' => 'it.webmapp.otherapp', 'sha256_cert_fingerprints' => ['XX'],
            ]],
        ]));

        $app = $this->makeApp();
        $this->addAppEntry($app);

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertCount(2, $apple['applinks']['details']);

        $android = json_decode(Storage::disk('well_known_registry')->get('assetlinks.json'), true);
        $this->assertCount(2, $android);
    }

    /** @test */
    public function removing_an_app_only_removes_its_own_entry()
    {
        $app = $this->makeApp();
        $otherApp = $this->makeApp(['sku' => 'it.webmapp.otherapp']);

        $this->addAppEntry($app);
        $this->addAppEntry($otherApp);
        WellKnownRegistryService::make()->removeAppEntry($app->sku);

        $apple = json_decode(Storage::disk('well_known_registry')->get('apple-app-site-association'), true);
        $this->assertCount(1, $apple['applinks']['details']);
        $this->assertSame('TEAM1234X.it.webmapp.otherapp', $apple['applinks']['details'][0]['appID']);

        $android = json_decode(Storage::disk('well_known_registry')->get('assetlinks.json'), true);
        $this->assertCount(1, $android);
        $this->assertSame('it.webmapp.otherapp', $android[0]['target']['package_name']);
    }

    /** @test */
    public function a_backup_is_created_before_overwriting_existing_files()
    {
        $app = $this->makeApp();

        $this->addAppEntry($app);
        WellKnownRegistryService::make()->removeAppEntry($app->sku);

        $backups = collect(Storage::disk('well_known_registry')->files())
            ->filter(fn ($file) => str_contains($file, '.backup-'));

        $this->assertGreaterThanOrEqual(2, $backups->count());
    }

    /** @test */
    public function multiple_comma_separated_fingerprints_are_all_included()
    {
        $app = $this->makeApp(['properties' => [
            'android_cert_sha256' => str_repeat('AB:', 31).'CD, '.str_repeat('EF:', 31).'01',
        ]]);

        $this->addAppEntry($app);

        $android = json_decode(Storage::disk('well_known_registry')->get('assetlinks.json'), true);
        $this->assertSame([
            str_repeat('AB:', 31).'CD',
            str_repeat('EF:', 31).'01',
        ], $android[0]['target']['sha256_cert_fingerprints']);
    }

    /** @test */
    public function android_entry_is_skipped_when_fingerprint_is_missing()
    {
        $app = $this->makeApp(['properties' => ['android_cert_sha256' => null]]);

        $this->addAppEntry($app);

        $android = json_decode(Storage::disk('well_known_registry')->get('assetlinks.json'), true);
        $this->assertCount(0, $android);
    }

    /** @test */
    public function malformed_existing_apple_file_aborts_sync_without_overwriting()
    {
        Storage::disk('well_known_registry')->put('apple-app-site-association', '{not valid json');

        $app = $this->makeApp();

        $this->expectException(\RuntimeException::class);
        $this->addAppEntry($app);

        $this->assertSame('{not valid json', Storage::disk('well_known_registry')->get('apple-app-site-association'));
    }
}
