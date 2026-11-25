<template>
    <card class="p-6">
        <h3 style="font-size:1rem; font-weight:600; margin-bottom:12px;">{{ card.title }}</h3>

        <!-- Nessun polling: a differenza di DetailField la card mostra le posizioni del momento
             in cui si apre la pagina (oc:9040). Profilo altimetrico spento come nella detail
             view del Layer, dove DetailField lo accende solo con field.enableSlopeChart. -->
        <FeatureCollectionMap :geojson-url="card.geojson_url" :height="500"
            :enable-slope-chart="false"
            :resource-name="card.resource_name" :resource-id="card.resource_id" />

        <!-- Stessa legenda di DetailField.vue: si mostra solo se PostHog è configurato, altrimenti
             non arriva nessuna posizione. -->
        <div v-if="card.show_live_user_positions" style="display:flex; gap:16px; align-items:center; margin-top:8px; font-size:0.75rem; color:#6b7280; flex-wrap:wrap;">
            <span style="display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:12px; height:12px; border-radius:50%; background:rgba(34, 197, 94, 0.9); border:2px solid rgba(255,255,255,1); box-shadow:0 0 0 1px rgba(0,0,0,0.15);"></span>
                {{ card.legend_label }}
            </span>
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

    props: ['card']
};
</script>
