import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'

Nova.booting((app, store) => {
  app.component('index-geo-reference-field', IndexField)
  app.component('detail-geo-reference-field', DetailField)
  app.component('form-geo-reference-field', FormField)
})
