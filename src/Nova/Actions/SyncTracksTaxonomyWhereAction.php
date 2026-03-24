<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;

class SyncTracksTaxonomyWhereAction extends Action
{
    use InteractsWithQueue, Queueable;

    public $name = 'Sincronizza Taxonomy Where su Tracks';

    public $onlyOnIndex = false;

    public $standalone = true;

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $updated = DB::statement("
            UPDATE ec_tracks
            SET properties = jsonb_set(
                COALESCE(properties, '{}'),
                '{taxonomy_where}',
                COALESCE(
                    (
                        SELECT jsonb_object_agg(
                            tw.osmfeatures_id,
                            jsonb_build_object('name', tw.name, 'admin_level', tw.admin_level)
                        )
                        FROM taxonomy_wheres tw
                        WHERE tw.geometry IS NOT NULL
                          AND ST_Intersects(ec_tracks.geometry::geometry, tw.geometry::geometry)
                    ),
                    '{}'::jsonb
                )
            )
            WHERE geometry IS NOT NULL
        ");

        $count = DB::selectOne('SELECT COUNT(*) as c FROM ec_tracks WHERE geometry IS NOT NULL AND properties->\'taxonomy_where\' != \'{}\'')->c;

        return Action::message("taxonomy_where aggiornata su {$count} tracks.");
    }

    public function fields(\Laravel\Nova\Http\Requests\NovaRequest $request): array
    {
        return [];
    }
}
