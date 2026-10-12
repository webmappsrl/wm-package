/**
 * Inquadratura iniziale della mappa (oc:8747).
 *
 * Le feature con `context: true` (es. i percorsi dell'App attorno a una traccia UGC) sono solo
 * sfondo: il fit le ignora, così la mappa inquadra la traccia e non tutto il contesto. Se ci sono
 * solo feature di contesto si inquadrano quelle. Fra le restanti, come prima, si preferiscono le
 * linee.
 *
 * Accetta feature OpenLayers (`get('context')`) o oggetti GeoJSON (`properties.context`).
 *
 * @param {Array<any>} features
 * @returns {Array<any>}
 */
export function featuresForFit(features) {
    if (!Array.isArray(features)) {
        return [];
    }
    const own = features.filter((f) => !isContextFeature(f));
    const base = own.length > 0 ? own : features;
    const lines = base.filter((f) => {
        const type = geometryType(f);
        return type === 'LineString' || type === 'MultiLineString';
    });

    return lines.length > 0 ? lines : base;
}

/**
 * Allarga un extent [minX, minY, maxX, maxY] di `ratio` per lato, in proporzione a larghezza e
 * altezza (0.3 = 30% per lato). Ratio 0, negativo o non numerico: extent invariato.
 *
 * @param {number[]} extent
 * @param {number} ratio
 * @returns {number[]}
 */
export function expandExtent(extent, ratio) {
    const r = Number(ratio);
    if (!Array.isArray(extent) || extent.length < 4 || !Number.isFinite(r) || r <= 0) {
        return extent;
    }
    const dx = (extent[2] - extent[0]) * r;
    const dy = (extent[3] - extent[1]) * r;

    return [extent[0] - dx, extent[1] - dy, extent[2] + dx, extent[3] + dy];
}

function isContextFeature(f) {
    const value = typeof f?.get === 'function' ? f.get('context') : f?.properties?.context;

    return value === true;
}

function geometryType(f) {
    if (typeof f?.getGeometry === 'function') {
        return f.getGeometry()?.getType?.() ?? null;
    }

    return f?.geometry?.type ?? null;
}
