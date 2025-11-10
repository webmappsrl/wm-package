<?php

namespace Wm\WmPackage\Traits;

use Illuminate\Support\Arr;
use Spatie\Translatable\HasTranslations;
use Spatie\Translatable\Translatable;

trait HasSafeTranslatable
{
    use HasTranslations;



    public function getTranslations(?string $key = null, ?array $allowedLocales = null): array
    {
        if ($key !== null) {
            $this->guardAgainstNonTranslatableAttribute($key);
            $translatableConfig = app(Translatable::class);

            if ($this->isNestedKey($key)) {
                [$key, $nestedKey] = explode('.', str_replace('->', '.', $key), 2);
            }

            // Normalizza i dati per evitare errori con array_filter
            $attributeValue = $this->getAttributeFromArray($key);
            
            // Se il valore è una stringa vuota o null, restituisci array vuoto
            if (empty($attributeValue) || !is_string($attributeValue)) {
                return [];
            }
            
            // Prova a decodificare il JSON
            $decodedValue = $this->fromJson($attributeValue);
            
            // Se la decodifica fallisce o non restituisce un array, restituisci array vuoto
            if (!is_array($decodedValue)) {
                return [];
            }
            
            // Estrai il valore nested se necessario
            $nestedValue = Arr::get($decodedValue, $nestedKey ?? null, []);
            
            // Assicurati che il valore nested sia un array
            if (!is_array($nestedValue)) {
                return [];
            }

            return array_filter(
                $nestedValue,
                fn ($value, $locale) => $this->filterTranslations($value, $locale, $allowedLocales, $translatableConfig->allowNullForTranslation, $translatableConfig->allowEmptyStringForTranslation),
                ARRAY_FILTER_USE_BOTH,
            );
        }

        return array_reduce($this->getTranslatableAttributes(), function ($result, $item) use ($allowedLocales) {
            $result[$item] = $this->getTranslations($item, $allowedLocales);

            return $result;
        });
    }

}
