<?php

namespace Wm\WmPackage\TrailRegistry\Anomalies;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Wm\WmPackage\TrailRegistry\Enums\TrailRegistryAnomalyType;

/**
 * I tipi del catasto restano l'enum, cosi' ogni confronto esistente continua
 * a funzionare; tutti gli altri — dichiarati da uno shard, o rimasti in
 * tabella dopo che lo shard li ha tolti — restano stringhe. Un cast enum
 * puro solleverebbe ValueError al caricamento e bloccherebbe l'intera lista.
 */
class AnomalyTypeCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): TrailRegistryAnomalyType|string|null
    {
        if ($value === null) {
            return null;
        }

        return TrailRegistryAnomalyType::tryFrom($value) ?? (string) $value;
    }

    public function set($model, string $key, $value, array $attributes): ?string
    {
        return $value instanceof TrailRegistryAnomalyType ? $value->value : $value;
    }
}
