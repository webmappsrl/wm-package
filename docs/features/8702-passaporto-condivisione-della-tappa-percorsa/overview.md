> Ticket: oc:8702

# Passaporto: condivisione della tappa percorsa — parte `wm-package`

Documento d'insieme: `webmapp-app/docs/features/8702-passaporto-condivisione-della-tappa-percorsa/overview.md`.
La parte specifica del passaporto sta in `camminiditalia`, nella cartella con lo stesso slug.

## Cosa cambia

`MapRenderService` espone un metodo pubblico generico che disegna più tracciati, ciascuno con il
proprio stile, più dei marker, con un'inquadratura scelta da chi chiama. `render(UgcTrack …)`
diventa un involucro di quel metodo, con un solo tracciato: l'immagine UGC di oggi non cambia.

## Perché

L'immagine di condivisione della tappa del passaporto (in `camminiditalia`) deve mostrare tutto il
cammino e, sopra, la tappa percorsa con uno stile diverso, inquadrata sulla tappa. Oggi l'unico
metodo pubblico è `render(UgcTrack $ugcTrack, App $app, int $width, int $height)`, con un solo
tracciato e un solo stile, e tutti gli altri metodi sono `private`: da fuori non si possono riusare.
Copiare la logica in `camminiditalia` creerebbe due copie dello stesso codice di disegno.

## Requisiti

- [ ] Metodo pubblico nuovo, es. `renderLayers(array $layers, array $focusBbox, App $app, int $width, int $height)`:
      - `$layers`: elenco di tracciati, ciascuno con geometria (LineString o MultiLineString),
        colore, spessore, opacità, ed eventuale bordo;
      - marker opzionali (punto e tipo, es. partenza e arrivo);
      - `$focusBbox`: l'area da inquadrare, a cui si applica il margine esistente.
- [ ] I layer si disegnano nell'ordine dato, così il chiamante decide cosa sta sopra.
- [ ] `render(UgcTrack …)` mantiene firma e risultato: chiama il metodo nuovo con un solo layer e
      lo stile di oggi.
- [ ] Test esistenti di `MapRenderService`, `StoryShareImageService`, `ShareStoryImageController` e
      della pagina pubblica UGC passano senza modifiche.
- [ ] Test nuovi del metodo generico: più layer, ordine di disegno, marker, inquadratura.

## Rischi

- **`wm-package` è condiviso da tutti i backend Webmapp.** Una regressione nel disegno della mappa
  rompe la condivisione UGC ovunque. Mitigazione: i test esistenti come rete, `render(UgcTrack …)`
  invariato.
- **Ordine di rilascio:** il bump di `wm-package` in `camminiditalia` va fatto prima del codice che
  usa il metodo nuovo.

## Out of scope

- Il layout dell'immagine della tappa: sta in `camminiditalia`.
- Modifiche a `StoryShareImageService` e al layout UGC.

## Moduli toccati

- `src/Services/Models/StoryShare/MapRenderService.php`
- `tests/Unit/Services/StoryShare/MapRenderServiceTest.php`
