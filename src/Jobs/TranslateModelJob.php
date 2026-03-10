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
     * System prompt: una lingua target per chiamata, tutti i campi insieme.
     *
     * Input: JSON con i campi italiani da tradurre (es. {"description": "...", "name": "..."})
     * Output: JSON con gli stessi campi tradotti nella lingua target.
     *
     * Regole per "name": preserva nomi propri e luoghi; se è un codice/sigla alfanumerica
     * restituiscilo invariato.
     * Regole per "description": traduzione libera preservando tono e significato.
     */
    protected const PROMPT = <<<'PROMPT'
You are a professional translator specializing in outdoor and hiking content.
You will receive a JSON object where each key is a field name (e.g. "name", "description")
and the value is the Italian source text to translate into %s.

Rules for "name":
- Keep proper nouns (people, local place names, mountains, villages) in their original Italian form
  unless they have a widely recognized official equivalent (e.g. "Monte Bianco" → "Mont Blanc" in French).
- If the value is a code, abbreviation, or alphanumeric identifier (e.g. "SI-C G09-B"),
  return it UNCHANGED.

Rules for "description":
- Translate freely, preserving the meaning and tone of the original text.

Return ONLY a valid JSON object with the same keys and the translated values.
No explanation, no extra keys.
PROMPT;

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
     * Raccoglie i testi italiani dei campi traducibili (description, name).
     * Restituisce es. ['description' => 'testo it...', 'name' => 'nome it'].
     */
    protected function buildItalianTexts(array $properties): array
    {
        $texts = [];

        // Campo description
        $description = $properties['description'] ?? null;
        if (is_string($description)) {
            $description = ['it' => $description];
        }
        if (is_array($description) && ! empty($description['it'])) {
            $texts['description'] = $description['it'];
        }

        // Campo name — da properties o dalla colonna Spatie
        $nameArray = $properties['name'] ?? null;
        if (is_string($nameArray)) {
            $nameArray = ['it' => $nameArray];
        }
        $italianName = is_array($nameArray) ? ($nameArray['it'] ?? null) : null;
        if (empty($italianName) && in_array('name', $this->model->translatable ?? [])) {
            $italianName = $this->model->getTranslation('name', 'it', false) ?: null;
        }
        if (! empty($italianName)) {
            $texts['name'] = $italianName;
        }

        return $texts;
    }

    /**
     * Restituisce le lingue che mancano in almeno uno dei campi.
     */
    protected function getMissingLocales(array $properties, array $italianTexts): array
    {
        $missing = [];

        foreach ($this->locales as $locale) {
            foreach (array_keys($italianTexts) as $field) {
                if ($field === 'description') {
                    $current = $properties['description'] ?? null;
                    if (is_string($current)) {
                        $current = ['it' => $current];
                    }
                    if (empty(($current[$locale] ?? null))) {
                        $missing[] = $locale;
                        break;
                    }
                }

                if ($field === 'name') {
                    $nameArray = $properties['name'] ?? null;
                    if (is_string($nameArray)) {
                        $nameArray = ['it' => $nameArray];
                    }
                    $existingInProps = is_array($nameArray) ? ($nameArray[$locale] ?? null) : null;
                    $existingInSpatie = in_array('name', $this->model->translatable ?? [])
                        ? ($this->model->getTranslation('name', $locale, false) ?: null)
                        : null;

                    if (empty($existingInProps) || empty($existingInSpatie)) {
                        $missing[] = $locale;
                        break;
                    }
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

        if (isset($italianTexts['description'])) {
            $current = $properties['description'] ?? null;
            if (is_string($current)) {
                $current = ['it' => $current];
            }
            if (empty(($current[$locale] ?? null))) {
                $fields['description'] = $italianTexts['description'];
            }
        }

        if (isset($italianTexts['name'])) {
            $nameArray = $properties['name'] ?? null;
            if (is_string($nameArray)) {
                $nameArray = ['it' => $nameArray];
            }
            $existingInProps = is_array($nameArray) ? ($nameArray[$locale] ?? null) : null;
            if (empty($existingInProps)) {
                $fields['name'] = $italianTexts['name'];
            }
        }

        return $fields;
    }

    /**
     * Applica le traduzioni alle properties e alla colonna Spatie.
     */
    protected function applyTranslations(array $translations, string $locale, array &$properties): void
    {
        if (isset($translations['description']) && ! $this->looksLikeRefusal($translations['description'])) {
            $current = is_array($properties['description'] ?? null)
                ? $properties['description']
                : ['it' => $properties['description'] ?? null];
            $current[$locale] = $translations['description'];
            $properties['description'] = $current;
        }

        if (isset($translations['name']) && ! $this->looksLikeRefusal($translations['name'])) {
            $current = is_array($properties['name'] ?? null)
                ? $properties['name']
                : ['it' => $translations['name']];
            $current[$locale] = $translations['name'];
            $properties['name'] = $current;

            if (in_array('name', $this->model->translatable ?? [])) {
                $this->model->setTranslation('name', $locale, $translations['name']);
            }
        }
    }

    /**
     * Una chiamata OpenAI per una sola lingua target.
     * Input: {'description': 'testo it', 'name': 'nome it'}
     * Output: {'description': 'translated', 'name': 'translated'}
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
                    'content' => sprintf(self::PROMPT, $targetLanguage),
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
            "I cannot",
            "I can't",
            "does not appear to be",
            "cannot be translated",
            "is not translatable",
            "Please provide",
            "not in Italian",
            "does not seem to be",
        ];

        foreach ($refusalPatterns as $pattern) {
            if (stripos($text, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
