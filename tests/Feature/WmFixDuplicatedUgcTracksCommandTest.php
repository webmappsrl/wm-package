<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Models\UgcDuplicatesService;

beforeEach(function () {
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/** Traccia salvata senza observer, con geometria scritta in SQL (come i dati reali). */
function dupTrack(int $userId, int $appId, string $uuid, array $props = [], string $wkt = 'MULTILINESTRING Z ((13 43 10, 13.001 43.001 10))', ?string $createdAt = null): UgcTrack
{
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'name' => $props['name'] ?? 'Tappa',
        'properties' => array_merge(['name' => 'Tappa', 'uuid' => $uuid], $props),
    ]));
    DB::update('UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT(?), created_at = COALESCE(?::timestamp, created_at) WHERE id = ?', ["SRID=4326;{$wkt}", $createdAt, $track->id]);

    return $track->refresh();
}

it('in report elenca i gruppi senza scrivere nulla', function () {
    $a = dupTrack($this->user->id, $this->app_->id, 'g1', [], createdAt: '2026-06-16 14:06:14');
    $b = dupTrack($this->user->id, $this->app_->id, 'g1', [], createdAt: '2026-06-16 14:33:34');

    $this->artisan('wm:fix-duplicated-ugc')
        ->expectsOutputToContain('1 gruppi')
        ->assertSuccessful();

    expect(UgcTrack::whereIn('id', [$a->id, $b->id])->count())->toBe(2)
        ->and(DB::table('ugc_duplicates_archive')->count())->toBe(0);
});

it('segnala da verificare un gruppo con geometrie distanti più di 1 metro (calcolo in metri)', function () {
    dupTrack($this->user->id, $this->app_->id, 'g2');
    // circa 11 m più a nord: in gradi sarebbe 0.0001, molto sotto 1
    dupTrack($this->user->id, $this->app_->id, 'g2', [], 'MULTILINESTRING Z ((13 43.0001 10, 13.001 43.0011 10))');

    $this->artisan('wm:fix-duplicated-ugc')
        ->expectsOutputToContain('da verificare')
        ->assertSuccessful();
});

it('tratta come uguali geometrie che differiscono solo per arrotondamento', function () {
    dupTrack($this->user->id, $this->app_->id, 'g3');
    dupTrack($this->user->id, $this->app_->id, 'g3', [], 'MULTILINESTRING Z ((13.0000000001 43 10, 13.001 43.001 10))');

    $groups = UgcDuplicatesService::make()->groups(UgcTrack::class);

    expect($groups->firstWhere('uuid', 'g3')['max_distance_m'])->toBeLessThan(1.0);
});

it('con --execute unisce nel più vecchio, archivia e cancella le copie', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e1', ['updatedAt' => '2026-06-16T14:00:58Z', 'form' => ['difficulty' => 'easy']], createdAt: '2026-06-16 14:06:14');
    $c = dupTrack($this->user->id, $this->app_->id, 'e1', ['updatedAt' => '2026-06-16T14:36:17Z', 'form' => ['difficulty' => 'medium'], 'id' => 999], createdAt: '2026-06-16 14:33:34');

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();

    $parent = UgcTrack::find($p->id);
    expect(UgcTrack::find($c->id))->toBeNull()
        ->and($parent->properties['form']['difficulty'])->toBe('medium')
        ->and($parent->properties)->not->toHaveKey('id')
        ->and(DB::table('ugc_duplicates_archive')->where('original_id', $c->id)->value('reference_id'))->toBe($p->id);
});

it('conserva le chiavi del padre che le copie non hanno (es. layer_id)', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e2', ['layer_id' => 10, 'chiave_del_progetto' => false]);
    dupTrack($this->user->id, $this->app_->id, 'e2');

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();

    expect(UgcTrack::where('properties->uuid', 'e2')->count())->toBe(1)
        ->and(UgcTrack::find($p->id)->properties)->toMatchArray(['layer_id' => 10, 'chiave_del_progetto' => false]);
});

it('allinea la colonna name a properties.name', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e3', ['name' => 'Vecchio', 'updatedAt' => '2026-01-01T00:00:00Z']);
    dupTrack($this->user->id, $this->app_->id, 'e3', ['name' => 'Nuovo', 'updatedAt' => '2026-01-02T00:00:00Z']);

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();

    expect(DB::table('ugc_tracks')->where('id', $p->id)->value('name'))->toBe('Nuovo');
});

it('non tocca i gruppi da verificare', function () {
    $p = dupTrack($this->user->id, $this->app_->id, 'e4');
    $c = dupTrack($this->user->id, $this->app_->id, 'e4', [], 'MULTILINESTRING Z ((13 43.0001 10, 13.001 43.0011 10))');

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();

    expect(UgcTrack::find($c->id))->not->toBeNull();
});

it('sposta i media delle copie sul padre scartando quelli con lo stesso contenuto', function () {
    config()->set('wm-package.shard_name', 'test_shard');
    Storage::fake('s3');
    Storage::fake('wmfe');
    Storage::fake('public');
    config()->set('medialibrary.disk_name', 'public');
    $p = dupTrack($this->user->id, $this->app_->id, 'e5');
    $c = dupTrack($this->user->id, $this->app_->id, 'e5');
    $file = fn ($s) => UploadedFile::fake()->image("i{$s}.jpg", 10 + ord($s) - ord('a'), 10);
    $p->addMedia($file('a'))->toMediaCollection('default');
    $c->addMedia($file('a'))->toMediaCollection('default');
    $c->addMedia($file('b'))->toMediaCollection('default');

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();

    expect(UgcTrack::find($p->id)->getMedia('default'))->toHaveCount(2)
        ->and(DB::table('media')->where('model_id', $c->id)->where('model_type', $p->getMorphClass())->count())->toBe(0);
});

it('rilanciato dopo --execute non trova più nulla', function () {
    dupTrack($this->user->id, $this->app_->id, 'e7');
    dupTrack($this->user->id, $this->app_->id, 'e7');

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();
    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])
        ->expectsOutputToContain('0 gruppi')
        ->assertSuccessful();
});

it('scrive nel canale duplicated-ugc una riga per gruppo con l\'esito, anche per i gruppi saltati', function () {
    $log = sys_get_temp_dir().'/duplicated-ugc-test-'.uniqid().'.log';
    config()->set('logging.channels.duplicated-ugc', ['driver' => 'single', 'path' => $log]);
    dupTrack($this->user->id, $this->app_->id, 'l1');
    dupTrack($this->user->id, $this->app_->id, 'l1');
    dupTrack($this->user->id, $this->app_->id, 'l2');
    dupTrack($this->user->id, $this->app_->id, 'l2', [], 'MULTILINESTRING Z ((13 43.0001 10, 13.001 43.0011 10))');

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])->assertSuccessful();

    $content = file_get_contents($log);
    @unlink($log);
    expect($content)->toMatch('/"uuid":"l1".*"esito":"unito"/')
        ->and($content)->toMatch('/"uuid":"l2".*"esito":"da verificare"/')
        ->and($content)->toMatch('/"modalita":"execute".*"gruppi":2/');
});
