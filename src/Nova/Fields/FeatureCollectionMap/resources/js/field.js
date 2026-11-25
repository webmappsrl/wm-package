import DetailField from './components/DetailField.vue';
import FormField from './components/FormField.vue';
import IndexField from './components/IndexField.vue';
import LayerMapCard from './components/LayerMapCard.vue';

Nova.booting((app) => {
    app.component('detail-feature-collection-map', DetailField);
    app.component('form-feature-collection-map', FormField);
    app.component('index-feature-collection-map', IndexField);
    // Card per mettere la mappa di un layer dove un Field non va, es. una Dashboard (oc:9040)
    app.component('layer-map-card', LayerMapCard);
});
