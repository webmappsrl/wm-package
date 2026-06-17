> Ticket: oc:8089

# Fix UI layer owner: correggere link occhio tracce nella widget LayerFeatures

## Cosa cambia

Il link sull'icona occhio nella widget tracce del layer (`LayerFeatures`) punta all'URL corretto con prefisso `/nova`, letto dinamicamente via `Nova::path()`.

## Perché

Il link era hardcodato come `/resources/<model>/<id>` in `useGrid.ts`, mancando il prefisso `/nova`. Il path Nova viene ora iniettato dalla classe PHP `LayerFeatures` tramite `withMeta` e usato nel componente Vue.

## Requisiti

- [ ] `LayerFeatures.php` passa `novaPath` (via `Nova::path()`) nel `withMeta`
- [ ] `useGrid.ts` usa `props.novaPath` per costruire il link icona occhio
- [ ] Il dist viene ricompilato dopo la modifica

## Rischi

- Il componente Vue deve dichiarare `novaPath` tra le props — verificare che non ci siano altri consumer del componente che non passino questa prop (fallback a `/nova` consigliato).

## Out of scope

- Altri field Nova con lo stesso pattern (`PropertiesPanel`, `ImportController`)

## Moduli toccati

- `src/Nova/Fields/LayerFeatures/src/LayerFeatures.php`
- `src/Nova/Fields/LayerFeatures/resources/js/composables/useGrid.ts`
- `src/Nova/Fields/LayerFeatures/dist/` *(rebuild)*
