<?php

namespace Wm\WmPackage\TrailRegistry\Policies;

/**
 * Anomalie in sola lettura da Nova: le calcola il service. Le regole sono
 * tutte nella base; la classe esiste solo per la registrazione per modello
 * (vedi TrailRegistryPolicy).
 */
class TrailRegistryAnomalyPolicy extends TrailRegistryPolicy {}
