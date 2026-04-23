<?php

namespace Wm\WmPackage\Http\Clients;

use Exception;
use Illuminate\Support\Facades\Http;
use Wm\WmPackage\Http\Clients\Abstracts\JsonClient;

class Osm2caiClient extends JsonClient
{
    protected function getHost(): string
    {
        return 'https://osm2cai.cai.it';
    }

    /**
     * Returns list of CAI sectors.
     * The API returns a plain array (no pagination).
     *
     * @return array<array{id: int, updated_at: string, name: string}>
     */
    public function getSectorsList(?string $bbox = null): array
    {
        $params = [];
        if ($bbox !== null) {
            $params['bbox'] = $this->normalizeBboxForQuery($bbox);
        }

        $response = Http::get($this->getHost().'/api/v3/sectors/list', $params);

        if (! $response->successful()) {
            throw new Exception("OSM2CAI sectors/list HTTP {$response->status()}: ".$response->body());
        }

        return array_map(fn ($s) => [
            'id' => $s['id'],
            'updated_at' => $s['updated_at'],
            'name' => $s['name']['it'] ?? $s['name']['en'] ?? (string) $s['id'],
        ], $response->json() ?? []);
    }

    /**
     * Returns sector detail: geometry + properties.
     * Mirrors OsmfeaturesClient::getAdminAreaDetail().
     *
     * @return array{osm2cai_id: int, name: string, code: string|null, full_code: string|null, human_name: string|null, manager: string|null, geometry: string|null}
     */
    public function getSectorDetail(int $id): array
    {
        $response = $this->getHttpClient()->get($this->getHost().'/api/v3/sectors/'.$id);

        if (! $response->successful()) {
            throw new Exception("OSM2CAI sectors/{$id} HTTP {$response->status()}: ".$response->body());
        }

        $data = $response->json();
        $properties = $data['properties'] ?? [];

        return [
            'osm2cai_id' => $id,
            'name' => $properties['name']['it'] ?? $properties['name']['en'] ?? (string) $id,
            'code' => $properties['code'] ?? null,
            'full_code' => $properties['full_code'] ?? null,
            'human_name' => $properties['human_name']['it'] ?? $properties['human_name']['en'] ?? null,
            'manager' => $properties['manager'] ?? null,
            'geometry' => isset($data['geometry']) ? json_encode($data['geometry']) : null,
        ];
    }

    /**
     * Normalizza il bbox per la query string.
     * Stesso approccio di OsmfeaturesClient::normalizeBboxForQuery().
     */
    protected function normalizeBboxForQuery(string $bbox): string
    {
        $trimmed = trim($bbox);
        if ($trimmed === '') {
            return $bbox;
        }

        if (str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded) && count($decoded) === 4) {
                return implode(',', array_map(static fn ($v) => (string) $v, $decoded));
            }
        }

        return $bbox;
    }
}
