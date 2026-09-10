<?php

namespace Wm\WmPackage\TrailRegistry\Enums;

/**
 * Stato dell'istruttoria di un'istanza.
 *
 * Tre valori e non cinque: «presentata» non è raggiungibile (un'istanza che
 * non passa la prevalidazione non viene scritta) e «prevalidata» è il fatto
 * di avere una riga nel registro, che nasce solo da reserve().
 */
enum TrailApplicationStatus: string
{
    case UnderReview = 'under_review';
    case Rejected = 'rejected';
    case Approved = 'approved';
}
