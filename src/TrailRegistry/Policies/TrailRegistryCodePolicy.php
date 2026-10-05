<?php

namespace Wm\WmPackage\TrailRegistry\Policies;

/**
 * Codici in sola lettura da Nova: li scrivono il service e l'import. Le regole
 * sono tutte nella base; la classe esiste solo per la registrazione per
 * modello (vedi TrailRegistryPolicy).
 */
class TrailRegistryCodePolicy extends TrailRegistryPolicy {}
