import { describe, expect, it } from 'vitest';
import { buildLabelFeatures, halfwayCoordinate, signKind } from './trail-sign.mjs';

describe('signKind', () => {
    it('il codice in esame e arancione finche e attivo', () => {
        expect(signKind({ current: true, codeStatus: 'reserved' })).toBe('current');
        expect(signKind({ current: true, codeStatus: 'assigned' })).toBe('current');
    });

    it('il codice in esame liberato e grigio barrato', () => {
        expect(signKind({ current: true, codeStatus: 'released' })).toBe('released');
    });

    it('i vicini seguono il loro stato', () => {
        expect(signKind({ current: false, codeStatus: 'assigned' })).toBe('assigned');
        expect(signKind({ current: false, codeStatus: 'reserved' })).toBe('reserved');
    });

    it('un vicino senza stato e trattato come validato', () => {
        expect(signKind({ current: false })).toBe('assigned');
    });
});

describe('halfwayCoordinate', () => {
    it('sta a meta della lunghezza, non al centro dell estensione', () => {
        // Una L: 10 in orizzontale e 10 in verticale; a meta si e all angolo.
        expect(halfwayCoordinate([[[0, 0], [10, 0], [10, 10]]])).toEqual([10, 0]);
    });

    it('di una MultiLineString usa la parte piu lunga', () => {
        expect(halfwayCoordinate([[[0, 0], [1, 0]], [[100, 0], [120, 0]]])).toEqual([110, 0]);
    });

    it('senza coordinate restituisce null', () => {
        expect(halfwayCoordinate([])).toBeNull();
    });
});

describe('buildLabelFeatures', () => {
    const fake = (props, coords) => ({
        get: (k) => props[k],
        getGeometry: () => ({ getType: () => 'LineString', getCoordinates: () => coords }),
    });

    it('produce il numero del codice in esame anche senza vicini', () => {
        const out = buildLabelFeatures([fake({ current: true, label: '66', codeStatus: 'reserved' }, [[0, 0], [2, 0]])]);
        expect(out.neighbours).toEqual([]);
        expect(out.current).toEqual([{ coordinate: [1, 0], label: '66', kind: 'current' }]);
    });

    it('separa vicini e codice in esame', () => {
        const out = buildLabelFeatures([
            fake({ neighbour: true, label: '61', codeStatus: 'reserved' }, [[0, 0], [2, 0]]),
            fake({ current: true, label: '66', codeStatus: 'reserved' }, [[0, 0], [2, 0]]),
            fake({ tooltip: 'Settore' }, [[0, 0], [2, 0]]),
        ]);
        expect(out.neighbours).toEqual([{ coordinate: [1, 0], label: '61', kind: 'reserved' }]);
        expect(out.current).toEqual([{ coordinate: [1, 0], label: '66', kind: 'current' }]);
    });

    it('salta le feature senza etichetta', () => {
        const out = buildLabelFeatures([fake({ neighbour: true, codeStatus: 'assigned' }, [[0, 0], [2, 0]])]);
        expect(out.neighbours).toEqual([]);
    });
});
