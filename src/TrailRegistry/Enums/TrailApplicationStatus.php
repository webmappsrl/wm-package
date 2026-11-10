<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Stato di un'istanza di accatastamento.
 *
 * Quattro valori. «Presentata» e «prevalidata» continuano a non esistere: la
 * prima non e' raggiungibile — un'istanza che non passa la prevalidazione non
 * viene scritta — e la seconda e' il fatto di avere una riga nel registro, che
 * nasce solo da reserve().
 *
 * `UnderCompilation` e' la domanda che lo sportello ha creato e non ha ancora
 * finito di consegnare: l'istanza esiste e il suo numero e' gia' riservato, ma
 * gli allegati non sono tutti arrivati. Non e' una fase del procedimento
 * amministrativo, che vive altrove: e' lo stato della consegna, e serve perche'
 * l'operatore non istruisca una pratica a cui mancano meta' dei documenti
 * credendola completa (oc:8960).
 */
enum TrailApplicationStatus: string
{
    case UnderCompilation = 'under_compilation';
    case UnderReview = 'under_review';
    case Rejected = 'rejected';
    case Approved = 'approved';

    /**
     * Il testo che legge l'operatore. Sta qui e non nei punti che lo stampano —
     * il campo della Resource e le opzioni del filtro — perche' il prossimo
     * valore dell'enum lo erediti senza che nessuno debba ricordarsi di due
     * posti distinti (oc:8960).
     */
    public function label(): string
    {
        return match ($this) {
            self::UnderCompilation => __('Under compilation'),
            self::UnderReview => __('Under review'),
            self::Rejected => __('Rejected'),
            self::Approved => __('Approved'),
        };
    }
}
