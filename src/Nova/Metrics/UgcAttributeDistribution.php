<?php

namespace Wm\WmPackage\Nova\Metrics;

use DateTimeInterface;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

class UgcAttributeDistribution extends Partition
{
    /**
     * Etichetta visualizzata sulla metrica
     */
    protected string $customLabel;

    /**
     * Path SQL dell'attributo da contare (es: properties->'device'->>'appVersion')
     */
    protected string $path;

    /**
     * Classe del modello da utilizzare
     */
    protected string $modelClass;

    /**
     * Costruttore parametrico
     */
    public function __construct(string $label, string $path, string $modelClass)
    {
        parent::__construct();
        $this->customLabel = $label;
        $this->path = $path;
        $this->modelClass = $modelClass;
    }

    /**
     * Calculate the value of the metric.
     */
    public function calculate(NovaRequest $request): PartitionResult
    {
        $data = $this->modelClass::query()
            ->selectRaw("{$this->path} as value, count(*) as count")
            ->groupBy('value')
            ->get()
            ->pluck('count', 'value')
            ->toArray();

        return $this->normalizeAndFormatData($data);
    }

    /**
     * Normalizza e formatta i dati per il risultato
     */
    protected function normalizeAndFormatData(array $data): PartitionResult
    {
        $normalizedData = $this->normalizeKeys($data);
        $normalizedData = $this->groupSmallValues($normalizedData);
        $this->sortData($normalizedData);

        return $this->result($normalizedData);
    }

    /**
     * Normalizza le chiavi sostituendo valori null/vuoti con 'No Attribute'
     */
    protected function normalizeKeys(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $count) {
            $label = $this->normalizeKey($key);
            $normalized[$label] = ($normalized[$label] ?? 0) + $count;
        }

        return $normalized;
    }

    /**
     * Normalizza una singola chiave
     */
    protected function normalizeKey($key): string
    {
        return (is_null($key) || $key === '' || $key === false) ? 'No Attribute' : (string) $key;
    }

    /**
     * Raggruppa valori con conteggio basso in "Others"
     */
    protected function groupSmallValues(array $data, int $threshold = 5): array
    {
        $others = 0;
        $toRemove = [];

        foreach ($data as $key => $count) {
            // Non raggruppare i valori speciali
            if ($this->isSpecialKey($key)) {
                continue;
            }

            if ($count < $threshold) {
                $others += $count;
                $toRemove[] = $key;
            }
        }

        // Rimuovi le chiavi da raggruppare
        foreach ($toRemove as $key) {
            unset($data[$key]);
        }

        // Aggiungi "Others" se ci sono valori raggruppati
        if ($others > 0) {
            $data['Others'] = $others;
        }

        return $data;
    }

    /**
     * Ordina i dati in base al tipo di attributi rilevati
     */
    protected function sortData(array &$data): void
    {
        $isVersionData = $this->hasOnlyVersions($data);

        uksort($data, function ($a, $b) use ($isVersionData) {
            // Sposta "No Attribute" e "Others" alla fine
            $aIsSpecial = $this->isSpecialKey($a);
            $bIsSpecial = $this->isSpecialKey($b);

            if ($aIsSpecial && ! $bIsSpecial) {
                return 1;
            }
            if (! $aIsSpecial && $bIsSpecial) {
                return -1;
            }
            if ($aIsSpecial && $bIsSpecial) {
                return strcasecmp($a, $b);
            }

            // Confronto in base al tipo di dati
            return $isVersionData
                ? version_compare($b, $a) // Versioni: decrescente
                : strcasecmp($a, $b);      // Testuali: alfabetico
        });
    }

    /**
     * Verifica se i dati contengono solo versioni software (esclusi i valori speciali)
     */
    protected function hasOnlyVersions(array $data): bool
    {
        $hasAnyVersion = false;

        foreach ($data as $key => $count) {
            if ($this->isSpecialKey($key)) {
                continue;
            }

            if ($this->isVersionNumber($key)) {
                $hasAnyVersion = true;
            } else {
                // Se troviamo anche solo un valore non-versione, non sono solo versioni
                return false;
            }
        }

        // Restituisce true solo se ci sono effettivamente versioni
        return $hasAnyVersion;
    }

    /**
     * Verifica se una chiave è un valore speciale da mettere alla fine
     */
    protected function isSpecialKey(string $key): bool
    {
        return $key === 'No Attribute' || $key === 'Others';
    }

    /**
     * Verifica se una stringa è un numero di versione software (es: "3.0.4", "v1.2.3")
     */
    protected function isVersionNumber(string $value): bool
    {
        return preg_match('/^(v)?(\d+\.)+\d+$/i', $value) === 1;
    }

    /**
     * Determine the amount of time the results of the metric should be cached.
     */
    public function cacheFor(): ?DateTimeInterface
    {
        return null;
    }

    /**
     * Get the name of the metric.
     */
    public function name()
    {
        return __($this->customLabel);
    }
}
