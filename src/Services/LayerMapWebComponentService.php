<?php

namespace Wm\WmPackage\Services;

use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer as LayerModel;

/**
 * Snippet del web component `wm-layer-map` e bottone di Nova che lo copia.
 *
 * Condiviso dalla Resource Layer (snippet del cammino) e dalla Resource EcTrack (snippet del
 * cammino con `track-id`, che apre la tappa all'avvio: oc:8944). Il bottone riceve una o più
 * option: con una sola copia il suo snippet, con più compare una select e si copia quello
 * dell'option selezionata al momento del click. Ogni snippet sta in un attributo `data-snippet`,
 * così il JS non contiene mai testo dell'utente.
 */
class LayerMapWebComponentService
{
    /**
     * URL statico del bootstrap del web component wm-layer-map, pubblicato da
     * webmappsrl/wm-elements (branch `dist`). Unica fonte di verità per il default:
     * `config/wm-package.php` NON duplica questa stringa — se un consumer non sovrascrive
     * `web_components.layer_map.fallback.script_url`, viene usata questa costante. Un config non
     * deve dipendere da una classe solo per leggere un URL (oc:8590, review).
     */
    public const DEFAULT_SCRIPT_URL = 'https://cdn.jsdelivr.net/gh/webmappsrl/wm-elements@dist/wm-layer-map/wm-layer-map.js';

    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT;

    public function isEnabledFor(LayerModel $layer): bool
    {
        $app = $layer->getRelationValue('appOwner');
        $properties = $app instanceof App ? ($app->properties ?? []) : [];

        return (bool) ($properties['layer_web_component_enabled'] ?? false);
    }

    public function snippet(LayerModel $layer, ?int $trackId = null): string
    {
        $shard = (string) config('wm-package.shard_name');
        $componentConfig = $this->componentConfig();
        $tagName = $componentConfig['tag_name'];
        $scriptUrl = $componentConfig['script_url'];
        $defaultStyle = $componentConfig['default_style'];
        $trackLine = $trackId !== null ? "\n  track-id=\"{$trackId}\"" : '';

        return <<<HTML
<{$tagName}
  shard="{$shard}"
  app-id="{$layer->app_id}"
  layer-id="{$layer->id}"{$trackLine}
  style="{$defaultStyle}"
></{$tagName}>
<script
  type="module"
  src="{$scriptUrl}"
></script>
HTML;
    }

    /**
     * @param  list<array{label: string, snippet: string}>  $options
     */
    public function renderCopyButton(array $options): string
    {
        $defaultLabelJson = json_encode((string) __('Copy web component code'), self::JSON_FLAGS);
        $successLabelJson = json_encode((string) __('Code copied'), self::JSON_FLAGS);
        $errorLabelJson = json_encode((string) __('Copy error'), self::JSON_FLAGS);

        $onClick = <<<JS
(async function(button) {
  const select = button.parentNode.querySelector('select');
  const text = select ? select.selectedOptions[0].dataset.snippet : button.dataset.snippet;
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

        $escapedOnClick = $this->escape($onClick);
        $buttonLabel = $this->escape((string) __('Copy web component code'));
        $helperHtml = $this->renderHelper();

        $selectHtml = '';
        $buttonSnippetAttribute = '';

        if (count($options) > 1) {
            $optionsHtml = '';
            foreach (array_values($options) as $index => $option) {
                $selected = $index === 0 ? ' selected' : '';
                $optionsHtml .= '<option data-snippet="'.$this->escape($option['snippet']).'"'.$selected.'>'
                    .$this->escape($option['label']).'</option>';
            }
            $selectLabel = $this->escape((string) __('Layer'));
            $selectHtml = <<<HTML
  <select aria-label="{$selectLabel}" class="form-control form-select form-input-bordered" style="display:block;margin-bottom:10px;max-width:480px;">{$optionsHtml}</select>

HTML;
        } elseif (count($options) === 1) {
            $buttonSnippetAttribute = ' data-snippet="'.$this->escape(array_values($options)[0]['snippet']).'"';
        }

        return <<<HTML
<div>
{$selectHtml}  <button type="button"{$buttonSnippetAttribute} onclick="{$escapedOnClick}" style="border:0;border-radius:14px;background:#19b7a1;color:#ffffff;padding:14px 26px;font-size:16px;font-weight:600;cursor:pointer;">
    {$buttonLabel}
  </button>
  <div style="margin-top:10px;font-size:13px;line-height:1.5;color:#6b7280;">
    {$helperHtml}
  </div>
</div>
HTML;
    }

    private function renderHelper(): string
    {
        $helperText = $this->escape((string) __('Test the copied code by pasting it into'));
        $url = 'https://html.onlineviewer.net/';

        return <<<HTML
{$helperText} <a href="{$url}" target="_blank" rel="noopener noreferrer">{$url}</a>
HTML;
    }

    /**
     * @return array{tag_name: string, script_url: string, default_style: string}
     */
    private function componentConfig(): array
    {
        $fallback = config('wm-package.web_components.layer_map.fallback', []);

        return [
            'tag_name' => (string) ($fallback['tag_name'] ?? 'wm-layer-map'),
            'script_url' => (string) ($fallback['script_url'] ?? self::DEFAULT_SCRIPT_URL),
            'default_style' => (string) ($fallback['default_style'] ?? 'display:block;width:100%;height:600px'),
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
