<?php

namespace Wm\WmPackage\TrailRegistry\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\TrailRegistry\Enums\TrailApplicationStatus;
use Wm\WmPackage\TrailRegistry\Nova\Actions\ApproveTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Actions\RejectTrailApplication;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationSourceFilter;
use Wm\WmPackage\TrailRegistry\Nova\Filters\TrailApplicationStatusFilter;

/**
 * @extends resource<\Wm\WmPackage\TrailRegistry\Models\TrailApplication>
 */
class TrailApplication extends Resource
{
    use ResolvesCanonicalResources;

    public static $model = \Wm\WmPackage\TrailRegistry\Models\TrailApplication::class;

    public static $title = 'name';

    public static $search = ['name'];

    public static function label(): string
    {
        return __('Istanze');
    }

    public static function singularLabel(): string
    {
        return __('Istanza');
    }

    /**
     * Nessun form di modifica in questo ciclo: cosa sia modificabile dopo la
     * presentazione e' una domanda aperta con il cliente — se la traccia
     * cambiasse, potrebbe cambiare il settore e quindi il prefisso di un
     * codice gia' comunicato.
     */
    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    /**
     * Nemmeno la cancellazione: un'istanza ha sempre almeno un codice nel
     * registro che la referenzia, e senza questo diniego l'operatore
     * otterrebbe una violazione di chiave esterna invece di una risposta
     * comprensibile.
     */
    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [
            Text::make(__('Denominazione'), 'name')->sortable(),

            Text::make(__('Codice'), fn () => $this->activeCode?->code),

            Text::make(__('Stato istruttoria'), fn () => $this->status->value),

            Text::make(__('Provenienza'), 'source'),
        ];

        $userResource = static::resourceForModel(User::class);

        // La Resource utente vive nel consumer (`\App\Nova\User`), quindi si
        // risolve a runtime: se il consumer non ne monta nessuna, si ripiega
        // sul nome, invece di costruire un BelongsTo senza Resource.
        $fields[] = $userResource !== null
            ? BelongsTo::make(__('Inserita da'), 'user', $userResource)->nullable()
            : Text::make(__('Inserita da'), fn () => $this->user->name);

        $fields[] = DateTime::make(__('Presentata il'), 'created_at')->sortable();

        $fields[] = Code::make(__('Proprietà'), 'properties')->json()->onlyOnDetail();

        return $fields;
    }

    public function filters(NovaRequest $request): array
    {
        return [
            new TrailApplicationStatusFilter,
            new TrailApplicationSourceFilter,
        ];
    }

    /**
     * Le due action si offrono solo sulle istanze in istruttoria. La stessa
     * guardia e' ripetuta dentro handle(): questa risparmia all'operatore un
     * bottone che non puo' funzionare, quella e' il presidio.
     */
    public function actions(NovaRequest $request): array
    {
        $onlyUnderReview = fn ($request, $application) => $application->status === TrailApplicationStatus::UnderReview;

        return [
            (new ApproveTrailApplication)->canRun($onlyUnderReview),
            (new RejectTrailApplication)->canRun($onlyUnderReview),
        ];
    }
}
