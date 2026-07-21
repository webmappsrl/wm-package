<template>
  <DefaultField :field="field" :errors="errors" :show-help-text="showHelpText" :full-width-content="fullWidthContent">
    <template #field>
      <div>
        <!-- Toolbar: ricerca + aggiungi -->
        <div class="flex flex-wrap items-center" style="gap: 16px; margin-bottom: 16px">
          <TextInput
            v-model="searchQuery"
            class="flex-1"
            style="min-width: 220px"
            placeholder="Cerca per chiave..."
          />
          <Button variant="primary" class="whitespace-nowrap" @click="openAddForm">+ Nuova traduzione</Button>
        </div>

        <!-- Import/export: select lingua + azioni, valido per N lingue -->
        <div
          class="flex flex-wrap items-center"
          style="gap: 12px; margin-bottom: 20px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 10px 14px"
        >
          <label class="text-xs font-bold uppercase tracking-wide text-gray-500" style="margin: 0; margin-right: 8px">Lingua</label>
          <SelectInput v-model="selectedLang">
            <option v-for="lang in langs" :key="lang" :value="lang">{{ lang.toUpperCase() }}</option>
          </SelectInput>

          <Button variant="outline" style="gap: 8px" title="Scarica traduzioni attuali" @click="downloadCurrent(selectedLang)">
            <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 flex-shrink-0">
              <path d="M10 3a.75.75 0 01.75.75v10.638l3.96-4.158a.75.75 0 111.08 1.04l-5.25 5.5a.75.75 0 01-1.08 0l-5.25-5.5a.75.75 0 111.08-1.04l3.96 4.158V3.75A.75.75 0 0110 3z" />
              <path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z" />
            </svg>
            <span>Scarica</span>
          </Button>

          <Button variant="outline" style="gap: 8px" title="Carica file JSON" @click="triggerFileInput()">
            <svg viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 flex-shrink-0">
              <path d="M9.25 13.25a.75.75 0 001.5 0V4.636l2.955 3.129a.75.75 0 001.09-1.03l-4.25-4.5a.75.75 0 00-1.09 0l-4.25 4.5a.75.75 0 101.09 1.03L9.25 4.636v8.614z" />
              <path d="M3.5 12.75a.75.75 0 00-1.5 0v2.5A2.75 2.75 0 004.75 18h10.5A2.75 2.75 0 0018 15.25v-2.5a.75.75 0 00-1.5 0v2.5c0 .69-.56 1.25-1.25 1.25H4.75c-.69 0-1.25-.56-1.25-1.25v-2.5z" />
            </svg>
            <span>Carica JSON</span>
          </Button>

          <input
            type="file"
            accept="application/json"
            class="hidden"
            ref="fileInputEl"
            @change="onFileSelected($event, selectedLang)"
          />
        </div>

        <!-- Tabella traduzioni: colonna chiave fissa, scroll orizzontale per le lingue,
             scroll verticale interno (niente paginazione, per dataset grandi e multilingua) -->
        <div class="tb-table-wrapper">
          <div class="tb-table-scroll">
            <table class="tb-table">
              <thead>
                <tr>
                  <th class="tb-key-col">Chiave</th>
                  <th v-for="lang in langs" :key="lang">{{ lang }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="key in filteredKeys" :key="key" class="tb-row" @click="openEditForm(key)">
                  <td class="tb-key-col">{{ key }}</td>
                  <td v-for="lang in langs" :key="lang">{{ state[lang]?.[key] ?? '' }}</td>
                </tr>
                <tr v-if="filteredKeys.length === 0">
                  <td :colspan="langs.length + 1" class="tb-empty">Nessuna traduzione trovata</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Modale aggiungi/modifica -->
        <Teleport to="body">
          <div
            v-if="showForm"
            class="fixed inset-0 z-50 flex items-center justify-center"
            style="background-color: rgba(0, 0, 0, 0.5)"
            @click.self="closeForm"
          >
            <div class="bg-white rounded-xl shadow-lg w-full max-w-lg overflow-hidden" style="padding: 0">
              <div class="border-b border-gray-100" style="padding: 20px">
                <h3 class="font-bold text-lg">
                  {{ isEditingExistingKey ? 'Modifica traduzione' : 'Aggiungi traduzione' }}
                </h3>
              </div>

              <div style="padding: 20px">
                <div style="margin-bottom: 16px">
                  <label class="text-sm font-semibold block" style="margin-bottom: 4px">Chiave</label>
                  <TextInput v-model="formKey" :readonly="isEditingExistingKey" />
                </div>

                <div v-for="lang in langs" :key="lang" style="margin-bottom: 16px">
                  <label class="text-sm font-semibold uppercase block" style="margin-bottom: 4px">{{ lang }}</label>
                  <TextInput v-model="formValues[lang]" />
                </div>
              </div>

              <div class="flex justify-end bg-gray-50 border-t border-gray-100" style="padding: 20px; gap: 8px">
                <Button variant="secondary" @click="closeForm">Annulla</Button>
                <Button variant="primary" @click="submitForm">Salva</Button>
              </div>
            </div>
          </div>
        </Teleport>

        <!-- Modale riepilogo import -->
        <Teleport to="body">
          <div
            v-if="showImportSummary"
            class="fixed inset-0 z-50 flex items-center justify-center"
            style="background-color: rgba(0, 0, 0, 0.5)"
            @click.self="cancelImport"
          >
            <div class="bg-white rounded-xl shadow-lg w-full max-w-md overflow-hidden">
              <div style="padding: 20px">
                <p>
                  Verranno aggiunte <strong>{{ importSummary.newCount }}</strong> chiavi e sovrascritte
                  <strong>{{ importSummary.overwriteCount }}</strong> chiavi esistenti ({{ importSummary.lang }}). Procedi?
                </p>
              </div>
              <div class="flex justify-end bg-gray-50 border-t border-gray-100" style="padding: 20px; gap: 8px">
                <Button variant="secondary" @click="cancelImport">Annulla</Button>
                <Button variant="primary" @click="confirmImport">Procedi</Button>
              </div>
            </div>
          </div>
        </Teleport>
      </div>
    </template>
  </DefaultField>
</template>

<script>
import { FormField, HandlesValidationErrors } from 'laravel-nova'
import Button from '../../../../_shared/resources/js/components/Button.vue'
import SelectInput from '../../../../_shared/resources/js/components/SelectInput.vue'
import TextInput from '../../../../_shared/resources/js/components/TextInput.vue'
import { collectAllKeys } from '../../../../_shared/resources/js/utils/collectAllKeys.js'
export default {
  mixins: [FormField, HandlesValidationErrors],

  components: { Button, SelectInput, TextInput },

  props: ['resourceName', 'resourceId', 'field'],

  data() {
    return {
      state: {},
      selectedLang: null,
      searchQuery: '',
      showForm: false,
      formKey: '',
      formValues: {},
      isEditingExistingKey: false,
      showImportSummary: false,
      importSummary: { newCount: 0, overwriteCount: 0, entries: [], lang: null },
    }
  },

  computed: {
    langs() {
      return (this.field.value && this.field.value.langs) || ['it']
    },

    allKeys() {
      return collectAllKeys(this.langs, this.state)
    },

    filteredKeys() {
      if (!this.searchQuery) {
        return this.allKeys
      }
      const query = this.searchQuery.toLowerCase()
      return this.allKeys.filter(k => k.toLowerCase().includes(query))
    },
  },

  methods: {
    /*
     * Set the initial, internal value for the field.
     */
    setInitialValue() {
      const fieldValue = this.field.value || { langs: ['it'], values: {} }
      const langs = fieldValue.langs || ['it']
      const state = {}
      langs.forEach(lang => {
        state[lang] = { ...(fieldValue.values?.[lang] || {}) }
      })
      this.state = state
      this.selectedLang = langs[0]
      this.value = JSON.stringify(this.state)
    },

    /**
     * Fill the given FormData object with the field's internal value.
     */
    fill(formData) {
      formData.append(this.fieldAttribute, JSON.stringify(this.state))
    },

    /**
     * Unico punto di scrittura sullo stato in memoria: usato sia dal form
     * manuale sia dall'import file JSON.
     */
    upsertTranslation(key, valuesByLang) {
      this.langs.forEach(lang => {
        if (Object.prototype.hasOwnProperty.call(valuesByLang, lang)) {
          if (!this.state[lang]) {
            this.state[lang] = {}
          }
          this.state[lang][key] = valuesByLang[lang]
        }
      })
    },

    keyExists(key) {
      return this.langs.some(lang => Object.prototype.hasOwnProperty.call(this.state[lang] || {}, key))
    },

    openAddForm() {
      this.formKey = ''
      this.formValues = Object.fromEntries(this.langs.map(l => [l, '']))
      this.isEditingExistingKey = false
      this.showForm = true
    },

    openEditForm(key) {
      this.formKey = key
      this.formValues = Object.fromEntries(this.langs.map(l => [l, this.state[l]?.[key] ?? '']))
      this.isEditingExistingKey = true
      this.showForm = true
    },

    closeForm() {
      this.showForm = false
    },

    submitForm() {
      const key = this.formKey.trim()
      if (!key) {
        return
      }

      // Conferma di sovrascrittura solo per il form manuale, solo quando la
      // chiave digitata esiste già e non si tratta già di un'azione di modifica
      // esplicita (click su riga esistente) — vedi Requisiti in overview.md.
      if (!this.isEditingExistingKey && this.keyExists(key)) {
        if (!window.confirm('La chiave esiste già, vuoi sovrascriverla?')) {
          return
        }
      }

      this.upsertTranslation(key, { ...this.formValues })
      this.showForm = false
    },

    triggerFileInput() {
      if (this.$refs.fileInputEl) {
        this.$refs.fileInputEl.click()
      }
    },

    onFileSelected(event, lang) {
      const file = event.target.files && event.target.files[0]
      if (!file) {
        return
      }

      const reader = new FileReader()
      reader.onload = e => {
        let parsed
        try {
          parsed = JSON.parse(e.target.result)
        } catch (err) {
          window.alert('Il file selezionato non è un JSON valido.')
          event.target.value = ''
          return
        }

        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) {
          window.alert('Il file selezionato non è un JSON valido.')
          event.target.value = ''
          return
        }

        const entries = Object.entries(parsed)
          .map(([key, value]) => [String(key).trim(), value])
          .filter(([key, value]) => key !== '' && ['string', 'number', 'boolean'].includes(typeof value))
          .map(([key, value]) => [key, String(value)])
        const existing = this.state[lang] || {}
        let newCount = 0
        let overwriteCount = 0
        entries.forEach(([key]) => {
          if (Object.prototype.hasOwnProperty.call(existing, key)) {
            overwriteCount++
          } else {
            newCount++
          }
        })

        this.importSummary = { newCount, overwriteCount, entries, lang }
        this.showImportSummary = true
        event.target.value = ''
      }
      reader.readAsText(file)
    },

    cancelImport() {
      this.showImportSummary = false
      this.importSummary = { newCount: 0, overwriteCount: 0, entries: [], lang: null }
    },

    confirmImport() {
      const { entries, lang, newCount, overwriteCount } = this.importSummary
      entries.forEach(([key, value]) => {
        this.upsertTranslation(key, { [lang]: value })
      })
      this.showImportSummary = false
      this.importSummary = { newCount: 0, overwriteCount: 0, entries: [], lang: null }

      Nova.success(`${lang.toUpperCase()}: ${newCount} traduzioni aggiunte, ${overwriteCount} aggiornate.`)
    },

    downloadCurrent(lang) {
      const data = this.state[lang] || {}
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `translations_${lang}.json`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    },
  },
}
</script>

<style scoped>
/*
 * Stile scritto qui invece che come classi Tailwind: questo campo non ha una
 * build Tailwind propria e riusa solo le utility già presenti nel CSS
 * compilato di Nova — qualunque classe non usata altrove in Nova viene
 * eliminata dal suo purge e risulta senza effetto. Qui serve controllo preciso
 * (colonna chiave sticky, scroll interno) quindi si usa CSS puro, compilato
 * nel nostro dist/css/field.css.
 */
.tb-table-wrapper {
  /* Colori ripetuti nelle regole sottostanti, centralizzati qui una volta sola
     (ereditati dai discendenti via CSS custom properties). */
  --tb-border: #e5e7eb;
  --tb-border-light: #f3f4f6;
  --tb-bg-muted: #f9fafb;
  --tb-text-muted: #6b7280;
  --tb-text-faint: #9ca3af;

  border: 1px solid var(--tb-border);
  border-radius: 0.5rem;
  overflow: hidden;
}

.tb-table-scroll {
  /* ~10 righe visibili prima di scrollare: numero scelto per non far
     scomparire troppo la tabella su schermi piccoli, non un vincolo tecnico. */
  max-height: 420px;
  overflow: auto;
}

.tb-table {
  width: 100%;
  table-layout: fixed;
  border-collapse: separate;
  border-spacing: 0;
}

.tb-table th,
.tb-table td {
  padding: 8px 12px;
  font-size: 0.875rem;
  white-space: normal;
  overflow-wrap: break-word;
  word-break: break-word;
  border-bottom: 1px solid var(--tb-border-light);
  border-right: 1px solid var(--tb-border-light);
  text-align: left;
}

.tb-table th:last-child,
.tb-table td:last-child {
  border-right: none;
}

.tb-table thead th {
  position: sticky;
  top: 0;
  background: var(--tb-bg-muted);
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--tb-text-muted);
  z-index: 2;
}

.tb-table .tb-key-col {
  position: sticky;
  left: 0;
  background-color: #ffffff;
  font-family: monospace;
  border-right: 1px solid var(--tb-border);
  box-shadow: 2px 0 4px -2px rgba(0, 0, 0, 0.12);
  z-index: 1;
  /* Larghezza fissa per colonna: abbastanza per la maggior parte delle chiavi
     senza lasciare che una chiave lunga comprima le colonne lingua. */
  width: 200px;
  white-space: normal;
  word-break: break-word;
}

.tb-table th:not(.tb-key-col),
.tb-table td:not(.tb-key-col) {
  /* Larghezza fissa per colonna lingua: spazio ragionevole per una traduzione
     tipica: table-layout:fixed richiede una larghezza esplicita su ogni
     colonna, altrimenti il browser la comprime al minimo (vedi notes.md). */
  width: 220px;
}

.tb-table thead .tb-key-col {
  background-color: var(--tb-bg-muted);
  z-index: 3;
}

.tb-row:hover .tb-key-col {
  background-color: var(--tb-bg-muted);
}

.tb-row {
  cursor: pointer;
}

.tb-row:hover td {
  background: var(--tb-bg-muted);
}

.tb-empty {
  color: var(--tb-text-faint);
  text-align: center;
  white-space: normal;
}
</style>

