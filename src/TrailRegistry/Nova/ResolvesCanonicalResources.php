<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Laravel\Nova\Nova;

/**
 * Il package non puo' referenziare `\App\Nova\*`, che vive nel consumer: la
 * Resource di un modello del package si risolve a runtime con
 * Nova::resourceForModel(), che ritorna quella canonica registrata per quel
 * modello. Il ripiego serve quando nessuna Resource e' registrata (in test,
 * o in un consumer che non monta quel modello): un BelongsTo costruito con
 * `null` sarebbe un errore fatale.
 *
 * @internal
 */
trait ResolvesCanonicalResources
{
    protected static function resourceForModel(string $model, ?string $fallback = null): ?string
    {
        return Nova::resourceForModel($model) ?? $fallback;
    }
}
