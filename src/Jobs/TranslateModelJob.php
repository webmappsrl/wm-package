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
     * Regole di traduzione per i campi hardcoded (name, description).
     * Ogni campo extra passato via $additionalFieldRules che non ha una voce qui
     * usa DEFAULT_FIELD_RULE.
     */
    protected const FIELD_RULES = [
        'name' => <<<'RULE'
        Keep proper nouns (people, local place names, mountains, villages) in their original Italian form
        unless they have a widely recognized official equivalent (e.g. "Monte Bianco" -> "Mont Blanc" in French).
        If the value is a code, abbreviation, or alphanumeric identifier (e.g. "SI-C G09-B"), return it UNCHANGED.
        RULE,
        'description' => <<<'RULE'
        Translate freely, preserving the meaning and tone of the original text.
        RULE,
    ];

    public const DEFAULT_FIELD_RULE = <<<'RULE'
    Translate freely, preserving the meaning and tone of the original text.
    RULE;

    protected array $locales;

    public function __construct(
        protected Model $model,
        array $locales = ['en', 'de', 'fr', 'es'],
        protected array $additionalFieldRules = []
    ) {
        $this->locales = $locales;
    }

    /**
     * Regole per tutti i campi gestiti da questa istanza: i due hardcoded + gli extra.
     */
    protected function fieldRules(): array
    {
        return array_merge(self::FIELD_RULES, $this->additionalFieldRules);
    }

    public function handle(): void
    {
        $properties = $this->model->properties ?? [];

        // Raccoglie i testi italiani da tradurre
        $italianTexts = $this->buildItalianTexts($properties);

        if (empty($italianTexts)) {
            return;
        }

        // Calcola le lingue mancanti su almeno un campo
        $missingLocales = $this->getMissingLocales($properties, $italianTexts);

        if (empty($missingLocales)) {
            return;
        }

        $updated = false;

        // Una chiamata per lingua: output piccolo e affidabile anche per testi lunghi
        foreach ($missingLocales as $locale) {
            $fieldsToTranslate = $this->getFieldsMissingForLocale($properties, $italianTexts, $locale);

            if (empty($fieldsToTranslate)) {
                continue;
            }

            $translations = $this->callOpenAI($fieldsToTranslate, $locale);

            if (empty($translations)) {
                continue;
            }

            $this->applyTranslations($translations, $locale, $properties);
            $updated = true;
        }

        if ($updated) {
            $this->model->properties = $properties;
            $this->model->saveQuietly();
        }
    }

    /**
     * Legge properties->{$field}->{$locale}, normalizzando il caso in cui il valore
     * sia ancora una stringa semplice (non ancora migrato al formato {locale: testo}).
     */
    protected function readPropertiesLocale(array $properties, string $field, string $locale): ?string
    {
        $value = $properties[$field] ?? null;
        if (is_string($value)) {
            $value = ['it' => $value];
        }

        return is_array($value) ? ($value[$locale] ?? null) : null;
    }

    /**
     * Raccoglie i testi italiani di tutti i campi gestiti (hardcoded + extra).
     * Restituisce es. ['description' => 'testo it...', 'name' => 'nome it', 'not_accessible_message' => '...'].
     */
    protected function buildItalianTexts(array $properties): array
    {
        $texts = [];

        foreach (array_keys($this->fieldRules()) as $field) {
            if ($field === 'name') {
                $italianName = $this->readPropertiesLocale($properties, 'name', 'it');
                if (empty($italianName) && in_array('name', $this->model->translatable ?? [])) {
                    $italianName = $this->model->getTranslation('name', 'it', false) ?: null;
                }
                if (! empty($italianName)) {
                    $texts['name'] = $italianName;
                }

                continue;
            }

            $value = $this->readPropertiesLocale($properties, $field, 'it');
            if (! empty($value)) {
                $texts[$field] = $value;
            }
        }

        return $texts;
    }

    /**
     * Restituisce le lingue che mancano in almeno uno dei campi raccolti in buildItalianTexts().
     */
    protected function getMissingLocales(array $properties, array $italianTexts): array
    {
        $missing = [];

        foreach ($this->locales as $locale) {
            foreach (array_keys($italianTexts) as $field) {
                if ($field === 'name') {
                    $existingInProps = $this->readPropertiesLocale($properties, 'name', $locale);
                    $existingInSpatie = in_array('name', $this->model->translatable ?? [])
                        ? ($this->model->getTranslation('name', $locale, false) ?: null)
                        : null;

                    if (empty($existingInProps) || empty($existingInSpatie)) {
                        $missing[] = $locale;
                        break;
                    }

                    continue;
                }

                if (empty($this->readPropertiesLocale($properties, $field, $locale))) {
                    $missing[] = $locale;
                    break;
                }
            }
        }

        return array_values(array_unique($missing));
    }

    /**
     * Restituisce i campi italiani che mancano ancora per una lingua specifica.
     */
    protected function getFieldsMissingForLocale(array $properties, array $italianTexts, string $locale): array
    {
        $fields = [];

        foreach ($italianTexts as $field => $italianValue) {
            if ($field === 'name') {
                $existingInProps = $this->readPropertiesLocale($properties, 'name', $locale);
                if (empty($existingInProps)) {
                    $fields['name'] = $italianValue;
                }

                continue;
            }

            if (empty($this->readPropertiesLocale($properties, $field, $locale))) {
                $fields[$field] = $italianValue;
            }
        }

        return $fields;
    }

    /**
     * Applica le traduzioni alle properties e, per name, anche alla colonna Spatie.
     */
    protected function applyTranslations(array $translations, string $locale, array &$properties): void
    {
        foreach ($translations as $field => $translatedValue) {
            if ($this->looksLikeRefusal($translatedValue)) {
                continue;
            }

            $current = is_array($properties[$field] ?? null)
                ? $properties[$field]
                : ['it' => $properties[$field] ?? null];
            $current[$locale] = $translatedValue;
            $properties[$field] = $current;

            if ($field === 'name' && in_array('name', $this->model->translatable ?? [])) {
                $this->model->setTranslation('name', $locale, $translatedValue);
            }
        }
    }

    /**
     * Assembla il system prompt solo con le regole dei campi effettivamente
     * presenti in questa chiamata (mai i campi già tradotti/non richiesti).
     */
    protected function buildPrompt(array $fields, string $targetLanguage): string
    {
        $fieldRules = $this->fieldRules();

        $rulesText = collect($fields)
            ->keys()
            ->map(fn ($field) => sprintf(
                "Rules for \"%s\":\n%s",
                $field,
                $fieldRules[$field] ?? self::DEFAULT_FIELD_RULE
            ))
            ->implode("\n\n");

        return <<<PROMPT
        You are a professional translator specializing in outdoor and hiking content.
        You will receive a JSON object where each key is a field name and the value is the Italian
        source text to translate into {$targetLanguage}.

        {$rulesText}

        Return ONLY a valid JSON object with the same keys and the translated values.
        No explanation, no extra keys.
        PROMPT;
    }

    /**
     * Una chiamata OpenAI per una sola lingua target, solo con i campi mancanti per quella lingua.
     */
    protected function callOpenAI(array $fields, string $locale): ?array
    {
        $apiKey = config('wm-package.clients.openai.api_key', env('OPENAI_API_KEY'));

        if (empty($apiKey)) {
            Log::warning('TranslateModelJob: OPENAI_API_KEY not configured');

            return null;
        }

        $targetLanguage = self::LOCALE_NAMES[$locale] ?? $locale;

        $response = Http::timeout(120)->withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => config('wm-package.clients.openai.model', 'gpt-4o-mini'),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $this->buildPrompt($fields, $targetLanguage),
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($fields, JSON_UNESCAPED_UNICODE),
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('TranslateModelJob: OpenAI API error', [
                'model_class' => $this->model::class,
                'model_id' => $this->model->id,
                'locale' => $locale,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }

        $content = $response->json('choices.0.message.content');
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            Log::error('TranslateModelJob: OpenAI returned invalid JSON', [
                'model_class' => $this->model::class,
                'model_id' => $this->model->id,
                'locale' => $locale,
                'content' => $content,
            ]);

            return null;
        }

        return $decoded;
    }

    protected function looksLikeRefusal(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $refusalPatterns = [
            "I'm sorry",
            'I cannot',
            "I can't",
            'does not appear to be',
            'cannot be translated',
            'is not translatable',
            'Please provide',
            'not in Italian',
            'does not seem to be',
        ];

        foreach ($refusalPatterns as $pattern) {
            if (stripos($text, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
