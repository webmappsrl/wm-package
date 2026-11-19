<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Actions\Transaction;
use Laravel\Nova\Fields\ActionFields;
use Wm\WmPackage\Jobs\TaxonomyWhere\SyncModelTaxonomyWhereJob;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Nova\Actions\ConvertUgcPoiToEcPoi;
use Illuminate\Support\Facades\Http;

// Creare un contenuto geolocalizzato interroga osmfeatures per le aree amministrative e il DEM
// per i dati tecnici: qui non si verificano quei due servizi, si fingono perche' il test non
// esca in rete.
beforeEach(function () {
    Http::fake([
        '*osmfeatures*' => Http::response(['features' => []]),
        '*dem*' => Http::response([
            'ele' => 0,
            'geometry' => ['type' => 'LineString', 'coordinates' => []],
            'properties' => [],
        ]),
    ]);
});

/*
 * oc:8967 — conversione di un UgcPoi in EcPoi dall'action Nova.
 *
 * L'action va eseguita dentro Transaction::run(), come fa Nova (DispatchAction): il bug
 * del ticket (25P02 dopo l'errore sull'uuid del media) esiste solo dentro quella
 * transazione esterna.
 */

uses(DatabaseTransactions::class);

beforeEach(function () {
    config()->set('wm-package.shard_name', 'test_shard');
    config()->set('media-library.disk_name', 'wmfe');
    Storage::fake('wmfe');
    Storage::fake('conf');
    Storage::fake('pois');
    Queue::fake();
    Bus::fake();

    $this->app_ = App::factory()->createQuietly();
    $this->user = User::factory()->create(['app_id' => $this->app_->id]);
    $this->actingAs($this->user);
});

function convert8967Ugc(string $name = 'Fontana'): UgcPoi
{
    return UgcPoi::create([
        'name' => $name,
        'app_id' => test()->app_->id,
        'user_id' => test()->user->id,
        'properties' => ['form' => ['id' => 'poi', 'title' => $name], 'name' => $name],
        'geometry' => DB::raw("ST_GeomFromText('POINT Z (13.5272 42.4259 0)', 4326)"),
    ]);
}

function convert8967AddPhoto(UgcPoi $ugc, string $geometry = 'POINT Z (13.531 42.428 0)'): Media
{
    $path = tempnam(sys_get_temp_dir(), 'oc8967').'.jpg';
    $image = imagecreatetruecolor(320, 240);
    imagefill($image, 0, 0, imagecolorallocate($image, random_int(0, 255), 120, 200));
    imagejpeg($image, $path, 80);

    return $ugc->addMedia($path)
        ->withCustomProperties(['geometry' => $geometry])
        ->toMediaCollection('default');
}

/** Esegue l'action come Nova: dentro la transazione esterna di Transaction::run(). */
function convert8967Run(Collection $models): ActionResponse
{
    return Transaction::run(
        fn () => (new ConvertUgcPoiToEcPoi)->handle(new ActionFields(collect(), collect()), $models)
    );
}

function convert8967Geometry(string $table, int $id): string
{
    return DB::selectOne("select ST_AsText(geometry::geometry) as g from {$table} where id = ?", [$id])->g;
}

it('converte un UgcPoi senza foto', function () {
    $ugc = convert8967Ugc();

    $response = convert8967Run(collect([$ugc]));

    $ecPoiId = $ugc->fresh()->properties['ec_poi_id'] ?? null;
    expect($ecPoiId)->not->toBeNull();
    $ecPoi = EcPoi::find($ecPoiId);
    expect($ecPoi)->not->toBeNull()
        ->and($ecPoi->app_id)->toBe($this->app_->id)
        ->and(array_values($ecPoi->getTranslations('name')))->toContain('Fontana')
        ->and(convert8967Geometry('ec_pois', $ecPoi->id))->toBe(convert8967Geometry('ugc_pois', $ugc->id))
        ->and($response['danger'] ?? null)->toBeNull()
        ->and($response['message'])->not->toBeEmpty();
});

it('copia le foto sull\'EcPoi con file e uuid nuovi, lasciando intatte quelle dell\'UgcPoi', function () {
    $ugc = convert8967Ugc();
    $photos = [convert8967AddPhoto($ugc), convert8967AddPhoto($ugc)];

    $response = convert8967Run(collect([$ugc]));

    expect($response['danger'] ?? null)->toBeNull();
    $ecPoi = EcPoi::find($ugc->fresh()->properties['ec_poi_id']);
    $copies = Media::where('model_type', $ecPoi->getMorphClass())->where('model_id', $ecPoi->id)->orderBy('id')->get();
    expect($copies)->toHaveCount(2);

    $disk = Storage::disk('wmfe');
    foreach ($copies as $i => $copy) {
        $original = $photos[$i]->fresh();
        expect($copy->id)->not->toBe($original->id)
            ->and($copy->uuid)->not->toBe($original->uuid)
            ->and($disk->exists($copy->getPathRelativeToRoot()))->toBeTrue()
            ->and(md5($disk->get($copy->getPathRelativeToRoot())))->toBe(md5($disk->get($original->getPathRelativeToRoot())));
    }

    // Le foto dell'UgcPoi restano dove erano.
    foreach ($photos as $photo) {
        expect(Media::find($photo->id))->not->toBeNull()
            ->and($disk->exists($photo->getPathRelativeToRoot()))->toBeTrue();
    }
});

it('conserva la posizione della foto invece di usare il punto dell\'EcPoi', function () {
    $ugc = convert8967Ugc();
    $photo = convert8967AddPhoto($ugc, 'POINT Z (13.531 42.428 0)');

    convert8967Run(collect([$ugc]));

    $ecPoi = EcPoi::find($ugc->fresh()->properties['ec_poi_id']);
    $copy = Media::where('model_type', $ecPoi->getMorphClass())->where('model_id', $ecPoi->id)->first();

    expect(convert8967Geometry('media', $copy->id))->toBe(convert8967Geometry('media', $photo->id))
        ->and(convert8967Geometry('media', $copy->id))->not->toBe(convert8967Geometry('ec_pois', $ecPoi->id));
});

it('annulla la conversione se il file di una foto manca, senza lasciare righe né file', function () {
    $ugc = convert8967Ugc();
    convert8967AddPhoto($ugc);
    $missing = convert8967AddPhoto($ugc);
    Storage::disk('wmfe')->delete($missing->getPathRelativeToRoot());

    $filesBefore = Storage::disk('wmfe')->allFiles();
    $ecPoisBefore = EcPoi::count();
    $mediaBefore = Media::count();

    $response = convert8967Run(collect([$ugc]));

    expect($ugc->fresh()->properties['ec_poi_id'] ?? null)->toBeNull()
        ->and(EcPoi::count())->toBe($ecPoisBefore)
        ->and(Media::count())->toBe($mediaBefore)
        ->and(Storage::disk('wmfe')->allFiles())->toEqualCanonicalizing($filesBefore)
        ->and(str_contains((string) $response['danger'], (string) $ugc->id))->toBeTrue();
});

it('in una selezione multipla converte gli altri UgcPoi se uno fallisce', function () {
    $first = convert8967Ugc('Primo');
    convert8967AddPhoto($first);
    $broken = convert8967Ugc('Rotto');
    $missing = convert8967AddPhoto($broken);
    Storage::disk('wmfe')->delete($missing->getPathRelativeToRoot());
    $third = convert8967Ugc('Terzo');
    convert8967AddPhoto($third);

    $response = convert8967Run(collect([$first, $broken, $third]));

    expect($first->fresh()->properties['ec_poi_id'] ?? null)->not->toBeNull()
        ->and($third->fresh()->properties['ec_poi_id'] ?? null)->not->toBeNull()
        ->and($broken->fresh()->properties['ec_poi_id'] ?? null)->toBeNull()
        ->and(str_contains((string) $response['danger'], (string) $broken->id))->toBeTrue();
});

it('un errore SQL intercettato in silenzio durante una conversione non blocca le successive', function () {
    // Imita MediaObserver::handleException: intercetta l'errore e prosegue, ma su
    // Postgres la transazione resta invalidata. Senza savepoint l'UgcPoi successivo
    // fallirebbe con 25P02, come nel bug del ticket.
    EcPoi::creating(function (EcPoi $ecPoi) {
        if ($ecPoi->name === 'boom' || $ecPoi->getTranslation('name', 'it', false) === 'boom') {
            try {
                DB::statement('select 1/0');
            } catch (Throwable) {
                // intercettato apposta
            }
        }
    });

    $broken = convert8967Ugc('boom');
    $next = convert8967Ugc('Dopo');

    $response = convert8967Run(collect([$broken, $next]));

    expect($broken->fresh()->properties['ec_poi_id'] ?? null)->toBeNull()
        ->and($next->fresh()->properties['ec_poi_id'] ?? null)->not->toBeNull()
        ->and(str_contains((string) $response['danger'], (string) $broken->id))->toBeTrue();
});

it('salta gli UgcPoi già convertiti', function () {
    $ugc = convert8967Ugc();
    $ugc->properties = array_merge($ugc->properties, ['ec_poi_id' => 999999]);
    $ugc->saveQuietly();
    $ecPoisBefore = EcPoi::count();

    $response = convert8967Run(collect([$ugc->fresh()]));

    expect(EcPoi::count())->toBe($ecPoisBefore)
        ->and(str_contains((string) $response['message'], (string) $ugc->id))->toBeTrue();
});

it('accoda la catena di località e quota dell\'EcPoi solo dopo il commit', function () {
    // Bus::fake() non onora afterCommit(): si verifica la proprietà del job accodato
    // (trappola oc:8571 in .claude/rules/test.md).
    $ugc = convert8967Ugc();

    convert8967Run(collect([$ugc]));

    Bus::assertDispatched(
        SyncModelTaxonomyWhereJob::class,
        fn (SyncModelTaxonomyWhereJob $job) => $job->afterCommit === true
    );
});
