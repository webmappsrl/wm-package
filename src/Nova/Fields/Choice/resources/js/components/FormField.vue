<template>
  <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText" :full-width-content="fullWidthContent">
    <template #field>
      <div>
        <ChoicePicker :model-value="selectedItems" @update:model-value="onPick" :options="options"
          :multiple="isMultiple" :max-items="maxItems" :search-placeholder="searchPlaceholder"
          :icon-class="iconClass" :input-id="field.attribute + '_search'" :input-class="errorClasses" />

        <!-- Hidden input for form submission -->
        <input :id="field.attribute" type="hidden" :name="field.attribute" :value="formValue" />
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'
import ChoicePicker from './ChoicePicker'

export default {
  components: { ChoicePicker },

  mixins: [FormField, HandlesValidationErrors],

  props: ['resourceName', 'resourceId', 'field'],

  data() {
    return {
      selectedItems: []
    }
  },

  computed: {
    options() {
      return this.field.options || []
    },

    searchPlaceholder() {
      return this.field.searchPlaceholder || 'Cerca un\'icona...'
    },

    isMultiple() {
      return this.field.multiple || false
    },

    maxItems() {
      return this.field.maxItems || null
    },

    iconClass() {
      return this.field.iconClass || 'fas'
    },

    formValue() {
      if (this.isMultiple) {
        return this.selectedItems.map(item => item.value).join(',')
      }
      return this.selectedItems.length > 0 ? this.selectedItems[0].value : ''
    }
  },

  methods: {
    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue() {
      const value = this.field.value || ''

      if (value) {
        if (this.isMultiple) {
          const values = value.split(',')
          this.selectedItems = this.options.filter(option => values.includes(option.value))
        } else {
          const selectedOption = this.options.find(option => option.value === value)
          if (selectedOption) {
            this.selectedItems = [selectedOption]
          }
        }
      }

      this.value = value
    },

    /**
     * Fill the given FormData object with the field's internal value.
     */
    fill(formData) {
      formData.append(this.fieldAttribute, this.formValue)
    },

    /**
     * Annuncia il nuovo valore a Nova. Senza questo i campi dichiarati con dependsOn() non si
     * aggiornano: Nova ricostruisce i dipendenti solo quando il campo sorgente lo comunica
     * (vedi il mixin FormField di Nova, handleChange).
     */
    annunciaCambio() {
      this.value = this.formValue
      if (this.field) {
        this.emitFieldValueChange(this.fieldAttribute, this.value)
        this.$emit('field-changed')
      }
    },

    /*
     * Il selettore restituisce le opzioni scelte: prima si aggiorna la scelta, poi si annuncia.
     */
    onPick(items) {
      this.selectedItems = items
      this.annunciaCambio()
    }
  }
}
</script>
