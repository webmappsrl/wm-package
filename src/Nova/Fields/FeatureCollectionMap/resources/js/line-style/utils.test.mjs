import { describe, expect, it } from 'vitest';
import { LINE_LABEL_FALLBACK_COLOR, lineLabelFeatures, lineStyleSpec, lineStyles } from './utils.mjs';

const line = (properties) => ({ type: 'Feature', geometry: { type: 'LineString', coordinates: [[0, 0], [1, 1]] }, properties });

describe('lineStyleSpec', () => {
    it('senza proprietà: stile di prima', () => {
        expect(lineStyleSpec({})).toEqual({
            strokeColor: 'rgba(0, 0, 255, 1)',
            strokeWidth: 3,
            lineDash: undefined,
            fillColor: 'rgba(0, 0, 255, 0.3)',
            zIndex: undefined,
        });
        expect(lineStyleSpec(null).strokeWidth).toBe(3);
    });

    it('le feature di contesto vanno nel gruppo sotto', () => {
        expect(lineStyleSpec({ context: true }).zIndex).toBe(-1);
        expect(lineStyleSpec({ context: 'true' }).zIndex).toBeUndefined();
    });

    it('rispetta colore, spessore e tratteggio', () => {
        const s = lineStyleSpec({ strokeColor: 'red', strokeWidth: 4, strokeDash: [8, 8] });
        expect([s.strokeColor, s.strokeWidth, s.lineDash]).toEqual(['red', 4, [8, 8]]);
    });
});

describe('lineLabelFeatures', () => {
    it('una feature solo-etichetta per ogni linea con lineLabel, nel colore della linea', () => {
        const withLabel = line({ lineLabel: ' Tappa 05 ', strokeColor: 'rgba(220, 38, 38, 0.9)', tooltip: 'x', slopeChart: true });
        const out = lineLabelFeatures({ features: [withLabel, line({ tooltip: 'senza' })] });

        expect(out).toEqual([{
            type: 'Feature',
            geometry: withLabel.geometry,
            properties: { labelText: 'Tappa 05', labelColor: 'rgba(220, 38, 38, 0.9)' },
        }]);
    });

    it('senza strokeColor usa il colore scuro di riserva', () => {
        expect(lineLabelFeatures({ features: [line({ lineLabel: 'A' })] })[0].properties.labelColor)
            .toBe(LINE_LABEL_FALLBACK_COLOR);
    });

    it('niente etichetta per lineLabel vuota o non stringa, per i punti e per `label`', () => {
        const point = { type: 'Feature', geometry: { type: 'Point', coordinates: [0, 0] }, properties: { lineLabel: 'P' } };
        expect(lineLabelFeatures({ features: [
            line({ lineLabel: '' }), line({ lineLabel: '  ' }), line({ lineLabel: 3 }), line({ label: 'catasto' }), point,
        ] })).toEqual([]);
        expect(lineLabelFeatures(null)).toEqual([]);
    });
});

describe('lineStyles', () => {
    it('senza zoomedOutStyle: un solo stile, identico a lineStyleSpec', () => {
        for (const props of [{}, { strokeWidth: 4, strokeDash: [8, 8] }, { context: true }]) {
            expect(lineStyles(props, 100)).toEqual([lineStyleSpec(props)]);
            expect(lineStyles(props, 0.1)).toEqual([lineStyleSpec(props)]);
        }
        expect(lineStyles(null, 10)).toEqual([lineStyleSpec({})]);
    });

    it('sotto la soglia usa lo stile normale', () => {
        const props = { strokeWidth: 3, zoomedOutStyle: { minResolution: 5, strokeWidth: 6 } };
        expect(lineStyles(props, 4.99)).toEqual([lineStyleSpec(props)]);
    });

    it('casing: contorno sotto la linea, a ogni zoom, senza tratteggio', () => {
        const props = { strokeColor: 'blue', strokeWidth: 2, strokeDash: [8, 8], casing: { color: 'white', width: 3 } };
        for (const resolution of [0.1, 100]) {
            const [casing, line] = lineStyles(props, resolution);
            expect(casing).toEqual({ ...lineStyleSpec(props), strokeColor: 'white', strokeWidth: 3, lineDash: undefined });
            expect(line).toEqual(lineStyleSpec(props));
        }
    });

    it('sopra la soglia: colore, spessore e gruppo di zoomedOutStyle', () => {
        const props = {
            strokeColor: 'rgba(1, 2, 3, 0.9)',
            context: true,
            zoomedOutStyle: { minResolution: 5, strokeColor: 'rgba(1, 2, 3, 1)', strokeWidth: 16, zIndex: -4 },
        };
        expect(lineStyles(props, 6)).toEqual([
            { ...lineStyleSpec(props), strokeColor: 'rgba(1, 2, 3, 1)', strokeWidth: 16, zIndex: -4, lineDash: undefined },
        ]);
    });

    it('dashColors: segmenti della stessa lunghezza, un colore dopo l\'altro', () => {
        const props = { zoomedOutStyle: { minResolution: 5, strokeWidth: 6, zIndex: -3, dashColors: ['a', 'b', 'c'], dashLength: 8 } };
        const styles = lineStyles(props, 5);
        expect(styles.map((s) => [s.strokeColor, s.lineDash, s.lineDashOffset, s.strokeWidth, s.zIndex, s.lineCap])).toEqual([
            ['a', [8, 16], 0, 6, -3, 'butt'],
            ['b', [8, 16], 16, 6, -3, 'butt'],
            ['c', [8, 16], 8, 6, -3, 'butt'],
        ]);
        // Sotto la soglia: lo stile di sempre.
        expect(lineStyles(props, 1)).toEqual([lineStyleSpec(props)]);
    });

    it('dashColors con un colore solo: linea piena di quel colore', () => {
        const props = { zoomedOutStyle: { minResolution: 5, dashColors: ['a'], dashLength: 8 } };
        expect(lineStyles(props, 5)).toEqual([{ ...lineStyleSpec(props), strokeColor: 'a', lineDash: undefined }]);
    });

    it('onlyZoomedOut: nessuno stile sotto la soglia, uno sopra', () => {
        const props = { strokeWidth: 10, onlyZoomedOut: true, zoomedOutStyle: { minResolution: 5, zIndex: -1 } };
        expect(lineStyles(props, 1)).toEqual([]);
        expect(lineStyles(props, 5)).toHaveLength(1);
        expect(lineStyles({ onlyZoomedOut: true }, 100)).toEqual([]);
    });

    it('zoomedOutStyle malformato: stile normale', () => {
        expect(lineStyles({ zoomedOutStyle: { minResolution: '5' } }, 10)).toEqual([lineStyleSpec({})]);
        expect(lineStyles({ zoomedOutStyle: 'x' }, 10)).toEqual([lineStyleSpec({})]);
    });
});
