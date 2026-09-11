<?php

declare(strict_types=1);

namespace Wm\WmPackage\Http\Controllers\Nova;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Nova\Actions\Concerns\HasTaxonomyWhereImportHelpers;

class GeohubWhereSelectionController extends Controller
{
    use HasTaxonomyWhereImportHelpers;

    public function import(Request $request): JsonResponse
    {
        // Boundary HTTP separato dall'Action Nova: il middleware `nova` non
        // porta autenticazione Nova (solo `nova.api_middleware`, usato da
        // `nova-api/*`, la porta) — questo è l'UNICO controllo di
        // autorizzazione su questa route, non un controllo aggiuntivo sopra
        // un'autenticazione già garantita altrove. Vedi overview.md, sezione
        // Follow-up 2, "Attenzione al boundary di autenticazione".
        if (! $this->isGeohubSourceAllowed($request->user())) {
            return response()->json(['message' => 'Sorgente GeoHub riservata ai super-admin.'], 403);
        }

        $appId = $request->input('app_id');
        // is_numeric() prima di App::find(): un app_id non numerico farebbe
        // fallire la query con un QueryException non gestito (Postgres non
        // castabile a bigint), producendo un 500 invece di un 422 pulito.
        $app = is_numeric($appId) ? App::find($appId) : null;
        if (! $app) {
            return response()->json(['message' => 'App non trovata.'], 422);
        }

        $geohubApp = $this->resolveGeohubApp($app);
        if (is_string($geohubApp)) {
            return response()->json(['message' => $geohubApp], 422);
        }

        $selectedIds = (array) $request->input('selected_ids', []);

        $result = $this->executeGeohubImport($app, $geohubApp, $selectedIds);

        if ($result === null) {
            return response()->json(['message' => 'Nessuna where selezionata.'], 422);
        }

        return response()->json([
            'message' => "Creati {$result['created']} record, aggiornati {$result['updated']} record TaxonomyWhere da GeoHub. Geometrie in copia in background; la sincronizzazione delle track partira' automaticamente al termine.",
            'created' => $result['created'],
            'updated' => $result['updated'],
        ]);
    }
}
