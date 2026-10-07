<?php

use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\TrailRegistry\Anomalies\AnomalyTypeDefinition;
use Wm\WmPackage\TrailRegistry\Anomalies\TrailRegistryAnomalyTypes;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;
use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;
use Wm\WmPackage\TrailRegistry\Nova\AnomalyDetailRenderer;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailAnomalyTypeFilter;
use Wm\WmPackage\TrailRegistry\Nova\TrailRegistryAnomaly as AnomalyResource;

beforeEach(fn () => runTrailRegistryStubs());

class ShardAnomalyType implements AnomalyTypeDefinition
{
    public function label(): string
    {
        return 'Tipo di esempio dello shard';
    }

    public function detailRows(TrailRegistryAnomaly $anomaly): array
    {
        return [['Valore', e($anomaly->context['raw'] ?? '')]];
    }
}

it('un tipo del catasto resta un enum', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'codice_gia_assegnato']);

    expect($anomaly->type)->toBe(TrailRegistryAnomalyType::CodiceGiaAssegnato);
});

it('un tipo dello shard si legge e si rende con la sua definizione', function () {
    config(['wm-package.features.trail_registry.anomaly_types' => ['shard_tipo_esempio' => ShardAnomalyType::class]]);
    $anomaly = new TrailRegistryAnomaly(['type' => 'shard_tipo_esempio', 'context' => ['raw' => '<b>x</b>']]);

    expect($anomaly->type)->toBe('shard_tipo_esempio');
    expect(AnomalyDetailRenderer::render($anomaly))->toContain('&lt;b&gt;x&lt;/b&gt;');
    expect(TrailRegistryAnomalyTypes::values())->toContain('shard_tipo_esempio', 'codice_gia_assegnato');
});

it('un tipo sconosciuto non manda in errore titolo e dettaglio', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'tipo_rimosso', 'ec_track_id' => 7]);

    expect((new AnomalyResource($anomaly))->title())->toBe('tipo_rimosso · #7');
    expect(AnomalyDetailRenderer::render($anomaly))->toBeString();
});

it('il campo Tipo mostra l etichetta dello shard senza leggere ->value su una stringa', function () {
    config(['wm-package.features.trail_registry.anomaly_types' => ['shard_tipo_esempio' => ShardAnomalyType::class]]);
    $anomaly = new TrailRegistryAnomaly(['type' => 'shard_tipo_esempio']);

    $field = collect((new AnomalyResource($anomaly))->fields(resolve(NovaRequest::class)))
        ->first(fn ($field) => $field->name === 'Type');

    $field->resolveForDisplay($anomaly);

    expect($field->value)->toBe('Tipo di esempio dello shard');
});

it('un tipo sconosciuto regge anche i campi dell index', function () {
    $anomaly = new TrailRegistryAnomaly(['type' => 'tipo_rimosso', 'ec_track_id' => null]);
    $request = NovaRequest::create('/nova-api/trail-registry-anomalies', 'GET');

    // indexFields() e' il percorso che Nova fa per l'index: filtra i campi
    // per l'index e li risolve per la visualizzazione.
    $fields = (new AnomalyResource($anomaly))->indexFields($request);

    expect($fields->first(fn ($field) => $field->name === 'Type')->value)->toBe('tipo_rimosso');
});

it('il titolo di un tipo dello shard usa la sua etichetta', function () {
    config(['wm-package.features.trail_registry.anomaly_types' => ['shard_tipo_esempio' => ShardAnomalyType::class]]);
    $anomaly = new TrailRegistryAnomaly(['type' => 'shard_tipo_esempio', 'ec_track_id' => null]);

    expect((new AnomalyResource($anomaly))->title())->toBe('Tipo di esempio dello shard');
});

class ShardAnomalyTypeWithDuplicateLabel extends ShardAnomalyType {}

it('il filtro non fa collassare due tipi con la stessa etichetta', function () {
    config(['wm-package.features.trail_registry.anomaly_types' => [
        'shard_tipo_esempio' => ShardAnomalyType::class,
        'shard_altro_tipo' => ShardAnomalyTypeWithDuplicateLabel::class,
    ]]);

    $options = (new TrailAnomalyTypeFilter)->options(resolve(NovaRequest::class));

    expect($options)->toHaveKey('Tipo di esempio dello shard (shard_tipo_esempio)', 'shard_tipo_esempio');
    expect($options)->toHaveKey('Tipo di esempio dello shard (shard_altro_tipo)', 'shard_altro_tipo');
    expect($options)->toHaveKey('codice_gia_assegnato', 'codice_gia_assegnato');
});
