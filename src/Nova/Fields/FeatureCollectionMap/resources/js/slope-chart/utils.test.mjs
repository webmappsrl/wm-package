import { describe, expect, it } from 'vitest';
import { STEPS, computeSlopeChartData, findExplicitSlopeChartFeature, getSlopeChartTrackFromGeojson, haversineMeters, isTrack3d } from './utils.mjs';

describe('SlopeChart computeSlopeChartData', () => {
    it('considera 3D solo se esiste almeno una z != 0', () => {
        expect(isTrack3d([[0, 0], [1, 1]])).toBe(false);
        expect(isTrack3d([[0, 0, 0], [1, 1, 0]])).toBe(false);
        expect(isTrack3d([[0, 0, 0], [1, 1, 12]])).toBe(true);
    });

    it('se non 3D ritorna is3d=false e niente campioni', () => {
        const track = {
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: [[0, 0], [0, 0.001]] },
            properties: {},
        };
        const r = computeSlopeChartData(track);
        expect(r.is3d).toBe(false);
        expect(r.altitudes).toHaveLength(0);
        expect(r.slopes).toHaveLength(0);
        expect(r.locations).toHaveLength(0);
        expect(r.labelsKm).toHaveLength(0);
    });

    it('campiona sempre STEPS+1 punti (inclusi inizio e fine)', () => {
        const track = {
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: [[0, 0, 10], [0, 0.001, 20]] },
            properties: {},
        };
        const r = computeSlopeChartData(track);
        expect(r.is3d).toBe(true);
        expect(r.altitudes).toHaveLength(STEPS + 1);
        expect(r.slopes).toHaveLength(STEPS + 1);
        expect(r.locations).toHaveLength(STEPS + 1);
        expect(r.labelsKm).toHaveLength(STEPS + 1);
    });

    it('trackLength coincide con haversine su 2 punti (floating safe)', () => {
        const a = [0, 0, 10];
        const b = [0, 0.001, 20];
        const expected = haversineMeters(a, b);
        const track = { type: 'Feature', geometry: { type: 'LineString', coordinates: [a, b] } };
        const r = computeSlopeChartData(track);
        expect(r.trackLength).toBeGreaterThan(0);
        expect(r.trackLength).toBeCloseTo(expected, 10);
    });

    it('yMin/yMax applicano padding 10% sul range', () => {
        const track = {
            type: 'Feature',
            geometry: { type: 'LineString', coordinates: [[0, 0, 100], [0, 0.001, 200]] },
        };
        const r = computeSlopeChartData(track);
        // range=100, pad=10 => yMin=floor(90), yMax=ceil(210)
        expect(r.yMin).toBe(90);
        expect(r.yMax).toBe(210);
    });

    it('slopes: il primo valore è 0 e poi calcola deltaAlt/deltaDist*100', () => {
        const a = [0, 0, 0];
        const b = [0, 0.001, 100];
        const expectedDist = haversineMeters(a, b);
        const approxSlope = (100 / expectedDist) * 100;
        const track = { type: 'Feature', geometry: { type: 'LineString', coordinates: [a, b] } };
        const r = computeSlopeChartData(track);
        expect(r.slopes[0]).toBe(0);
        // su retta lineare la pendenza è circa costante: confrontiamo l'ultimo step
        expect(r.slopes[r.slopes.length - 1]).toBeCloseTo(approxSlope, 1);
    });
});

describe('SlopeChart enable/disable (FeatureCollection gating)', () => {
    it('se enableSlopeChart=false non ritorna mai una traccia', () => {
        const fc = {
            type: 'FeatureCollection',
            features: [
                { type: 'Feature', geometry: { type: 'LineString', coordinates: [[0, 0, 10], [0, 0.001, 20]] } },
            ],
        };
        expect(getSlopeChartTrackFromGeojson(fc, false)).toBeNull();
    });

    it('richiede ESATTAMENTE 1 LineString/MultiLineString', () => {
        const fc0 = { type: 'FeatureCollection', features: [] };
        expect(getSlopeChartTrackFromGeojson(fc0, true)).toBeNull();

        const fc2 = {
            type: 'FeatureCollection',
            features: [
                { type: 'Feature', geometry: { type: 'LineString', coordinates: [[0, 0, 1], [0, 0.001, 2]] } },
                { type: 'Feature', geometry: { type: 'LineString', coordinates: [[1, 1, 1], [1, 1.001, 2]] } },
            ],
        };
        expect(getSlopeChartTrackFromGeojson(fc2, true)).toBeNull();
    });

    it('accetta LineString e ritorna la stessa feature', () => {
        const f = { type: 'Feature', properties: { id: 1 }, geometry: { type: 'LineString', coordinates: [[0, 0, 10], [0, 0.001, 20]] } };
        const fc = { type: 'FeatureCollection', features: [f] };
        const r = getSlopeChartTrackFromGeojson(fc, true);
        expect(r).not.toBeNull();
        expect(r.geometry.type).toBe('LineString');
        expect(r.geometry.coordinates.length).toBe(2);
        expect(r.properties.id).toBe(1);
    });

    it('accetta MultiLineString e fa flatten completo (non solo il primo segmento)', () => {
        const fc = {
            type: 'FeatureCollection',
            features: [
                {
                    type: 'Feature',
                    geometry: {
                        type: 'MultiLineString',
                        coordinates: [
                            [[0, 0, 10], [0, 0.001, 20]],
                            [[0, 0.001, 20], [0, 0.002, 30]],
                        ],
                    },
                },
            ],
        };
        const r = getSlopeChartTrackFromGeojson(fc, true);
        expect(r).not.toBeNull();
        expect(r.geometry.type).toBe('LineString');
        // 4 punti totali, con duplicato al join rimosso => 3
        expect(r.geometry.coordinates.length).toBe(3);
        expect(r.geometry.coordinates[0][2]).toBe(10);
        expect(r.geometry.coordinates[r.geometry.coordinates.length - 1][2]).toBe(30);
    });
});


describe('linea del profilo indicata nel GeoJSON (oc:8662)', () => {
    const line = (props, coords = [[0, 0, 1], [0, 0.001, 2]]) => ({
        type: 'Feature',
        properties: props,
        geometry: { type: 'LineString', coordinates: coords },
    });

    it('con piu linee sceglie quella marcata slopeChart', () => {
        const fc = { type: 'FeatureCollection', features: [line({ neighbour: true }), line({ slopeChart: true, id: 'x' }), line({})] };
        expect(getSlopeChartTrackFromGeojson(fc)?.properties?.id).toBe('x');
    });

    it('senza marcatura resta il comportamento di prima: piu linee, niente profilo', () => {
        const fc = { type: 'FeatureCollection', features: [line({}), line({})] };
        expect(getSlopeChartTrackFromGeojson(fc)).toBeNull();
    });

    it('senza marcatura una sola linea da ancora il profilo', () => {
        const fc = { type: 'FeatureCollection', features: [line({ id: 'solo' })] };
        expect(getSlopeChartTrackFromGeojson(fc)?.properties?.id).toBe('solo');
    });

    it('ignora la marcatura su una geometria che non e una linea', () => {
        const fc = { type: 'FeatureCollection', features: [{ type: 'Feature', properties: { slopeChart: true }, geometry: { type: 'Point', coordinates: [0, 0] } }] };
        expect(findExplicitSlopeChartFeature(fc)).toBeNull();
    });

    it('con il profilo spento non restituisce nulla anche se marcato', () => {
        const fc = { type: 'FeatureCollection', features: [line({ slopeChart: true })] };
        expect(getSlopeChartTrackFromGeojson(fc, false)).toBeNull();
    });
});
