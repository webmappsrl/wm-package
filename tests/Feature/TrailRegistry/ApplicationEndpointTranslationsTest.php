<?php

/**
 * I messaggi che i tre endpoint delle domande rivolgono a chi li chiama
 * (controller, Form Request, regole) hanno una voce in tutte e cinque le
 * lingue del package: senza, chi integra riceve la chiave inglese al posto
 * del testo, o peggio una lingua sì e una no.
 *
 * Le chiavi si leggono dal codice con il tokenizer, come in
 * `TrailRegistryTranslationKeysTest`, e non da un elenco scritto qui: una
 * chiave nuova senza voce fa fallire il test da sola. Lo scope non comprende
 * le etichette di Nova né lo schema dei campi, che hanno un'origine e un
 * ritmo di traduzione propri.
 */
function applicationEndpointLocales(): array
{
    return ['de', 'en', 'es', 'fr', 'it'];
}

/**
 * @return list<string> le chiavi letterali passate a `__()` nei file dello scope
 */
function applicationEndpointKeys(): array
{
    $root = dirname(__DIR__, 3);
    $keys = [];

    foreach (['src/Http/Controllers/Api/TrailRegistry', 'src/Http/Requests/TrailRegistry', 'src/TrailRegistry/Rules', 'src/TrailRegistry/Services'] as $directory) {
        foreach (glob("{$root}/{$directory}/*.php") as $file) {
            $tokens = array_values(array_filter(
                token_get_all(file_get_contents($file)),
                fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
            ));

            foreach ($tokens as $i => $token) {
                if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== '__' || ($tokens[$i + 1] ?? null) !== '(') {
                    continue;
                }

                $previous = $tokens[$i - 1] ?? null;

                if (is_array($previous) && in_array($previous[0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                    continue;
                }

                $argument = $tokens[$i + 2] ?? null;

                if (is_array($argument) && $argument[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $body = substr($argument[1], 1, -1);
                    $keys[] = $argument[1][0] === "'"
                        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body)
                        : stripcslashes($body);
                }
            }
        }
    }

    return array_values(array_unique($keys));
}

it('legge dal codice almeno i messaggi degli endpoint', function () {
    expect(applicationEndpointKeys())->not->toBeEmpty();
});

it('ha una voce, in tutte e cinque le lingue, per ogni messaggio degli endpoint delle domande', function () {
    $missing = [];

    foreach (applicationEndpointLocales() as $locale) {
        $voci = json_decode(file_get_contents(dirname(__DIR__, 3)."/resources/lang/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);

        foreach (applicationEndpointKeys() as $key) {
            if (! isset($voci[$key]) || trim($voci[$key]) === '') {
                $missing[] = "{$locale}: «{$key}»";
            }
        }
    }

    expect($missing)->toBe([]);
});
