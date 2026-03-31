<template>
    <div v-if="is3d" class="wm-slope-chart">
        <div class="wm-slope-chart__canvas-wrap">
            <canvas ref="canvasEl" class="wm-slope-chart__canvas"></canvas>
        </div>
        <div class="wm-slope-chart__legend">
            <div class="wm-slope-chart__legend-label">Pendenza</div>
            <div class="wm-slope-chart__legend-bar">
                <div class="wm-slope-chart__legend-gradient"></div>
                <div
                    v-if="Number.isFinite(slope.selectedValue)"
                    class="wm-slope-chart__legend-dot"
                    :style="{ left: (slope.selectedPercentage ?? 0) + '%' }"
                    :title="`${Math.round((slope.selectedValue ?? 0) * 10) / 10}%`"
                ></div>
            </div>
            <div class="wm-slope-chart__legend-value">
                <span v-if="Number.isFinite(slope.selectedValue)">{{ Math.round(slope.selectedValue * 10) / 10 }}%</span>
                <span v-else>&nbsp;</span>
            </div>
        </div>
    </div>
    <div v-else class="wm-slope-chart wm-slope-chart--empty">
        <div class="wm-slope-chart__empty">
            Nessun dato quota disponibile per questa traccia.
        </div>
    </div>
</template>

<script>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    Chart,
    LineController,
    LineElement,
    PointElement,
    LinearScale,
    CategoryScale,
    Tooltip,
    Filler,
    Legend,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler, Legend);

const EARTH_RADIUS_M = 6371e3;
const STEPS = 100;
const SLOPE_MAX = 15;

const SLOPE_LEVELS = [
    { pct: 0, color: '#22c55e' },  // EASY
    { pct: 4, color: '#a3e635' },
    { pct: 7, color: '#facc15' },
    { pct: 10, color: '#fb923c' },
    { pct: 15, color: '#ef4444' }, // HARD
];

const SLOPE_CHART_FILL_COLOR = 'rgba(148, 163, 184, 0.35)'; // slate-400

function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
}

function toRad(deg) {
    return (deg * Math.PI) / 180;
}

function haversineMeters(a, b) {
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

function isTrack3d(coords) {
    if (!Array.isArray(coords) || coords.length < 2) return false;
    return coords.some((c) => Array.isArray(c) && c.length >= 3 && Number(c[2]) !== 0);
}

function getSlopeGradientColor(slopePct) {
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

export default {
    name: 'SlopeChart',
    props: {
        track: {
            type: Object,
            default: null,
        },
    },
    emits: ['hover'],
    setup(props, { emit }) {
        const canvasEl = ref(null);
        const chart = ref(null);

        const slope = ref({
            selectedValue: undefined,
            selectedPercentage: undefined,
        });

        const computedData = computed(() => {
            const feature = normalizeTrackFeature(props.track);
            if (!feature) {
                return {
                    is3d: false,
                    trackLength: 0,
                    labelsKm: [],
                    altitudes: [],
                    slopes: [],
                    locations: [],
                    surfaceDatasets: [],
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
                    surfaceDatasets: [],
                    yMin: 0,
                    yMax: 0,
                };
            }

            // Normalizza altitude: se manca, usa 0
            const pts = coords
                .filter((c) => Array.isArray(c) && c.length >= 2)
                .map((c) => [Number(c[0]), Number(c[1]), Number(c[2] || 0)]);

            // Distanze cumulative sui punti originali
            const cum = [0];
            for (let i = 1; i < pts.length; i++) {
                const d = haversineMeters(pts[i - 1], pts[i]);
                cum.push(cum[i - 1] + (Number.isFinite(d) ? d : 0));
            }
            const trackLength = cum[cum.length - 1] || 0;

            // Campionamento uniforme in 100 step lungo la distanza
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
                // trova i tale che cum[i] <= target < cum[i+1]
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

            const surfaceDatasets = [
                {
                    label: 'fill',
                    data: altitudes,
                    fill: true,
                    backgroundColor: SLOPE_CHART_FILL_COLOR,
                    borderWidth: 0,
                    pointRadius: 0,
                    tension: 0.25,
                },
            ];

            return {
                is3d: true,
                trackLength,
                labelsKm,
                altitudes,
                slopes,
                locations,
                surfaceDatasets,
                yMin,
                yMax,
            };
        });

        const is3d = computed(() => computedData.value.is3d);

        function destroyChart() {
            if (chart.value) {
                chart.value.destroy();
                chart.value = null;
            }
        }

        function emitHover(index) {
            if (index == null || index < 0) {
                slope.value.selectedValue = undefined;
                slope.value.selectedPercentage = undefined;
                emit('hover', { location: undefined });
                return;
            }
            const loc = computedData.value.locations[index];
            const slopePct = computedData.value.slopes[index];
            slope.value.selectedValue = slopePct;
            slope.value.selectedPercentage = clamp((Math.abs(slopePct) / SLOPE_MAX) * 100, 0, 100);
            emit('hover', { location: loc });
        }

        function renderChart() {
            destroyChart();
            if (!canvasEl.value || !computedData.value.is3d) return;

            const data = computedData.value;
            const ctx = canvasEl.value.getContext('2d');
            if (!ctx) return;

            const webmappTooltipPlugin = {
                id: 'webmappTooltipPlugin',
                beforeTooltipDraw(chartInstance) {
                    const tooltip = chartInstance.tooltip;
                    const active = tooltip?.getActiveElements?.() || [];
                    const { chartArea, ctx: c } = chartInstance;
                    if (!chartArea) return;

                    if (active.length > 0) {
                        const index = active[0].index;
                        const x = active[0].element?.x;
                        if (typeof x === 'number') {
                            c.save();
                            // Linea verticale
                            c.beginPath();
                            c.strokeStyle = 'rgba(15, 23, 42, 0.45)'; // slate-900
                            c.lineWidth = 1;
                            c.setLineDash([4, 4]);
                            c.moveTo(x, chartArea.top);
                            c.lineTo(x, chartArea.bottom);
                            c.stroke();
                            c.setLineDash([]);

                            // Box km sotto asse X
                            const km = data.labelsKm[index];
                            const text = `${km} km`;
                            c.font = '12px system-ui, -apple-system, Segoe UI, sans-serif';
                            const paddingX = 8;
                            const paddingY = 6;
                            const textW = c.measureText(text).width;
                            const boxW = textW + paddingX * 2;
                            const boxH = 24;
                            const y = chartArea.bottom + 6;
                            const minX = chartArea.left;
                            const maxX = chartArea.right - boxW;
                            const boxX = clamp(x - boxW / 2, minX, maxX);

                            c.fillStyle = 'rgba(255,255,255,0.95)';
                            c.strokeStyle = 'rgba(148,163,184,0.8)';
                            c.lineWidth = 1;
                            c.beginPath();
                            c.roundRect(boxX, y, boxW, boxH, 6);
                            c.fill();
                            c.stroke();

                            c.fillStyle = 'rgba(15, 23, 42, 0.95)';
                            c.textBaseline = 'middle';
                            c.fillText(text, boxX + paddingX, y + boxH / 2);
                            c.restore();
                        }

                        emitHover(index);
                    } else {
                        emitHover(null);
                    }
                },
            };

            const altitudeDatasetWhite = {
                label: 'quota_border',
                data: data.altitudes,
                borderColor: 'rgba(255,255,255,1)',
                borderWidth: 5,
                pointRadius: 0,
                tension: 0.25,
                fill: false,
                order: 0,
                borderCapStyle: 'round',
                borderJoinStyle: 'round',
            };

            const altitudeDatasetColored = {
                label: 'quota',
                data: data.altitudes,
                borderWidth: 3,
                pointRadius: 0,
                tension: 0.25,
                fill: false,
                order: 30,
                borderColor: SLOPE_LEVELS[0].color, // fallback
                borderCapStyle: 'round',
                borderJoinStyle: 'round',
                segment: {
                    borderColor: (ctxSeg) => {
                        const i = ctxSeg.p0DataIndex;
                        const s = data.slopes[i] ?? 0;
                        return getSlopeGradientColor(s);
                    },
                },
            };

            chart.value = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labelsKm,
                    datasets: [
                        ...data.surfaceDatasets,
                        // IMPORTANTE: bianco sotto, colorato sopra
                        altitudeDatasetWhite,
                        altitudeDatasetColored,
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                title(items) {
                                    const i = items?.[0]?.dataIndex ?? 0;
                                    const alt = Math.round(data.altitudes[i] ?? 0);
                                    const sl = Math.round((data.slopes[i] ?? 0) * 10) / 10;
                                    return `${alt} m / ${sl}%`;
                                },
                                label() {
                                    return '';
                                },
                            },
                        },
                    },
                    scales: {
                        y: {
                            min: data.yMin,
                            max: data.yMax,
                            ticks: {
                                callback: (v) => `${v} m`,
                                color: 'rgba(71, 85, 105, 0.9)', // slate-600
                            },
                            grid: {
                                borderDash: [4, 4],
                                color: 'rgba(148, 163, 184, 0.45)',
                                drawBorder: false,
                            },
                        },
                        x: {
                            ticks: {
                                callback: (_value, idx) => `${data.labelsKm[idx]} km`,
                                color: 'rgba(71, 85, 105, 0.9)',
                                maxRotation: 0,
                                autoSkip: true,
                                maxTicksLimit: 6,
                            },
                            grid: {
                                color: (ctxGrid) => (ctxGrid.tick?.major ? 'rgba(148, 163, 184, 0.35)' : 'rgba(0,0,0,0)'),
                                drawBorder: false,
                                drawOnChartArea: true,
                                drawTicks: true,
                            },
                        },
                    },
                },
                plugins: [webmappTooltipPlugin],
            });
        }

        onMounted(async () => {
            await nextTick();
            renderChart();
        });

        onUnmounted(() => {
            destroyChart();
        });

        watch(
            () => props.track,
            async () => {
                await nextTick();
                renderChart();
            },
            { deep: true }
        );

        return {
            canvasEl,
            is3d,
            slope,
        };
    },
};
</script>

<style scoped>
.wm-slope-chart {
    width: 100%;
}

.wm-slope-chart__canvas-wrap {
    position: relative;
    width: 100%;
    height: 170px;
    border-radius: 8px;
    background: linear-gradient(180deg, rgba(15, 23, 42, 0.06), rgba(15, 23, 42, 0.02));
    overflow: hidden;
}

.wm-slope-chart__canvas {
    width: 100%;
    height: 100%;
}

.wm-slope-chart__legend {
    display: grid;
    grid-template-columns: auto 1fr auto;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
    padding: 0 2px;
}

.wm-slope-chart__legend-label {
    font-size: 14px;
    color: rgba(71, 85, 105, 1);
    font-weight: 600;
}

.wm-slope-chart__legend-bar {
    position: relative;
    height: 6px;
}

.wm-slope-chart__legend-gradient {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    background: linear-gradient(
        90deg,
        #22c55e 0%,
        #a3e635 27%,
        #facc15 47%,
        #fb923c 67%,
        #ef4444 100%
    );
}

.wm-slope-chart__legend-dot {
    position: absolute;
    top: 50%;
    transform: translate(-50%, -50%);
    width: 12px;
    height: 12px;
    border-radius: 999px;
    background: rgba(15, 23, 42, 1);
    box-shadow: 0 0 0 2px rgba(255, 255, 255, 1);
    pointer-events: none;
    transition: left 140ms linear;
}

.wm-slope-chart__legend-value {
    font-size: 14px;
    color: rgba(71, 85, 105, 1);
    font-weight: 600;
    min-width: 52px;
    text-align: right;
}

.wm-slope-chart__empty {
    width: 100%;
    padding: 12px 14px;
    border-radius: 8px;
    background: rgba(248, 250, 252, 1);
    border: 1px solid rgba(226, 232, 240, 1);
    color: rgba(71, 85, 105, 1);
    font-size: 13px;
}
</style>
