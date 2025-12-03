import DetailField from './components/DetailField.vue';
import FormField from './components/FormField.vue';
import IndexField from './components/IndexField.vue';

Nova.booting((app) => {
    app.component('detail-feature-collection-map', DetailField);
    app.component('form-feature-collection-map', FormField);
    app.component('index-feature-collection-map', IndexField);
});
