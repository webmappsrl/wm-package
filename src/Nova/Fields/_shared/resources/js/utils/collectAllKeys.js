/**
 * Unisce le chiavi presenti in una o più lingue in un'unica lista ordinata,
 * senza duplicati. Condivisa tra i componenti Form/Detail/Index dei Nova
 * Field custom che gestiscono valori chiave-valore per lingua (es.
 * TranslationsBuilder), così la logica di aggregazione vive in un solo posto.
 *
 * @param {string[]} langs - lingue configurate, es. ['it', 'en']
 * @param {Object<string, Object<string, unknown>>} valuesByLang - valori per lingua, es. { it: {...}, en: {...} }
 * @returns {string[]} chiavi uniche, ordinate alfabeticamente
 */
export function collectAllKeys(langs, valuesByLang) {
  const keys = new Set()
  langs.forEach(lang => {
    Object.keys(valuesByLang[lang] || {}).forEach(key => keys.add(key))
  })
  return Array.from(keys).sort()
}
