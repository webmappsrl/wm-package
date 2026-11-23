<?php

namespace Wm\WmPackage\Services\Print\Contracts;

/**
 * Modello di cui si può stampare la scheda PDF (oc:8982). L'implementazione di default è il trait
 * `Wm\WmPackage\Traits\Printable`: un trait non si può usare come tipo, l'interfaccia sì.
 */
interface PrintableModel
{
    public function printView(): string;

    /** @return array<string,mixed> */
    public function printViewData(string $locale): array;

    public function printFileName(string $locale): string;

    /** @return list<string> */
    public function printPhotoUrls(): array;

    public function printTranslation(string $key, string $locale): string;
}
