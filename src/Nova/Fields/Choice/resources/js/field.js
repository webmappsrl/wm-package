import IndexField from './components/IndexField'
import DetailField from './components/DetailField'
import FormField from './components/FormField'
import FilterField from './components/FilterField'

Nova.booting((app, store) => {
  app.component('index-choice', IndexField)
  app.component('detail-choice', DetailField)
  app.component('form-choice', FormField)
  // Il componente dei filtri costruiti su ChoiceFilter (oc:8988).
  app.component('choice-filter', FilterField)
})
