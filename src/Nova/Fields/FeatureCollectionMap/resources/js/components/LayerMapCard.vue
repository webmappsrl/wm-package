<template>
    <card class="p-6">
        <h3 style="font-size:1rem; font-weight:600; margin-bottom:12px;">{{ card.title }}</h3>

        <!-- I percorsi si scaricano una volta, le posizioni da live_positions_url ogni
             live_positions_refresh_seconds (la durata della loro cache sul server) senza
             cambiare zoom e centro (oc:9040). Profilo altimetrico spento come nella detail
             view del Layer, dove DetailField lo accende solo con field.enableSlopeChart. -->
        <FeatureCollectionMap :geojson-url="card.geojson_url" :height="500"
            :enable-slope-chart="false"
            :live-positions-url="card.live_positions_url"
            :live-positions-refresh-seconds="card.live_positions_refresh_seconds"
            :resource-name="card.resource_name" :resource-id="card.resource_id"
            @live-positions-loaded="onLivePositionsLoaded" />

        <!-- Box sotto la mappa (oc:9040): il pallino è lo stesso della mappa, quindi fa da legenda;
             poi quante persone ci sono e, a destra, l'ora dell'ultimo aggiornamento. Si mostra solo
             se lo shard lo accende (withLiveLegend()) e PostHog è configurato, dopo la prima
             risposta delle posizioni. Colori da variabili CSS che lo shard può impostare:
             --wm-live-count-color (numero; default il colore primario di Nova),
             --wm-live-count-bg e --wm-live-count-bg-dark (fondo), --wm-live-count-dot (pallino). -->
        <div v-if="card.show_live_legend && liveCount !== null" class="wm-live-count" data-test="live-count">
            <span class="wm-live-count-main">
                <span class="wm-live-count-dot" :class="{ 'is-off': liveCount === 0 }" aria-hidden="true"></span>
                <span class="wm-live-count-number">{{ liveCount }}</span>
                <span class="wm-live-count-text">
                    <strong>{{ liveCount === 1 ? card.live_count_texts.one : card.live_count_texts.other }}</strong>
                    <span>{{ card.live_count_texts.window }}</span>
                </span>
            </span>
            <span class="wm-live-count-updated">{{ card.live_count_texts.updated.replace(':time', updatedAt) }}</span>
        </div>
    </card>
</template>

<script>
import FeatureCollectionMap from './FeatureCollectionMap.vue';

export default {
    name: 'LayerMapCard',

    components: {
        FeatureCollectionMap
    },

    props: ['card'],

    data() {
        return { liveCount: null, updatedAt: '' };
    },

    methods: {
        onLivePositionsLoaded(count) {
            this.liveCount = count;
            this.updatedAt = new Date().toLocaleTimeString(document.documentElement.lang || undefined, {
                hour: '2-digit',
                minute: '2-digit',
            });
        }
    }
};
</script>

<style>
.wm-live-count {
    margin-top: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 16px;
    padding: 10px 16px;
    border-radius: 8px;
    background: var(--wm-live-count-bg, rgb(var(--colors-gray-100)));
}

.dark .wm-live-count {
    background: var(--wm-live-count-bg-dark, rgb(var(--colors-gray-900)));
}

.wm-live-count-main {
    display: flex;
    align-items: center;
    gap: 12px;
}

.wm-live-count-dot {
    position: relative;
    flex-shrink: 0;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--wm-live-count-dot, rgb(34, 197, 94));
    box-shadow: 0 0 0 2px rgb(var(--colors-white, 255, 255, 255)), 0 0 0 3.5px var(--wm-live-count-dot, rgb(34, 197, 94));
}

/* Pulsa quando c'è qualcuno: dice che il dato è in tempo reale. */
.wm-live-count-dot::after {
    content: "";
    position: absolute;
    inset: -3px;
    border-radius: 50%;
    border: 2px solid var(--wm-live-count-dot, rgb(34, 197, 94));
    animation: wm-live-count-ping 1.8s cubic-bezier(0, 0, 0.2, 1) infinite;
}

.wm-live-count-dot.is-off {
    background: rgb(var(--colors-gray-400));
    box-shadow: 0 0 0 2px rgb(var(--colors-white, 255, 255, 255)), 0 0 0 3.5px rgb(var(--colors-gray-400));
}

.wm-live-count-dot.is-off::after {
    display: none;
}

@keyframes wm-live-count-ping {
    0% { transform: scale(0.8); opacity: 0.9; }
    80%, 100% { transform: scale(2.1); opacity: 0; }
}

@media (prefers-reduced-motion: reduce) {
    .wm-live-count-dot::after { display: none; }
}

.wm-live-count-number {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    color: var(--wm-live-count-color, rgb(var(--colors-primary-500)));
}

.wm-live-count-text {
    display: grid;
    line-height: 1.25;
}

.wm-live-count-text strong {
    font-size: 0.9375rem;
    font-weight: 600;
    color: rgb(var(--colors-gray-800));
}

.wm-live-count-text span,
.wm-live-count-updated {
    font-size: 0.75rem;
    color: rgb(var(--colors-gray-500));
}

.dark .wm-live-count-text strong {
    color: rgb(var(--colors-gray-100));
}

.dark .wm-live-count-text span,
.dark .wm-live-count-updated {
    color: rgb(var(--colors-gray-400));
}
</style>
