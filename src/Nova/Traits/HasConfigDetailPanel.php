<?php

namespace Wm\WmPackage\Nova\Traits;

use Illuminate\Support\Facades\Config;
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

        $locales = Config::get('wm-tab-translatable.locales', []);
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

            foreach ($items as $itemIndex => $item) {
                $titles = is_array($item['title'] ?? null) ? $item['title'] : [];
                $contents = is_array($item['content'] ?? null) ? $item['content'] : [];
                $groupName = 'wm-cd-tabs-'.$groupIndex.'-'.$itemIndex;

                $groupHtml .= $this->renderConfigDetailItem($groupName, $locales, $titles, $contents);
            }

            $html .= '<div style="border:2px solid #cbd5e1;border-radius:8px;padding:14px 14px 4px;margin-bottom:22px;background:#fcfcfd;">';
            $html .= '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#94a3b8;margin-bottom:10px;">#'.((int) $groupIndex + 1).' '.__('Info Box').'</div>';
            $html .= $groupHtml;
            $html .= '</div>';
        }

        if ($html === '') {
            return '<p style="color:#94a3b8;">'.__('No blocks configured.').'</p>';
        }

        return $this->configDetailPreviewStyles().$html;
    }

    /**
     * One Info Box item: a locale tab bar that sits OUTSIDE (as a sibling of) the
     * collapsible <details>, so it's usable — and updates the summary title — before the
     * item is even expanded, then also drives the body content once opened. The radios
     * and tab labels must be siblings of both <summary> and the body for the CSS general
     * sibling selectors in configDetailTabActiveRules() to reach into each via a trailing
     * descendant selector (`~ .wm-cd-details .wm-cd-summary-locale[data-locale=X]` /
     * `~ .wm-cd-details .wm-cd-content[data-locale=X]`) — a checkbox-free equivalent of
     * the IT/EN/FR/ES/DE tabs every other translatable field shows in detail view
     * (NovaTabTranslatable's own DetailField.vue). No JS: Nova's asHtml() Text field
     * renders a static HTML blob, not a Vue component, so this has to work from CSS alone.
     */
    protected function renderConfigDetailItem(string $groupName, array $locales, array $titles, array $contents): string
    {
        if ($locales === []) {
            return '';
        }

        $defaultLocale = $this->pickLocalizedConfigDetailLocale($titles, $contents, $locales);

        $html = '<div class="wm-cd-item">';

        foreach ($locales as $locale) {
            $checked = $locale === $defaultLocale ? ' checked' : '';
            $html .= '<input type="radio" class="wm-cd-radio" name="'.e($groupName).'" id="'.e($groupName.'-'.$locale).'" data-locale-radio="'.e($locale).'"'.$checked.'>';
        }

        $html .= '<div class="wm-cd-tabbar">';
        foreach ($locales as $locale) {
            $html .= '<label class="wm-cd-tab" for="'.e($groupName.'-'.$locale).'">'.e(strtoupper($locale)).'</label>';
        }
        $html .= '</div>';

        $html .= '<details class="wm-cd-details">';
        $html .= '<summary class="wm-cd-summary">';
        foreach ($locales as $locale) {
            $html .= '<span class="wm-cd-summary-locale" data-locale="'.e($locale).'">'.e((string) ($titles[$locale] ?? '')).'</span>';
        }
        $html .= '<span class="wm-config-detail-chevron" aria-hidden="true">&#9656;</span>';
        $html .= '</summary>';
        $html .= '<div class="wm-cd-body-wrap">';
        foreach ($locales as $locale) {
            $html .= '<div class="wm-cd-content" data-locale="'.e($locale).'">'.(string) ($contents[$locale] ?? '').'</div>';
        }
        $html .= '</div>';
        $html .= '</details>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Same fallback order as the old single-locale preview (app locale, then it, then en,
     * then first non-empty) — only used to pick which tab starts selected.
     */
    protected function pickLocalizedConfigDetailLocale(array $titles, array $contents, array $locales): string
    {
        foreach ([app()->getLocale(), 'it', 'en'] as $locale) {
            if (in_array($locale, $locales, true) && (! empty($titles[$locale]) || ! empty($contents[$locale]))) {
                return $locale;
            }
        }

        foreach ($locales as $locale) {
            if (! empty($titles[$locale]) || ! empty($contents[$locale])) {
                return $locale;
            }
        }

        return $locales[0];
    }

    protected function configDetailPreviewStyles(): string
    {
        return '<style>'
            .'.wm-cd-item{position:relative;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:10px;overflow:hidden}'
            .'.wm-cd-radio{position:absolute;opacity:0;pointer-events:none}'
            .'.wm-cd-tabbar{display:flex;gap:4px;background:#eef1f4;padding:8px 8px 0;flex-wrap:wrap}'
            .'.wm-cd-tab{padding:6px 12px;font-size:12px;font-weight:700;text-transform:uppercase;color:#7c858e;cursor:pointer;border-radius:4px 4px 0 0;user-select:none}'
            .'.wm-cd-details{border:0}'
            .'.wm-cd-details > summary{list-style:none;background:#f8fafc;padding:8px 12px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:8px}'
            .'.wm-cd-details > summary::-webkit-details-marker{display:none}'
            .'.wm-cd-details > summary::marker{content:"";}'
            .'.wm-config-detail-chevron{display:inline-block;transition:transform .15s ease;flex-shrink:0}'
            .'.wm-cd-details[open] .wm-config-detail-chevron{transform:rotate(90deg)}'
            .'.wm-cd-summary-locale{display:none}'
            .'.wm-cd-body-wrap{padding:12px}'
            .'.wm-cd-content{display:none}'
            .$this->configDetailTabActiveRules()
            .'</style>';
    }

    protected function configDetailTabActiveRules(): string
    {
        $locales = Config::get('wm-tab-translatable.locales', []);
        $rules = '';

        foreach ($locales as $position => $locale) {
            $rules .= '.wm-cd-radio[data-locale-radio="'.$locale.'"]:checked ~ .wm-cd-tabbar .wm-cd-tab:nth-child('.((int) $position + 1).'){background:#fff;color:#4099de}';
            $rules .= '.wm-cd-radio[data-locale-radio="'.$locale.'"]:checked ~ .wm-cd-details .wm-cd-summary-locale[data-locale="'.$locale.'"]{display:inline}';
            $rules .= '.wm-cd-radio[data-locale-radio="'.$locale.'"]:checked ~ .wm-cd-details .wm-cd-content[data-locale="'.$locale.'"]{display:block}';
        }

        return $rules;
    }

}
