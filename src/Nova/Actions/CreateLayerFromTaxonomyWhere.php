<?php

namespace Wm\WmPackage\Nova\Actions;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;

class CreateLayerFromTaxonomyWhere extends Action
{
    use InteractsWithQueue, Queueable;

    public function name(): string
    {
        return __('Crea Layer');
    }

    public function handle(ActionFields $fields, Collection $models): mixed
    {
        $apps = App::all();
        $app = null;

        if ($apps->count() === 1) {
            $app = $apps->first();
            $appId = $app?->id;
        } else {
            $appId = $fields->get('app_id');
            if (! $appId) {
                return Action::danger("Seleziona un'App.");
            }

            $app = App::find($appId);
            if (! $app) {
                return Action::danger('App non trovata.');
            }
        }

        if (! $app || empty($app->user_id)) {
            return Action::danger("L'App selezionata non ha un utente associato.");
        }

        $count = 0;

        foreach ($models as $taxonomyWhere) {
            $layerName = $this->resolveLayerName($taxonomyWhere);
            $layer = Layer::create([
                'name'    => $layerName,
                'app_id'  => $appId,
                'user_id' => $app->user_id,
            ]);

            $layer->taxonomyWheres()->attach($taxonomyWhere->id);
            $this->syncFeatureImageFromTaxonomy($taxonomyWhere, $layer);
            $count++;
        }

        return Action::message("Creati {$count} layer.");
    }

    public function fields(NovaRequest $request): array
    {
        $fields = [];

        $apps = App::all();
        if ($apps->count() > 1) {
            $fields[] = Select::make('App', 'app_id')
                ->options($apps->pluck('name', 'id')->toArray())
                ->rules('required');
        }

        return $fields;
    }

    /**
     * @return string|array<string,string>
     */
    private function resolveLayerName($taxonomyWhere): string|array
    {
        $name = $taxonomyWhere->name;
        if (is_string($name) && trim($name) !== '') {
            return $name;
        }
        if (is_array($name) && count(array_filter($name, static fn ($v) => is_string($v) && trim($v) !== '')) > 0) {
            return $name;
        }

        $properties = is_array($taxonomyWhere->properties ?? null) ? $taxonomyWhere->properties : [];
        $titles = [];

        foreach (['title', 'name', 'layer_name'] as $key) {
            $candidate = $properties[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
            if (is_array($candidate)) {
                foreach (['it', 'en'] as $lang) {
                    if (isset($candidate[$lang]) && is_string($candidate[$lang]) && trim($candidate[$lang]) !== '') {
                        $titles[$lang] = $candidate[$lang];
                    }
                }
                if ($titles !== []) {
                    return $titles;
                }
            }
        }

        return 'Layer '.(string) $taxonomyWhere->id;
    }

    private function syncFeatureImageFromTaxonomy($taxonomyWhere, Layer $layer): void
    {
        $taxonomyProperties = is_array($taxonomyWhere->properties ?? null) ? $taxonomyWhere->properties : [];
        $featureImage = $taxonomyProperties['feature_image'] ?? null;
        if (! is_string($featureImage) || trim($featureImage) === '') {
            return;
        }

        try {
            $existing = $layer->getMedia('default')->first(function ($media) use ($featureImage) {
                return ($media->custom_properties['source_feature_image_url'] ?? null) === $featureImage;
            });

            if ($existing !== null) {
                return;
            }

            $layer->addMediaFromUrl($featureImage)
                ->usingName('feature_image_'.(string) $taxonomyWhere->id)
                ->usingFileName('feature-image-'.(string) $taxonomyWhere->id.'-'.Str::random(6).'.jpg')
                ->withCustomProperties([
                    'source_feature_image_url' => $featureImage,
                    'source_taxonomy_where_id' => $taxonomyWhere->id,
                ])
                ->toMediaCollection('default');
        } catch (\Throwable $e) {
            Log::warning('CreateLayerFromTaxonomyWhere: feature image sync failed', [
                'taxonomy_where_id' => $taxonomyWhere->id,
                'layer_id' => $layer->id,
                'feature_image' => $featureImage,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
