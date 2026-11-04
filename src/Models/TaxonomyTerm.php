<?php

namespace Wm\WmPackage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Translatable\HasTranslations;

/**
 * Termine di classificazione del modello unico (oc:8910). Senza parent_id è una tassonomia,
 * con parent_id è un termine di quella tassonomia.
 */
class TaxonomyTerm extends Model
{
    use HasFactory, HasTranslations;

    /**
     * Formato dell'identifier di una tassonomia: kebab-case (minuscole, cifre, trattini) più i due
     * punti dell'import (`sardegnasentieri:tid:123`). Vale solo per i tassonomie, perché il loro
     * identifier è la chiave sotto cui termsForSearch() raggruppa i termini nell'indice, e un
     * punto in una chiave Elasticsearch la espande in oggetti annidati. Per un termine
     * l'identifier è un valore: lì un punto non rompe niente e non si impone nulla.
     * `\z` e non `$`: in PCRE il `$` accetta anche un a capo finale.
     */
    public const VOCABULARY_IDENTIFIER_FORMAT = '/^[a-z0-9]+(?:[-:][a-z0-9]+)*\z/';

    public array $translatable = ['name', 'description', 'excerpt'];

    protected $fillable = [
        'parent_id',
        'is_system',
        'identifier',
        'name',
        'description',
        'excerpt',
        'icon',
        'sort_order',
        'single_choice',
        'properties',
    ];

    protected $casts = [
        'properties' => 'array',
        'single_choice' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $term) {
            if ($term->parent_id === null
                // Senza identifier ci pensa il NOT NULL del database.
                && $term->identifier !== null
                && ($term->isDirty('identifier') || ! $term->exists || $term->isDirty('parent_id'))
                && ! preg_match(self::VOCABULARY_IDENTIFIER_FORMAT, (string) $term->identifier)) {
                throw new InvalidArgumentException(
                    "L'identifier di una tassonomia deve essere in kebab-case (minuscole, cifre, trattini, due punti): \"{$term->identifier}\"."
                );
            }

            // La gerarchia è a due livelli: il padre deve essere una tassonomia, altrimenti
            // parent_id sul legame smette di significare «la tassonomia». Il controllo scatta
            // solo quando parent_id cambia.
            if ($term->parent_id === null || ! $term->isDirty('parent_id')) {
                return;
            }

            if ($term->exists && (int) $term->parent_id === (int) $term->getKey()) {
                throw new InvalidArgumentException('Un termine non può essere padre di sé stesso.');
            }

            if (self::query()->whereKey($term->parent_id)->whereNotNull('parent_id')->exists()) {
                throw new InvalidArgumentException(
                    "Il termine {$term->parent_id} non è una tassonomia: la gerarchia è a due livelli."
                );
            }

            // Un tassonomia che ha già figli non può diventare figlia: i suoi figli sarebbero nipoti.
            if ($term->exists && self::query()->where('parent_id', $term->getKey())->exists()) {
                throw new InvalidArgumentException(
                    "Il termine {$term->getKey()} è una tassonomia con figli: non può avere un padre."
                );
            }
        });

        // La copia della tassonomia sui legami (taxonomy_termables.parent_id) si scrive alla nascita
        // del legame: se il termine cambia tassonomia va riscritta, altrimenti un filtro per tassonomia
        // sulla sola tabella dei legami restituisce i contenuti della tassonomia vecchia. Update
        // diretto e non per modelli, perché i legami possono essere molti: per questo non scatta
        // l'observer del legame e i contenuti non vengono reindicizzati (serve un reindex).
        static::updated(function (self $term) {
            if ($term->wasChanged('parent_id')) {
                DB::table('taxonomy_termables')
                    ->where('taxonomy_term_id', $term->getKey())
                    ->update(['parent_id' => $term->parent_id]);
            }
        });
    }

    /**
     * @return BelongsTo<TaxonomyTerm, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<TaxonomyTerm, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function scopeTaxonomies(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function isTaxonomy(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Le app in cui questo tassonomia è acceso (oc:8910).
     *
     * Una riga del pivot vuol dire acceso: non esiste uno stato «spento» scritto da qualche
     * parte, si toglie la riga. Il legame vive sul tassonomia; un termine lo eredita dal suo.
     *
     * @return BelongsToMany<App, $this>
     */
    public function apps(): BelongsToMany
    {
        return $this->belongsToMany(App::class, 'app_taxonomy_term');
    }

    /**
     * I tassonomie accesi in un'app.
     *
     * Senza app (un contenuto che non è ancora stato salvato, un comando fuori contesto) non si
     * filtra niente: nascondere tutto in creazione farebbe sparire i campi mentre si compila.
     *
     * @param  Builder<TaxonomyTerm>  $query
     * @return Builder<TaxonomyTerm>
     */
    public function scopeForApp(Builder $query, ?int $appId): Builder
    {
        if ($appId === null) {
            return $query;
        }

        return $query->whereHas('apps', fn (Builder $apps) => $apps->where('apps.id', $appId));
    }

    public function ecTracks(): MorphToMany
    {
        // Il modello della traccia è quello configurato dallo shard (come in TaxonomyWhere e Layer).
        return $this->morphedByMany(
            config('wm-package.ec_track_model', EcTrack::class),
            'termable',
            'taxonomy_termables'
        );
    }

    public function ecPois(): MorphToMany
    {
        return $this->morphedByMany(EcPoi::class, 'termable', 'taxonomy_termables');
    }
}
