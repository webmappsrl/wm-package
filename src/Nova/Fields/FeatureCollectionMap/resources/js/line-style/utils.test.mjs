import { describe, expect, it } from 'vitest';
import { LINE_LABEL_FALLBACK_COLOR, lineLabelFeatures, lineStyleSpec } from './utils.mjs';

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
