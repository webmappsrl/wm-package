<?php

namespace Wm\WmPackage\Nova\Flexible\ConfigDetail;

use Illuminate\Support\Facades\Config;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\Repeater\Repeatable;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;
use Wm\WmPackage\Nova\Traits\HasFlexibleTranslatableFields;

class InfoBoxItemRepeatable extends Repeatable
{
    use HasFlexibleTranslatableFields;

    public static function key(): string
    {
        return 'info-box-item';
    }

    /**
     * @return array<int, Field>
     */
    public function fields(NovaRequest $request): array
    {
        $fields = $this->translatableFields(__('Title'), 'title');

        // Build the HTMLPurifier config/instance once per fields() call instead
        // of once per locale/row/request — HTMLPurifier_Config/HTMLPurifier
        // construction (and the HTMLDefinition it builds) is comparatively
        // expensive, and this loop iterates once per configured locale.
        $cachePath = storage_path('framework/cache/htmlpurifier');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0775, true);
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', 'p,br,b,strong,i,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href]');
        // Explicit, writable cache path: HTMLPurifier's default serializes its
        // HTMLDefinition into vendor/ezyang/htmlpurifier/..., which is not
        // writable on read-only/rebuilt-vendor deploys.
        $config->set('Cache.SerializerPath', $cachePath);

        $purifier = new \HTMLPurifier($config);

        foreach (Config::get('wm-tab-translatable.locales', []) as $locale) {
            $fields[] = Trix::make(__('Content').' ('.$locale.')', "content_{$locale}")
                ->nullable()
                ->fillUsing(function (NovaRequest $request, $model, $attribute, $requestAttribute) use ($purifier) {
                    $value = $request->input($requestAttribute);

                    if (! is_string($value) || $value === '') {
                        $model->{$attribute} = $value;

                        return;
                    }

                    $model->{$attribute} = $purifier->purify($value);
                });
        }

        return $fields;
    }
}
