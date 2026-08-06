<?php

declare(strict_types=1);

namespace Wm\WmPackage\Nova\Fields;

use Illuminate\Support\Str;
use Kongulov\NovaTabTranslatable\NovaTabTranslatable;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Image;
use RuntimeException;
use Wm\WmPackage\Nova\Fields\Concerns\HasEmbeddableRichText;

/**
 * Drop-in replacement for kongulov/nova-tab-translatable usable inside
 * whitecube/nova-flexible-content Layouts and Nova Repeater rows — neither
 * is a real Eloquent Model with Spatie HasTranslations, which is what
 * NovaTabTranslatable::createTranslatedField() assumes by default.
 *
 * Synced with kongulov/nova-tab-translatable v2.1.7 — re-check
 * createTranslatedField() on every vendor bump (composer.json pins ^2.1).
 *
 * Two storage modes, chosen via the named constructors:
 * - simple(): one JSON attribute nested by locale, e.g. `title: {it:.., en:..}`.
 * - richText(): N flat attributes, one per locale, e.g. `content_it`, `content_en`,
 *   with HTMLPurifier sanitization (single shared instance across locales) — the
 *   sanitizer/embed-marker mechanics live in HasEmbeddableRichText, not here (found
 *   mixed together in review — kept in this class before, split out since it's a
 *   concern any other rich-text Nova Field could reuse). The two whitelist constants
 *   below stay HERE, not on the trait — see HasEmbeddableRichText's docblock for why
 *   (PHP <8.2 can't have constants in a trait; wm-package still supports 8.1.x).
 */
class FlexibleTranslatable extends NovaTabTranslatable
{
    use HasEmbeddableRichText;

    /**
     * Default HTMLPurifier whitelist for richText() mode. Includes `iframe` for generic
     * embeds (video, maps, social posts, ...) — safe only because HasEmbeddableRichText's
     * makePurifier() also enables HTMLPurifier's `HTML.SafeIframe` + `URI.SafeIframeRegexp`
     * (still requires http(s), see SAFE_IFRAME_SRC_REGEXP) and registers the extra
     * attributes real embed snippets carry (allow, allowfullscreen, referrerpolicy, title,
     * loading — see makePurifier()). HTMLPurifier has no safe default definition for
     * `iframe` at all otherwise — allowing the tag here without those safeguards would let
     * an editor embed a page that can run script in the parent context, a real
     * XSS/clickjacking risk.
     */
    public const DEFAULT_RICH_TEXT_ALLOWED_HTML = 'p,br,b,strong,i,em,u,ul,ol,li,h2,h3,h4,blockquote,a[href],img[src|alt|width|height|title],iframe[src|width|height|frameborder|allow|allowfullscreen|referrerpolicy|title|loading]';

    /**
     * `src` allowlist for richText() mode's embedded iframes, passed to HTMLPurifier's
     * `URI.SafeIframeRegexp`. Deliberately unrestricted by provider/domain (dev decision:
     * editors are trusted Nova admins/managers, not the public — the risk accepted here is
     * an editor embedding an iframe from a source they didn't fully vet, not an anonymous
     * attacker). Still requires http(s) — HTMLPurifier's default `URI.AllowedSchemes`
     * (independent of this regexp) already rejects `javascript:`/`data:` src values, so an
     * iframe can point anywhere on the web but never execute a script/URI payload.
     */
    public const SAFE_IFRAME_SRC_REGEXP = '%^https?://%';

    protected bool $richText = false;

    /**
     * Implements HasEmbeddableRichText's abstract contract — see that trait's docblock
     * for why these are methods bridging to a class constant, not a trait constant.
     */
    protected function defaultRichTextAllowedHtml(): string
    {
        return self::DEFAULT_RICH_TEXT_ALLOWED_HTML;
    }

    protected function safeIframeSrcRegexp(): string
    {
        return self::SAFE_IFRAME_SRC_REGEXP;
    }

    public function __construct(
        array $fields = [],
        array $locales = [],
        bool $richText = false,
        string $allowedHtml = self::DEFAULT_RICH_TEXT_ALLOWED_HTML
    ) {
        $this->richText = $richText;

        if ($richText) {
            $this->initializeEmbeddableRichText($allowedHtml);
        }

        // Capture the first original field's own attribute BEFORE parent::__construct()
        // runs — createTranslatedField() clones $fields' field objects into $this->data,
        // so the objects in $fields itself are never mutated by the parent constructor.
        $originalAttribute = $fields[0]->attribute ?? null;

        parent::__construct($fields, $locales);

        if ($originalAttribute !== null) {
            // NovaTabTranslatable's constructor leaves $this->attribute at its default
            // ('tab_translatable', derived from $this->name = 'Tab translatable' set in
            // Field's own constructor) — setTitle() only ever changes the display name,
            // never the attribute. Two FlexibleTranslatable instances used as sibling
            // fields of the SAME Repeater row (e.g. one for `title`, one for `content`)
            // would otherwise collide on that shared default attribute, both in the
            // scoped request path Nova's Repeater JSON preset computes
            // ("{$requestAttribute}.{$itemIndex}.fields.{$field->attribute}") and in
            // Nova's frontend uniqueKey/fieldRefs. Distinguishing by the wrapped field's
            // own attribute keeps each instance unique without requiring callers to pass
            // anything extra.
            //
            // simple() storage is a single JSON-ish attribute merged by locale (the
            // wireSimpleField()/normalizeStoredValue() "old KeyValue shape") — the bare
            // original attribute ('title') already uniquely identifies it and IS the
            // exact key the Repeater's JSON preset (and ConfigDetailResolver's
            // buildInfoElement()/hydrateInfoAttributes(), which read/write that same
            // {fields: {title: {...}}} shape) already use.
            //
            // richText() storage has no single "whole field" attribute — it's N flat
            // attributes, one per locale (content_it, content_en, ...) — so there is no
            // bare key to reuse. Falling back to the first configured locale's own flat
            // attribute keeps the outer field's identity inside that same flat-attribute
            // family (still unique against a sibling simple() field's bare attribute) and,
            // as a side effect, makes the outer field directly discoverable by that flat
            // attribute wherever code (or a test) looks up the row's top-level fields by
            // the on-disk attribute name rather than drilling into sub-fields.
            $this->attribute = $richText
                ? $originalAttribute.'_'.($this->getLocales()[0] ?? '')
                : $originalAttribute;
        }
    }

    /**
     * NovaTabTranslatable::fillInto() ignores $attribute/$requestAttribute entirely and
     * just does `foreach ($this->data as $field) { $field->fill($request, $model); }` —
     * each sub-field then defaults its own request lookup to its bare ->attribute
     * (Field::fill()/fillInto()). That is correct when used directly on a Whitecube
     * Layout: Layout::fill() passes a ScopedRequest that has already rewritten the
     * whole request input to the group-scoped, flattened values, so an unscoped lookup
     * by the sub-field's own bare attribute still finds the right value.
     *
     * Inside a Nova Repeater row, the default JSON preset does NOT rewrite the request —
     * it expects each field to use the explicit scoped path it computes and passes as the
     * 4th argument ("{$requestAttribute}.{$itemIndex}.fields.{$field->attribute}"). Because
     * the parent's fillInto() throws that path away, the fill was silently a no-op there.
     */
    public function fillInto($request, $model, $attribute, $requestAttribute = null): void
    {
        if ($requestAttribute === null) {
            // Layout-direct path — parent's existing loop already works correctly.
            parent::fillInto($request, $model, $attribute, $requestAttribute);

            return;
        }

        if ($this->richText) {
            $this->fillRichTextInto($request, $model, $requestAttribute);
        } else {
            $this->fillSimpleInto($request, $model, $requestAttribute);
        }
    }

    /**
     * simple() mode: the field's actual Vue component (kongulov/nova-tab-translatable's
     * FormField.vue) submits EACH locale sub-field independently under its OWN flat
     * attribute ("translations_{attr}_{locale}") — there is no single aggregated key in
     * the real payload. Mirrors fillRichTextInto(): strip the trailing
     * ".{$this->attribute}" from $requestAttribute to get the row-scoped prefix, then
     * delegate to each sub-field's own fillInto() at that sub-field's own scoped path.
     * Named to mirror wireSimpleField() (found asymmetric in review — that method has a
     * dedicated name per mode, this fillInto() branch didn't).
     */
    private function fillSimpleInto($request, $model, string $requestAttribute): void
    {
        $prefix = $this->stripOwnAttributeSuffix($requestAttribute);

        $touched = false;
        foreach ($this->data as $field) {
            $path = "{$prefix}.{$field->attribute}";
            if ($request->exists($path)) {
                $field->fillInto($request, $model, $field->attribute, $path);
                $touched = true;
            }
        }

        if (! $touched) {
            // Legacy aggregated KeyValue shape (old test fixtures, possibly old stored
            // requests) — the whole locale map submitted as one value at our own scoped
            // path, e.g. {"it":"Storia"} at "items.0.fields.title". Only reached when none
            // of the per-locale flat keys above are present in the request.
            $value = $request->input($requestAttribute);

            if ($value !== null) {
                $model->{$this->attribute} = $this->normalizeStoredValue($value);
            }
        }
    }

    /**
     * richText() mode — Nova computed $requestAttribute using OUR OWN outer ->attribute,
     * itself the first locale's flat attribute (e.g. "items.0.fields.content_it"). Strip
     * the trailing ".{$this->attribute}" to get the row-scoped prefix
     * ("items.0.fields"), then rebuild the scoped path for each sub-field using THAT
     * sub-field's own flat attribute — matching the flat per-locale keys ("content_it",
     * "content_en", ...) this storage mode actually submits. Named to mirror
     * wireRichTextField() (see fillSimpleInto()'s docblock).
     */
    private function fillRichTextInto($request, $model, string $requestAttribute): void
    {
        $prefix = $this->stripOwnAttributeSuffix($requestAttribute);

        foreach ($this->data as $field) {
            $field->fillInto($request, $model, $field->attribute, "{$prefix}.{$field->attribute}");
        }
    }

    /**
     * Shared by both fillInto() branches (found duplicated in review): strips the trailing
     * ".{$this->attribute}" Nova appended when computing $requestAttribute from OUR OWN
     * outer attribute, leaving the row-scoped prefix each sub-field's own path gets rebuilt
     * from. Falls back to $requestAttribute unchanged if the suffix isn't there (defensive
     * — not expected in practice, matches both branches' prior inline behavior).
     */
    private function stripOwnAttributeSuffix(string $requestAttribute): string
    {
        $suffix = '.'.$this->attribute;

        return Str::endsWith($requestAttribute, $suffix)
            ? substr($requestAttribute, 0, -strlen($suffix))
            : $requestAttribute;
    }

    /**
     * NovaTabTranslatable::resolve() only resolves the per-locale sub-fields' own
     * ->value (correct for driving the tabbed form/detail UI) but never sets the OUTER
     * field's own ->value. That is invisible when only the sub-fields are inspected
     * directly (e.g. FlexibleTranslatableTest.php), but a Nova Repeater row's
     * jsonSerialize() exposes the ROW's top-level configured fields — i.e. this outer
     * field itself — so its own ->value needs to reflect the on-disk shape too.
     */
    public function resolve($resource, $attribute = null): void
    {
        parent::resolve($resource, $attribute);

        $this->value = $this->richText
            ? $this->resolveRichTextValue($resource)
            : $this->resolveSimpleValue($resource);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveSimpleValue($resource): array
    {
        return array_merge(
            array_fill_keys($this->getLocales(), ''),
            $this->normalizeStoredValue(data_get($resource, $this->attribute))
        );
    }

    private function resolveRichTextValue($resource): mixed
    {
        $stored = data_get($resource, $this->attribute, '');

        // Same collapse the sub-fields' own resolveUsing() applies (see
        // wireRichTextField()) — found in review: without it, this outer value could
        // resurface a raw <iframe> if anything ever reads it directly instead of
        // drilling into the sub-fields, reintroducing the "Trix silently drops it on
        // reload" bug for that path.
        return is_string($stored) ? $this->collapseEmbedMedia($stored) : $stored;
    }

    public static function simple(string $label, array $fields, array $locales = []): static
    {
        return (new static($fields, $locales, false))->setTitle($label);
    }

    public static function richText(
        string $label,
        array $fields,
        array $locales = [],
        string $allowedHtml = self::DEFAULT_RICH_TEXT_ALLOWED_HTML
    ): static {
        return (new static($fields, $locales, true, $allowedHtml))->setTitle($label);
    }

    protected function createTranslatedField(Field $originalField, string $locale): Field
    {
        if ($originalField instanceof Image || $originalField instanceof File) {
            throw new RuntimeException(
                static::class.' does not support Image/File fields inside Flexible layouts (out of scope, see docs/features/8349-translatable-field-flexible/overview.md).'
            );
        }

        $translatedField = clone $originalField;
        $originalAttribute = $translatedField->attribute;

        $translatedField->withMeta([
            'defaultValue' => $translatedField->defaultCallback,
            'locale' => $locale,
            'showOnIndex' => $translatedField->showOnIndex,
            'showOnDetail' => $translatedField->showOnDetail,
            'showOnCreation' => $translatedField->showOnCreation,
            'showOnUpdate' => $translatedField->showOnUpdate,
            'onlyOnDetail' => $translatedField->onlyOnDetail,
        ]);

        // Both inherited from the parent unmodified (found missing in review — no current
        // consumer uses ->rules('required_lang:...')/SluggableText/Slug with this field, so
        // this only adds capability, no behavior change today): setRules() translates the
        // vendor's pseudo-rules (required_lang, required_with, unique) into a real
        // per-locale Laravel rule; compatibilityWithOtherPlugins() lets a future consumer
        // pair this field with SluggableText/Slug the same way NovaTabTranslatable already
        // supports outside Flexible.
        $translatedField = $this->setRules($translatedField);

        $locales = $this->getLocales();
        $translatedField->name = (count($locales) > 1)
            ? ($this->displayLocalizedNameUsingCallback)($translatedField, $locale)
            : $translatedField->name;

        $translatedField->panel = $this->panel;

        if ($this->richText) {
            $this->wireRichTextField($translatedField, $originalAttribute, $locale);
        } else {
            $this->wireSimpleField($translatedField, $originalAttribute, $locale);
        }

        $translatedField = $this->compatibilityWithOtherPlugins($translatedField);

        return $translatedField;
    }

    /**
     * Get locales using Reflection (parent class has $locales as private).
     * @return array<string>
     */
    private function getLocales(): array
    {
        $reflection = new \ReflectionProperty(NovaTabTranslatable::class, 'locales');
        $reflection->setAccessible(true);

        return $reflection->getValue($this);
    }

    protected function wireSimpleField(Field $translatedField, string $originalAttribute, string $locale): void
    {
        $translatedField->attribute = 'translations_'.$originalAttribute.'_'.$locale;

        $translatedField->resolveUsing(function ($value, $model) use ($originalAttribute, $locale) {
            $stored = $this->normalizeStoredValue(data_get($model, $originalAttribute));

            return $stored[$locale] ?? '';
        });

        $translatedField->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($originalAttribute, $locale) {
            $inputValue = $request->input($requestAttribute);

            // Only set the locale if the value is not null
            if ($inputValue !== null) {
                $current = $this->normalizeStoredValue($model->{$originalAttribute} ?? null);
                $current[$locale] = $inputValue;

                $model->{$originalAttribute} = $current;
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeStoredValue(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * The wire-format attribute name a richText() field uses for one locale — a flat
     * "{attribute}_{locale}" key inside the Repeater block's `fields`. Exposed as a public
     * contract (not just an internal implementation detail of wireRichTextField()) so
     * consumers that read/write this shape directly — e.g. ConfigDetailResolver, which
     * reshapes it into a persisted {attribute: {locale: value}} object — don't have to
     * hardcode and independently keep in sync the same naming convention.
     */
    public static function richTextAttributeFor(string $originalAttribute, string $locale): string
    {
        return "{$originalAttribute}_{$locale}";
    }

    protected function wireRichTextField(Field $translatedField, string $originalAttribute, string $locale): void
    {
        $flatAttribute = self::richTextAttributeFor($originalAttribute, $locale);
        $translatedField->attribute = $flatAttribute;

        // Marks the rendered <trix-editor> so resources/js/nova.js can scope the "Insert
        // embed" button/paste handling to fields wired through THIS method — found in
        // review: without this marker, that JS was registered globally on every
        // <trix-editor> on the page, including any plain Trix field elsewhere in this
        // package/app that never goes through expandEmbedMarkers()/HTMLPurifier, so an
        // editor pasting an iframe there would have gotten the raw `[[embed:...]]` marker
        // saved literally, un-expanded. `extraAttributes` is the same generic Nova Field
        // meta key Text/Email/Date/etc. already use to add arbitrary HTML attributes
        // (Trix.vue's extraAttributes computed forwards it via v-bind onto <trix-editor>).
        // embedToolingAttributes() (HasEmbeddableRichText) is the single PHP-side source
        // for the attribute name/value — kept out of a trait constant for the same
        // PHP<8.2 reason as the two whitelist constants above.
        $translatedField->withMeta([
            'extraAttributes' => array_merge(
                $translatedField->meta['extraAttributes'] ?? [],
                $this->embedToolingAttributes()
            ),
        ]);

        $translatedField->resolveUsing(function ($value, $model) use ($flatAttribute) {
            $stored = data_get($model, $flatAttribute, '');

            return is_string($stored) ? $this->collapseEmbedMedia($stored) : $stored;
        });

        $translatedField->fillUsing(function ($request, $model, $attribute, $requestAttribute) use ($flatAttribute) {
            $value = $request->input($requestAttribute);

            // Same guard as wireSimpleField(): a locale's tab not rendered/touched in the
            // real Vue component can be entirely absent from the request, and without this
            // check that null would overwrite already-saved content for that locale (found
            // in review, reproduced with a request missing every locale key).
            if ($value === null) {
                return;
            }

            if (is_string($value) && $value !== '') {
                $value = $this->expandEmbedMarkers($value);
            }

            $model->{$flatAttribute} = (is_string($value) && $value !== '')
                ? $this->purifier->purify($value)
                : $value;
        });
    }
}
