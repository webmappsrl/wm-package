<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\User;
use Wm\WmPackage\Services\Osm\OsmImportReportPresenter;
use Wm\WmPackage\Services\Osm\OsmImportReportStore;
use Wm\WmPackage\Services\Osm\OsmPoiImporter;
use Wm\WmPackage\Services\RolesAndPermissionsService;

/**
 * Imports {@see EcPoi} records from comma-separated OSM node IDs.
 *
 * UI: textarea + app select + global + dry-run. POI owner is not chosen manually; it comes from
 * {@see App::$user_id} on the selected app.
 *
 * After completion (including dry-run), response is a redirect to the internal report page
 * (same tab), without external toasts or popups.
 *
 * App visibility:
 *  - Administrator role, or email in {@see RolesAndPermissionsService::allowsUser()} allowlist → all apps
 *  - other users → only apps where they are `user_id` ({@see User::apps()})
 *
 * App select: first app (by name) is pre-selected; when the user sees only one app, the select is read-only.
 */
class ImportEcPoiFromOsm extends Action
{
    use InteractsWithQueue, Queueable;

    public $standalone = true;

    public function __construct()
    {
        // English strings: Nova applies `Nova::__()` on serialization (correct user locale).
        $this->confirmText = 'Data will be downloaded from openstreetmap.org for each OSM ID. Continue?';
        $this->confirmButtonText = 'Import';
    }

    public function name(): string
    {
        return __('Import POIs from OSM');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $rawOsmIds = (string) ($fields->get('osm_ids') ?? '');
        $osmIds = $this->parseOsmIds($rawOsmIds);

        if ($osmIds === []) {
            return Action::danger(__('No valid OSM IDs found. Enter numeric IDs separated by commas.'));
        }

        $app = $this->resolveApp($fields);
        if ($app === null) {
            return Action::danger(__('No app selected or available.'));
        }

        $userId = $app->user_id !== null ? (int) $app->user_id : null;
        $dryRun = (bool) $fields->get('dry_run');
        $global = (bool) $fields->get('global', true);

        /** @var OsmPoiImporter $importer */
        $importer = app(OsmPoiImporter::class);
        $report = $importer->importNodes($osmIds, (int) $app->id, $userId, $dryRun, $global);

        $payload = OsmImportReportPresenter::payload($report, count($osmIds));
        $token = OsmImportReportStore::put($payload, (int) Auth::id());

        return Action::redirect(route('osm.import.report', ['token' => $token]));
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [
            Textarea::make(__('OSM node IDs (comma-separated)'), 'osm_ids')
                ->rows(4)
                ->help(__('Example: 12345, 67890, 11223. OSM nodes only (points).').' '.__('If an OSM ID was already imported, its POI will be updated.'))
                ->rules('required', 'string', 'max:10000'),
        ];

        $apps = $this->visibleAppsFor($request->user())->orderBy('name')->get();

        $appSelect = Select::make(__('App'), 'app_id')
            ->options($apps->pluck('name', 'id')->toArray())
            ->rules('required')
            ->searchable()
            ->displayUsingLabels()
            ->help(__('The POI owner is automatically set to the user_id of the selected app.'));

        if ($apps->isNotEmpty()) {
            $appSelect->default($apps->first()->id);
        }

        if ($apps->count() === 1) {
            $appSelect->readonly();
        }

        $fields[] = $appSelect;

        $fields[] = Boolean::make(__('Include in app pois.geojson (EcPoi.global = true)'), 'global')
            ->default(true)
            ->help(__('When enabled, POIs are included in the app’s pois.geojson file (global filter in getAllPoisGeojson). When disabled, they are imported but excluded from that file until global is set to true.'));

        $fields[] = Boolean::make(__('Dry run (no writes)'), 'dry_run')
            ->default(false)
            ->help(__('When enabled, data is fetched and the outcome is shown without persisting any changes.'));

        return $fields;
    }

    /**
     * Apps visible to the current user:
     *  - Administrator role, or super-admin email allowlist → all apps;
     *  - others → only apps they own (`apps.user_id = user.id`).
     */
    private function visibleAppsFor(?User $user): Builder
    {
        $query = App::query();

        if ($user === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('Administrator') || RolesAndPermissionsService::allowsUser($user)) {
            return $query;
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * @return list<int>
     */
    private function parseOsmIds(string $input): array
    {
        $ids = [];
        foreach (preg_split('/[\s,;]+/', $input) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || ! ctype_digit($token)) {
                continue;
            }
            $ids[] = (int) $token;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resolves the app from the selected `app_id`, or auto-selection when the user sees only one app.
     * Ensures the app is among visible apps (no bypass via tampered form data).
     */
    private function resolveApp(ActionFields $fields): ?App
    {
        $user = Auth::user();
        $visible = $this->visibleAppsFor($user instanceof User ? $user : null);

        $appIdFromField = $fields->get('app_id');
        if (! empty($appIdFromField)) {
            return $visible->where('id', (int) $appIdFromField)->first();
        }

        $apps = (clone $visible)->limit(2)->get();

        return $apps->count() === 1 ? $apps->first() : null;
    }
}
