<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Fields\Concerns;

use HTMLPurifier;
use HTMLPurifier_Config;
use Illuminate\Support\Str;

/**
 * Sanitizes a rich-text value with HTMLPurifier and lets it carry embeds (iframes:
 * video, maps, social posts, ...) via a `[[embed:TYPE:BASE64]]` text marker instead of
 * raw markup — extracted from FlexibleTranslatable (found in review: that class mixed
 * this whole embed sub-system with the unrelated "bind a Nova field to a Flexible
 * fake-model" responsibility, making the embed logic impossible to reuse without a
 * copy-paste).
 *
 * Contract for the consuming class — enforced by PHP itself, not just this docblock
 * (found in review: an earlier version relied on `self::CONST` references resolving
 * against two constants the consumer was only DOCUMENTED to define, which PHP checks
 * lazily at first *use*, not when the class is declared — a consumer missing them would
 * compile clean and only blow up on the first real request). Implement:
 * - `defaultRichTextAllowedHtml(): string` — HTMLPurifier's `HTML.Allowed` value.
 * - `safeIframeSrcRegexp(): string` — HTMLPurifier's `URI.SafeIframeRegexp` value.
 * Both are `abstract` below, so PHP itself refuses to declare a concrete, non-abstract
 * consumer class that omits either one — a Fatal Error the moment the class is loaded,
 * not the moment richText() content is first saved.
 *
 * These two are methods, not trait constants: PHP only allows constants inside a trait
 * since 8.2 (`Fatal error: Traits cannot have constants` before that) — wm-package's
 * composer.json requires `"php": ">8.1"`, satisfied by 8.1.x too. A first version of
 * this refactor put them as `const` directly in this trait and was only caught in a
 * second review pass — do not reintroduce trait constants here.
 *
 * The consumer also needs $this->purifier, set via initializeEmbeddableRichText() in
 * its constructor, and — ONLY if it wants the "Insert embed" toolbar button/paste
 * interception from resources/js/nova.js, which this trait does NOT provide on its own —
 * must merge embedToolingAttributes() into the Nova field's `extraAttributes` meta (see
 * FlexibleTranslatable::wireRichTextField() for the reference implementation).
 */
trait HasEmbeddableRichText
{
    protected ?HTMLPurifier $purifier = null;

    abstract protected function defaultRichTextAllowedHtml(): string;

    abstract protected function safeIframeSrcRegexp(): string;

    protected function initializeEmbeddableRichText(?string $allowedHtml = null): void
    {
        $this->purifier = $this->makePurifier($allowedHtml ?? $this->defaultRichTextAllowedHtml());
    }

    /**
     * HTML attributes to merge into a Nova field's `extraAttributes` meta so
     * resources/js/nova.js's "Insert embed" toolbar button/paste interception apply to
     * it — nova.js checks for this exact attribute on the rendered `<trix-editor>`
     * (`EMBED_SUPPORT_ATTR`, kept in sync by tests/Feature/Nova/Fields/FlexibleTranslatableTest.php's
     * assertion against the raw nova.js source). Without it, the field still gets
     * sanitized rich text (makePurifier()/expandEmbedMarkers() work regardless), just no
     * toolbar button.
     */
    protected function embedToolingAttributes(): array
    {
        return ['data-wm-embed-support' => 'true'];
    }

    protected function makePurifier(string $allowedHtml): HTMLPurifier
    {
        $cachePath = storage_path('framework/cache/htmlpurifier');

        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0775, true);
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', $allowedHtml);
        $config->set('Cache.SerializerPath', $cachePath);

        // Harmless to set even when $allowedHtml doesn't include `iframe` at all — the
        // SafeIframe URI filter only ever runs against tokens HTMLPurifier already
        // decided are `iframe` elements, so it's a no-op if that tag isn't whitelisted.
        $config->set('HTML.SafeIframe', true);
        $config->set('URI.SafeIframeRegexp', $this->safeIframeSrcRegexp());

        // HTMLPurifier's own Iframe module only ever defines src/width/height/name/
        // scrolling/frameborder/longdesc/margin{height,width} — `allowfullscreen` is
        // added ONLY under the blanket-unsafe `HTML.Trusted` flag (never enabled here,
        // since that also disables unrelated safety checks project-wide), and `allow`/
        // `referrerpolicy`/`title`/`loading` aren't defined at all, under any flag. Real
        // embed snippets (YouTube's own "Share > Embed" button, Vimeo, Google Maps, ...)
        // carry all of these. Registering them individually — via HTMLPurifier's own
        // documented "add a custom element/attribute" mechanism — keeps that support
        // without a global trust flag; `HTML.Allowed` above still has the final say on
        // whether `iframe` (and which of its attributes) survives at all.
        $config->getHTMLDefinition(true)->addElement(
            'iframe',
            'Inline',
            'Flow',
            'Common',
            [
                'src' => 'URI#embedded',
                'width' => 'Length',
                'height' => 'Length',
                'frameborder' => 'Text',
                'allow' => 'Text',
                'allowfullscreen' => 'Bool#allowfullscreen',
                'referrerpolicy' => 'Text',
                'title' => 'Text',
                'loading' => 'Text',
            ]
        );

        return new HTMLPurifier($config);
    }

    /**
     * Expands `[[embed:url:<base64>]]` / `[[embed:html:<base64>]]` markers — inserted by
     * the "Insert embed" Trix toolbar button or the paste interceptor (wm-package's
     * resources/js/nova.js) — into real markup, run BEFORE HTMLPurifier so the expanded
     * result is still fully subject to makePurifier()'s whitelist/URI checks (the marker
     * grants no extra trust).
     *
     * Plain text via Trix's `editor.insertString()`, not a Trix "attachment": verified
     * live that Trix's `Attachment` (contentType application/vnd.trix-instant-content)
     * serializes its content HTML-escaped inside a `data-trix-attachment` JSON attribute
     * rather than as inline markup — the value this server receives would still have the
     * iframe escaped as text, the exact bug this feature exists to fix. Plain text has no
     * such serialization quirk.
     *
     * `url` markers wrap the decoded value in a default-sized iframe; `html` markers
     * (offered when the pasted/typed input already starts with `<`) use the decoded
     * value verbatim, e.g. a full snippet copied from YouTube's own "Share > Embed" button.
     *
     * The decoded payload is shape-checked before being trusted (`html` must start with
     * `<`, `url` must be an http(s) URL) — found in review: an editor typing/pasting
     * literal text that happens to coincide with the `[[embed:TYPE:BASE64]]` syntax (e.g.
     * documenting this very feature) would otherwise have it silently decoded and
     * substituted at save time, corrupting their text with no error. A shape mismatch (or
     * a decode failure) now leaves the original matched text untouched instead.
     */
    protected function expandEmbedMarkers(string $value): string
    {
        return preg_replace_callback(
            '/\[\[embed:(url|html):([A-Za-z0-9+\/=]+)\]\]/',
            function (array $matches) {
                $decoded = base64_decode($matches[2], true);

                if ($decoded === false || $decoded === '') {
                    return $matches[0];
                }

                if ($matches[1] === 'html') {
                    return Str::startsWith(ltrim($decoded), '<') ? $decoded : $matches[0];
                }

                return preg_match('%^https?://%i', $decoded) === 1
                    ? '<iframe src="'.htmlspecialchars($decoded, ENT_QUOTES).'" width="560" height="315" frameborder="0" allowfullscreen></iframe>'
                    : $matches[0];
            },
            $value
        );
    }

    /**
     * Reverses expandEmbedMarkers() when a stored value is resolved back into the Trix
     * field for editing. Trix's own HTML-to-document parser only understands a fixed set
     * of elements (paragraphs, lists, inline formatting, ...) — when it's asked to load
     * markup containing an element it doesn't recognize, like `<iframe>`, it silently drops
     * that element instead of preserving it (verified live: reopening a saved embed showed
     * only the surrounding text, the iframe gone from the editor). Without this, the very
     * next save — even one triggered by editing an unrelated field on the same form — would
     * purify the now-iframe-less Trix content and overwrite the stored value, permanently
     * deleting the embed. Collapsing every `<iframe>` back into its `[[embed:html:<base64>]]`
     * marker means Trix only ever has to round-trip plain text, which it always preserves
     * exactly; expandEmbedMarkers() re-expands it identically on the next save.
     */
    protected function collapseEmbedIframes(string $html): string
    {
        return preg_replace_callback(
            '/<iframe\b[^>]*>.*?<\/iframe>/is',
            fn (array $matches) => '[[embed:html:'.base64_encode($matches[0]).']]',
            $html
        );
    }
}
