import DetailField from './components/DetailField.vue';
import FormField from './components/FormField.vue';
import IndexField from './components/IndexField.vue';

Nova.booting((app) => {
    app.component('detail-bbox-field', DetailField);
    app.component('form-bbox-field', FormField);
    app.component('index-bbox-field', IndexField);
});
