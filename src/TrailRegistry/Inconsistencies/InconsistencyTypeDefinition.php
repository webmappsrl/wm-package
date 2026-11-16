<?php

namespace Wm\WmPackage\TrailRegistry\Inconsistencies;

/**
 * Un tipo di incongruenza catastale dichiarato da uno shard (oc:8989).
 *
 * **Un'incongruenza non e' un'anomalia.** Un'anomalia riguarda il codice del
 * sentiero, e il dato lo possiede la piattaforma di origine: si corregge alla
 * fonte e la riga sparisce al giro di import successivo. Un'incongruenza e'
 * due informazioni che il catasto pubblica sullo stesso sentiero e che insieme
 * non stanno in piedi: si risolve qui dentro, inserendo il dato che manca.
 *
 * La differenza non e' leggibile dal codice — le due famiglie hanno la stessa
 * forma — quindi sta scritta qui: prima di dichiarare un tipo, chiediti dove si
 * risolve.
 */
interface InconsistencyTypeDefinition
{
    /** Il nome che si legge nel backoffice, tradotto. */
    public function label(): string;

    /** Cosa non torna, in una frase: la legge chi deve decidere se intervenire. */
    public function description(): string;
}
