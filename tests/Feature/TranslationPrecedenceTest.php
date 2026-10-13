<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Le voci JSON dello shard devono vincere su quelle del package a parità di chiave
 * (docs/knowledge/8672-chiavi-di-traduzione-...). oc:8247 aveva registrato la cartella
 * `resources/lang` del package anche come path PHP senza namespace: Laravel la rileggeva in
 * `FileLoader::loadJsonPaths()` dopo la `lang/` dello shard e il testo del package vinceva (oc:8773).
 */
function reloadTranslatorWithLangPath(string $langPath): void
{
    app()->useLangPath($langPath);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');
}

beforeEach(function () {
    $this->originalLangPath = app()->langPath();
    $this->tmpLangPath = sys_get_temp_dir().'/wm-translation-precedence-'.uniqid();
    File::ensureDirectoryExists($this->tmpLangPath);
});

afterEach(function () {
    File::deleteDirectory($this->tmpLangPath);
    reloadTranslatorWithLangPath($this->originalLangPath);
});

it('lets the consumer app JSON override a package JSON key', function () {
    File::put($this->tmpLangPath.'/it.json', json_encode([
        'EcTracks crossed outside any layer' => 'Tappe attraversate fuori da ogni cammino',
    ]));
    reloadTranslatorWithLangPath($this->tmpLangPath);
    app()->setLocale('it');

    expect(__('EcTracks crossed outside any layer'))->toBe('Tappe attraversate fuori da ogni cammino')
        // chiavi non sovrascritte dallo shard: resta la voce del package
        ->and(__('Layers crossed by the track'))->toBe('Layer attraversati dalla traccia');
});

it('keeps resolving the package validation messages of oc:8247 in every locale', function () {
    reloadTranslatorWithLangPath($this->tmpLangPath);

    app()->setLocale('it');
    expect(__('validation.custom.icon.dimensions'))->toBe("L'icona deve essere almeno 1024×1024px e quadrata (proporzioni 1:1).")
        ->and(__('validation.custom.splash.dimensions'))->toBe('Lo splash screen deve essere almeno 2732×2732px e quadrato (proporzioni 1:1).');

    app()->setLocale('en');
    expect(__('validation.custom.icon.dimensions'))->toBe('The icon must be at least 1024×1024px and square (1:1 ratio).')
        ->and(__('validation.custom.splash.dimensions'))->toBe('The splash screen must be at least 2732×2732px and square (1:1 ratio).');
});
