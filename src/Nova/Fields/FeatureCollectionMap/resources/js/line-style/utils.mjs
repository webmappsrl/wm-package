/**
 * Decisioni di stile per le linee della mappa, senza OpenLayers (testabili).
 */

/** Colore di riserva del testo di un'etichetta senza strokeColor. */
export const LINE_LABEL_FALLBACK_COLOR = 'rgba(51, 51, 51, 1)';

/**
 * Stile di linee e poligoni.
 * - `context: true` (oc:8747): feature di sfondo, disegnata in un gruppo sotto le altre
 *   (zIndex -1), così la traccia principale resta sopra anche dove si sovrappongono.
 *
 * @param {Record<string, any>} props
 * @returns {{strokeColor: string, strokeWidth: number, lineDash: number[]|undefined, fillColor: string, zIndex: number|undefined}}
 */
export function lineStyleSpec(props) {
    const p = props && typeof props === 'object' ? props : {};

    return {
        strokeColor: p.strokeColor || 'rgba(0, 0, 255, 1)',
        strokeWidth: p.strokeWidth || 3,
        // Tratteggio opzionale, es. [8, 8] per i tratti ricostruiti delle tracce UGC (oc:8719).
        lineDash: Array.isArray(p.strokeDash) ? p.strokeDash : undefined,
        fillColor: p.fillColor || 'rgba(0, 0, 255, 0.3)',
        zIndex: p.context === true ? -1 : undefined,
    };
}

/**
 * Feature solo-etichetta (oc:8747) per le linee con `properties.lineLabel` non vuota: stessa
 * geometria, nessun'altra proprietà (niente tooltip, link, slopeChart), testo nel colore della
 * linea. Vanno su un layer separato con declutter, escluso dalla ricerca delle feature sotto
 * il mouse: le etichette che si sovrappongono si scartano invece di ammucchiarsi, e i tooltip
 * delle linee restano quelli di prima (build.md).
 * Si usa `lineLabel` e non `label` perché `label` ha già un altro significato nelle mappe del
 * catasto sentieri, che riusano questo componente.
 *
 * @param {{features?: Array<any>}|null} geojson FeatureCollection GeoJSON
 * @returns {Array<{type: 'Feature', geometry: any, properties: {labelText: string, labelColor: string}}>}
 */
export function lineLabelFeatures(geojson) {
    const features = Array.isArray(geojson?.features) ? geojson.features : [];

    return features
        .filter((f) => {
            const type = f?.geometry?.type;
            const text = f?.properties?.lineLabel;
            return (type === 'LineString' || type === 'MultiLineString')
                && typeof text === 'string' && text.trim() !== '';
        })
        .map((f) => ({
            type: 'Feature',
            geometry: f.geometry,
            properties: {
                labelText: f.properties.lineLabel.trim(),
                labelColor: f.properties.strokeColor || LINE_LABEL_FALLBACK_COLOR,
            },
        }));
}
