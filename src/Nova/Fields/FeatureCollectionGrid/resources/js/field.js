import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'

Nova.booting((app, store) => {
  app.component('index-feature-collection-grid', IndexField)
  app.component('detail-feature-collection-grid', DetailField)
  app.component('form-feature-collection-grid', FormField)
})


