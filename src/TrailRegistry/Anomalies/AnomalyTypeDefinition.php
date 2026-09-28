<?php

namespace Wm\WmPackage\TrailRegistry\Anomalies;

use Wm\WmPackage\TrailRegistry\Models\TrailRegistryAnomaly;

/**
 * Un tipo di anomalia dichiarato da uno shard, accanto a quelli del catasto
 * (`TrailRegistryAnomalyType`). Il package non ne conosce il significato: sa
 * solo come chiedergli l'etichetta e le righe di dettaglio della scheda.
 */
interface AnomalyTypeDefinition
{
    public function label(): string;

    /**
     * @return list<array{0: string, 1: string}> etichetta e valore, il
     *                                           valore gia' sottoposto a escape con e()
     */
    public function detailRows(TrailRegistryAnomaly $anomaly): array;
}
