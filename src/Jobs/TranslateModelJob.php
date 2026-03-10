<?php

namespace Wm\WmPackage\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslateModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected const LOCALE_NAMES = [
        'en' => 'English',
        'de' => 'German',
        'fr' => 'French',
        'es' => 'Spanish',
    ];

    /**
     * System prompt per la traduzione di testi descrittivi.
     * Traduzione libera mantenendo il senso del testo.
     */
    protected const PROMPT_DESCRIPTION = "You are a professional translator specializing in outdoor and hiking content. Translate the user's text from Italian to %s. Return ONLY the translated text, without any explanation, introduction, or additional commentary.";

    /**
     * System prompt per la traduzione di nomi/titoli.
     * Preserva i nomi propri di persone e luoghi senza traduzione letterale,
     * usando la denominazione ufficiale nella lingua target solo se universalmente nota.
     */
    protected const PROMPT_NAME = "You are a professional translator specializing in outdoor and hiking content. Translate the user's text from Italian to %s. Important rules: keep proper nouns (names of people, local place names, mountain names, village names) in their original Italian form unless they have a widely recognized official equivalent in the target language (e.g. 'Monte Bianco' → 'Mont Blanc' in French). Do NOT invent translations for local place names. Return ONLY the translated text, without any explanation, introduction, or additional commentary.";

    protected array $locales;

    public function __construct(
        protected Model $model,
        array $locales = ['en', 'de', 'fr', 'es']
    ) {
        $this->locales = $locales;
    }

    public function handle(): void
    {
        $properties = $this->model->properties ?? [];
        $updated = false;

        $updated = $this->translateField($properties, 'description', self::PROMPT_DESCRIPTION) || $updated;
        $updated = $this->translateField($properties, 'name', self::PROMPT_NAME) || $updated;
        $updated = $this->translateNameColumn(self::PROMPT_NAME) || $updated;

        if ($updated) {
            $this->model->properties = $properties;
            $this->model->saveQuietly();
        }
    }

    /**
     * Traduce il campo `name` come colonna translatable diretta (Spatie),
     * usando l'italiano presente in properties['name'] o nel campo name stesso.
     */
    protected function translateNameColumn(string $promptTemplate): bool
    {
        if (! in_array('name', $this->model->translatable ?? [])) {
            return false;
        }

        // Preferisce l'italiano da properties['name'], poi dalla colonna name
        $properties = $this->model->properties ?? [];
        $propertiesName = $properties['name'] ?? null;
        if (is_array($propertiesName)) {
            $italianText = $propertiesName['it'] ?? null;
        } else {
            $italianText = $this->model->getTranslation('name', 'it', false);
        }

        if (empty($italianText)) {
            return false;
        }

        $updated = false;
        foreach ($this->locales as $locale) {
            if (! empty($this->model->getTranslation('name', $locale, false))) {
                continue;
            }

            $translated = $this->callOpenAI($italianText, $locale, $promptTemplate);
            if ($translated !== null) {
                $this->model->setTranslation('name', $locale, $translated);
                $updated = true;
            }
        }

        return $updated;
    }

    protected function translateField(array &$properties, string $field, string $promptTemplate): bool
    {
        $value = $properties[$field] ?? null;

        if (empty($value)) {
            return false;
        }

        // Normalizza a array se è ancora una stringa semplice
        if (is_string($value)) {
            $value = ['it' => $value];
        }

        if (! is_array($value) || empty($value['it'] ?? null)) {
            return false;
        }

        $italianText = $value['it'];
        $updated = false;

        foreach ($this->locales as $locale) {
            if (! empty($value[$locale])) {
                continue;
            }

            $translated = $this->callOpenAI($italianText, $locale, $promptTemplate);
            if ($translated !== null) {
                $value[$locale] = $translated;
                $updated = true;
            }
        }

        if ($updated) {
            $properties[$field] = $value;
        }

        return $updated;
    }

    protected function callOpenAI(string $text, string $locale, string $promptTemplate): ?string
    {
        $targetLanguage = self::LOCALE_NAMES[$locale] ?? $locale;
        $apiKey = config('wm-package.clients.openai.api_key', env('OPENAI_API_KEY'));

        if (empty($apiKey)) {
            Log::warning('TranslateModelDescriptionJob: OPENAI_API_KEY not configured');

            return null;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => config('wm-package.clients.openai.model', 'gpt-4o-mini'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => sprintf($promptTemplate, $targetLanguage),
                ],
                [
                    'role' => 'user',
                    'content' => $text,
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('TranslateModelDescriptionJob: OpenAI API error', [
                'model_class' => $this->model::class,
                'model_id' => $this->model->id,
                'locale' => $locale,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        return $response->json('choices.0.message.content');
    }
}
