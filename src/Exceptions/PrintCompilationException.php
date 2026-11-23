<?php

namespace Wm\WmPackage\Exceptions;

use Exception;

/**
 * Errore di compilazione della scheda PDF (errore LaTeX, timeout, memoria, immagine non allineata).
 */
class PrintCompilationException extends Exception {}
