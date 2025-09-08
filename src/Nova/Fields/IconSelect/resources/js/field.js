import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'
import PreviewField from './components/PreviewField'

Nova.booting((app, store) => {
  app.component('index-icon-select', IndexField)
  app.component('detail-icon-select', DetailField)
  app.component('form-icon-select', FormField)
  // app.component('preview-icon-select', PreviewField)
})
