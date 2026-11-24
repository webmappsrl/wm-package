<?php

use Illuminate\Support\Facades\Route;
use Wm\WmPackage\Http\Controllers\PrintController;

// Rotte della stampa PDF delle schede (oc:8982), caricate sempre da WmPackageServiceProvider. Rispondono
// 404 per gli elementi di un'App senza `properties.ec_pdf_enabled` (PrintService::enabledFor()).
// Il pulsante «Stampa» del sito è un semplice link a `print.show`.
// Le tre rotte di stato hanno un throttle proprio (`print-status`, 60/min): la pagina d'attesa le
// interroga ogni 5 s, e con il contatore senza prefisso condiviso con `show` e con le altre rotte
// dell'app esaurirebbero il limite.
Route::middleware('web')
    ->prefix('print')
    ->whereIn('type', PrintController::TYPES)
    ->group(function () {
        Route::get('/{type}/{id}', [PrintController::class, 'show'])->middleware('throttle:30,1')->name('print.show');
        Route::get('/{type}/{id}/status', [PrintController::class, 'status'])->middleware('throttle:60,1,print-status')->name('print.status');
    });

// ZIP di più schede. Il throttle di `zip` ha un prefisso proprio: senza, il contatore sarebbe
// condiviso con le interrogazioni dello stato e le esaurirebbe.
Route::middleware(['web', 'throttle:5,1,print-zip'])->get('/print/zip', [PrintController::class, 'zip'])->name('print.zip');
Route::middleware(['web', 'throttle:60,1,print-status'])->get('/print/zip/status', [PrintController::class, 'zipStatus'])->name('print.zip.status');

// Mappa d'insieme di più tracce (A4 orizzontale). Stesso schema dello ZIP: throttle proprio per la generazione.
Route::middleware(['web', 'throttle:5,1,print-overview'])->get('/print/overview-map', [PrintController::class, 'overviewMap'])->name('print.overview-map');
Route::middleware(['web', 'throttle:60,1,print-status'])->get('/print/overview-map/status', [PrintController::class, 'overviewMapStatus'])->name('print.overview-map.status');
