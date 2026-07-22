import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'

Nova.booting((app, store) => {
  app.component('index-poi-track-reference-field', IndexField)
  app.component('detail-poi-track-reference-field', DetailField)
  app.component('form-poi-track-reference-field', FormField)
})
