<?php

namespace Wm\WmPackage\Nova\Traits;

use Laravel\Nova\Fields\Repeater;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Panel;
use Whitecube\NovaFlexibleContent\Flexible;
use Wm\WmPackage\Nova\Flexible\ConfigDetail\InfoBoxItemRepeatable;
use Wm\WmPackage\Nova\Flexible\Resolvers\ConfigDetailResolver;

/**
 * Shared "Blocchi Dettaglio" panel (properties->config_detail) for Layer/EcTrack/EcPoi:
 * the editable Flexible builder (forms only) plus a read-only HTML preview for detail view,
 * since Nova's Repeater field does not render inside a Flexible layout on detail (only on forms).
 */
trait HasConfigDetailPanel
{
    protected function configDetailPanel(): Panel
    {
        return Panel::make(__('Detail Blocks'), [
            Flexible::make(__('Detail Blocks'), 'properties->config_detail')
                ->resolver(ConfigDetailResolver::class)
                ->addLayout(__('Info Box'), 'info', [
                    Repeater::make(__('Items'), 'items')
                        ->repeatables([InfoBoxItemRepeatable::make()])
                        ->rules('required', 'array'),
                ])
                ->button(__('Add Info Box'))
                ->confirmRemove(__('Are you sure you want to delete this box?'), __('Delete'), __('Cancel'))
                ->fullWidth()
                ->onlyOnForms(),
            Text::make(__('Detail Blocks Preview'), function () {
                return $this->renderConfigDetailPreview($this->resource);
            })->asHtml()->onlyOnDetail(),
        ])->collapsible();
    }

    protected function renderConfigDetailPreview($model): string
    {
        $groups = data_get($model, 'properties.config_detail', []);

        if (! is_array($groups) || $groups === []) {
            return '<p style="color:#94a3b8;">'.__('No blocks configured.').'</p>';
        }

        $html = '';

        foreach ($groups as $groupIndex => $group) {
            if (! is_array($group) || ($group['box_type'] ?? null) !== 'info') {
                continue;
            }

            $items = (array) ($group['items'] ?? []);

            if ($items === []) {
                continue;
            }

            $groupHtml = '';

            foreach ($items as $item) {
                $title = $this->pickLocalizedConfigDetailValue($item['title'] ?? []);
                $content = $this->pickLocalizedConfigDetailValue($item['content'] ?? []);

                $groupHtml .= '<details class="wm-config-detail-item" style="border:1px solid #e2e8f0;border-radius:6px;margin-bottom:10px;overflow:hidden;">';
                $groupHtml .= '<summary style="background:#f8fafc;padding:8px 12px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px;">';
                $groupHtml .= '<span>'.e($title).'</span>';
                $groupHtml .= '<span class="wm-config-detail-chevron" aria-hidden="true">&#9656;</span>';
                $groupHtml .= '</summary>';
                $groupHtml .= '<div style="padding:12px;">'.$content.'</div>';
                $groupHtml .= '</details>';
            }

            $html .= '<div style="border:2px solid #cbd5e1;border-radius:8px;padding:14px 14px 4px;margin-bottom:22px;background:#fcfcfd;">';
            $html .= '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:10px;">#'.((int) $groupIndex + 1).' '.__('Info Box').'</div>';
            $html .= $groupHtml;
            $html .= '</div>';
        }

        if ($html === '') {
            return '<p style="color:#94a3b8;">'.__('No blocks configured.').'</p>';
        }

        $style = '<style>'
            .'.wm-config-detail-item > summary{list-style:none}'
            .'.wm-config-detail-item > summary::-webkit-details-marker{display:none}'
            .'.wm-config-detail-item > summary::marker{content:"";}'
            .'.wm-config-detail-chevron{display:inline-block;transition:transform .15s ease;flex-shrink:0}'
            .'.wm-config-detail-item[open] .wm-config-detail-chevron{transform:rotate(90deg)}'
            .'</style>';

        return $style.$html;
    }

    protected function pickLocalizedConfigDetailValue($value): string
    {
        if (! is_array($value)) {
            return '';
        }

        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            if (! empty($value[$locale])) {
                return (string) $value[$locale];
            }
        }

        foreach ($value as $candidate) {
            if (! empty($candidate)) {
                return (string) $candidate;
            }
        }

        return '';
    }
}
