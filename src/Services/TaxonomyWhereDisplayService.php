<?php

namespace Wm\WmPackage\Services;

use Illuminate\Support\Facades\DB;
use Wm\WmPackage\Models\App;
use Wm\WmPackage\Models\EcPoi;
use Wm\WmPackage\Models\UgcPoi;
use Wm\WmPackage\Models\UgcTrack;

/**
 * Logica di visualizzazione di `properties.taxonomy_where` (oc:8588).
 *
 * Il dato salvato può essere in tre forme (più vecchia senza livello, forma vecchia con
 * `_admin_level`, forma oc:8487 con `name/admin_level/source`); in uscita si produce sempre
 * la forma vecchia, l'unica che wm-core (`<wm-txn-where>`) e wp-geohub sanno leggere.
 *
 * Registrato `scoped`: la cache dell'opzione App vive per una sola richiesta o un solo job,
 * quindi un worker Horizon a lunga vita rilegge l'opzione dopo una modifica in Nova.
 */
class TaxonomyWhereDisplayService extends BaseService
{
    public const SOURCE_PREFIX = 'source:';

    public const OSMFEATURES_SOURCE = 'osmfeatures';

    public const APP_OPTION = 'taxonomy_where_display';

    private const LANGUAGE_KEY_PATTERN = '/^[a-z]{2,3}$/';

    /** @var array<int, string[]> */
    private array $appCategories = [];

    public function categoryOf(array $entry): ?string
    {
        $level = $this->levelOf($entry);
        if ($level !== null) {
            return (string) $level;
        }

        $source = $this->sourceOf($entry);
        if ($source !== null) {
            return self::SOURCE_PREFIX.$source;
        }

        return null;
    }

    public function toLegacyEntry(array $entry): array
    {
        $names = $entry['name'] ?? $entry;
        if (is_string($names)) {
            // Nome stringa semplice o JSON serializzato, come già accettava getValidName().
            $trimmed = trim($names);
            $decoded = str_starts_with($trimmed, '{') ? json_decode($trimmed, true) : null;
            $names = is_array($decoded) ? $decoded : ($trimmed !== '' ? ['it' => $trimmed, 'en' => $trimmed] : []);
        }
        if (! is_array($names)) {
            $names = [];
        }

        $legacy = [];
        foreach ($names as $key => $value) {
            if (is_string($key) && preg_match(self::LANGUAGE_KEY_PATTERN, $key) && is_string($value) && trim($value) !== '') {
                $legacy[$key] = $value;
            }
        }

        $level = $this->levelOf($entry);
        if ($level !== null) {
            $legacy['_admin_level'] = $level;
        }

        $source = $this->sourceOf($entry);
        if ($source !== null) {
            $legacy['_source'] = $source;
        }

        return $legacy;
    }

    /**
     * Livello amministrativo di un'entry, sia nella forma vecchia (`_admin_level`) sia in quella
     * oc:8487 (`admin_level`) — estratto da `categoryOf()`/`toLegacyEntry()` (oc:8588, review):
     * stesso comportamento di prima, solo centralizzato.
     */
    private function levelOf(array $entry): ?int
    {
        $level = $entry['_admin_level'] ?? $entry['admin_level'] ?? null;
        if (is_int($level) || (is_string($level) && ctype_digit($level))) {
            return (int) $level;
        }

        return null;
    }

    /**
     * Sorgente di un'entry, sia nella forma vecchia (`_source`) sia in quella oc:8487
     * (`source`) — estratta da `categoryOf()`/`toLegacyEntry()` (oc:8588, review).
     */
    private function sourceOf(array $entry): ?string
    {
        $source = $entry['_source'] ?? $entry['source'] ?? null;

        return (is_string($source) && $source !== '') ? $source : null;
    }

    public function normalize(array $taxonomyWhere): array
    {
        $normalized = [];
        foreach ($taxonomyWhere as $id => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $legacy = $this->toLegacyEntry($entry);
            if ($this->hasName($legacy)) {
                $normalized[$id] = $legacy;
            }
        }

        return $normalized;
    }

    public function filter(array $taxonomyWhere, array $categories): array
    {
        $normalized = $this->normalize($taxonomyWhere);
        if ($categories === []) {
            return $normalized;
        }

        $categories = array_map('strval', $categories);

        return array_filter(
            $normalized,
            fn (array $entry) => in_array($this->categoryOf($entry), $categories, true)
        );
    }

    /** @return string[] */
    public function orderedNames(array $taxonomyWhere): array
    {
        $entries = [];
        $idx = 0;
        foreach ($this->normalize($taxonomyWhere) as $entry) {
            $entries[] = [
                'name' => $entry['it'] ?? $entry['en'] ?? $this->firstName($entry),
                'level' => $entry['_admin_level'] ?? null,
                'idx' => $idx++,
            ];
        }

        usort($entries, function (array $a, array $b): int {
            if (($a['level'] === null) !== ($b['level'] === null)) {
                return $a['level'] === null ? -1 : 1;
            }

            return [$a['level'], $a['idx']] <=> [$b['level'], $b['idx']];
        });

        return array_map(static fn (array $e) => trim($e['name']), $entries);
    }

    /** @return string[] */
    public function selectedCategoriesForApp(?int $appId): array
    {
        if ($appId === null) {
            return [];
        }

        if (! array_key_exists($appId, $this->appCategories)) {
            $properties = App::query()->whereKey($appId)->value('properties');
            $properties = is_array($properties) ? $properties : (json_decode((string) $properties, true) ?: []);
            $this->appCategories[$appId] = $this->normalizeOptionValue($properties[self::APP_OPTION] ?? []);
        }

        return $this->appCategories[$appId];
    }

    /**
     * Normalizza un valore di opzione multi-select salvato in `properties` (oc:8588, review):
     * usato per `properties->taxonomy_where_display` sia lato Nova (campo Multiselect) sia lato
     * `AppObserver` (confronto prima/dopo per decidere se rigenerare le uscite pubbliche). Un
     * cast diretto `(array) $value` su una stringa JSON produrrebbe un solo elemento con
     * l'intera stringa invece di normalizzarla: il campo Nova Outl1ne salva un array nativo
     * quando dichiara `->saveAsJSON()`, ma un dato storico o un futuro campo che dimentica quella
     * opzione salverebbe una stringa JSON serializzata.
     *
     * @return string[]
     */
    public function normalizeOptionValue(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true) ?: [];
        }

        if (! is_array($value)) {
            return [];
        }

        // is_scalar() prima di strval() (fix round 1, review): un elemento non scalare (es. un
        // array annidato, dato corrotto o mai atteso da un Multiselect) farebbe fallire
        // array_map('strval', ...) con "Array to string conversion" — lo si scarta invece di
        // farlo esplodere.
        return array_values(array_map('strval', array_filter($value, 'is_scalar')));
    }

    /**
     * Categorie presenti nei taxonomy_where di EcTrack, EcPoi, UgcPoi e UgcTrack dell'App, più
     * quelle già salvate (restano selezionabili anche se nessun record le contiene più).
     *
     * @return array<string, string>
     */
    public function availableCategories(int $appId, array $alreadySelected = []): array
    {
        $trackModel = config('wm-package.ec_track_model');
        $tables = [(new $trackModel)->getTable(), (new EcPoi)->getTable(), (new UgcPoi)->getTable(), (new UgcTrack)->getTable()];

        $categories = [];
        foreach ($tables as $table) {
            // Stessa whitelist usata dagli altri metodi che interpolano un nome tabella in SQL
            // puro (es. GeometryComputationService::syncTaxonomyWhere()), oc:8588 review.
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                throw new \InvalidArgumentException('Invalid table name.');
            }

            $rows = DB::select("
                SELECT DISTINCT e.value AS entry
                FROM {$table} t,
                     jsonb_each(CASE WHEN jsonb_typeof(t.properties->'taxonomy_where') = 'object'
                                     THEN t.properties->'taxonomy_where' ELSE '{}'::jsonb END) e
                WHERE t.app_id = ?
            ", [$appId]);

            foreach ($rows as $row) {
                $category = $this->categoryOf(json_decode($row->entry, true) ?: []);
                if ($category !== null) {
                    $categories[$category] = true;
                }
            }
        }

        foreach ($alreadySelected as $category) {
            $categories[(string) $category] = true;
        }

        $options = [];
        foreach (array_keys($categories) as $category) {
            $options[(string) $category] = $this->labelFor((string) $category);
        }
        ksort($options, SORT_NATURAL);

        return $options;
    }

    public function labelFor(string $category): string
    {
        if (str_starts_with($category, self::SOURCE_PREFIX)) {
            return __('Source: :source', ['source' => substr($category, strlen(self::SOURCE_PREFIX))]);
        }

        return match ($category) {
            '4' => __('Region'),
            '6' => __('Province'),
            '8' => __('Municipality'),
            default => __('Admin level :level', ['level' => $category]),
        };
    }

    public function fromOsmfeatures(array $wheres): array
    {
        $mapped = [];
        foreach ($wheres as $id => $where) {
            if (! is_array($where)) {
                continue;
            }
            $legacy = $this->toLegacyEntry($where);
            if (! $this->hasName($legacy)) {
                continue;
            }
            $legacy['_source'] = self::OSMFEATURES_SOURCE;
            $mapped[$id] = $legacy;
        }

        return $mapped;
    }

    public function classifyFormat(mixed $taxonomyWhere): string
    {
        if (! is_array($taxonomyWhere) || $taxonomyWhere === []) {
            return 'empty';
        }

        // Una sola passata (oc:8588, review): 'oc8487' ha sempre priorità appena trovata (come
        // prima, che la cercava in un primo giro completo su tutte le entry prima di controllare
        // 'legacy' in un secondo giro); l'unica differenza è che qui i due controlli condividono
        // lo stesso giro invece di due giri separati sullo stesso array filtrato.
        $hasLegacy = false;
        foreach ($taxonomyWhere as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (is_array($entry['name'] ?? null)) {
                return 'oc8487';
            }
            if (! $hasLegacy && (array_key_exists('_admin_level', $entry) || array_key_exists('_source', $entry))) {
                $hasLegacy = true;
            }
        }

        return $hasLegacy ? 'legacy' : 'oldest';
    }

    private function hasName(array $legacy): bool
    {
        return $this->firstName($legacy) !== '';
    }

    private function firstName(array $legacy): string
    {
        foreach ($legacy as $key => $value) {
            if (! str_starts_with((string) $key, '_') && is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return '';
    }
}
