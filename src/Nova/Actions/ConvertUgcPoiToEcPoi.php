<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;
use Wm\WmPackage\Exceptions\MediaFileMissingException;
use Wm\WmPackage\Models\EcPoi;

class ConvertUgcPoiToEcPoi extends Action
{
    use InteractsWithQueue, Queueable;

    public function name()
    {
        return __('Convert To EcPoi');
    }

    /**
     * Converte ogni UgcPoi selezionato in un EcPoi, foto comprese.
     *
     * Nova esegue `handle()` dentro una transazione (`Transaction::run`): ogni UgcPoi
     * viene convertito in un savepoint suo, così un errore annulla solo quell'UgcPoi.
     * Su Postgres una query fallita invalida la transazione anche se l'eccezione viene
     * intercettata, quindi dentro la closure del savepoint non ci deve essere nessun
     * catch: l'eccezione deve uscire perché il savepoint venga annullato (oc:8967).
     *
     * @return ActionResponse
     */
    public function handle(ActionFields $fields, Collection $models)
    {
        $converted = [];
        $alreadyConverted = [];
        $failed = [];

        foreach ($models as $model) {
            if (! empty($model->properties['ec_poi_id'])) {
                $alreadyConverted[] = $model->id;

                continue;
            }

            $copiedMedia = [];
            try {
                DB::transaction(function () use ($model, &$copiedMedia) {
                    $ecPoi = EcPoi::create([
                        'name' => $model->name,
                        'geometry' => $model->geometry,
                        'user_id' => auth()->user()->id,
                        'app_id' => $model->app_id,
                    ]);

                    foreach ($model->media as $media) {
                        $copiedMedia[] = $this->copyMedia($media, $ecPoi);
                    }

                    $properties = $model->properties;
                    $properties['ec_poi_id'] = $ecPoi->id;
                    $model->properties = $properties;
                    $model->saveQuietly();
                });
                $converted[] = $model->id;
            } catch (Throwable $e) {
                $this->deleteCopiedFiles($copiedMedia);
                Log::error('ConvertUgcPoiToEcPoi: UgcPoi '.$model->id.' non convertito', ['exception' => $e]);
                $failed[$model->id] = $this->failureReason($e);
            }
        }

        return $this->resultMessage($converted, $alreadyConverted, $failed);
    }

    /**
     * Copia una foto dell'UgcPoi sull'EcPoi, file compreso.
     *
     * `replicate()` duplicava solo la riga di `media`: il file restava nella cartella
     * del media originale e la foto dell'EcPoi rispondeva 404. `copy()` crea un media
     * nuovo e ne copia il file, ma con il file mancante non dà errore (crea un file da
     * 0 byte) e non porta la posizione della foto: le due cose si gestiscono qui.
     */
    private function copyMedia(Media $media, EcPoi $ecPoi): Media
    {
        $path = $media->getPathRelativeToRoot();
        if (! Storage::disk($media->disk)->exists($path)) {
            throw new MediaFileMissingException($media->id, $path);
        }

        // MediaObserver prende la geometria del nuovo media da custom_properties['geometry']
        // e, senza, usa il punto dell'EcPoi. withCustomProperties() sostituisce l'array, da
        // qui il merge con le custom_properties copiate da copy().
        $customProperties = $media->custom_properties ?? [];
        $geometry = DB::selectOne('select ST_AsText(geometry::geometry) as wkt from media where id = ?', [$media->id])?->wkt;
        if ($geometry !== null) {
            $customProperties['geometry'] = $geometry;
        }

        return $media->copy(
            $ecPoi,
            $media->collection_name,
            $media->disk,
            '',
            fn (FileAdder $adder) => $adder->withCustomProperties($customProperties)
        );
    }

    /**
     * Cancella dallo storage i file dei media copiati per un UgcPoi la cui conversione è
     * stata annullata: il savepoint annulla le righe, non i file.
     *
     * @param  array<int, Media>  $copiedMedia
     */
    private function deleteCopiedFiles(array $copiedMedia): void
    {
        foreach ($copiedMedia as $media) {
            try {
                Storage::disk($media->disk)->deleteDirectory(dirname($media->getPathRelativeToRoot()));
            } catch (Throwable $e) {
                Log::error('ConvertUgcPoiToEcPoi: file del media '.$media->id.' non cancellati', ['exception' => $e]);
            }
        }
    }

    /** Motivo leggibile per l'utente: i dettagli tecnici (SQL, percorsi) restano nel log. */
    private function failureReason(Throwable $e): string
    {
        if ($e instanceof MediaFileMissingException) {
            return __('photo :media not found on storage', ['media' => $e->mediaId]);
        }

        return __('conversion error, see the log');
    }

    /**
     * @param  array<int, int>  $converted
     * @param  array<int, int>  $alreadyConverted
     * @param  array<int, string>  $failed  motivo per id dell'UgcPoi
     */
    private function resultMessage(array $converted, array $alreadyConverted, array $failed): ActionResponse
    {
        if ($alreadyConverted === [] && $failed === []) {
            return Action::message(__('Conversion completed successfully'));
        }

        $parts = [];
        if ($converted !== []) {
            $parts[] = __('Converted UgcPois: :ids', ['ids' => implode(', ', $converted)]);
        }
        if ($alreadyConverted !== []) {
            $parts[] = __('UgcPois already converted to EcPoi: :ids', ['ids' => implode(', ', $alreadyConverted)]);
        }
        foreach ($failed as $id => $reason) {
            $parts[] = __('UgcPoi :id not converted: :reason', ['id' => $id, 'reason' => $reason]);
        }

        $text = implode(' — ', $parts);

        return $failed === [] ? Action::message($text) : Action::danger($text);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
