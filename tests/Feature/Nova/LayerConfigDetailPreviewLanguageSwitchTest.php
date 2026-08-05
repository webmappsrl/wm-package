<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Tests\TestCase;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\Layer;
use Wm\WmPackage\Nova\Layer as LayerResource;

/**
 * Regression coverage for HasConfigDetailPanel::renderConfigDetailItem() (found missing
 * in review: the language-switch-visible-before-opening-the-accordion behavior had no
 * automated test, only manual live verification) — asserts the actual generated HTML
 * shape (radio inputs + tab bar as SIBLINGS of, and BEFORE, the collapsible <details>,
 * not nested inside its body) rather than re-verifying it live every time.
 */
uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Queue::fake();
    Http::fake();
});

function layerConfigDetailPreviewHtml(Layer $model): string
{
    $request = NovaRequest::create('/');
    $resource = new LayerResource($model);

    foreach ($resource->fields($request) as $item) {
        $fields = $item instanceof Panel ? collect($item->data) : collect([$item]);
        $found = $fields->first(fn ($f) => $f instanceof Text && $f->name === 'Detail Blocks Preview');
        if ($found) {
            $found->resolve($model);

            return (string) $found->value;
        }
    }

    throw new RuntimeException('Detail Blocks Preview field not found on Layer resource');
}

it('renders the language tab bar as a sibling of, and BEFORE, the collapsible details — not inside its body', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => [
            'config_detail' => [
                ['box_type' => 'info', 'items' => [[
                    'title' => ['it' => 'Storia', 'en' => 'History'],
                    'content' => ['it' => '<p>Testo storia</p>', 'en' => '<p>History text</p>'],
                ]]],
            ],
        ],
    ]);

    $html = layerConfigDetailPreviewHtml($layer);

    $tabbarPosition = strpos($html, 'class="wm-cd-tabbar"');
    $detailsPosition = strpos($html, '<details class="wm-cd-details"');

    expect($tabbarPosition)->not->toBeFalse();
    expect($detailsPosition)->not->toBeFalse();
    expect($tabbarPosition)->toBeLessThan($detailsPosition);

    // One radio + one tab label per configured locale, all sharing the same group name
    // (radio "name") so only one can be checked at a time.
    $locales = config('wm-tab-translatable.locales', []);
    foreach ($locales as $locale) {
        expect($html)->toContain('data-locale-radio="'.$locale.'"');
        expect($html)->toContain('>'.strtoupper($locale).'</label>');
    }

    // The <summary> (visible even with the accordion collapsed) carries one span per
    // locale with the item's own title text for that locale — this is what lets the
    // title change language before the item is ever opened.
    expect($html)->toContain('<span class="wm-cd-summary-locale" data-locale="it">Storia</span>');
    expect($html)->toContain('<span class="wm-cd-summary-locale" data-locale="en">History</span>');

    // The body content per locale still exists (drives the accordion once opened).
    expect($html)->toContain('<div class="wm-cd-content" data-locale="it"><p>Testo storia</p></div>');
    expect($html)->toContain('<div class="wm-cd-content" data-locale="en"><p>History text</p></div>');
});

it('checks the radio for the first locale with actual content, following the app-locale -> it -> en -> first-non-empty fallback', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly([
        'properties' => [
            'config_detail' => [
                // No 'it'/app-locale content at all — only 'fr' is filled, so the
                // fallback must skip straight to the "first non-empty locale" branch.
                ['box_type' => 'info', 'items' => [[
                    'title' => ['fr' => 'Histoire'],
                    'content' => ['fr' => '<p>Texte</p>'],
                ]]],
            ],
        ],
    ]);

    $html = layerConfigDetailPreviewHtml($layer);

    expect($html)->toContain('data-locale-radio="fr" checked');
    expect($html)->not->toContain('data-locale-radio="it" checked');
    expect($html)->not->toContain('data-locale-radio="en" checked');
});

it('shows the empty-state message when no info box is configured', function () {
    App::factory()->createQuietly();
    $layer = Layer::factory()->createQuietly(['properties' => ['config_detail' => []]]);

    $html = layerConfigDetailPreviewHtml($layer);

    expect($html)->toContain('No blocks configured.');
    expect($html)->not->toContain('wm-cd-tabbar');
});
