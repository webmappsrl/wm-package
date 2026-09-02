<?php

namespace Wm\WmPackage\Tests\Feature;

require_once __DIR__.'/../Concerns/SharesGeohubConnectionWithLocal.php';
require_once __DIR__.'/../Concerns/InjectsGeohubImportService.php';

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Wm\WmPackage\Jobs\Import\ImportEcTrackJob;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Services\Import\GeohubImportService;
use Wm\WmPackage\Tests\Concerns\InjectsGeohubImportService;
use Wm\WmPackage\Tests\Concerns\SharesGeohubConnectionWithLocal;

class ImportEcTrackJobThemeSyncTest extends TestCase
{
    use DatabaseTransactions, InjectsGeohubImportService, SharesGeohubConnectionWithLocal;

    private GeohubImportService $geohubImportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shareGeohubConnectionWithLocal();

        $this->geohubImportService = app(GeohubImportService::class);
    }

    private function makeJob(int $geohubTrackId): \Wm\WmPackage\Jobs\Import\BaseImportJob
    {
        return $this->makeJobWithGeohubImportService(ImportEcTrackJob::class, $geohubTrackId, $this->geohubImportService);
    }

    public function test_track_theme_pivot_is_populated_from_embedded_properties(): void
    {
        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Embedded Theme']),
            'identifier' => 'osm2cai-sda4-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 7001]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identifier = DB::table('taxonomy_themes')->where('id', $themeId)->value('identifier');

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30001,
                'themes' => json_encode(['7001' => [$identifier]]),
            ],
        ]);

        $job = $this->makeJob(30001);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30001], $track);

        $this->assertTrue(
            $track->taxonomyThemes()->where('taxonomy_themes.id', $themeId)->exists(),
            'Il pivot taxonomy_themeables locale deve essere popolato leggendo properties[themes], come già avviene per activities'
        );
    }

    public function test_track_activity_pivot_is_populated_from_embedded_properties(): void
    {
        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'Embedded Activity']),
            'identifier' => 'osm2cai-sda4-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 7002]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identifier = DB::table('taxonomy_activities')->where('id', $activityId)->value('identifier');

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30004,
                'activities' => json_encode(['7002' => [$identifier]]),
            ],
        ]);

        $job = $this->makeJob(30004);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30004], $track);

        $this->assertTrue(
            $track->taxonomyActivities()->where('taxonomy_activities.id', $activityId)->exists(),
            'Il pivot taxonomy_activityables locale deve essere popolato leggendo properties[activities], comportamento preesistente al refactor'
        );

        // La relazione taxonomyActivities() non dichiara withPivot(), quindi le colonne pivot
        // custom non sono selezionabili tramite ->pivot->... — verifica diretta sulla tabella.
        $pivotRow = DB::table('taxonomy_activityables')
            ->where('taxonomy_activity_id', $activityId)
            ->where('taxonomy_activityable_id', $track->id)
            ->where('taxonomy_activityable_type', 'App\Models\EcTrack')
            ->first();

        $this->assertNotNull($pivotRow, 'La riga pivot taxonomy_activityables deve esistere');
        $this->assertSame(0, (int) $pivotRow->duration_forward);
        $this->assertSame(0, (int) $pivotRow->duration_backward);
    }

    public function test_re_running_does_not_reset_existing_activity_pivot_duration_to_zero(): void
    {
        // Regressione per il finding bloccante della re-review oc:8094: il codice originale
        // pre-refactor attaccava la taxonomy_activity SOLO se il pivot non esisteva già —
        // syncWithoutDetaching() con pivotData non vuoto, introdotto dalla generalizzazione,
        // chiamerebbe invece updateExistingPivot() anche su un pivot già presente, sovrascrivendo
        // ad ogni re-import i duration_forward/duration_backward reali (scritti dal job taxonomy
        // dedicato) con gli 0/0 hardcoded di questo sync — esattamente il caso che l'overview
        // definiva "l'unico oggi esente" dal bug del ticket.
        $activityId = DB::table('taxonomy_activities')->insertGetId([
            'name' => json_encode(['it' => 'Real Duration Activity']),
            'identifier' => 'real-duration-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 7006]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $identifier = DB::table('taxonomy_activities')->where('id', $activityId)->value('identifier');

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30006,
                'activities' => json_encode(['7006' => [$identifier]]),
            ],
        ]);

        // Simula il job taxonomy dedicato che ha già scritto valori reali (non zero) — es. da un
        // ciclo di import precedente.
        DB::table('taxonomy_activityables')->insert([
            'taxonomy_activity_id' => $activityId,
            'taxonomy_activityable_id' => $track->id,
            'taxonomy_activityable_type' => 'App\Models\EcTrack',
            'duration_forward' => 120,
            'duration_backward' => 90,
        ]);

        $job = $this->makeJob(30006);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30006], $track);

        $pivotRow = DB::table('taxonomy_activityables')
            ->where('taxonomy_activity_id', $activityId)
            ->where('taxonomy_activityable_id', $track->id)
            ->where('taxonomy_activityable_type', 'App\Models\EcTrack')
            ->first();

        $this->assertNotNull($pivotRow, 'La riga pivot deve continuare a esistere');
        $this->assertSame(1, DB::table('taxonomy_activityables')
            ->where('taxonomy_activity_id', $activityId)
            ->where('taxonomy_activityable_id', $track->id)
            ->count(), 'Non deve essere creata una seconda riga pivot');
        $this->assertSame(120, (int) $pivotRow->duration_forward, 'I valori reali di durata non devono essere resettati a 0 da un re-import');
        $this->assertSame(90, (int) $pivotRow->duration_backward, 'I valori reali di durata non devono essere resettati a 0 da un re-import');
    }

    public function test_exact_identifier_match_is_preferred_over_fuzzy_substring_match(): void
    {
        // Regressione per il finding bloccante della review oc:8094: identifier reali su Geohub
        // possono essere sottostringa di altri (es. 'via-francigena' di
        // 'via-francigena-toscana-sud', verificato su dati reali). Senza un match esatto
        // prioritario, il fuzzy LIKE (nessun ORDER BY, ->first() su match multipli) potrebbe
        // attaccare il theme sbagliato in modo non deterministico.
        $exactThemeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Via Francigena']),
            'identifier' => 'via-francigena',
            'properties' => json_encode(['geohub_id' => 7004]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Variante più specifica il cui identifier CONTIENE quello esatto come sottostringa —
        // deve esistere in DB per rendere il test significativo (altrimenti il fuzzy LIKE
        // troverebbe comunque un solo risultato, mascherando l'assenza del match esatto).
        DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Via Francigena Toscana Sud']),
            'identifier' => 'via-francigena-toscana-sud',
            'properties' => json_encode(['geohub_id' => 7005]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30005,
                'themes' => json_encode(['7004' => ['via-francigena']]),
            ],
        ]);

        $job = $this->makeJob(30005);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30005], $track);

        $this->assertTrue(
            $track->taxonomyThemes()->where('taxonomy_themes.id', $exactThemeId)->exists(),
            'Deve essere attaccato il theme con identifier esattamente uguale, non una variante più specifica che lo contiene come sottostringa'
        );
        $this->assertCount(1, $track->taxonomyThemes, 'Solo il theme con match esatto deve essere attaccato, non entrambi');
    }

    public function test_malformed_themes_json_does_not_throw(): void
    {
        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30002,
                'themes' => 'not-valid-json{{{',
            ],
        ]);

        $job = $this->makeJob(30002);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);

        // Non deve lanciare eccezioni né emettere warning PHP su foreach(null)
        $method->invoke($job, ['id' => 30002], $track);

        $this->assertCount(0, $track->taxonomyThemes);
    }

    public function test_theme_sync_is_skipped_when_config_flag_disabled(): void
    {
        config(['wm-geohub-import.child_side_taxonomy_sync.enabled' => false]);

        $themeId = DB::table('taxonomy_themes')->insertGetId([
            'name' => json_encode(['it' => 'Disabled Theme']),
            'identifier' => 'test-theme-disabled-track-'.uniqid(),
            'properties' => json_encode(['geohub_id' => 7003]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $identifier = DB::table('taxonomy_themes')->where('id', $themeId)->value('identifier');

        $track = EcTrack::factory()->createQuietly([
            'properties' => [
                'geohub_id' => 30003,
                'themes' => json_encode(['7003' => [$identifier]]),
            ],
        ]);

        $job = $this->makeJob(30003);
        $method = new \ReflectionMethod($job, 'processDependencies');
        $method->setAccessible(true);
        $method->invoke($job, ['id' => 30003], $track);

        $this->assertCount(0, $track->taxonomyThemes);
    }
}
