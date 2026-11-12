<?php

namespace Wm\WmPackage\Http\Clients;

use Exception;
use Illuminate\Support\Facades\Log;
use Wm\WmPackage\Http\Clients\Abstracts\JsonClient;

class DemClient extends JsonClient
{
    public function getElevation($x, $y)
    {
        $response = $this->getHttpClient()->get($this->getElevationUrl($x, $y));

        return $response->json()['ele'];
    }

    private function getElevationUrl($x, $y)
    {
        return $this->getHost().'/'.rtrim(config('wm-package.clients.dem.ele_api'), '/')."/$x/$y";
    }

    /**
     * I dati tecnici DEM di una traccia.
     *
     * Il timeout e' sempre applicato, senza retry: senza, il client erediterebbe i
     * 30 secondi di default di Laravel e una chiamata dentro una richiesta HTTP
     * terrebbe occupato un worker per mezzo minuto. Il default e' quello
     * sincrono (`clients.dem.timeout_seconds`); chi gira in coda passa un valore
     * piu' largo, vedi `clients.dem.async_timeout_seconds`.
     *
     * Il body della risposta non finisce nell'eccezione, che puo' arrivare a chi
     * chiama le API: si scrive nel log.
     *
     * @param  int|null  $timeout  secondi; null = `clients.dem.timeout_seconds`
     */
    public function getTechData($geojson, ?int $timeout = null)
    {
        // Minimo 1: zero per Guzzle significa "aspetta per sempre" (es. env vuota).
        $seconds = max(1, $timeout ?? (int) config('wm-package.clients.dem.timeout_seconds', 5));

        $response = $this->getHttpClient()
            ->connectTimeout($seconds)
            ->timeout($seconds)
            ->post($this->getTechDataUrl(), $geojson);

        $responseData = $response->json();

        // Check the response
        if (! $response->successful() || empty($responseData['geometry'])) {
            $errorCode = $response->status();
            Log::warning('DemClient::getTechData: risposta non valida dal servizio DEM', [
                'status' => $errorCode,
                'body' => $response->body(),
            ]);
            throw new Exception("DemClient::getTechData: il servizio DEM ha risposto con errore (HTTP {$errorCode})");
        }

        return $responseData;
    }

    private function getTechDataUrl()
    {
        return $this->getHost().'/'.rtrim(config('wm-package.clients.dem.tech_data_api'), '/');
    }

    public function getPointMatrix($featureCollection)
    {
        $response = $this->getHttpClient()->post(
            $this->getPointMatrixUrl(),
            $featureCollection
        );

        $responseData = $response->json();

        if (! $response->successful() || empty($responseData)) {
            $errorCode = $response->status();
            $errorBody = $response->body();
            throw new Exception("DemClient::getPointMatrix: FAILED: Error {$errorCode}: {$errorBody}");
        }

        return $responseData;
    }

    private function getPointMatrixUrl()
    {
        return $this->getHost().'/'.rtrim(config('wm-package.clients.dem.point_matrix_api'), '/');
    }

    protected function getHost(): string
    {
        return rtrim(config('wm-package.clients.dem.host'));
    }
}
