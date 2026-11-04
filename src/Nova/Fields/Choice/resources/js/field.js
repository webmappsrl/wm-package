import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'

Nova.booting((app, store) => {
  app.component('index-choice', IndexField)
  app.component('detail-choice', DetailField)
  app.component('form-choice', FormField)
})
