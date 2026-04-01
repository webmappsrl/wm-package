export const EARTH_RADIUS_M = 6371e3;
export const STEPS = 100;
export const SLOPE_MAX = 15;

export const SLOPE_LEVELS = [
    { pct: 0, color: '#22c55e' }, // EASY
    { pct: 4, color: '#a3e635' },
    { pct: 7, color: '#facc15' },
    { pct: 10, color: '#fb923c' },
    { pct: 15, color: '#ef4444' }, // HARD
];

export function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
}

export function toRad(deg) {
    return (deg * Math.PI) / 180;
}

export function haversineMeters(a, b) {
    const [lon1, lat1] = a;
    const [lon2, lat2] = b;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const rLat1 = toRad(lat1);
    const rLat2 = toRad(lat2);
    const sinDLat = Math.sin(dLat / 2);
    const sinDLon = Math.sin(dLon / 2);
    const h = sinDLat * sinDLat + Math.cos(rLat1) * Math.cos(rLat2) * sinDLon * sinDLon;
    return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
}

export function isTrack3d(coords) {
    if (!Array.isArray(coords) || coords.length < 2) return false;
    return coords.some((c) => Array.isArray(c) && c.length >= 3 && Number(c[2]) !== 0);
}

export function getSlopeGradientColor(slopePct) {
    const v = clamp(Math.abs(Number(slopePct) || 0), 0, SLOPE_MAX);
    for (let i = 0; i < SLOPE_LEVELS.length - 1; i++) {
        const a = SLOPE_LEVELS[i];
        const b = SLOPE_LEVELS[i + 1];
        if (v >= a.pct && v <= b.pct) {
            const t = (v - a.pct) / (b.pct - a.pct || 1);
            const ca = a.color.replace('#', '');
            const cb = b.color.replace('#', '');
            const ra = parseInt(ca.slice(0, 2), 16);
            const ga = parseInt(ca.slice(2, 4), 16);
            const ba = parseInt(ca.slice(4, 6), 16);
            const rb = parseInt(cb.slice(0, 2), 16);
            const gb = parseInt(cb.slice(2, 4), 16);
            const bb = parseInt(cb.slice(4, 6), 16);
            const r = Math.round(ra + (rb - ra) * t);
            const g = Math.round(ga + (gb - ga) * t);
            const b2 = Math.round(ba + (bb - ba) * t);
            return `rgb(${r}, ${g}, ${b2})`;
        }
    }
    return SLOPE_LEVELS[SLOPE_LEVELS.length - 1].color;
}

function normalizeTrackFeature(track) {
    if (!track || typeof track !== 'object') return null;
    const geom = track.geometry || track.geojson || null;
    if (!geom || geom.type !== 'LineString' || !Array.isArray(geom.coordinates)) return null;
    return { type: 'Feature', properties: track.properties || {}, geometry: geom };
}

/**
 * Appiattisce un MultiLineString in un'unica lista di coordinate (LineString),
 * preservando la quota (z) e rimuovendo eventuali duplicati tra segmenti consecutivi.
 */
export function flattenMultiLineStringCoordinates(multiCoords) {
    if (!Array.isArray(multiCoords) || multiCoords.length === 0) {
        return [];
    }
    const flat = [];
    for (const part of multiCoords) {
        if (!Array.isArray(part) || part.length === 0) {
            continue;
        }
        for (let i = 0; i < part.length; i++) {
            const c = part[i];
            if (!Array.isArray(c) || c.length < 2) {
                continue;
            }
            if (flat.length > 0 && i === 0) {
                const prev = flat[flat.length - 1];
                if (Array.isArray(prev) && prev[0] === c[0] && prev[1] === c[1] && prev[2] === c[2]) {
                    continue;
                }
            }
            flat.push(c);
        }
    }
    return flat;
}

/**
 * Converte un Feature con geometry LineString o MultiLineString in un Feature LineString.
 * Ritorna null per altri tipi.
 */
export function toLineStringFeatureObject(featureObject) {
    if (!featureObject || typeof featureObject !== 'object') {
        return null;
    }
    const g = featureObject.geometry;
    if (!g || !g.type) {
        return null;
    }
    if (g.type === 'LineString') {
        return featureObject;
    }
    if (g.type === 'MultiLineString' && Array.isArray(g.coordinates) && g.coordinates.length) {
        return {
            type: 'Feature',
            properties: featureObject.properties || {},
            geometry: {
                type: 'LineString',
                coordinates: flattenMultiLineStringCoordinates(g.coordinates),
            },
        };
    }
    return null;
}

/**
 * Decide se lo SlopeChart è “abilitabile” dalla FeatureCollection:
 * - deve essere enableSlopeChart=true
 * - deve esserci ESATTAMENTE 1 feature LineString o MultiLineString
 * Ritorna il track normalizzato (LineString) oppure null.
 */
export function getSlopeChartTrackFromGeojson(featureCollection, enableSlopeChart = true) {
    if (!enableSlopeChart) {
        return null;
    }
    if (!featureCollection || typeof featureCollection !== 'object') {
        return null;
    }
    const feats = Array.isArray(featureCollection.features) ? featureCollection.features : [];
    const lineFeats = feats.filter((f) => {
        const t = f?.geometry?.type;
        return t === 'LineString' || t === 'MultiLineString';
    });
    if (lineFeats.length !== 1) {
        return null;
    }
    return toLineStringFeatureObject(lineFeats[0]);
}

/**
 * Dati “puri” dello slope chart (testabili senza DOM/Chart.js).
 */
export function computeSlopeChartData(track) {
    const feature = normalizeTrackFeature(track);
    if (!feature) {
        return {
            is3d: false,
            trackLength: 0,
            labelsKm: [],
            altitudes: [],
            slopes: [],
            locations: [],
            yMin: 0,
            yMax: 0,
        };
    }

    const coords = feature.geometry.coordinates;
    const ok3d = isTrack3d(coords);
    if (!ok3d) {
        return {
            is3d: false,
            trackLength: 0,
            labelsKm: [],
            altitudes: [],
            slopes: [],
            locations: [],
            yMin: 0,
            yMax: 0,
        };
    }

    const pts = coords
        .filter((c) => Array.isArray(c) && c.length >= 2)
        .map((c) => [Number(c[0]), Number(c[1]), Number(c[2] || 0)]);

    const cum = [0];
    for (let i = 1; i < pts.length; i++) {
        const d = haversineMeters(pts[i - 1], pts[i]);
        cum.push(cum[i - 1] + (Number.isFinite(d) ? d : 0));
    }
    const trackLength = cum[cum.length - 1] || 0;
    const stepDist = trackLength / STEPS;

    const locations = [];
    const altitudes = [];
    const slopes = [];
    const labelsKm = [];

    let minAlt = Infinity;
    let maxAlt = -Infinity;

    function interpolatedAt(targetMeters) {
        if (targetMeters <= 0) return { i: 0, t: 0 };
        if (targetMeters >= trackLength) return { i: pts.length - 2, t: 1 };
        let i = 0;
        while (i < cum.length - 2 && cum[i + 1] < targetMeters) i++;
        const segLen = (cum[i + 1] - cum[i]) || 1;
        const t = (targetMeters - cum[i]) / segLen;
        return { i, t: clamp(t, 0, 1) };
    }

    for (let s = 0; s <= STEPS; s++) {
        const distM = stepDist * s;
        const { i, t } = interpolatedAt(distM);
        const a = pts[i];
        const b = pts[i + 1] || pts[i];
        const lon = a[0] + (b[0] - a[0]) * t;
        const lat = a[1] + (b[1] - a[1]) * t;
        const alt = a[2] + (b[2] - a[2]) * t;

        locations.push({ lon, lat, alt });
        altitudes.push(alt);
        minAlt = Math.min(minAlt, alt);
        maxAlt = Math.max(maxAlt, alt);
        labelsKm.push(Math.round((distM / 1000) * 10) / 10);

        if (s === 0) {
            slopes.push(0);
        } else {
            const prevLoc = locations[s - 1];
            const prevDistM = stepDist * (s - 1);
            const deltaDist = Math.max(1e-6, distM - prevDistM);
            const deltaAlt = alt - (prevLoc?.alt ?? alt);
            slopes.push((deltaAlt / deltaDist) * 100);
        }
    }

    const range = maxAlt - minAlt;
    const pad = range > 0 ? range * 0.1 : 10;
    const yMin = Math.floor(minAlt - pad);
    const yMax = Math.ceil(maxAlt + pad);

    return {
        is3d: true,
        trackLength,
        labelsKm,
        altitudes,
        slopes,
        locations,
        yMin,
        yMax,
    };
}

