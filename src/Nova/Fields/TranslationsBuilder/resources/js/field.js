import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'

Nova.booting((app, store) => {
  app.component('index-translations-builder', IndexField)
  app.component('detail-translations-builder', DetailField)
  app.component('form-translations-builder', FormField)
})
