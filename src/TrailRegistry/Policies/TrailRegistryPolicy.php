<?php

namespace Wm\WmPackage\TrailRegistry\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Chi vede e usa il Catasto in Nova (oc:8700): solo Administrator ed Editor,
 * in ogni shard che accende il dominio.
 *
 * Serve una policy, non bastano gli authorizedTo*() delle Resource: senza
 * policy `authorizable()` e' falso e Nova non controlla detail, modifica e
 * download aperti per URL (`Authorizable::authorizeTo()`). Le Action con
 * `canRun()` saltano comunque l'autorizzazione della Resource
 * (`ActionModelCollection::filterForExecution()`): per questo `allows()` e'
 * pubblico, e le Action lo richiamano nel proprio `canRun()`.
 *
 * Perche' tre classi e non una: il Gate di Laravel toglie il nome della classe
 * dagli argomenti prima di chiamare la policy, quindi `create()` non puo'
 * sapere per quale modello viene chiamato. Questa base ha le regole comuni
 * (sola lettura, nessuna cancellazione) e una sottoclasse per modello
 * dichiara solo cio' che la distingue: TrailApplicationPolicy crea e modifica,
 * le altre due restano in sola lettura.
 *
 * Non va usata fuori da Nova: le API del Catasto non chiamano `can()` sui
 * suoi modelli, e non devono iniziare a farlo per effetto di questa regola.
 */
class TrailRegistryPolicy
{
    public const ROLES = ['Administrator', 'Editor'];

    /**
     * L'unico punto in cui stanno i ruoli: Policy, Action e Resource degli
     * shard (forestas: le righe del registro) chiamano questo metodo.
     */
    public static function allows(?Authenticatable $user): bool
    {
        return $user !== null
            // PHPStan crede che Authenticatable abbia hasAnyRole (trait Spatie visto
            // dall'analisi); a runtime l'utente puo' non averlo, quindi il controllo resta.
            && method_exists($user, 'hasAnyRole') // @phpstan-ignore function.alreadyNarrowedType
            && $user->hasAnyRole(self::ROLES);
    }

    public function viewAny(Authenticatable $user): bool
    {
        return static::allows($user);
    }

    public function view(Authenticatable $user, Model $model): bool
    {
        return static::allows($user);
    }

    /**
     * Di base niente si crea da Nova: codici e anomalie li scrivono il service
     * e l'import. Le istanze lo ridefiniscono.
     */
    public function create(Authenticatable $user): bool
    {
        return false;
    }

    /**
     * Di base niente si modifica da Nova. Le istanze lo ridefiniscono.
     */
    public function update(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function delete(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function restore(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function forceDelete(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function replicate(Authenticatable $user, Model $model): bool
    {
        return false;
    }

    public function runAction(Authenticatable $user, Model $model): bool
    {
        return static::allows($user);
    }

    public function runDestructiveAction(Authenticatable $user, Model $model): bool
    {
        return static::allows($user);
    }
}
