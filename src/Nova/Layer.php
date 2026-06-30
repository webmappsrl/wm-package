<?php

namespace Wm\WmPackage\Nova;

use App\Nova\User;
use Ebess\AdvancedNovaMediaLibrary\Fields\Images;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\MorphToMany;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Wm\WmPackage\Models\Layer as LayerModel;
use Wm\WmPackage\Nova\Actions\AddLayersToConfigHomeAction;
use Wm\WmPackage\Nova\Actions\ExecuteEcTrackDataChainAction;
use Wm\WmPackage\Nova\Cards\ApiLinksCard\LayerApiLinksCard;
use Wm\WmPackage\Nova\Cards\LayerAnalytics\LayerAnalyticsCard;
use Wm\WmPackage\Nova\Fields\FeatureCollectionMap\src\FeatureCollectionMap;
use Wm\WmPackage\Nova\Fields\LayerFeatures\LayerFeatures;
use Wm\WmPackage\Nova\Fields\PropertiesPanel;
use Wm\WmPackage\Nova\Filters\AppFilter;
use Wm\WmPackage\Nova\Traits\MultiPolygonResourceTrait;

class Layer extends AbstractGeometryResource
{
    use MultiPolygonResourceTrait {
        fields as protected fieldsTrait;
    }

    public static $with = ['ecTracks', 'ecPois', 'appOwner', 'associatedApps'];

    public static $model = LayerModel::class;

    public static $title = 'name';

    public static $search = [
        'id',
        'name',
    ];

    public function fields(NovaRequest $request): array
    {
        return [
            ID::make()->sortable(),
            Boolean::make('In Home', function () {
                /** @var LayerModel $layer */
                $layer = $this->resource;

                if (! $layer->app_id) {
                    return false;
                }

                $app = $layer->appOwner;
                if (! $app) {
                    return false;
                }

                $raw = $app->getRawOriginal('config_home');
                if (empty($raw)) {
                    return false;
                }

                $data = json_decode($raw, true);
                $home = $data['HOME'] ?? [];

                return collect($home)->contains(
                    fn ($item) => ($item['box_type'] ?? '') === 'layer'
                        && (int) ($item['layer'] ?? 0) === $layer->id
                );
            })->onlyOnIndex(),
            NovaTabTranslatable::make([
                Text::make(__('Name'), 'name')->required(),
            ]),
            Number::make(__('Rank'), 'rank', function () {
                if (is_array($this->properties) && isset($this->properties['rank'])) {
                    return (int) $this->properties['rank'];
                }

                return $this->rank ?? 0;
            })->onlyOnIndex()->sortable(),
            BelongsTo::make(__('App'), 'appOwner', App::class),
            BelongsTo::make(__('Owner'), 'layerOwner', User::class)
                ->nullable()
                ->searchable(),
            Images::make(__('Image'), 'default'),
            PropertiesPanel::makeWithModel(__('Properties'), 'properties', $this, true)->collapsible(),
            MorphToMany::make(__('Activities'), 'taxonomyActivities', TaxonomyActivity::class),
            MorphToMany::make('Taxonomy Where', 'taxonomyWheres', TaxonomyWhere::class)
                ->actions(fn () => []),
            Panel::make(__('Map'), [
                FeatureCollectionMap::make(__('Geometry'), 'geometry')->onlyOnDetail(),
            ]),
            Panel::make('Ec Tracks', [
                LayerFeatures::make(__('tracks'), $this->resource, config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack'))
                    ->hideWhenCreating()
                    ->withMeta(['model_class' => config('wm-package.ec_track_model', 'Wm\WmPackage\Models\EcTrack')]),
            ]),
            Panel::make('Ec Pois', [
                LayerFeatures::make(__('pois'), $this->resource, config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi'))
                    ->hideWhenCreating()
                    ->withMeta(['model_class' => config('wm-package.ec_poi_model', 'Wm\WmPackage\Models\EcPoi')]),
            ]),
            Text::make(__('Web Component'), function () {
                /** @var LayerModel $layer */
                $layer = $this->resource;

                return $this->renderLayerWebComponentCopyButton($layer);
            })
                ->asHtml()
                ->onlyOnDetail()
                ->canSee(function () {
                    /** @var LayerModel $layer */
                    $layer = $this->resource;

                    return $this->shouldShowLayerWebComponentCopyButton($layer);
                }),
        ];
    }

    public function actions(NovaRequest $request): array
    {
        return [
            ...parent::actions($request),
            new AddLayersToConfigHomeAction,
            new Actions\RegenerateLayerPbfAction,
            ExecuteEcTrackDataChainAction::make()
                ->confirmText(__('Are you sure you want to process all tracks of this layer?'))
                ->confirmButtonText(__('Yes, process'))
                ->cancelButtonText(__('No, cancel')),
        ];
    }

    public function filters(NovaRequest $request): array
    {
        return [
            ...parent::filters($request),
            new AppFilter,
        ];
    }

    public function cards(NovaRequest $request): array
    {
        if (! $request->resourceId) {
            return [];
        }

        /** @var LayerModel $layer */
        $layer = $request->findModelOrFail();
        $app = $layer->appOwner;
        $appProperties = $this->getLayerAppProperties($layer);
        $cards = [new LayerApiLinksCard($layer)];
        $analyticsEnabled = $app &&
            (($appProperties['analytics_app_enabled'] ?? false) ||
             ($appProperties['analytics_webapp_enabled'] ?? false));

        if ($analyticsEnabled) {
            $cards[] = new LayerAnalyticsCard($layer);
        }

        return $cards;
    }

    private function shouldShowLayerWebComponentCopyButton(LayerModel $layer): bool
    {
        $appProperties = $this->getLayerAppProperties($layer);

        return (bool) ($appProperties['layer_web_component_enabled'] ?? false);
    }

    /** @return array<string, mixed> */
    private function getLayerAppProperties(LayerModel $layer): array
    {
        $app = $layer->appOwner;

        return $app ? ($app->properties ?? []) : [];
    }

    private function renderLayerWebComponentCopyButton(LayerModel $layer): string
    {
        $snippet = $this->buildLayerWebComponentSnippet($layer);
        $snippetJson = json_encode($snippet, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $defaultLabelJson = json_encode((string) __('Copy web component code'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $successLabelJson = json_encode((string) __('Code copied'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $errorLabelJson = json_encode((string) __('Copy error'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);

        $onClick = <<<JS
(async function(button) {
  const text = {$snippetJson};
  const defaultLabel = {$defaultLabelJson};
  const successLabel = {$successLabelJson};
  const errorLabel = {$errorLabelJson};

  try {
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(text);
    } else {
      const textArea = document.createElement('textarea');
      textArea.value = text;
      textArea.setAttribute('readonly', '');
      textArea.style.position = 'absolute';
      textArea.style.left = '-9999px';
      document.body.appendChild(textArea);
      textArea.select();

      const copied = document.execCommand('copy');

      document.body.removeChild(textArea);

      if (!copied) {
        throw new Error('Clipboard copy failed');
      }
    }

    button.textContent = successLabel;
  } catch (error) {
    button.textContent = errorLabel;
  }

  window.setTimeout(function() {
    button.textContent = defaultLabel;
  }, 2000);
})(this); return false;
JS;

        $escapedOnClick = htmlspecialchars($onClick, ENT_QUOTES, 'UTF-8');
        $buttonLabel = htmlspecialchars((string) __('Copy web component code'), ENT_QUOTES, 'UTF-8');
        $helperHtml = $this->renderLayerWebComponentHelper();

        return <<<HTML
<div>
  <button type="button" onclick="{$escapedOnClick}" style="border:0;border-radius:14px;background:#19b7a1;color:#ffffff;padding:14px 26px;font-size:16px;font-weight:600;cursor:pointer;">
    {$buttonLabel}
  </button>
  <div style="margin-top:10px;font-size:13px;line-height:1.5;color:#6b7280;">
    {$helperHtml}
  </div>
</div>
HTML;
    }

    private function renderLayerWebComponentHelper(): string
    {
        $helperText = htmlspecialchars((string) __('Test the copied code by pasting it into'), ENT_QUOTES, 'UTF-8');
        $url = 'https://html.onlineviewer.net/';

        return <<<HTML
{$helperText} <a href="{$url}" target="_blank" rel="noopener noreferrer">{$url}</a>
HTML;
    }

    private function buildLayerWebComponentSnippet(LayerModel $layer): string
    {
        $shard = (string) config('wm-package.shard_name');
        $componentConfig = $this->resolveLayerMapComponentConfig();
        $tagName = $componentConfig['tag_name'];
        $scriptUrl = $componentConfig['script_url'];
        $defaultStyle = $componentConfig['default_style'];

        return <<<HTML
<{$tagName}
  shard="{$shard}"
  app-id="{$layer->app_id}"
  layer-id="{$layer->id}"
  style="{$defaultStyle}"
></{$tagName}>
<script
  type="module"
  src="{$scriptUrl}"
></script>
HTML;
    }

    /**
     * @return array{tag_name: string, script_url: string, default_style: string}
     */
    private function resolveLayerMapComponentConfig(): array
    {
        $config = config('wm-package.web_components.layer_map', []);
        $fallback = $this->getFallbackLayerMapComponentConfig($config);
        $exampleUrl = (string) ($config['example_url'] ?? '');
        $timeout = (int) ($config['timeout'] ?? 10);
        $cacheTtl = (int) ($config['cache_ttl'] ?? 1800);

        if ($exampleUrl === '') {
            return $fallback;
        }

        $cacheKey = 'wm_layer_map_component_config_'.md5($exampleUrl);

        return Cache::remember($cacheKey, $cacheTtl, function () use ($exampleUrl, $timeout, $fallback) {
            $exampleConfig = $this->fetchLayerMapExampleConfig($exampleUrl, $timeout, $fallback);
            if ($exampleConfig !== null) {
                return $exampleConfig;
            }

            return $fallback;
        });
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{tag_name: string, script_url: string, default_style: string}
     */
    private function getFallbackLayerMapComponentConfig(array $config): array
    {
        $fallback = $config['fallback'] ?? [];

        return [
            'tag_name' => (string) ($fallback['tag_name'] ?? 'wm-layer-map'),
            'script_url' => (string) ($fallback['script_url'] ?? 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-layer-map@refs/heads/main/src/wm-layer-map.js'),
            'default_style' => (string) ($fallback['default_style'] ?? 'display:block;width:100%;height:600px'),
        ];
    }

    /**
     * @param  array{tag_name: string, script_url: string, default_style: string}  $fallback
     * @return array{tag_name: string, script_url: string, default_style: string}|null
     */
    private function fetchLayerMapExampleConfig(string $exampleUrl, int $timeout, array $fallback): ?array
    {
        if ($exampleUrl === '') {
            return null;
        }

        try {
            $response = Http::timeout($timeout)->get($exampleUrl);
            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();
            if (! preg_match('/<script\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>\s*<\/script>/i', $html, $matches)) {
                return null;
            }

            $scriptUrl = trim((string) ($matches[1] ?? ''));
            if ($scriptUrl === '') {
                return null;
            }

            return [
                'tag_name' => $fallback['tag_name'],
                'script_url' => $scriptUrl,
                'default_style' => $fallback['default_style'],
            ];
        } catch (\Throwable $exception) {
            Log::warning('Layer resource: unable to fetch wm-layer-map example: '.$exception->getMessage());

            return null;
        }
    }
}
