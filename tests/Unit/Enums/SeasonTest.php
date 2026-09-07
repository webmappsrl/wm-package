<?php

namespace Wm\WmPackage\Tests\Unit\Enums;

use Wm\WmPackage\Enums\Season;
use Wm\WmPackage\Tests\TestCase;

class SeasonTest extends TestCase
{
    public function test_case_values_are_stable_lowercase_identifiers(): void
    {
        $this->assertSame('spring', Season::SPRING->value);
        $this->assertSame('summer', Season::SUMMER->value);
        $this->assertSame('autumn', Season::AUTUMN->value);
        $this->assertSame('winter', Season::WINTER->value);
    }

    public function test_to_array_returns_value_label_map_for_select_options(): void
    {
        $options = Season::toArray();

        $this->assertSame(['spring', 'summer', 'autumn', 'winter'], array_keys($options));
        foreach ($options as $value => $label) {
            $this->assertIsString($label);
            $this->assertNotSame('', $label, "Label vuota per il valore {$value}");
        }
    }

    public function test_try_from_returns_null_on_unknown_legacy_value(): void
    {
        $this->assertNull(Season::tryFrom('primavera'));
    }

    /**
     * labelIn() serve al consumer per comporre la mappa delle traduzioni da
     * esporre nel config: deve restituire una label non vuota per ogni caso in
     * ognuna delle lingue configurate, indipendentemente dalla locale attiva.
     */
    public function test_label_in_returns_a_non_empty_label_for_every_case_and_locale(): void
    {
        $locales = config('wm-tab-translatable.locales', ['it', 'en']);

        $this->assertNotEmpty($locales);

        foreach (Season::cases() as $case) {
            foreach ($locales as $locale) {
                $label = $case->labelIn($locale);

                $this->assertIsString($label);
                $this->assertNotSame('', $label, "Label vuota per {$case->value} in {$locale}");
                $this->assertNotSame(
                    $case->value,
                    $label,
                    "Label non tradotta (uguale al codice) per {$case->value} in {$locale}"
                );
            }
        }
    }

    public function test_label_in_is_independent_from_the_active_locale(): void
    {
        app()->setLocale('en');

        $this->assertSame(Season::SPRING->labelIn('it'), (function () {
            app()->setLocale('de');

            return Season::SPRING->labelIn('it');
        })());
    }
}
