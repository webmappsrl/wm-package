import { describe, expect, it } from 'vitest';
import { expandExtent, featuresForFit } from './utils.mjs';

const line = (props = {}) => ({ geometry: { type: 'MultiLineString' }, properties: props });
const point = (props = {}) => ({ geometry: { type: 'Point' }, properties: props });

describe('featuresForFit', () => {
    it('ignora le feature di contesto', () => {
        const track = line();
        const context = line({ context: true });
        expect(featuresForFit([track, context])).toEqual([track]);
    });

    it('preferisce le linee, come prima', () => {
        const track = line();
        expect(featuresForFit([point(), track])).toEqual([track]);
        const p = point();
        expect(featuresForFit([p])).toEqual([p]);
    });

    it('con sole feature di contesto inquadra quelle', () => {
        const context = line({ context: true });
        expect(featuresForFit([context])).toEqual([context]);
    });

    it('legge context anche da feature OpenLayers', () => {
        const olFeature = (context) => ({
            get: (k) => (k === 'context' ? context : undefined),
            getGeometry: () => ({ getType: () => 'LineString' }),
        });
        const track = olFeature(undefined);
        expect(featuresForFit([track, olFeature(true)])).toEqual([track]);
    });

    it('input non array: lista vuota', () => {
        expect(featuresForFit(null)).toEqual([]);
    });
});

describe('expandExtent', () => {
    it('allarga di ratio per lato', () => {
        expect(expandExtent([0, 0, 10, 20], 0.3)).toEqual([-3, -6, 13, 26]);
    });

    it('ratio 0 o non valido: invariato', () => {
        const e = [0, 0, 10, 20];
        expect(expandExtent(e, 0)).toBe(e);
        expect(expandExtent(e, -1)).toBe(e);
        expect(expandExtent(e, 'x')).toBe(e);
        expect(expandExtent(null, 0.3)).toBe(null);
    });
});
