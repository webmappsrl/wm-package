<?php

namespace Wm\WmPackage\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Wm\WmPackage\Services\StorageService;

class GlobalFileController extends Controller
{
    protected StorageService $storageService;

    public function __construct(StorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    /**
     * Mostra la pagina di upload per un tipo specifico di file
     */
    public function show()
    {
        // Verifica che l'utente sia autenticato
        $this->middleware('auth');

        // Ottieni parametri dalle routes (obbligatori)
        $fileType = request()->route('fileType');
        $filename = request()->route('filename');

        // Verifica che i parametri siano stati passati
        if (! $fileType || ! $filename) {
            abort(500, 'Parametri fileType e filename obbligatori nelle routes');
        }

        // Lista dei file esistenti per il tipo specificato
        $existingFiles = [];
        $filePath = $this->getFilePathByType($fileType);

        try {
            // Verifica se la cartella esiste, se non esiste la crea
            if (! Storage::disk('wmfe')->exists($filePath)) {
                // Crea la cartella se non esiste
                Storage::disk('wmfe')->makeDirectory($filePath);
                Log::info('Cartella file globali creata', ['filePath' => $filePath]);
            }

            $files = Storage::disk('wmfe')->files($filePath);
            foreach ($files as $file) {
                $fileName = basename($file);
                // Filtra solo i file che corrispondono al tipo specificato
                if (str_contains($fileName, $fileType) || $fileName === $filename) {
                    $existingFiles[] = [
                        'name' => $fileName,
                        'size' => Storage::disk('wmfe')->size($file),
                        'modified' => Storage::disk('wmfe')->lastModified($file),
                        'path' => $file,
                    ];
                }
            }
        } catch (\Exception $e) {
            // Se non riesce a creare la cartella o accedere ai file, continua con una lista vuota
            Log::warning('Impossibile accedere alla cartella dei file globali', [
                'filePath' => $filePath,
                'error' => $e->getMessage(),
            ]);
        }

        return view('wm-package::global-file-upload', compact('existingFiles', 'fileType', 'filename'));
    }

    /**
     * Ottiene il percorso base per i file JSON
     */
    private function getFilePathByType(string $fileType): string
    {
        return $this->storageService->getShardBasePath().'json/';
    }

    /**
     * Gestisce l'upload di file per un tipo specifico
     */
    public function upload(Request $request)
    {
        // Verifica che l'utente sia autenticato
        $this->middleware('auth');

        $request->validate([
            'json_file' => 'required|file|mimes:json|max:10240', // 10MB max
        ]);

        try {
            $file = $request->file('json_file');

            // Usa il nome file definito nella route
            $fileType = request()->route('fileType');
            $filename = request()->route('filename');

            // Verifica che i parametri siano stati passati
            if (! $fileType || ! $filename) {
                abort(500, 'Parametri fileType e filename obbligatori nelle routes');
            }
            $filePath = $this->getFilePathByType($fileType);
            $path = $file->storeAs($filePath, $filename, 'wmfe');

            Log::info('File caricato con successo', [
                'user_id' => Auth::user()->id,
                'filename' => $filename,
                'file_type' => $fileType,
                'path' => $path,
            ]);

            // Redirect dinamico in base al tipo
            $routeName = $fileType.'.upload.show';

            return redirect()->route($routeName)
                ->with('success', 'File salvato come: '.$filename);

        } catch (\Exception $e) {
            $fileType = request()->route('fileType');
            Log::error('Errore durante il caricamento del file', [
                'user_id' => Auth::user()->id,
                'file_type' => $fileType,
                'error' => $e->getMessage(),
            ]);

            // Redirect dinamico in base al tipo
            $routeName = $fileType.'.upload.show';

            return redirect()->route($routeName)
                ->with('error', 'Errore durante il caricamento: '.$e->getMessage());
        }
    }

    /**
     * Scarica un file esistente
     */
    public function download($filename)
    {
        $this->middleware('auth');

        $fileType = request()->route('fileType');
        $filePath = $this->getFilePathByType($fileType).$filename;

        if (! Storage::disk('wmfe')->exists($filePath)) {
            abort(404, 'File non trovato');
        }

        return Storage::disk('wmfe')->download($filePath);
    }

    /**
     * Visualizza un file JSON nel browser
     */
    public function view($filename)
    {
        $this->middleware('auth');

        $fileType = request()->route('fileType');
        $filePath = $this->getFilePathByType($fileType).$filename;

        if (! Storage::disk('wmfe')->exists($filePath)) {
            abort(404, 'File non trovato');
        }

        $content = Storage::disk('wmfe')->get($filePath);
        $jsonData = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            abort(400, 'File JSON non valido');
        }

        return response()->json($jsonData, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Elimina un'icona esistente
     */
    public function delete($filename)
    {
        $this->middleware('auth');

        $fileType = request()->route('fileType');
        $filePath = $this->getFilePathByType($fileType).$filename;

        try {
            if (Storage::disk('wmfe')->exists($filePath)) {
                Storage::disk('wmfe')->delete($filePath);
            }

            Log::info('File eliminato', [
                'user_id' => Auth::user()->id,
                'filename' => $filename,
                'file_type' => $fileType,
            ]);

            // Redirect dinamico in base al tipo
            $routeName = $fileType.'.upload.show';

            return redirect()->route($routeName)
                ->with('success', 'File eliminato con successo');

        } catch (\Exception $e) {
            Log::error('Errore durante l\'eliminazione del file', [
                'user_id' => Auth::user()->id,
                'filename' => $filename,
                'file_type' => $fileType,
                'error' => $e->getMessage(),
            ]);

            // Redirect dinamico in base al tipo
            $routeName = $fileType.'.upload.show';

            return redirect()->route($routeName)
                ->with('error', 'Errore durante l\'eliminazione: '.$e->getMessage());
        }
    }
}
