import DetailField from './components/DetailField.vue';
import FormField from './components/FormField.vue';
import IndexField from './components/IndexField.vue';

Nova.booting((app) => {
    app.component('detail-order-list', DetailField);
    app.component('form-order-list', FormField);
    app.component('index-order-list', IndexField);
});
