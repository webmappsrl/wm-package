<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Database\Eloquent\Model;
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

    /**
     * Le Resource del dominio si cercano per uriKey, non per modello: la
     * chiave e' fissa nella classe base ed ereditata dalla sottoclasse dello
     * shard, mentre il modello puo' essere stato sostituito da config senza
     * che `$model` della Resource lo segua — e allora resourceForModel()
     * non troverebbe nulla.
     */
    protected static function resourceForKey(string $uriKey, ?string $fallback = null): ?string
    {
        return Nova::resourceForKey($uriKey) ?? $fallback;
    }

    /**
     * Il modello che la Resource istanzia. Se la sottoclasse dello shard ha
     * cambiato `$model` rispetto a quello del package, vince la sua scelta;
     * altrimenti si usa la classe dichiarata in config (TrailRegistryClasses).
     * Gli attributi di default passano come fa Nova in Resource::newModel().
     *
     * @template TDomainModel of Model
     *
     * @param  class-string<TDomainModel>  $packageModel  il modello del package
     * @param  class-string<TDomainModel>  $configuredModel  quello risolto da config
     * @return TDomainModel
     */
    protected static function newDomainModel(string $packageModel, string $configuredModel): Model
    {
        /** @var class-string<TDomainModel> $class */
        $class = static::$model !== $packageModel ? static::$model : $configuredModel;

        return new $class(static::defaultAttributes());
    }
}
