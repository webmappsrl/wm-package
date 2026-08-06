<?php

declare(strict_types=1);

use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Support\Fluent;
use Tests\TestCase;
use Whitecube\NovaFlexibleContent\Layouts\Layout;
use Wm\WmPackage\Nova\Fields\FlexibleTranslatable;

uses(TestCase::class);

function flexibleTranslatableRequest(string $attribute, ?string $value)
{
    return \Laravel\Nova\Http\Requests\NovaRequest::create('/', 'PUT', [$attribute => $value]);
}

/**
 * Field::fill() returns a callback to invoke (or nothing) rather than mutating $model
 * directly — every test needs this same two-step dance (found repeated ~10x in review).
 */
function fillField($field, $request, $model): void
{
    $callback = $field->fill($request, $model);
    if (is_callable($callback)) {
        $callback();
    }
}

it('round-trips a simple field through a real Whitecube Layout, matching the old KeyValue shape', function () {
    $locales = ['it', 'en', 'fr', 'es', 'de'];
    $field = FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')], $locales);
    $layout = new Layout('Titolo', 'title', [$field]);

    $values = ['it' => 'Ciao', 'en' => 'Hello'];
    foreach ($field->data as $subField) {
        $locale = $subField->meta['locale'];
        fillField($subField, flexibleTranslatableRequest($subField->attribute, $values[$locale] ?? null), $layout);
    }

    expect($layout->getAttributes()['title'])->toBe(['it' => 'Ciao', 'en' => 'Hello']);

    foreach ($field->data as $subField) {
        $subField->resolve($layout);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);

    expect($resolvedByLocale['it'])->toBe('Ciao');
    expect($resolvedByLocale['en'])->toBe('Hello');
    expect($resolvedByLocale['fr'])->toBe('');
});

it('round-trips a simple field through the Fluent(fill)+raw-array(resolve) shapes used inside a Repeater row', function () {
    $locales = ['it', 'en', 'fr', 'es', 'de'];
    $field = FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')], $locales);

    $model = new Fluent;
    foreach ($field->data as $subField) {
        $locale = $subField->meta['locale'];
        $value = $locale === 'it' ? 'Storia' : null;
        fillField($subField, flexibleTranslatableRequest($subField->attribute, $value), $model);
    }

    expect($model->title)->toBe(['it' => 'Storia']);

    $stored = ['title' => ['it' => 'Storia']];
    foreach ($field->data as $subField) {
        $subField->resolve($stored);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);

    expect($resolvedByLocale['it'])->toBe('Storia');
    expect($resolvedByLocale['en'])->toBe('');
});

it('decodes a legacy JSON-string stored value on resolve (backward compatibility with old KeyValue records)', function () {
    $locales = ['it', 'en'];
    $field = FlexibleTranslatable::simple('Title', [Text::make('Title', 'title')], $locales);

    // Records saved by the old HasFlexibleTranslatableFields/KeyValue mechanism may have
    // the raw value stored as a JSON string rather than a decoded array.
    $stored = ['title' => json_encode(['it' => 'Vecchio dato'])];
    foreach ($field->data as $subField) {
        $subField->resolve($stored);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);

    expect($resolvedByLocale['it'])->toBe('Vecchio dato');
});

it('sanitizes rich text per locale, matching the old Trix+HTMLPurifier mechanism', function () {
    $locales = ['it', 'en'];
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], $locales);

    $payloads = [
        'it' => '<p onclick="alert(1)">Testo <script>alert(2)</script><b>sicuro</b></p>',
        'en' => '<p>Safe text</p>',
    ];

    $model = new Fluent;
    foreach ($field->data as $subField) {
        $locale = $subField->meta['locale'];
        fillField($subField, flexibleTranslatableRequest($subField->attribute, $payloads[$locale]), $model);
    }

    expect($model->content_it)->toContain('<b>sicuro</b>');
    expect($model->content_it)->not->toContain('onclick');
    expect($model->content_it)->not->toContain('<script>');
    expect($model->content_en)->toBe('<p>Safe text</p>');

    $stored = ['content_it' => $model->content_it, 'content_en' => $model->content_en];
    foreach ($field->data as $subField) {
        $subField->resolve($stored);
    }

    $resolvedByLocale = collect($field->data)->mapWithKeys(fn ($f) => [$f->meta['locale'] => $f->value]);
    expect($resolvedByLocale['it'])->toBe($model->content_it);
    expect($resolvedByLocale['en'])->toBe('<p>Safe text</p>');
});

it('does not wipe already-saved rich text when a locale tab is absent from the request (mirrors the wireSimpleField guard)', function () {
    // A locale's tab not rendered/touched by the real Vue component can be entirely
    // absent from the submitted request — found in review: without a null guard
    // mirroring wireSimpleField()'s, this silently overwrote already-saved content
    // for EVERY locale with null.
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it', 'en']);

    $model = new Fluent;
    $model->content_it = '<p>Testo italiano già salvato</p>';
    $model->content_en = '<p>English text already saved</p>';

    $emptyRequest = \Laravel\Nova\Http\Requests\NovaRequest::create('/', 'PUT', []);

    foreach ($field->data as $subField) {
        fillField($subField, $emptyRequest, $model);
    }

    expect($model->content_it)->toBe('<p>Testo italiano già salvato</p>');
    expect($model->content_en)->toBe('<p>English text already saved</p>');
});

it('keeps an embed iframe from any http(s) source, including its real-world attributes', function () {
    // The exact snippet YouTube's own "Share > Embed" button generates.
    $html = '<p>Guarda</p><iframe width="560" height="315" src="https://www.youtube.com/embed/jcs2X6tS8NU?si=XP6ekt3QitxNmqqF" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe>';

    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $html), $model);

    expect($model->content_it)->toContain('<iframe')
        ->toContain('src="https://www.youtube.com/embed/jcs2X6tS8NU?si=XP6ekt3QitxNmqqF"')
        ->toContain('allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"')
        ->toContain('allowfullscreen')
        ->toContain('referrerpolicy="strict-origin-when-cross-origin"')
        ->toContain('title="YouTube video player"');

    // Any http(s) source is accepted (dev decision: editors are trusted Nova
    // admins/managers, not the public) — a random non-video-provider domain also
    // survives, unlike a strict provider whitelist would allow.
    $field2 = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model2 = new Fluent;
    $sub2 = $field2->data[0];
    fillField($sub2, flexibleTranslatableRequest($sub2->attribute, '<iframe src="https://maps.google.com/maps?output=embed"></iframe>'), $model2);
    expect($model2->content_it)->toContain('src="https://maps.google.com/maps?output=embed"');
});

it('expands the [[embed:url:...]] marker inserted by the toolbar button into a real sanitized iframe', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];

    $marker = '[[embed:url:'.base64_encode('https://www.youtube.com/embed/dQw4w9WgXcQ').']]';
    $html = '<p>Guarda:</p>'.$marker;

    fillField($subField, flexibleTranslatableRequest($subField->attribute, $html), $model);

    expect($model->content_it)->toContain('<p>Guarda:</p>')
        ->toContain('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"')
        ->not->toContain('[[embed:');
});

it('expands the [[embed:html:...]] marker verbatim, e.g. a full snippet pasted from a provider', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];

    $snippet = '<iframe src="https://player.vimeo.com/video/76979871" width="640" height="360" allow="autoplay"></iframe>';
    $marker = '[[embed:html:'.base64_encode($snippet).']]';

    fillField($subField, flexibleTranslatableRequest($subField->attribute, $marker), $model);

    expect($model->content_it)->toContain('src="https://player.vimeo.com/video/76979871"')
        ->not->toContain('[[embed:');
});

it('leaves literal text alone when it merely coincides with the marker syntax, instead of silently decoding/substituting it', function () {
    // Found in review: an editor typing/pasting text that happens to match
    // [[embed:TYPE:BASE64]] (e.g. documenting this very feature) would otherwise have it
    // silently decoded and substituted at save time with no error — a shape check on the
    // decoded payload (html must start with "<", url must be an http(s) URL) closes that.
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $subField = $field->data[0];

    $lookalikeHtml = 'See the marker syntax: [[embed:html:'.base64_encode('just some plain words, not markup').']]';
    $model = new Fluent;
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $lookalikeHtml), $model);
    expect($model->content_it)->toBe($lookalikeHtml);

    $lookalikeUrl = '[[embed:url:'.base64_encode('not a url at all').']]';
    $model2 = new Fluent;
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $lookalikeUrl), $model2);
    expect($model2->content_it)->toBe($lookalikeUrl);
});

it('collapses a stored iframe back into an [[embed:html:...]] marker on resolve, so Trix can round-trip it without dropping it', function () {
    // Trix's HTML parser silently drops elements it doesn't recognize (like <iframe>)
    // when loading a field's initial value — without collapsing back to a marker, the
    // very next save (even from an unrelated field on the same form) would purify the
    // now-iframe-less Trix content and permanently delete the embed from storage.
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $subField = $field->data[0];

    // HTMLPurifier normalizes the bare `allowfullscreen` boolean attribute to
    // `allowfullscreen="allowfullscreen"` — this is the shape real stored content has
    // (verified against an actual DB row), not the shorthand a hand-typed snippet would use.
    $storedIframe = '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" frameborder="0" allowfullscreen="allowfullscreen"></iframe>';
    $stored = ['content_it' => 'Guarda: '.$storedIframe];

    $subField->resolve($stored);

    expect($subField->value)->toBe('Guarda: [[embed:html:'.base64_encode($storedIframe).']]')
        ->not->toContain('<iframe');

    // Re-saving that resolved (marker) value must reproduce the exact original iframe —
    // proving the round-trip is lossless and idempotent across repeated open/save cycles.
    $model = new Fluent;
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $subField->value), $model);

    expect($model->content_it)->toBe('Guarda: '.$storedIframe);
});

it('still blocks dangerous iframe src schemes (javascript:/data:) despite the open domain policy', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];
    $html = '<iframe src="javascript:alert(1)"></iframe><p>testo</p>';
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $html), $model);

    expect($model->content_it)->not->toContain('javascript:')
        ->toContain('<p>testo</p>');
});

it('keeps an http(s) image from an external URL, including common attributes', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];
    $html = '<p>Foto</p><img src="https://example.com/path/photo.png" alt="Panorama" width="800" height="600">';
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $html), $model);

    expect($model->content_it)->toContain('<img')
        ->toContain('src="https://example.com/path/photo.png"')
        ->toContain('alt="Panorama"');
});

it('expands an [[embed:html:...]] marker containing an img snippet verbatim', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];
    $snippet = '<img src="https://cdn.example.com/logo.png" alt="Logo">';
    $marker = '[[embed:html:'.base64_encode($snippet).']]';
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $marker), $model);

    expect($model->content_it)->toContain('src="https://cdn.example.com/logo.png"')
        ->not->toContain('[[embed:');
});

it('still blocks dangerous img src schemes (javascript:/data:)', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $model = new Fluent;
    $subField = $field->data[0];
    $html = '<img src="javascript:alert(1)"><img src="data:image/svg+xml;base64,PHN2Zy8+"><p>testo</p>';
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $html), $model);

    expect($model->content_it)->not->toContain('javascript:')
        ->not->toContain('data:image')
        ->toContain('<p>testo</p>');
});

it('collapses a stored img back into an [[embed:html:...]] marker on resolve, so Trix can round-trip it without dropping it', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $subField = $field->data[0];
    $storedImg = '<img src="https://example.com/photo.jpg" alt="Vista" width="640" height="480">';
    $stored = ['content_it' => 'Foto: '.$storedImg];
    $subField->resolve($stored);

    expect($subField->value)->toBe('Foto: [[embed:html:'.base64_encode($storedImg).']]')
        ->not->toContain('<img');

    $model = new Fluent;
    fillField($subField, flexibleTranslatableRequest($subField->attribute, $subField->value), $model);

    expect($model->content_it)->toContain('Foto:')
        ->toContain('src="https://example.com/photo.jpg"')
        ->toContain('alt="Vista"')
        ->not->toContain('[[embed:');
});

it('leaves an empty rich text value empty instead of purifying an empty string', function () {
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);

    $model = new Fluent;
    foreach ($field->data as $subField) {
        fillField($subField, flexibleTranslatableRequest($subField->attribute, ''), $model);
    }

    expect($model->content_it)->toBe('');
});

it('rejects Image/File fields inside a Flexible-compatible translatable field', function () {
    expect(fn () => FlexibleTranslatable::simple('Icon', [Image::make('Icon', 'icon')], ['it']))
        ->toThrow(RuntimeException::class);
});

it('keeps the embed-tooling attribute in sync between PHP (embedToolingAttributes()) and resources/js/nova.js', function () {
    // Found in review: the attribute names that scope Embed/Image toolbar
    // buttons/paste interception to this field are duplicated as literals on both sides
    // (PHP sets them via extraAttributes, nova.js checks via EMBED_SUPPORT_ATTR /
    // IMAGE_SUPPORT_ATTR) — nothing previously caught a rename on one side drifting
    // silently from the other.
    $field = FlexibleTranslatable::richText('Content', [Trix::make('Content', 'content')], ['it']);
    $phpAttributes = $field->data[0]->meta['extraAttributes'];

    expect($phpAttributes)->toHaveKey('data-wm-embed-support', 'true');
    expect($phpAttributes)->toHaveKey('data-wm-image-support', 'true');
    expect($phpAttributes)->toHaveCount(2);

    $novaJs = file_get_contents(__DIR__.'/../../../../resources/js/nova.js');

    expect($novaJs)->toContain("EMBED_SUPPORT_ATTR = 'data-wm-embed-support'");
    expect($novaJs)->toContain("IMAGE_SUPPORT_ATTR = 'data-wm-image-support'");
    expect($novaJs)->toContain('data-wm-image-button');
    expect($novaJs)->toContain('data-trix-button-group="file-tools"');
});

it('enables only Image tooling when the whitelist has img but no iframe', function () {
    $field = FlexibleTranslatable::richText(
        'Content',
        [Trix::make('Content', 'content')],
        ['it'],
        'p,br,img[src|alt]'
    );
    $phpAttributes = $field->data[0]->meta['extraAttributes'];

    expect($phpAttributes)->toHaveKey('data-wm-image-support', 'true');
    expect($phpAttributes)->not->toHaveKey('data-wm-embed-support');
    expect($phpAttributes)->toHaveCount(1);
});
