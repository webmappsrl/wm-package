<?php

namespace Wm\WmPackage\TrailRegistry\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Wm\WmPackage\Models\EcTrack;
use Wm\WmPackage\Models\TaxonomyTerm;
use Wm\WmPackage\TrailRegistry\Enums\TrailStatusEventSource;
use Wm\WmPackage\TrailRegistry\Events\WalkabilityChanged;
use Wm\WmPackage\TrailRegistry\Exceptions\WalkabilityConflictException;
use Wm\WmPackage\TrailRegistry\Models\TrailStatusEvent;
use Wm\WmPackage\TrailRegistry\TrailRegistryClasses;

/**
 * L'unico punto da cui uno stato di un sentiero si scrive (oc:8989).
 *
 * Vale per qualsiasi famiglia di tassonomia che `trail_status_events` tiene: la
 * percorribilita', la validazione, e quelle che verranno. Un percorso che
 * scrivesse il legame da solo lascerebbe lo stato cambiato senza storia, ed e'
 * il difetto di osm2cai che questo lavoro non replica.
 *
 * **Perche' il lock.** Il pivot delle tassonomie impedisce di legare due volte
 * lo *stesso* termine, non due termini *diversi* della stessa famiglia:
 * `single_choice` e' una convenzione applicativa, non un vincolo del database.
 * Due salvataggi ravvicinati sullo stesso sentiero lascerebbero due stati
 * attaccati, l'API ne esporrebbe due, i conteggi delle card supererebbero il
 * totale dei sentieri, e chi legge «lo stato» ne otterrebbe uno dei due senza
 * sapere che ce n'e' un altro. Qui il sentiero si blocca, si rilegge, si
 * confronta e solo allora si scrive.
 */
class TrailStatusService
{
    /**
     * Porta un sentiero a uno stato di una famiglia, registrando l'evento.
     *
     * @param  string  $familyIdentifier  La famiglia a cui lo stato appartiene.
     * @param  string  $toIdentifier  L'identifier del termine di arrivo.
     * @param  string|null  $expectedFromIdentifier  Lo stato da cui chi scrive crede di partire:
     *                                               se non e' piu' quello la scrittura si rifiuta
     *                                               invece di applicarsi su una base vecchia.
     *
     * @throws WalkabilityConflictException
     * @throws InvalidArgumentException se la famiglia non esiste o il termine non le appartiene
     */
    public function set(
        EcTrack $track,
        string $familyIdentifier,
        string $toIdentifier,
        ?string $description,
        ?int $userId,
        TrailStatusEventSource $source,
        ?string $expectedFromIdentifier = null,
    ): ?TrailStatusEvent {
        $family = $this->family($familyIdentifier);
        $to = $this->termOfFamily($family, $toIdentifier);

        return DB::transaction(function () use ($track, $family, $to, $description, $userId, $source, $expectedFromIdentifier) {
            // Il lock e' sulla riga del sentiero: due scritture sullo stesso
            // sentiero si mettono in fila, due su sentieri diversi no.
            EcTrack::query()->whereKey($track->getKey())->lockForUpdate()->first();

            $current = $this->currentWithin($track, $family);

            if ($expectedFromIdentifier !== null && $current?->identifier !== $expectedFromIdentifier) {
                throw WalkabilityConflictException::for($expectedFromIdentifier, $current?->identifier);
            }

            // Lo stato non cambia: non si scrive una riga di storia che dice
            // che non e' successo niente.
            if ($current !== null && $current->id === $to->id) {
                return null;
            }

            $event = TrailRegistryClasses::statusEvent()::create([
                'ec_track_id' => $track->getKey(),
                'taxonomy_id' => $family->id,
                'from_term_id' => $current?->id,
                'to_term_id' => $to->id,
                'description' => $description,
                'user_id' => $userId,
                'source' => $source,
                'created_at' => now(),
            ]);

            // Il legame si sposta dopo l'evento: e' la proiezione della storia,
            // non la sua fonte.
            $track->taxonomyTerms()->detach(
                $family->children()->pluck('taxonomy_terms.id')->all()
            );
            $track->taxonomyTerms()->attach($to->id);
            $track->unsetRelation('taxonomyTerms');

            // Chi deve reagire lo fa da qui: il package non sa chi sia.
            WalkabilityChanged::dispatch($track, $event);

            return $event;
        });
    }

    /** Lo stato di un sentiero dentro una famiglia, se ne ha uno. */
    public function current(EcTrack $track, string $familyIdentifier): ?TaxonomyTerm
    {
        return $this->currentWithin($track, $this->family($familyIdentifier));
    }

    protected function currentWithin(EcTrack $track, TaxonomyTerm $family): ?TaxonomyTerm
    {
        return $track->taxonomyTerms()
            ->where('taxonomy_terms.parent_id', $family->id)
            ->orderBy('taxonomy_terms.id')
            ->first();
    }

    /**
     * La famiglia. Se manca, il dominio e' acceso ma le migration non sono
     * passate: meglio dirlo con un messaggio che nomina il rimedio che lasciare
     * che il dato sparisca in silenzio dalle API.
     */
    protected function family(string $identifier): TaxonomyTerm
    {
        $family = TaxonomyTerm::query()->where('identifier', $identifier)->whereNull('parent_id')->first();

        if ($family === null) {
            throw new InvalidArgumentException(
                "La famiglia «{$identifier}» non esiste: esegui le migration del dominio (`php artisan migrate`), ".
                'che la creano insieme ai suoi termini.'
            );
        }

        return $family;
    }

    protected function termOfFamily(TaxonomyTerm $family, string $identifier): TaxonomyTerm
    {
        $term = TaxonomyTerm::query()
            ->where('identifier', $identifier)
            ->where('parent_id', $family->id)
            ->first();

        if ($term === null) {
            throw new InvalidArgumentException(
                "«{$identifier}» non è uno stato di «{$family->identifier}»."
            );
        }

        return $term;
    }
}
