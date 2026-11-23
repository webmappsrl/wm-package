<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Wm\WmPackage\Services\Print\LatexText;
use Wm\WmPackage\Services\Print\PrintService;

/**
 * Dati della scheda PDF di un modello stampabile (EcTrack, EcPoi), oc:8982.
 *
 * Il trait fornisce i dati specifici del modello (titolo, descrizione, tabella, POI correlati,
 * foto); `PrintService` aggiunge quelli comuni (etichette, mappa, colore, stile). Tutte le
 * stringhe restituite per la view sono già LaTeX-sicure.
 *
 * Chi usa il trait implementa `Wm\WmPackage\Services\Print\Contracts\PrintableModel`. I dati
 * propri di un tipo di modello (tabella tecnica, POI correlati) arrivano dai due hook
 * `printStats()` e `printRelatedPois()`, che il modello sovrascrive (vedi EcTrack).
 *
 * Niente costanti qui: i trait non le ammettono sotto PHP 8.2 (vedi CLAUDE.md del package).
 */
trait Printable
{
    /**
     * View Blade del sorgente LaTeX, dal nome del modello del package (`EcTrack` -> `ec-track`)
     * anche quando lo shard usa una sottoclasse. Tre livelli, il primo che esiste vince:
     *
     * 1. la view della singola App, `print/apps/<app_id>/ec-track` (oc:8982);
     * 2. la view dello shard, `resources/views/vendor/wm-package/print/ec-track` (override di Laravel);
     * 3. la view di default del package.
     *
     * Il livello 1 sta anch'esso nello shard, in `resources/views/vendor/wm-package/print/apps/<app_id>/`,
     * e riceve le stesse variabili: può includere i partial dello shard e cambiarne solo una parte.
     */
    public function printView(): string
    {
        $class = static::class;
        foreach ([static::class, ...array_values(class_parents($this) ?: [])] as $candidate) {
            if (str_starts_with($candidate, 'Wm\\WmPackage\\Models\\')) {
                $class = $candidate;
                break;
            }
        }
        $name = Str::kebab(class_basename($class));

        $appId = $this->getAttribute('app_id');
        if (is_int($appId) || (is_string($appId) && ctype_digit($appId))) {
            $appView = 'wm-package::print.apps.'.$appId.'.'.$name;
            if (View::exists($appView)) {
                return $appView;
            }
        }

        return 'wm-package::print.'.$name;
    }

    /**
     * Nome del file su wmfe, senza estensione. Una lingua non valida (es. `../x`) è rifiutata.
     */
    public function printFileName(string $locale): string
    {
        PrintService::assertValidLocale($locale);

        return 'print-'.$locale;
    }

    /**
     * Il nome con cui la scheda si scarica: lo slug del nome del contenuto nella lingua della scheda
     * (con il ripiego di `printTranslation()`), senza lingua né id, es. `oliena-monte-corrasi`. La
     * chiave su wmfe resta `printFileName()`.
     */
    public function printDownloadName(string $locale): string
    {
        return Str::slug($this->printTranslation('name', $locale));
    }

    /**
     * Dati del modello per la view: `name`, `descriptionLatex`, `stats` e, se il modello ne ha,
     * `relatedPois`. Le chiavi comuni (labels, mapFile, photos, ...) le aggiunge PrintService.
     *
     * @return array<string,mixed>
     */
    public function printViewData(string $locale): array
    {
        $description = $this->printTranslation('properties->description', $locale);
        if ($description === '') {
            $description = $this->printTranslation('description', $locale);
        }

        $data = [
            'name' => LatexText::escape($this->printTranslation('name', $locale)),
            'descriptionLatex' => LatexText::fromHtml($description),
            'stats' => $this->printStats($locale),
        ];

        $relatedPois = $this->printRelatedPois($locale);
        if ($relatedPois !== null) {
            $data['relatedPois'] = $relatedPois;
        }

        return $data;
    }

    /**
     * URL delle foto della collection `default`, nell'ordine delle media (la prima è la copertina).
     *
     * @return list<string>
     */
    public function printPhotoUrls(): array
    {
        return $this->getMedia('default')
            ->filter(fn ($media) => str_starts_with((string) $media->mime_type, 'image/'))
            ->map(fn ($media) => (string) $media->getUrl())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Testo tradotto nella lingua chiesta. Se manca, si prende il fallback dell'app e poi la prima
     * lingua che ha del testo: la scheda non esce mai con un campo vuoto se un'altra lingua ce l'ha.
     */
    public function printTranslation(string $key, string $locale): string
    {
        if (! in_array($key, $this->getTranslatableAttributes(), true)) {
            return '';
        }

        $translations = $this->getTranslations($key);
        $candidates = [
            $translations[$locale] ?? null,
            $translations[config('app.fallback_locale')] ?? null,
            ...array_values($translations),
        ];
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Righe della tabella dei dati tecnici, già escaped. Default: nessuna.
     *
     * @return list<array{label:string,value:string}>
     */
    public function printStats(string $locale): array
    {
        return [];
    }

    /**
     * POI correlati, già escaped; null se il modello non ne ha (la view non riceve la chiave).
     *
     * @return list<array{name:string,typeLabel:?string}>|null
     */
    public function printRelatedPois(string $locale): ?array
    {
        return null;
    }
}
