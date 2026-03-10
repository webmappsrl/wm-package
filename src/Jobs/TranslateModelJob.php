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
     * System prompt unico per tutti i campi.
     *
     * Il JSON in input ha campi con traduzioni già presenti (stringa) o da compilare (null).
     * Regole per "name": preserva nomi propri e luoghi; se il valore italiano è un codice/sigla
     * alfanumerica, restituiscilo invariato in tutte le lingue.
     * Regole per "description": traduzione libera mantenendo il senso del testo.
     * Restituisci SOLO il JSON con i null compilati, senza modificare i valori già presenti.
     */
    protected const PROMPT = <<<'PROMPT'
You are a professional translator specializing in outdoor and hiking content.
You will receive a JSON object where each key is a field name (e.g. "name", "description"),
and the value is an object of locale codes mapped to text or null.
Non-null values are already translated — do NOT change them.
Fill in ONLY the null values with the appropriate translation from the Italian ("it") source.

Rules for "name":
- Keep proper nouns (people, local place names, mountains, villages) in their original Italian form
  unless they have a widely recognized official equivalent (e.g. "Monte Bianco" → "Mont Blanc" in French).
- If the Italian value is a code, abbreviation, or alphanumeric identifier, return it unchanged for all locales.

Rules for "description":
- Translate freely, preserving the meaning and tone of the original text.

Return ONLY the same JSON structure with null values replaced. No explanation, no extra keys.
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

        // Costruisce il payload con tutti i campi traducibili
        $payload = $this->buildPayload($properties);

        if (empty($payload)) {
            return;
        }

        $result = $this->callOpenAI($payload);

        if (empty($result)) {
            return;
        }

        $this->applyResult($result, $properties);

        $this->model->properties = $properties;
        $this->model->saveQuietly();
    }

    /**
     * Costruisce il JSON da inviare a OpenAI con i campi traducibili e le lingue mancanti a null.
     * Restituisce array vuoto se non c'è nulla da tradurre.
     *
     * Esempio output:
     * [
     *   "name"        => ["it" => "Sentiero dei Fiori", "en" => null, "de" => "...già presente..."],
     *   "description" => ["it" => "Un bellissimo sentiero...", "en" => null, "de" => null],
     * ]
     */
    protected function buildPayload(array $properties): array
    {
        $payload = [];

        // Campo description
        $description = $properties['description'] ?? null;
        if (is_string($description)) {
            $description = ['it' => $description];
        }
        if (is_array($description) && ! empty($description['it'])) {
            $entry = ['it' => $description['it']];
            $hasMissing = false;
            foreach ($this->locales as $locale) {
                $entry[$locale] = ! empty($description[$locale]) ? $description[$locale] : null;
                if ($entry[$locale] === null) {
                    $hasMissing = true;
                }
            }
            if ($hasMissing) {
                $payload['description'] = $entry;
            }
        }

        // Campo name — recupera it da properties o dalla colonna Spatie
        $nameArray = $properties['name'] ?? null;
        if (is_string($nameArray)) {
            $nameArray = ['it' => $nameArray];
        }
        $italianName = is_array($nameArray) ? ($nameArray['it'] ?? null) : null;
        if (empty($italianName) && in_array('name', $this->model->translatable ?? [])) {
            $italianName = $this->model->getTranslation('name', 'it', false);
        }
        if (! empty($italianName)) {
            $entry = ['it' => $italianName];
            $hasMissing = false;
            foreach ($this->locales as $locale) {
                $existing = is_array($nameArray) ? ($nameArray[$locale] ?? null) : null;
                if (empty($existing) && in_array('name', $this->model->translatable ?? [])) {
                    $existing = $this->model->getTranslation('name', $locale, false) ?: null;
                }
                $entry[$locale] = ! empty($existing) ? $existing : null;
                if ($entry[$locale] === null) {
                    $hasMissing = true;
                }
            }
            if ($hasMissing) {
                $payload['name'] = $entry;
            }
        }

        return $payload;
    }

    /**
     * Applica il risultato di OpenAI alle properties e alla colonna Spatie.
     */
    protected function applyResult(array $result, array &$properties): void
    {
        if (isset($result['description']) && is_array($result['description'])) {
            $current = is_array($properties['description'] ?? null)
                ? $properties['description']
                : ['it' => $properties['description'] ?? null];

            foreach ($this->locales as $locale) {
                $translated = $result['description'][$locale] ?? null;
                if (! empty($translated) && ! $this->looksLikeRefusal($translated)) {
                    $current[$locale] = $translated;
                }
            }
            $properties['description'] = $current;
        }

        if (isset($result['name']) && is_array($result['name'])) {
            $current = is_array($properties['name'] ?? null)
                ? $properties['name']
                : ['it' => $result['name']['it'] ?? null];

            foreach ($this->locales as $locale) {
                $translated = $result['name'][$locale] ?? null;
                if (! empty($translated) && ! $this->looksLikeRefusal($translated)) {
                    $current[$locale] = $translated;
                    // Sincronizza anche la colonna Spatie
                    if (in_array('name', $this->model->translatable ?? [])) {
                        $this->model->setTranslation('name', $locale, $translated);
                    }
                }
            }
            $properties['name'] = $current;
        }
    }

    /**
     * Invia il payload a OpenAI e restituisce il JSON compilato.
     */
    protected function callOpenAI(array $payload): ?array
    {
        $apiKey = config('wm-package.clients.openai.api_key', env('OPENAI_API_KEY'));

        if (empty($apiKey)) {
            Log::warning('TranslateModelJob: OPENAI_API_KEY not configured');

            return null;
        }

        $response = Http::timeout(120)->withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ])->post('https://api.openai.com/v1/chat/completions', [
            'model' => config('wm-package.clients.openai.model', 'gpt-4o-mini'),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => self::PROMPT,
                ],
                [
                    'role' => 'user',
                    'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ],
            ],
        ]);

        if (! $response->successful()) {
            Log::error('TranslateModelJob: OpenAI API error', [
                'model_class' => $this->model::class,
                'model_id' => $this->model->id,
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
