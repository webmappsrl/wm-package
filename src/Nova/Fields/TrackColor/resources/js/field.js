import DetailField from './components/DetailField.vue';
import FormField from './components/FormField.vue';
import IndexField from './components/IndexField.vue';

Nova.booting((app) => {
    app.component('detail-track-color', DetailField);
    app.component('form-track-color', FormField);
    app.component('index-track-color', IndexField);
});
