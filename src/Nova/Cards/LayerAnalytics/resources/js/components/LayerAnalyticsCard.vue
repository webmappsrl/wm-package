<template>
  <card class="p-6" ref="cardRoot">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h4 style="font-size:0.75rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">
        Analytics Layer — {{ rangeLabel }}
      </h4>
      <div style="display:flex; gap:8px; align-items:center;">
        <select
          v-model="selectedRange"
          @change="onRangeChange"
          style="font-size:0.75rem; padding:4px 8px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer;"
        >
          <optgroup label="Finestre mobili">
            <option value="days:30">Ultimi 30 giorni</option>
            <option value="days:90">Ultimi 90 giorni</option>
            <option value="days:365">Ultimi 365 giorni</option>
          </optgroup>
          <optgroup label="Mese specifico">
            <option v-for="m in monthOptions" :key="m.value" :value="m.value">
              {{ m.label }}
            </option>
          </optgroup>
        </select>
        <button
          v-if="!loading && !error"
          @click="exportPng"
          style="font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
        >
          ↓ PNG
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-gray-400 text-sm">Caricamento...</div>
    <div v-else-if="error" class="text-red-500 text-sm">{{ error }}</div>

    <template v-else>
      <!-- KPI row -->
      <div style="display:flex; gap:16px; margin-bottom:24px;">
        <div style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ data.total }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Aperture totali</p>
        </div>
        <div style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ data.unique_users }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Utenti unici</p>
        </div>
        <div style="flex:1; background:#f9fafb; border-radius:8px; padding:16px; text-align:center;">
          <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ avgPerDay }}</p>
          <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Media/giorno</p>
        </div>
      </div>

      <!-- Stacked bar chart -->
      <div style="margin-bottom:24px;">
        <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Aperture giornaliere per piattaforma</p>
        <canvas ref="dailyChart" style="width:100%; height:220px;"></canvas>
      </div>

      <!-- Breakdown totali -->
      <div v-if="data.breakdown && data.breakdown.length">
        <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Totale per piattaforma</p>
        <div style="display:flex; gap:16px;">
          <div
            v-for="item in data.breakdown"
            :key="item.lib"
            style="display:flex; align-items:center; gap:8px; font-size:0.875rem;"
          >
            <span
              style="display:inline-block; width:12px; height:12px; border-radius:3px;"
              :style="{ backgroundColor: platformColor(item.lib) }"
            ></span>
            <span style="color:#6b7280;">{{ libLabel(item.lib) }}:</span>
            <span style="font-weight:600;">{{ item.total }}</span>
          </div>
        </div>
      </div>

      <!-- Download per traccia -->
      <div v-if="data.track_downloads && data.track_downloads.length" style="margin-top:24px;">
        <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Download per traccia</p>
        <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
          <thead>
            <tr style="border-bottom:1px solid rgba(128,128,128,0.3);">
              <th style="text-align:left; padding:6px 8px; font-weight:500; opacity:0.6;">Traccia</th>
              <th style="text-align:right; padding:6px 8px; font-weight:500; opacity:0.6;">Download</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in data.track_downloads"
              :key="row.track_id"
              style="border-bottom:1px solid rgba(128,128,128,0.15);"
            >
              <td style="padding:6px 8px;">{{ row.name }}</td>
              <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.downloads }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </card>
</template>

<script>
import html2canvas from 'html2canvas'
import {
  Chart,
  BarController,
  BarElement,
  LinearScale,
  CategoryScale,
  Tooltip,
  Legend,
} from 'chart.js'

Chart.register(BarController, BarElement, LinearScale, CategoryScale, Tooltip, Legend)

const PLATFORMS = [
  { lib: 'posthog-android', label: 'Android', color: '#10b981' },
  { lib: 'posthog-ios',     label: 'iOS',     color: '#6366f1' },
  { lib: 'web',             label: 'Webapp',  color: '#f59e0b' },
]

const MONTH_NAMES = ['Gennaio','Febbraio','Marzo','Aprile','Maggio','Giugno',
                     'Luglio','Agosto','Settembre','Ottobre','Novembre','Dicembre']

export default {
  props: {
    card: { type: Object, required: true },
  },

  data() {
    return {
      selectedRange: 'days:30',
      loading: true,
      error: null,
      data: null,
      chartInstance: null,
    }
  },

  computed: {
    trackingSince() {
      const raw = this.card.tracking_since
      if (!raw || !/^\d{4}-\d{2}/.test(raw)) return '2026-01-01'
      return raw
    },

    monthOptions() {
      const start   = new Date(this.trackingSince)
      const now     = new Date()
      const options = []
      const cursor  = new Date(start.getFullYear(), start.getMonth(), 1)

      while (cursor <= now) {
        const y = cursor.getFullYear()
        const m = String(cursor.getMonth() + 1).padStart(2, '0')
        options.push({
          value: `month:${y}-${m}`,
          label: `${MONTH_NAMES[cursor.getMonth()]} ${y}`,
        })
        cursor.setMonth(cursor.getMonth() + 1)
      }

      return options
    },

    rangeLabel() {
      if (this.selectedRange.startsWith('month:')) {
        const [y, m] = this.selectedRange.slice(6).split('-')
        return `${MONTH_NAMES[parseInt(m, 10) - 1]} ${y}`
      }
      const days = this.selectedRange.split(':')[1]
      return `Ultimi ${days} giorni`
    },

    avgPerDay() {
      if (!this.data?.daily_breakdown?.length) return 0
      const days = new Set(this.data.daily_breakdown.map((r) => r.date)).size
      return days ? Math.round(this.data.total / days) : 0
    },

    fetchUrl() {
      const base = this.card.endpoint
      if (this.selectedRange.startsWith('month:')) {
        const month = this.selectedRange.slice(6)
        return `${base}?month=${month}`
      }
      const days = this.selectedRange.split(':')[1]
      return `${base}?days=${days}`
    },
  },

  watch: {
    data(val) {
      if (val) this.$nextTick(() => this.renderChart())
    },
  },

  async mounted() {
    await this.fetchData()
  },

  beforeUnmount() {
    if (this.chartInstance) this.chartInstance.destroy()
  },

  methods: {
    async onRangeChange() {
      await this.fetchData()
    },

    async fetchData() {
      this.loading = true
      this.error   = null
      try {
        const response = await fetch(this.fetchUrl, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
          },
        })
        if (!response.ok) throw new Error(`HTTP ${response.status}`)
        this.data = await response.json()
      } catch (e) {
        this.error = 'Impossibile caricare i dati analytics.'
        console.error(e)
      } finally {
        this.loading = false
      }
    },

    renderChart() {
      const canvas = this.$refs.dailyChart
      if (!canvas || !this.data?.daily_breakdown) return
      if (this.chartInstance) this.chartInstance.destroy()

      const days = [...new Set(this.data.daily_breakdown.map((r) => r.date))].sort()

      const lookup = {}
      for (const row of this.data.daily_breakdown) {
        lookup[`${row.date}|${row.lib}`] = row.total
      }

      const datasets = PLATFORMS.map(({ lib, label, color }) => ({
        label,
        data: days.map((d) => lookup[`${d}|${lib}`] ?? 0),
        backgroundColor: color,
        borderRadius: 2,
      }))

      this.chartInstance = new Chart(canvas, {
        type: 'bar',
        data: { labels: days, datasets },
        options: {
          responsive: false,
          plugins: {
            legend: { position: 'top' },
            tooltip: { mode: 'index' },
          },
          scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
          },
        },
      })
    },

    platformColor(lib) {
      return PLATFORMS.find((p) => p.lib === lib)?.color ?? '#9ca3af'
    },

    libLabel(lib) {
      return PLATFORMS.find((p) => p.lib === lib)?.label ?? lib
    },

    async exportPng() {
      const el = this.$refs.cardRoot?.$el ?? this.$refs.cardRoot
      if (!el) return
      const canvas = await html2canvas(el, { backgroundColor: '#ffffff', scale: 2 })
      const link   = document.createElement('a')
      link.download = `layer-analytics-${this.card.layer_id}.png`
      link.href     = canvas.toDataURL('image/png')
      link.click()
    },
  },
}
</script>
