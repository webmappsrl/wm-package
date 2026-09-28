<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Services\FeaturesService;

/**
 * Le Resource del Catasto le registra lo shard, come EcTrack: a dominio
 * spento resterebbero comunque in app/Nova. Il controllo sta qui, nella
 * classe base, perche' lo shard non debba ricordarsene.
 *
 * A dominio acceso ogni metodo lascia decidere al parent. Attenzione: un
 * metodo ridefinito nella Resource vince su quello del trait, quindi chi
 * ridefinisce un authorizedTo*() deve rifare il controllo con
 * trailRegistryEnabled() — o negare sempre, come fanno le Resource del
 * package sulle scritture.
 */
trait HidesWhenTrailRegistryDisabled
{
    protected static function trailRegistryEnabled(): bool
    {
        return FeaturesService::isEnabled('trail_registry');
    }

    public static function availableForNavigation(Request $request): bool
    {
        return static::trailRegistryEnabled() && static::$displayInNavigation;
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        return static::trailRegistryEnabled() && parent::authorizedToViewAny($request);
    }

    public function authorizedToView(Request $request): bool
    {
        return static::trailRegistryEnabled() && parent::authorizedToView($request);
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return static::trailRegistryEnabled() && parent::authorizedToCreate($request);
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return static::trailRegistryEnabled() && parent::authorizedToUpdate($request);
    }

    public function authorizedToDelete(Request $request): bool
    {
        return static::trailRegistryEnabled() && parent::authorizedToDelete($request);
    }

    public function authorizedToRunAction(NovaRequest $request, Action $action): bool
    {
        return static::trailRegistryEnabled() && parent::authorizedToRunAction($request, $action);
    }
}
