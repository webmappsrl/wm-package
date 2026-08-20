<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Panel;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\ConfigDetailPreviewRenderer;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver;

/**
 * Shared "Detail Blocks" panel (properties->config_detail) for Layer/EcTrack/EcPoi: the
 * editable Flexible builder (forms only) plus a read-only HTML preview for detail view,
 * since Nova's Repeater field does not render inside a Flexible layout on detail (only on
 * forms). The preview's own rendering logic lives in ConfigDetailPreviewRenderer, not here
 * — this trait is scoped to defining the Nova field/Panel, not to view-rendering.
 */
trait HasConfigDetailPanel
{
    protected function configDetailPanel(): Panel
    {
        return Panel::make(__('Detail Blocks'), [
            Flexible::make(__('Detail Blocks'), 'properties->config_detail')
                ->resolver(ConfigDetailResolver::class)
                ->addLayout(__('Info Box'), ConfigDetailResolver::INFO_BOX_TYPE, [
                    Repeater::make(__('Items'), 'items')
                        ->repeatables([InfoBoxItemRepeatable::make()])
                        ->rules('required', 'array')
                        ->fullWidth(),
                ])
                ->button(__('Add Info Box'))
                ->confirmRemove(__('Are you sure you want to delete this box?'), __('Delete'), __('Cancel'))
                ->fullWidth()
                ->onlyOnForms(),
            Text::make(__('Detail Blocks Preview'), function () {
                return (new ConfigDetailPreviewRenderer())->render($this->resource);
            })->asHtml()->onlyOnDetail(),
        ])->collapsible();
    }
}
