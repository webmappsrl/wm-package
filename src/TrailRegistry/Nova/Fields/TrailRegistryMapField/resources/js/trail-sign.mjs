/**
 * I segnavia della mappa del catasto (oc:8662): il numero di un sentiero
 * disegnato come la bandierina CAI — banda, fascia bianca con il numero,
 * banda — orizzontale e posato a meta' del tracciato, invece del testo
 * piegato lungo la linea.
 *
 * Qui stanno solo funzioni pure e il disegno su canvas: niente OpenLayers,
 * cosi' si testano senza mappa. I colori sono gli stessi della legenda
 * (`MapLegendRenderer::SIGNS`) e vanno cambiati insieme.
 */

export const SIGN_STYLES = {
    // Sentiero validato: bande rosse piene.
    assigned: { band: 'rgba(220, 38, 38, 1)', border: 'rgba(220, 38, 38, 1)', text: 'rgba(15, 23, 42, 1)', strike: false },
    // Proposto da un'altra istanza: stessa forma, bande vuote.
    reserved: { band: null, border: 'rgba(220, 38, 38, 1)', text: 'rgba(15, 23, 42, 1)', strike: false },
    // Codice in esame: il colore del tracciato dell'istanza.
    current: { band: 'rgba(234, 88, 12, 1)', border: 'rgba(234, 88, 12, 1)', text: 'rgba(15, 23, 42, 1)', strike: false },
    // Codice in esame liberato: non appartiene piu' a nessuno.
    released: { band: 'rgba(148, 163, 184, 1)', border: 'rgba(148, 163, 184, 1)', text: 'rgba(100, 116, 139, 1)', strike: true },
};

export function signKind({ current, codeStatus }) {
    if (current) {
        return codeStatus === 'released' ? 'released' : 'current';
    }

    return codeStatus === 'reserved' ? 'reserved' : 'assigned';
}

function length(line) {
    let total = 0;

    for (let i = 1; i < line.length; i++) {
        total += Math.hypot(line[i][0] - line[i - 1][0], line[i][1] - line[i - 1][1]);
    }

    return total;
}

/**
 * Il punto a meta' della lunghezza della parte piu' lunga. Non il centro
 * dell'estensione: su un sentiero a U quello cade fuori dal sentiero.
 */
export function halfwayCoordinate(lines) {
    const candidates = (lines || []).filter((l) => Array.isArray(l) && l.length > 0);

    if (candidates.length === 0) {
        return null;
    }

    const line = candidates.reduce((a, b) => (length(b) > length(a) ? b : a));
    let remaining = length(line) / 2;

    for (let i = 1; i < line.length; i++) {
        const [x0, y0] = line[i - 1];
        const [x1, y1] = line[i];
        const step = Math.hypot(x1 - x0, y1 - y0);

        if (step >= remaining && step > 0) {
            const t = remaining / step;

            return [x0 + (x1 - x0) * t, y0 + (y1 - y0) * t];
        }

        remaining -= step;
    }

    return [line[0][0], line[0][1]];
}

function linesOf(geometry) {
    const type = geometry?.getType?.();

    if (type === 'LineString') {
        return [geometry.getCoordinates()];
    }

    if (type === 'MultiLineString') {
        return geometry.getCoordinates();
    }

    return [];
}

/**
 * Dalle feature caricate, i descrittori delle etichette: i vicini da una
 * parte, il codice in esame dall'altra — quest'ultimo anche quando i vicini
 * non ci sono, che e' il caso della prima istanza di un settore.
 */
export function buildLabelFeatures(features) {
    const out = { neighbours: [], current: [] };

    for (const f of features || []) {
        const label = f.get('label');
        const isCurrent = f.get('current') === true;
        const isNeighbour = f.get('neighbour') === true;

        if (!label || (!isCurrent && !isNeighbour)) {
            continue;
        }

        const coordinate = halfwayCoordinate(linesOf(f.getGeometry()));

        if (!coordinate) {
            continue;
        }

        const item = { coordinate, label, kind: signKind({ current: isCurrent, codeStatus: f.get('codeStatus') }) };

        (isCurrent ? out.current : out.neighbours).push(item);
    }

    return out;
}

/**
 * Disegna il segnavia su un canvas, alla densita' dello schermo (`ratio`).
 */
export function paintSign(doc, label, kind, ratio = 1) {
    const style = SIGN_STYLES[kind] || SIGN_STYLES.assigned;
    const font = 'bold 12px sans-serif';
    const band = 5;
    const middle = 16;
    const padX = 5;

    const measure = doc.createElement('canvas').getContext('2d');
    measure.font = font;

    const width = Math.ceil(measure.measureText(label).width) + padX * 2;
    const height = band * 2 + middle;

    const canvas = doc.createElement('canvas');
    canvas.width = Math.ceil((width + 2) * ratio);
    canvas.height = Math.ceil((height + 2) * ratio);

    const ctx = canvas.getContext('2d');
    ctx.scale(ratio, ratio);
    ctx.translate(1, 1);

    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, width, height);

    if (style.band) {
        ctx.fillStyle = style.band;
        ctx.fillRect(0, 0, width, band);
        ctx.fillRect(0, height - band, width, band);
    }

    ctx.strokeStyle = style.border;
    ctx.lineWidth = 1.5;
    ctx.strokeRect(0, 0, width, height);

    ctx.fillStyle = style.text;
    ctx.font = font;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(label, width / 2, height / 2 + 0.5);

    if (style.strike) {
        ctx.strokeStyle = style.border;
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(1, height - 1);
        ctx.lineTo(width - 1, 1);
        ctx.stroke();
    }

    return canvas;
}
