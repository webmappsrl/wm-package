<?php

namespace Wm\WmPackage\Http\Controllers\Api;

use Exception;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Wm\WmPackage\Http\Controllers\Controller;
use Wm\WmPackage\Http\Resources\MediaResource;
use Wm\WmPackage\Models\Media;
use Wm\WmPackage\TrailRegistry\Models\TrailApplication;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

class MediaController extends Controller
{
    /**
     * Return Media JSON.
     *
     * Questa rotta e' pubblica e gli identificatori sono progressivi: chiunque
     * puo' scorrerli. Gli allegati di un'istanza di accatastamento contengono
     * dati personali (nomi, indirizzi, codici fiscali) e non devono uscire di
     * qui. La negazione guarda il modello proprietario del file, non un elenco
     * di collection: un elenco sarebbe una lista di eccezioni, e la prossima
     * collection aggiunta all'istanza sarebbe pubblica per difetto, senza che
     * nessuno se ne accorga. Cosi' ogni allegato nuovo nasce protetto, e per
     * esporre qualcosa in futuro lo si dovra' dichiarare esplicitamente.
     * Rispondiamo 404 e non 403: un 403 confermerebbe a chi scorre gli
     * identificatori che il file esiste.
     */
    public function show(Media $media): JsonResponse
    {
        abort_if($this->belongsToApplication($media), 404);

        $geojson = $media->getGeojson();
        $geojson['properties'] = (new MediaResource($media))->toArray(request());

        return response()->json($geojson);
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->validateUser($media);
        try {
            $media->delete();
        } catch (Exception $e) {
            return response()->json([
                'error' => "this media can't be deleted by api",
                'code' => 400,
            ], 400);
        }

        return response()->json(['success' => 'media deleted']);
    }

    /**
     * Il proprietario del media e' un'istanza di accatastamento?
     *
     * `model_type` puo' essere un alias del morphMap o il nome di classe reale
     * (TrailApplication::getMorphClass() restituisce il secondo). Si confronta
     * sia con la classe configurata dallo shard sia con quella base, e anche
     * con le loro sottoclassi: il dominio e' opzionale ma le classi esistono
     * sempre, quindi non serve controllare che sia attivo.
     */
    private function belongsToApplication(Media $media): bool
    {
        $type = (string) $media->model_type;

        if ($type === '') {
            return false;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_a($class, TrailRegistryClasses::application(), true)
            || is_a($class, TrailApplication::class, true);
    }
}
