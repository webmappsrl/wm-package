<?php

namespace Wm\WmPackage\Http\Controllers;

use Exception;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Services\Models\App\AppClassificationService;

class ClassificationController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function getRankedUsersNearPois(App $app)
    {
        try {

            $getRankedUserPositionNearPoisQuery = AppClassificationService::make();

            // Controlla se $app->classification_show è false
            if ($app->classification_show === false) {
                throw new Exception('La classificazione non è mostrata.');
            }

            $classification = $getRankedUserPositionNearPoisQuery->getRankedUsersNearPois($app);
            // Controlla se getRankedUsersNearPois() è vuoto
            if (empty($classification)) {
                throw new Exception('Nessun utente classificato.');
            }

            $data = $getRankedUserPositionNearPoisQuery->getAllRankedUsersNearPoisData($app);

            return $this->beautifyRankedUsersNearPois($classification, $data);
        } catch (Exception $e) {
            // Log l'errore
            Log::error($e->getMessage());

            // Reindirizza alla pagina 404
            abort(404, $e->getMessage());
        }
    }

    /**
     * Beautify the ranked users near pois.
     * TODO: move to Resource
     *
     * @return array
     */
    public function beautifyRankedUsersNearPois(array $classification, $data)
    {
        $classificaTrasformata = [];
        foreach ($classification as $userId => $ecPoiArray) {
            $utente = isset($data['Users'][$userId]) ? $data['Users'][$userId] : null;

            if ($utente) {
                $dettagliUtente = [
                    'name' => isset($utente['name']) ? $utente['name'] : '',
                    'lastname' => isset($utente['last_name']) ? $utente['last_name'] : '',
                    'email' => isset($utente['email']) ? $utente['email'] : '',
                    'total_points' => 0, // Assumendo che tu possa calcolarlo
                    'pois' => [],
                ];

                foreach ($ecPoiArray as $ecPoiInfo) {
                    foreach ($ecPoiInfo as $ecPoiId => $ecMediaIds) {
                        $ecPoi = isset($data['EcPois'][$ecPoiId]) ? $data['EcPois'][$ecPoiId] : null;

                        if ($ecPoi) {
                            $dettagliPoi = [
                                'name' => isset($ecPoi['name']) ? $ecPoi['name'] : '',
                            ];

                            $idsMedia = explode(',', $ecMediaIds);
                            foreach ($idsMedia as $idMedia) {
                                $media = isset($data['UgcMedia'][$idMedia]) ? $data['UgcMedia'][$idMedia] : null;
                                if ($media) {
                                    $dettagliPoi['medias'][] = [
                                        'id' => $idMedia,
                                        'url' => isset($media['url']) ? $media['url'] : '',
                                    ];
                                }
                            }

                            $dettagliUtente['pois'][] = $dettagliPoi;
                            $dettagliUtente['total_points'] += 1;
                        }
                    }
                }

                $classificaTrasformata[] = $dettagliUtente;
            }
        }

        usort($classificaTrasformata, function ($a, $b) {
            return $b['total_points'] - $a['total_points'];
        });

        return $classificaTrasformata;
    }
}
