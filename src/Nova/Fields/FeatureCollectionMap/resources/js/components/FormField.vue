<template>
    <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText">
        <template #field>
            <div class="space-y-3">
                <!-- Upload geometria: stesso blocco per tracce e POI (POI: anche CSV punto) -->
                <div>
                    <label :for="field.attribute + '_geometry_file'"
                        class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        {{ fileLabel }}
                    </label>
                    <div
                        class="mt-1 flex min-h-[3rem] items-center rounded-lg border border-gray-300 bg-gray-50 px-4 py-3 shadow-inner dark:border-gray-600 dark:bg-gray-900/40">
                        <input :id="field.attribute + '_geometry_file'" ref="fileInput" type="file"
                            class="block w-full cursor-pointer text-sm text-gray-600 file:mr-4 file:inline-flex file:h-9 file:cursor-pointer file:items-center file:justify-center file:rounded-md file:border file:border-gray-300 file:bg-white file:px-4 file:py-2 file:text-sm file:font-medium file:leading-none file:text-gray-700 file:shadow-sm hover:file:bg-gray-50 dark:text-gray-300 dark:file:border-gray-600 dark:file:bg-gray-800 dark:file:text-gray-200 dark:hover:file:bg-gray-700/80"
                            :accept="fileAccept" @change="onFileChange" />
                    </div>
                </div>

                <!-- POI: lat/lon sulla stessa riga -->
                <div v-if="isPointMode" style="display: flex; gap: 1rem; width: 100%;">
                    <div style="flex: 1; min-width: 0;">
                        <label :for="field.attribute + '_lat'"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Latitudine
                        </label>
                        <input :id="field.attribute + '_lat'" v-model="latInput" type="text" inputmode="decimal"
                            autocomplete="off" :placeholder="placeholderLat"
                            class="form-control form-input form-input-bordered mt-1 w-full"
                            @input="syncPointFromInputs" @blur="syncPointFromInputs" />
                    </div>
                    <div style="flex: 1; min-width: 0;">
                        <label :for="field.attribute + '_lon'"
                            class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Longitudine
                        </label>
                        <input :id="field.attribute + '_lon'" v-model="lonInput" type="text" inputmode="decimal"
                            autocomplete="off" :placeholder="placeholderLon"
                            class="form-control form-input form-input-bordered mt-1 w-full"
                            @input="syncPointFromInputs" @blur="syncPointFromInputs" />
                    </div>
                </div>

                <FeatureCollectionMap :geojson-url="geojsonUrlForMap" :inline-geojson="inlineOverride"
                    :height="field.height || 500" :show-zoom-controls="field.showZoomControls !== false"
                    :mouse-wheel-zoom="field.mouseWheelZoom !== false" :drag-pan="field.dragPan !== false"
                    :padding="field.padding || 50" :resource-name="resourceName" :resource-id="resourceId"
                    :popup-component="field.popupComponent" :enable-screenshot="false"
                    :enable-slope-chart="false"
                    :default-cursor="isPointMode ? 'crosshair' : ''"
                    @map-click="onMapClick" />
            </div>
        </template>
    </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova';
import * as toGeoJSON from '@tmcw/togeojson';
import FeatureCollectionMap from './FeatureCollectionMap.vue';

function extractFirstLineGeometry(parsed) {
    let geom = null;
    if (parsed.type === 'FeatureCollection' && parsed.features?.length) {
        for (const f of parsed.features) {
            if (f.geometry && ['LineString', 'MultiLineString'].includes(f.geometry.type)) {
                geom = f.geometry;
                break;
            }
        }
    } else if (parsed.type === 'Feature' && parsed.geometry) {
        geom = parsed.geometry;
    } else if (['LineString', 'MultiLineString'].includes(parsed.type)) {
        geom = parsed;
    }
    if (!geom) {
        return null;
    }
    if (geom.type === 'LineString') {
        return { type: 'MultiLineString', coordinates: [geom.coordinates] };
    }
    return geom;
}

function extractFirstPointGeometry(parsed) {
    if (!parsed || typeof parsed !== 'object') {
        return null;
    }
    const features = parsed.features || [];
    if (parsed.type === 'FeatureCollection' && features.length) {
        for (const f of features) {
            const g = f.geometry;
            if (g?.type === 'Point') {
                return g;
            }
            if (g?.type === 'MultiPoint' && g.coordinates?.length) {
                return { type: 'Point', coordinates: g.coordinates[0] };
            }
        }
        for (const f of features) {
            const g = f.geometry;
            if (g?.type === 'LineString' && g.coordinates?.length) {
                return { type: 'Point', coordinates: g.coordinates[0] };
            }
            if (g?.type === 'MultiLineString' && g.coordinates?.[0]?.length) {
                return { type: 'Point', coordinates: g.coordinates[0][0] };
            }
        }
    }
    if (parsed.type === 'Feature' && parsed.geometry?.type === 'Point') {
        return parsed.geometry;
    }
    if (parsed.type === 'Point') {
        return parsed;
    }
    if (parsed.type === 'MultiPoint' && parsed.coordinates?.length) {
        return { type: 'Point', coordinates: parsed.coordinates[0] };
    }
    return null;
}

function extractFirstPolygonGeometry(parsed) {
    if (!parsed || typeof parsed !== 'object') {
        return null;
    }
    const features = parsed.features || [];
    if (parsed.type === 'FeatureCollection' && features.length) {
        for (const f of features) {
            const g = f.geometry;
            if (g?.type === 'Polygon') {
                return { type: 'MultiPolygon', coordinates: [g.coordinates] };
            }
            if (g?.type === 'MultiPolygon') {
                return g;
            }
        }
    }
    if (parsed.type === 'Feature' && parsed.geometry) {
        if (parsed.geometry.type === 'Polygon') {
            return { type: 'MultiPolygon', coordinates: [parsed.geometry.coordinates] };
        }
        if (parsed.geometry.type === 'MultiPolygon') {
            return parsed.geometry;
        }
    }
    if (parsed.type === 'Polygon') {
        return { type: 'MultiPolygon', coordinates: [parsed.coordinates] };
    }
    if (parsed.type === 'MultiPolygon') {
        return parsed;
    }
    return null;
}

/**
 * Estrae la prima geometria compatibile con i types configurati.
 * Restituisce { geometry, type } oppure null.
 */
function extractGeometryByTypes(parsed, types) {
    for (const type of types) {
        let geom = null;
        if (type === 'point') {
            geom = extractFirstPointGeometry(parsed);
        } else if (type === 'multilinestring') {
            geom = extractFirstLineGeometry(parsed);
        } else if (type === 'multipolygon') {
            geom = extractFirstPolygonGeometry(parsed);
        }
        if (geom) {
            return { geometry: geom, type };
        }
    }
    return null;
}

/**
 * CSV: riga con intestazione (lat/latitude, lon/lng/…) oppure due colonne numeriche (lat, lon).
 */
function parseCsvPoint(text) {
    const lines = text
        .split(/\r?\n/)
        .map((l) => l.trim())
        .filter((l) => l.length > 0);
    if (!lines.length) {
        return null;
    }
    const first = lines[0];
    const delim = first.includes(';') ? ';' : first.includes('\t') ? '\t' : ',';
    const split = (line) =>
        line.split(delim).map((p) => p.trim().replace(/^["']|["']$/g, ''));
    const head = split(first).map((c) => c.toLowerCase());
    let latIdx = -1;
    let lonIdx = -1;
    head.forEach((h, i) => {
        if (/^(lat|latitude|nord|y)$/.test(h)) {
            latIdx = i;
        }
        if (/^(lon|lng|long|longitude|east|x)$/.test(h)) {
            lonIdx = i;
        }
    });
    let rowIdx = 0;
    if (latIdx >= 0 && lonIdx >= 0) {
        rowIdx = 1;
    }
    if (rowIdx >= lines.length) {
        return null;
    }
    const cells = split(lines[rowIdx]).map((c) => c.replace(',', '.'));
    let lat;
    let lon;
    if (latIdx >= 0 && lonIdx >= 0) {
        lat = parseFloat(cells[latIdx]);
        lon = parseFloat(cells[lonIdx]);
    } else if (cells.length >= 2) {
        const a = parseFloat(cells[0].replace(',', '.'));
        const b = parseFloat(cells[1].replace(',', '.'));
        if (!Number.isFinite(a) || !Number.isFinite(b)) {
            return null;
        }
        // Euristica Italia: lat ~35–48, lon ~6–20
        if (a >= 35 && a <= 48 && b >= 6 && b <= 20) {
            lat = a;
            lon = b;
        } else if (b >= 35 && b <= 48 && a >= 6 && a <= 20) {
            lat = b;
            lon = a;
        } else {
            lat = a;
            lon = b;
        }
    } else {
        return null;
    }
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
        return null;
    }
    if (lat < -90 || lat > 90 || lon < -180 || lon > 180) {
        return null;
    }
    return { type: 'Point', coordinates: [lon, lat] };
}

export default {
    name: 'FormFeatureCollectionMap',
    mixins: [FormField, HandlesValidationErrors],
    components: {
        FeatureCollectionMap
    },
    props: ['resourceName', 'resourceId'],
    data() {
        return {
            inlineOverride: null,
            pendingGeometry: null,
            latInput: '',
            lonInput: ''
        };
    },
    computed: {
        types() {
            return this.field.geometryTypes || ['multilinestring'];
        },
        hasType() {
            const t = this.types;
            return {
                point: t.includes('point'),
                multilinestring: t.includes('multilinestring'),
                multipolygon: t.includes('multipolygon')
            };
        },
        isPointMode() {
            return this.hasType.point;
        },
        placeholderLat() {
            return this.field.placeholderLat || 'es. 41.9028';
        },
        placeholderLon() {
            return this.field.placeholderLon || 'es. 12.4964';
        },
        fileLabel() {
            if (this.field.fileUploadLabel) {
                return this.field.fileUploadLabel;
            }
            const formats = ['GeoJSON', 'GPX', 'KML'];
            if (this.hasType.point) {
                formats.push('CSV');
            }
            return `Carica geometria (${formats.join(', ')})`;
        },
        fileAccept() {
            let accept = '.geojson,.json,.gpx,.kml,application/geo+json';
            if (this.hasType.point) {
                accept += ',.csv,text/csv';
            }
            return accept;
        },
        geojsonUrlComputed() {
            if (!this.resourceId) {
                return '';
            }
            const modelName = this.resourceName;
            const baseUrl = `/nova-vendor/feature-collection-map/${modelName}/${this.resourceId}`;
            const params = new URLSearchParams();
            if (this.field.demEnrichment) {
                params.append('dem_enrichment', '1');
            }
            return params.toString() ? `${baseUrl}?${params.toString()}` : baseUrl;
        },
        geojsonUrlForMap() {
            if (this.inlineOverride) {
                return '';
            }
            return this.geojsonUrlComputed;
        }
    },
    mounted() {
        this.bootstrapPendingGeometry();
    },
    methods: {
        applyPointGeometry(geometry) {
            if (!geometry || geometry.type !== 'Point' || !geometry.coordinates?.length) {
                return;
            }
            const [lon, lat] = geometry.coordinates;
            this.pendingGeometry = geometry;
            this.latInput = String(lat);
            this.lonInput = String(lon);
            this.inlineOverride = {
                type: 'FeatureCollection',
                features: [
                    {
                        type: 'Feature',
                        geometry,
                        properties: {}
                    }
                ]
            };
        },
        syncPointFromInputs() {
            if (!this.isPointMode) {
                return;
            }
            const lat = parseFloat(String(this.latInput).replace(',', '.'));
            const lon = parseFloat(String(this.lonInput).replace(',', '.'));
            const valid =
                Number.isFinite(lat) &&
                Number.isFinite(lon) &&
                lat >= -90 &&
                lat <= 90 &&
                lon >= -180 &&
                lon <= 180;
            if (!valid) {
                this.pendingGeometry = null;
                this.inlineOverride = null;
                return;
            }
            const geometry = { type: 'Point', coordinates: [lon, lat] };
            this.pendingGeometry = geometry;
            this.inlineOverride = {
                type: 'FeatureCollection',
                features: [
                    {
                        type: 'Feature',
                        geometry,
                        properties: {}
                    }
                ]
            };
        },
        bootstrapPendingGeometry() {
            const raw = this.field.geojson;
            if (!raw) {
                return;
            }
            const g = typeof raw === 'string' ? JSON.parse(raw) : raw;
            if (!g || !g.type) {
                return;
            }

            let geom;
            if (g.type === 'Point' && this.hasType.point && g.coordinates?.length >= 2) {
                geom = g;
                this.latInput = String(g.coordinates[1]);
                this.lonInput = String(g.coordinates[0]);
            } else if (g.type === 'LineString') {
                geom = { type: 'MultiLineString', coordinates: [g.coordinates] };
            } else if (g.type === 'Polygon') {
                geom = { type: 'MultiPolygon', coordinates: [g.coordinates] };
            } else {
                geom = g;
            }

            this.pendingGeometry = geom;
            this.inlineOverride = {
                type: 'FeatureCollection',
                features: [{ type: 'Feature', geometry: geom, properties: {} }]
            };
        },
        normalizeGeometryForSubmit(geom) {
            if (!geom) {
                return null;
            }
            if (geom.type === 'Point' && this.hasType.point) {
                return geom;
            }
            if (geom.type === 'LineString') {
                return { type: 'MultiLineString', coordinates: [geom.coordinates] };
            }
            if (geom.type === 'Polygon') {
                return { type: 'MultiPolygon', coordinates: [geom.coordinates] };
            }
            return geom;
        },
        fill(formData) {
            const geometry = this.normalizeGeometryForSubmit(this.pendingGeometry);
            if (geometry != null) {
                formData.append(this.field.attribute, JSON.stringify(geometry));
            }
            // Do not append when empty: FormData turns null into the string "null", which would clear geometry.
        },
        async onFileChange(event) {
            const file = event.target.files?.[0];
            if (!file) {
                return;
            }
            const reader = new FileReader();
            const fileName = file.name || '';
            reader.onload = async (ev) => {
                const tryRead = (errMsg) => {
                    if (this.$refs.fileInput) {
                        this.$refs.fileInput.value = '';
                    }
                    window.alert(errMsg);
                };
                try {
                    const res = ev.target.result;
                    const lower = fileName.toLowerCase();

                    if (this.hasType.point && (lower.endsWith('.csv') || lower.endsWith('.tsv'))) {
                        const pt = parseCsvPoint(res);
                        if (!pt) {
                            tryRead(
                                'CSV non valido. Usa due colonne latitudine/longitudine o intestazioni lat,lon (es. 45.12,11.34).'
                            );
                            return;
                        }
                        this.applyPointGeometry(pt);
                        await this.mergeInlineFromServer(pt);
                        return;
                    }

                    let parsed;
                    if (lower.endsWith('.gpx')) {
                        const parser = new DOMParser().parseFromString(res, 'text/xml');
                        parsed = toGeoJSON.gpx(parser);
                    } else if (lower.endsWith('.kml')) {
                        const parser = new DOMParser().parseFromString(res, 'text/xml');
                        parsed = toGeoJSON.kml(parser);
                    } else {
                        parsed = JSON.parse(res);
                    }

                    const result = extractGeometryByTypes(parsed, this.types);
                    if (!result) {
                        const labels = this.types.map(k => ({
                            point: 'Point',
                            multilinestring: 'LineString/MultiLineString',
                            multipolygon: 'Polygon/MultiPolygon'
                        })[k] || k).join(', ');
                        tryRead(`Nessuna geometria compatibile trovata nel file (tipi attesi: ${labels}).`);
                        return;
                    }

                    const { geometry, type } = result;

                    if (type === 'point') {
                        this.applyPointGeometry(geometry);
                        await this.mergeInlineFromServer(geometry);
                    } else {
                        this.pendingGeometry = geometry;
                        let merged = null;
                        if (this.resourceId) {
                            try {
                                const r = await fetch(this.geojsonUrlComputed);
                                if (r.ok) {
                                    merged = await r.json();
                                }
                            } catch (_) {
                                /* ignore */
                            }
                        }
                        if (merged && merged.features?.length) {
                            const fc = JSON.parse(JSON.stringify(merged));
                            fc.features[0].geometry = geometry;
                            this.inlineOverride = fc;
                        } else {
                            this.inlineOverride = {
                                type: 'FeatureCollection',
                                features: [
                                    {
                                        type: 'Feature',
                                        geometry,
                                        properties: {}
                                    }
                                ]
                            };
                        }
                    }
                } catch (e) {
                    console.error(e);
                    tryRead('File non valido o corrotto.');
                }
            };
            reader.readAsText(file);
        },
        onMapClick({ lon, lat }) {
            if (!this.isPointMode) {
                return;
            }
            const geometry = { type: 'Point', coordinates: [lon, lat] };
            this.applyPointGeometry(geometry);
        },
        async mergeInlineFromServer(geometry) {
            if (!this.resourceId) {
                return;
            }
            try {
                const r = await fetch(this.geojsonUrlComputed);
                if (!r.ok) {
                    return;
                }
                const merged = await r.json();
                if (merged && merged.features?.length) {
                    const fc = JSON.parse(JSON.stringify(merged));
                    fc.features[0].geometry = geometry;
                    this.inlineOverride = fc;
                }
            } catch (_) {
                /* mantieni inlineOverride da applyPointGeometry */
            }
        }
    }
};
</script>
