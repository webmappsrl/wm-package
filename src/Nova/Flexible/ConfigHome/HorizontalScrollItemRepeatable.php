<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigHome;

use Illuminate\Support\Facades\Config;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Models\TaxonomyActivity as TaxonomyActivityModel;
use Wm\WmPackage\Models\TaxonomyPoiType as TaxonomyPoiTypeModel;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

/**
 * Repeatable block for the `horizontal_scroll_activities` and `horizontal_scroll_poi_types` Flexible layouts
 * on the `config_home` field. Other `config_home` layouts (title, layer, slug, external_url) do not use it.
 */
class HorizontalScrollItemRepeatable extends Repeatable
{
    use HasFlexibleTranslatableFields;

    /**
     * Select options map (taxonomy identifier => label). Not the per-row field values.
     *
     * @var array<string, string>
     */
    protected array $options = [];

    /**
     * @param  array<string, string>  $optionsOrData  Either taxonomy options map (from `::make(...)`), or
     *                                                hydrated row `['res' => ..., 'image_url' => ...]` passed by Nova.
     */
    public function __construct(array $optionsOrData = [])
    {
        if ($this->isTaxonomySelectOptionsMap($optionsOrData)) {
            $this->options = $optionsOrData;
            parent::__construct();
        } else {
            parent::__construct($optionsOrData);
        }
    }

    /**
     * Returns true when the array looks like a taxonomy options map (identifier => label), not a hydrated repeater row.
     *
     * @param  array<string, string>  $data
     */
    private function isTaxonomySelectOptionsMap(array $data): bool
    {
        if ($data === []) {
            return false;
        }

        foreach (array_keys($data) as $key) {
            if ($key === 'res' || $key === 'image_url' || $key === 'title') {
                continue;
            }

            return true;
        }

        return false;
    }

    public static function key(): string
    {
        return 'horizontal-scroll-item';
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        $options = $this->options !== [] ? $this->options : $this->defaultTaxonomyOptionsForSelect();
        $languages = Config::get('wm-app-languages.languages', []);

        $fields = [
            Select::make(__('Item'), 'res')
                ->options($options)
                ->displayUsingLabels()
                ->rules('required'),
        ];

        foreach ($this->translatableFields(__('Title'), 'title') as $field) {
            $fields[] = $field->nullable()
                ->help(__('Overrides the default taxonomy label for this item in config JSON; leave empty to use the taxonomy name.'));
        }

        $fields[] = Text::make(__('Image URL'), 'image_url')
            ->nullable()
            ->help(__('Optional in Nova; include in config when you have a card image URL.'));

        return $fields;
    }

    /**
     * Loads taxonomy options when the hydrated instance was constructed without the template options map.
     *
     * @return array<string, string>
     */
    private function defaultTaxonomyOptionsForSelect(): array
    {
        $activities = TaxonomyActivityModel::query()
            ->get(['identifier', 'name'])
            ->mapWithKeys(function (TaxonomyActivityModel $activity) {
                return [
                    $activity->identifier => $this->taxonomyLabel($activity->name, $activity->identifier),
                ];
            });

        $poiTypes = TaxonomyPoiTypeModel::query()
            ->get(['identifier', 'name'])
            ->mapWithKeys(function (TaxonomyPoiTypeModel $poiType) {
                $identifier = 'poi_type_'.$poiType->identifier;

                return [
                    $identifier => $this->taxonomyLabel($poiType->name, $identifier),
                ];
            });

        return $activities->merge($poiTypes)->sortKeys()->all();
    }

    /**
     * @param  mixed  $name
     */
    private function taxonomyLabel($name, ?string $fallback): string
    {
        if (is_array($name)) {
            return (string) ($name['it'] ?? $name['en'] ?? $fallback);
        }

        if (is_string($name) && $name !== '') {
            return $name;
        }

        return $fallback;
    }
}
