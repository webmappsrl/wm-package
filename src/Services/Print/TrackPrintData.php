<?php

namespace Wm\WmPackage\Services\Print;

use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyPoiType;

/**
 * Dati della scheda propri delle tracce (oc:8982): tabella tecnica e POI correlati.
 * Li usa `EcTrack` sovrascrivendo gli hook del trait `Printable`. Stringhe già escaped.
 */
class TrackPrintData
{
    /**
     * @return list<array{label:string,value:string}>
     */
    public static function stats(EcTrack $track, string $locale): array
    {
        $rows = [];
        $distance = self::value($track, 'distance');
        if (is_numeric($distance) && (float) $distance > 0) {
            $rows[] = ['Length', self::number((float) $distance, 1, $locale).' km'];
        }
        $ascent = self::value($track, 'ascent');
        if (is_numeric($ascent)) {
            $rows[] = ['Ascent', self::number((float) $ascent, 0, $locale).' m'];
        }
        $descent = self::value($track, 'descent');
        if (is_numeric($descent)) {
            $rows[] = ['Descent', self::number((float) $descent, 0, $locale).' m'];
        }
        $duration = self::value($track, 'duration_forward');
        if (is_numeric($duration) && (float) $duration > 0) {
            $rows[] = ['Duration', self::duration((int) round((float) $duration))];
        }
        $difficulty = $track->printTranslation('properties->difficulty', $locale);
        if ($difficulty === '') {
            $difficulty = (string) ($track->properties['cai_scale'] ?? '');
        }
        if (trim($difficulty) !== '') {
            $rows[] = ['Difficulty', $difficulty];
        }

        return array_map(fn ($row) => [
            'label' => LatexText::escape((string) __($row[0], [], $locale)),
            'value' => LatexText::escape($row[1]),
        ], $rows);
    }

    /**
     * POI correlati nell'ordine della pivot; quelli senza nome in nessuna lingua si saltano.
     *
     * @return list<array{name:string,typeLabel:?string}>
     */
    public static function relatedPois(EcTrack $track, string $locale): array
    {
        $related = [];
        /** @var EcPoi $poi */
        foreach ($track->ecPois()->get() as $poi) {
            $name = $poi->printTranslation('name', $locale);
            if ($name === '') {
                continue;
            }
            /** @var TaxonomyPoiType|null $type */
            $type = $poi->taxonomyPoiTypes()->first();
            $typeLabel = $type ? (string) $type->getTranslation('name', $locale) : '';
            $related[] = [
                'name' => LatexText::escape($name),
                'typeLabel' => $typeLabel !== '' ? LatexText::escape($typeLabel) : null,
            ];
        }

        return $related;
    }

    /**
     * Valore tecnico con la precedenza manuale > OSM > DEM, poi il valore piatto di `properties`.
     */
    private static function value(EcTrack $track, string $field): mixed
    {
        $value = $track->classifyField($track, $field)['currentValue'] ?? null;

        return ($value === null || $value === '') ? ($track->properties[$field] ?? null) : $value;
    }

    private static function number(float $value, int $decimals, string $locale): string
    {
        $text = number_format($value, $decimals, '.', '');
        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $locale === 'en' ? $text : str_replace('.', ',', $text);
    }

    /** Minuti -> «4 h 05 min» oppure «45 min». */
    private static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return sprintf('%d h %02d min', intdiv($minutes, 60), $minutes % 60);
    }
}
