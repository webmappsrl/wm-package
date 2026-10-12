<?php

declare(strict_types=1);

/*
 * Caso reale (oc:8718): quattro gruppi di UgcTrack duplicate presi dal DB di sviluppo di
 * camminiditalia il 07/10/2026 (dump della produzione). Properties ridotte alle chiavi che
 * contano, geometrie accorciate, immagini sostituite da JPEG minuscoli: dove nel caso reale due
 * file avevano lo stesso sha256, qui hanno gli stessi byte. Le asserzioni sono la previsione
 * scritta prima di lanciare il command sui dati veri.
 */

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\UgcTrack;
use Wm\WmPackage\Models\User;

beforeEach(function () {
    config()->set('wm-package.shard_name', 'test_shard');
    config()->set('medialibrary.disk_name', 'public');
    Storage::fake('s3');
    Storage::fake('wmfe');
    Storage::fake('public');
    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
});

/** Traccia come quelle reali: salvata senza observer, geometria scritta in SQL. */
function realTrack(int $userId, int $appId, array $properties, string $createdAt, string $updatedAt, string $wkt): UgcTrack
{
    $track = UgcTrack::withoutEvents(fn () => UgcTrack::factory()->create([
        'user_id' => $userId,
        'app_id' => $appId,
        'name' => $properties['name'],
        'properties' => $properties,
    ]));
    DB::update(
        'UPDATE ugc_tracks SET geometry = ST_GeomFromEWKT(?), created_at = ?::timestamp, updated_at = ?::timestamp WHERE id = ?',
        ["SRID=4326;{$wkt}", $createdAt, $updatedAt, $track->id]
    );

    return $track->refresh();
}

/** JPEG minuscolo: stessa etichetta, stessi byte (lato dell'immagine legato all'etichetta). */
function realImage(string $label): UploadedFile
{
    $side = 8 + ord($label) - ord('A');

    return UploadedFile::fake()->image("image_{$label}.jpg", $side, $side);
}

function attachImages(UgcTrack $track, array $labels): void
{
    foreach ($labels as $label) {
        $track->addMedia(realImage($label))->toMediaCollection('default');
    }
}

function mediaCount(int $trackId): int
{
    return DB::table('media')->where('model_type', (new UgcTrack)->getMorphClass())->where('model_id', $trackId)->count();
}

const REAL_LINE = 'MULTILINESTRING Z ((13.6 42.4 1500, 13.61 42.41 1510, 13.62 42.42 1490))';
// Stessa linea con lo scarto di arrotondamento osservato su 1529846e (Hausdorff ~6.8e-10 gradi).
const REAL_LINE_ROUNDED = 'MULTILINESTRING Z ((13.6000000006 42.4 1500, 13.61 42.41 1510, 13.62 42.42 1490))';

it('unisce i quattro gruppi reali come previsto', function () {
    $u = $this->user->id;
    $a = $this->app_->id;

    // 1529846e — "Tappa 1 campo imperatore castel del monte": la copia 228 è arrivata 27 minuti
    // dopo ed è stata modificata dall'utente (difficulty easy → medium); contiene chiavi scritte
    // dal server (id, created_at, updated_at, taxonomyWheres) che il padre non ha.
    $tappaForm = ['id' => 'track', 'index' => 0, 'title' => 'Tappa 1 campo imperatore castel del monte', 'description' => ''];
    $tappaWhere = ['R53937' => ['it' => 'Abruzzo', '_admin_level' => 4], 'R41970' => ['it' => 'Castel del Monte', '_admin_level' => 8]];
    $p227 = realTrack($u, $a, [
        'name' => 'Tappa 1 campo imperatore castel del monte',
        'uuid' => '1529846e-4db9-418e-a60f-2b5231fe0161',
        'app_id' => 1,
        'createdAt' => '2026-06-16T14:00:58.399Z',
        'updatedAt' => '2026-06-16T14:00:58.399Z',
        'form' => $tappaForm + ['difficulty' => 'easy'],
        'taxonomy_where' => $tappaWhere,
        'distanceFilter' => 10,
    ], '2026-06-16 14:06:14', '2026-06-16 14:06:15', REAL_LINE);
    $c228 = realTrack($u, $a, [
        'name' => 'Tappa 1 campo imperatore castel del monte',
        'uuid' => '1529846e-4db9-418e-a60f-2b5231fe0161',
        'app_id' => 1,
        'createdAt' => '2026-06-16T14:00:58.399Z',
        'updatedAt' => '2026-06-16T14:36:17.290Z',
        'form' => $tappaForm + ['difficulty' => 'medium'],
        'taxonomy_where' => $tappaWhere,
        'distanceFilter' => 10,
        'id' => 228,
        'created_at' => '2026-06-16T14:33:34.000000Z',
        'updated_at' => '2026-06-16T14:33:35.000000Z',
        'taxonomyWheres' => [53937, 41970],
    ], '2026-06-16 14:33:34', '2026-06-16 14:35:48', REAL_LINE_ROUNDED);

    // 3e301343 — tre invii della stessa traccia, ognuno con le stesse tre foto.
    $t3e = fn (string $c) => ['name' => 'track 2026/04/27 16:12', 'uuid' => '3e301343-ee5f-4e0e-b2e4-654770410f39', 'updatedAt' => '2026-04-27T14:14:42.369Z', 'form' => ['id' => 'track', 'difficulty' => 'easy']];
    $p133 = realTrack($u, $a, $t3e('a'), '2026-04-27 14:18:16', '2026-04-27 14:18:16', REAL_LINE);
    $c134 = realTrack($u, $a, $t3e('b'), '2026-04-27 15:39:23', '2026-04-27 15:39:23', REAL_LINE);
    $c135 = realTrack($u, $a, $t3e('c'), '2026-04-27 17:36:29', '2026-04-27 17:36:29', REAL_LINE);
    attachImages($p133, ['A', 'B', 'C']);
    attachImages($c134, ['A', 'B', 'C']);
    attachImages($c135, ['A', 'B', 'C']);

    // 806f2dc5 — il primo invio ha salvato una sola foto, il retry tutte e tre.
    $t80 = ['name' => 'track 2026/04/29 17:21', 'uuid' => '806f2dc5-7a46-4d0e-aec7-accf1b2bb896', 'updatedAt' => '2026-04-29T15:22:43.925Z', 'form' => ['id' => 'track', 'difficulty' => 'easy']];
    $p143 = realTrack($u, $a, $t80, '2026-04-29 15:23:25', '2026-04-29 15:23:25', REAL_LINE);
    $c144 = realTrack($u, $a, $t80, '2026-04-29 15:58:33', '2026-04-29 15:58:33', REAL_LINE);
    attachImages($p143, ['D']);
    attachImages($c144, ['D', 'E', 'F']);

    // ae43aa51 — quattro righe: il padre senza foto, le copie con sottoinsiemi delle stesse due.
    $tae = ['name' => 'track ae43aa51', 'uuid' => 'ae43aa51-3aee-400c-9ef6-5455b59afb8b', 'layer_id' => 10, 'updatedAt' => '2026-07-01T08:00:00.000Z', 'form' => ['id' => 'track']];
    $p257 = realTrack($u, $a, $tae, '2026-07-01 08:05:00', '2026-07-01 08:05:00', REAL_LINE);
    $c258 = realTrack($u, $a, $tae, '2026-07-01 08:20:00', '2026-07-01 08:20:00', REAL_LINE);
    $c260 = realTrack($u, $a, $tae, '2026-07-01 09:10:00', '2026-07-01 09:10:00', REAL_LINE);
    $c270 = realTrack($u, $a, $tae, '2026-07-02 10:00:00', '2026-07-02 10:00:00', REAL_LINE);
    attachImages($c258, ['G', 'H']);
    attachImages($c260, ['G']);
    attachImages($c270, ['G', 'H']);

    $allIds = [$p227->id, $c228->id, $p133->id, $c134->id, $c135->id, $p143->id, $c144->id, $p257->id, $c258->id, $c260->id, $c270->id];
    $groupMedia = fn () => DB::table('media')->where('model_type', (new UgcTrack)->getMorphClass())->whereIn('model_id', $allIds)->count();
    expect($groupMedia())->toBe(18);

    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])
        ->expectsOutputToContain('4 gruppi trovati')
        ->assertSuccessful();

    // Righe: restano solo i padri, le copie sono in archivio con il riferimento giusto.
    $copies = [$c228->id => $p227->id, $c134->id => $p133->id, $c135->id => $p133->id, $c144->id => $p143->id,
        $c258->id => $p257->id, $c260->id => $p257->id, $c270->id => $p257->id];
    expect(UgcTrack::whereIn('id', array_keys($copies))->count())->toBe(0)
        ->and(UgcTrack::whereIn('id', [$p227->id, $p133->id, $p143->id, $p257->id])->count())->toBe(4)
        ->and(DB::table('ugc_duplicates_archive')->count())->toBe(7);
    foreach ($copies as $copyId => $parentId) {
        expect(DB::table('ugc_duplicates_archive')->where('original_id', $copyId)->value('reference_id'))->toBe($parentId);
    }

    // 1529846e: vince la modifica dell'utente, le chiavi del server restano quelle del padre.
    $props = UgcTrack::find($p227->id)->properties;
    expect($props['form']['difficulty'])->toBe('medium')
        ->and($props['updatedAt'])->toBe('2026-06-16T14:36:17.290Z')
        ->and($props)->not->toHaveKeys(['id', 'created_at', 'updated_at', 'taxonomyWheres'])
        ->and($props['taxonomy_where'])->toEqual($tappaWhere)
        ->and(DB::table('ugc_tracks')->where('id', $p227->id)->value('name'))->toBe('Tappa 1 campo imperatore castel del monte')
        ->and(json_decode(DB::table('ugc_duplicates_archive')->where('original_id', $c228->id)->value('media_ids'), true))->toBe([]);

    // Media: 18 prima, 8 dopo; 10 scartati perché uguali per contenuto.
    expect(mediaCount($p133->id))->toBe(3)
        ->and(mediaCount($p143->id))->toBe(3)
        ->and(mediaCount($p257->id))->toBe(2)
        ->and($groupMedia())->toBe(8)
        ->and(json_decode(DB::table('ugc_duplicates_archive')->where('original_id', $c134->id)->value('media_ids'), true))->toBe([])
        ->and(json_decode(DB::table('ugc_duplicates_archive')->where('original_id', $c144->id)->value('media_ids'), true))->toHaveCount(2)
        ->and(json_decode(DB::table('ugc_duplicates_archive')->where('original_id', $c258->id)->value('media_ids'), true))->toHaveCount(2);

    // Il layer_id assegnato dal server resta sul padre.
    expect(UgcTrack::find($p257->id)->properties['layer_id'])->toBe(10);

    // Rilanciato non trova più nulla.
    $this->artisan('wm:fix-duplicated-ugc', ['--execute' => true])
        ->expectsOutputToContain('0 gruppi trovati')
        ->assertSuccessful();
});
