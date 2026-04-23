<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

class AddLayersToConfigHomeAction extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Aggiungi alla Home');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $added = 0;
        $skipped = 0;

        // Raggruppa i layer per app per fare un solo UPDATE per app
        $byApp = $models->groupBy('app_id');

        foreach ($byApp as $appId => $layers) {
            $app = $layers->first()->appOwner;
            if (! $app) {
                $skipped += $layers->count();

                continue;
            }

            $raw = $app->getRawOriginal('config_home');
            $data = ! empty($raw) ? json_decode($raw, true) : [];
            $home = $data['HOME'] ?? [];

            // Set degli id già presenti in home per lookup veloce
            $presentIds = collect($home)
                ->where('box_type', 'layer')
                ->pluck('layer')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($layers as $layer) {
                if (in_array($layer->id, $presentIds, true)) {
                    $skipped++;

                    continue;
                }

                $title = $layer->getStringName();
                $home[] = [
                    'box_type' => 'layer',
                    'layer' => $layer->id,
                    'title' => ! empty($title) ? $title : 'Layer #'.$layer->id,
                ];
                $presentIds[] = $layer->id;
                $added++;
            }

            $data['HOME'] = $home;
            DB::table('apps')
                ->where('id', $app->id)
                ->update(['config_home' => json_encode($data)]);
        }

        $msg = "Aggiunti {$added} layer alla home.";
        if ($skipped > 0) {
            $msg .= " ({$skipped} già presenti, saltati)";
        }

        return Action::message($msg);
    }

    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
