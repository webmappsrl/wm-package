<?php

namespace Wm\WmPackage\TrailRegistry\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyActivity;
use Wm\WmPackage\Services\Models\EcTrackService;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryService;

/**
 * Approva l'istanza: crea il sentiero, gli attacca il tipo, replica i media e
 * rende definitivo il codice riservato.
 *
 * Il pattern segue ConvertUgcPoiToEcPoi (che esiste solo per i POI), con una
 * divergenza deliberata: il legame verso il sentiero e' una chiave esterna
 * vera nel registro (`trail_registry_codes.ec_track_id`), non una voce in
 * properties.
 */
class ApproveTrailApplication extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Approva');
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        $service = app(TrailRegistryService::class);

        $approved = 0;
        $refused = 0;

        foreach ($models as $application) {
            // La guardia vive qui e non solo in canRun(): l'autorizzazione
            // dell'interfaccia non e' un presidio, l'endpoint Nova puo' essere
            // invocato direttamente. Approvare due volte, o approvare
            // un'istanza respinta, significherebbe un secondo sentiero sullo
            // stesso codice.
            if ($application->status !== TrailApplicationStatus::UnderReview) {
                $refused++;

                continue;
            }

            $code = $application->activeCode;

            // Idempotenza: una seconda esecuzione non crea un altro sentiero.
            if ($code === null || $code->ec_track_id !== null) {
                $refused++;

                continue;
            }

            $track = DB::transaction(function () use ($application, $code, $service) {
                $track = $this->createTrack($application);

                $this->copyMedia($application, $track);
                $this->attachTrailType($track);

                $service->confirm($code, $track, auth()->id());

                $application->update(['status' => TrailApplicationStatus::Approved]);

                return $track;
            });

            // Fuori dalla transazione, e non prima: la catena e' fatta di job
            // in coda, e dentro la transazione lavorerebbero su dati non
            // ancora committati. E' esattamente cio' che EcTrackObserver
            // avrebbe chiamato su un `created` (src/Observers/EcTrackObserver.php),
            // che l'INSERT diretto non fa scattare: quota, dislivelli,
            // pendenze, tassonomie territoriali, mappe vettoriali e indice di
            // ricerca.
            app(EcTrackService::class)->createDataChain($track);

            $approved++;
        }

        if ($approved === 0) {
            return Action::danger(__('Nessuna istanza approvata: solo le istanze in istruttoria possono essere approvate.'));
        }

        if ($refused > 0) {
            return Action::message(__('Istanze approvate: :approved. Saltate perche\' non in istruttoria: :refused.', [
                'approved' => $approved,
                'refused' => $refused,
            ]));
        }

        return Action::message(__('Istanze approvate.'));
    }

    /**
     * La geometria si copia dall'istanza al sentiero SENZA passare da
     * Eloquent: le due colonne sono dello stesso tipo (`geography`,
     * `multiLineStringz`), quindi la copia e' diretta. Non e' un
     * `EcTrack::create()` seguito da un UPDATE perche' `ec_tracks.geometry` e'
     * NOT NULL senza default: la riga non puo' nascere senza geometria.
     *
     * Il nome si scrive poi via Eloquent (`saveQuietly()`, senza far scattare
     * gli observer): la colonna e' tradotta, e la codifica la conosce solo il
     * modello. La catena degli observer viene invocata a mano dal chiamante,
     * dopo il commit.
     *
     * Le `properties` dell'istanza (anagrafica del proponente, protocollo)
     * vengono riportate sul sentiero: e' l'unico posto dove quei dati vivono.
     */
    protected function createTrack(TrailApplication $application): EcTrack
    {
        $table = config('wm-package.ec_track_table', 'ec_tracks');
        $appId = $application->properties['app_id'] ?? App::query()->min('id');

        $properties = $application->properties ?? [];
        $properties['trail_application_id'] = $application->id;

        $id = DB::selectOne(
            <<<SQL
            INSERT INTO {$table} (name, app_id, user_id, properties, geometry, created_at, updated_at)
            SELECT :name, :app_id, :user_id, :properties::jsonb, geometry, now(), now()
            FROM trail_applications WHERE id = :application_id
            RETURNING id
            SQL,
            [
                'name' => (string) $application->name,
                'app_id' => $appId,
                'user_id' => auth()->id(),
                'properties' => json_encode($properties),
                'application_id' => $application->id,
            ],
        )->id;

        $track = EcTrack::findOrFail($id);
        $track->name = $application->name;
        $track->saveQuietly();

        return $track;
    }

    /**
     * Il tipo va attaccato dalla conversione e non lasciato all'operatore.
     *
     * L'identificatore e' un dato del consumer, non del package: se non e'
     * configurato o la tassonomia non esiste si registra un avviso e si
     * prosegue — non e' un motivo per far cadere l'approvazione.
     */
    protected function attachTrailType(EcTrack $track): void
    {
        $identifier = config('wm-package.features.trail_registry.trail_type_identifier');

        if (! $identifier) {
            Log::warning('trail_registry: identificatore del tipo sentiero non configurato', [
                'config' => 'wm-package.features.trail_registry.trail_type_identifier',
                'ec_track_id' => $track->id,
            ]);

            return;
        }

        $activity = TaxonomyActivity::where('identifier', $identifier)->first();

        if ($activity === null) {
            Log::warning('trail_registry: tassonomia del tipo sentiero non trovata', ['identifier' => $identifier]);

            return;
        }

        $track->taxonomyActivities()->syncWithoutDetaching([$activity->id]);
    }

    /**
     * La copia e' per allegato e cattura `\Throwable`, non `\Exception`: un
     * file che non si copia non deve far cadere l'intera conversione. La
     * lettura di `$application->media` non e' protetta da un proprio
     * try/catch: prima di questo lavoro lo era, per un timore di errori di
     * polimorfismo (l'istanza senza un alias nel morphMap del package), ma
     * `TrailApplication::getMorphClass()` (vedi la sua classdoc) risolve gia'
     * quel caso restituendo il nome di classe reale — la lettura non puo'
     * piu' sollevare nulla, ed e' per questo che PHPStan segnala quel catch
     * come irraggiungibile.
     *
     * `Media::copy()` invece di `replicate()`: copia anche il file sul disco,
     * mentre la replica della sola riga lascerebbe un allegato che punta al
     * file dell'istanza.
     */
    protected function copyMedia(TrailApplication $application, EcTrack $track): void
    {
        $media = $application->media;

        foreach ($media as $item) {
            try {
                $item->copy($track, $item->collection_name, $item->disk);
            } catch (\Throwable $e) {
                Log::error('trail_registry: copia media fallita -> '.$e->getMessage());
            }
        }
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
