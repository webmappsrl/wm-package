<template>
  <card class="p-6" ref="cardRoot">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
      <h4 style="font-size:0.75rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:0.05em; margin:0;">
        {{ card.mode === 'global' ? "Analytics — Tutti i cammini" : `Analytics Layer — ${rangeLabel}` }}
        <span
          v-if="card.mode === 'global'"
          style="display:inline-block; margin-left:8px; padding:2px 8px; border-radius:10px; background:#e5e7eb; color:#374151; font-size:0.65rem; font-weight:500; text-transform:none;"
        >Tutti i layer</span>
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
        <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Aperture cammini giornaliere per piattaforma</p>
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

      <!-- Classifiche globali (solo modalità globale) -->
      <div v-if="card.mode === 'global' && (data.ranking_layers?.length || data.ranking_tracks?.length || data.ranking_track_shares?.length || data.ranking_search_queries?.length)" style="margin-top:24px;">
        <div v-if="data.ranking_layers?.length" style="margin-bottom:24px;">
          <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Cammini più aperti</p>
          <div style="display:flex; align-items:center; justify-content:center; gap:16px; margin-bottom:16px;">
            <div v-for="p in platforms" :key="p.lib" style="display:flex; align-items:center; gap:6px;">
              <span :style="{ display:'inline-block', width:'40px', height:'12px', borderRadius:'2px', background: p.color }"></span>
              <span style="font-size:12px; color:#374151;">{{ p.label }}</span>
            </div>
          </div>
          <div>
            <div
              v-for="row in visibleLayerRanking"
              :key="row.layer_id"
              style="display:flex; align-items:center; gap:12px; margin-bottom:8px;"
            >
              <a
                :href="layerDetailUrl(row.layer_id)"
                target="_blank"
                rel="noopener noreferrer"
                @mouseenter="hoveredLayerLinkId = row.layer_id"
                @mouseleave="hoveredLayerLinkId = null"
                @focus="hoveredLayerLinkId = row.layer_id"
                @blur="hoveredLayerLinkId = null"
                :style="{
                  width: '220px', flexShrink: 0, fontSize: '0.8125rem',
                  whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis',
                  color: hoveredLayerLinkId === row.layer_id ? '#10b981' : '#374151',
                  textDecoration: hoveredLayerLinkId === row.layer_id ? 'underline' : 'none',
                  transition: 'color 150ms ease',
                }"
              >{{ row.name }}</a>
              <div style="flex:1; display:flex; align-items:center; gap:8px; min-width:0;">
                <div
                  tabindex="0"
                  style="position:relative; flex:1; background:#f3f4f6; border-radius:0 4px 4px 0; height:20px; overflow:visible; display:flex; outline:none;"
                  @mouseenter="hoveredLayerId = row.layer_id"
                  @mouseleave="hoveredLayerId = null"
                  @focus="hoveredLayerId = row.layer_id"
                  @blur="hoveredLayerId = null"
                >
                  <div style="position:absolute; inset:0; border-radius:0 4px 4px 0; overflow:hidden; display:flex;">
                    <div
                      v-for="seg in layerBarSegments(row)"
                      :key="seg.lib"
                      :style="{ width: seg.widthPercent + '%', height: '20px', background: seg.color, borderRadius: seg.isLast ? '0 4px 4px 0' : '0', filter: hoveredLayerId === row.layer_id ? 'brightness(1.1)' : 'none' }"
                    ></div>
                  </div>
                  <div
                    v-if="hoveredLayerId === row.layer_id"
                    style="position:absolute; bottom:calc(100% + 6px); left:0; z-index:20; background:rgba(0,0,0,0.8); color:#fff; padding:6px; border-radius:6px; font-size:12px; white-space:nowrap;"
                  >
                    <div style="font-weight:bold; margin-bottom:6px;">{{ row.name }}</div>
                    <div v-for="seg in layerBarTooltipRows(row)" :key="seg.lib" style="display:flex; align-items:center; gap:8px; line-height:1.4;">
                      <span :style="{ display:'inline-block', width:'10px', height:'10px', borderRadius:'2px', background: seg.color, flexShrink:0 }"></span>
                      <span>{{ seg.label }}: {{ seg.total }}</span>
                    </div>
                  </div>
                </div>
                <span style="width:44px; flex-shrink:0; font-size:0.8125rem; font-weight:600; color:#6b7280; text-align:right;">{{ row.total }}</span>
              </div>
            </div>
          </div>
          <button
            v-if="data.ranking_layers.length > 10"
            @click="showAllLayers = !showAllLayers"
            style="margin-top:8px; font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
          >{{ showAllLayers ? 'Mostra meno' : `Mostra tutti (${data.ranking_layers.length})` }}</button>
        </div>

        <div
          v-if="!showRestOfAnalytics && (data.ranking_tracks?.length || data.ranking_track_shares?.length || data.ranking_search_queries?.length)"
          style="position:relative; text-align:center; margin:8px 0 24px;"
        >
          <div style="position:absolute; top:50%; left:0; right:0; height:1px; background:rgba(128,128,128,0.2); z-index:0;"></div>
          <button
            @click="showRestOfAnalytics = true"
            aria-expanded="false"
            style="position:relative; z-index:1; display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; font-weight:500; padding:6px 16px; border-radius:20px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer;"
          >
            <span style="display:inline-block;">▾</span>
            Mostra altre statistiche
          </button>
        </div>

        <div v-show="showRestOfAnalytics">
          <div style="overflow-x:auto;">
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; min-width:600px;">
            <div>
              <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Tappe più scaricate</p>
              <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
                <thead>
                  <tr style="border-bottom:1px solid rgba(128,128,128,0.3);">
                    <th style="text-align:left; padding:6px 8px; font-weight:500; opacity:0.6;">Tappa</th>
                    <th style="text-align:right; padding:6px 8px; font-weight:500; opacity:0.6;">Download</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in visibleTrackRanking" :key="row.track_id" style="border-bottom:1px solid rgba(128,128,128,0.15);">
                    <td style="padding:6px 8px;">{{ row.name }}</td>
                    <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.downloads }}</td>
                  </tr>
                </tbody>
              </table>
              <button
                v-if="data.ranking_tracks.length > 10"
                @click="showAllTracks = !showAllTracks"
                style="margin-top:8px; font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
              >{{ showAllTracks ? 'Mostra meno' : `Mostra tutti (${data.ranking_tracks.length})` }}</button>
            </div>
            <div>
              <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Tappe più condivise</p>
              <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
                <thead>
                  <tr style="border-bottom:1px solid rgba(128,128,128,0.3);">
                    <th style="text-align:left; padding:6px 8px; font-weight:500; opacity:0.6;">Tappa</th>
                    <th style="text-align:right; padding:6px 8px; font-weight:500; opacity:0.6;">Condivisioni</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in visibleTrackShares" :key="row.track_id" style="border-bottom:1px solid rgba(128,128,128,0.15);">
                    <td style="padding:6px 8px;">{{ row.name }}</td>
                    <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.shares }}</td>
                  </tr>
                </tbody>
              </table>
              <button
                v-if="data.ranking_track_shares.length > 10"
                @click="showAllTrackShares = !showAllTrackShares"
                style="margin-top:8px; font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
              >{{ showAllTrackShares ? 'Mostra meno' : `Mostra tutti (${data.ranking_track_shares.length})` }}</button>
            </div>
          </div>
          </div>

          <div v-if="data.ranking_search_queries?.length" style="margin-top:24px;">
            <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Ricerche</p>
            <div style="background:#f9fafb; border-radius:8px; padding:16px; text-align:center; max-width:240px; margin-bottom:16px;">
              <p style="font-size:2rem; font-weight:700; color:#10b981; margin:0;">{{ data.search_total }}</p>
              <p style="font-size:0.75rem; color:#6b7280; margin:4px 0 0;">Ricerche totali</p>
            </div>
            <p style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; margin-bottom:8px;">Ricerche più frequenti</p>
            <table style="width:100%; border-collapse:collapse; font-size:0.875rem;">
              <thead>
                <tr style="border-bottom:1px solid rgba(128,128,128,0.3);">
                  <th style="text-align:left; padding:6px 8px; font-weight:500; opacity:0.6;">Query</th>
                  <th style="text-align:right; padding:6px 8px; font-weight:500; opacity:0.6;">Ricerche</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in visibleSearchQueries" :key="row.query" style="border-bottom:1px solid rgba(128,128,128,0.15);">
                  <td style="padding:6px 8px;">{{ row.query }}</td>
                  <td style="padding:6px 8px; text-align:right; font-weight:600; color:#10b981;">{{ row.total }}</td>
                </tr>
              </tbody>
            </table>
            <button
              v-if="data.ranking_search_queries.length > 10"
              @click="showAllSearchQueries = !showAllSearchQueries"
              style="margin-top:8px; font-size:0.75rem; padding:4px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; color:#6b7280; cursor:pointer;"
            >{{ showAllSearchQueries ? 'Mostra meno' : `Mostra tutti (${data.ranking_search_queries.length})` }}</button>
          </div>

          <div style="position:relative; text-align:center; margin:24px 0 8px;">
            <div style="position:absolute; top:50%; left:0; right:0; height:1px; background:rgba(128,128,128,0.2); z-index:0;"></div>
            <button
              @click="showRestOfAnalytics = false"
              aria-expanded="true"
              style="position:relative; z-index:1; display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; font-weight:500; padding:6px 16px; border-radius:20px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer;"
            >
              <span style="display:inline-block; transform:rotate(180deg);">▾</span>
              Nascondi altre statistiche
            </button>
          </div>
        </div>
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
      showAllLayers: false,
      showAllTracks: false,
      showAllTrackShares: false,
      hoveredLayerId: null,
      hoveredLayerLinkId: null,
      showAllSearchQueries: false,
      showRestOfAnalytics: false,
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

    visibleLayerRanking() {
      if (!this.data?.ranking_layers) return []
      return this.showAllLayers ? this.data.ranking_layers : this.data.ranking_layers.slice(0, 10)
    },

    visibleTrackRanking() {
      if (!this.data?.ranking_tracks) return []
      return this.showAllTracks ? this.data.ranking_tracks : this.data.ranking_tracks.slice(0, 10)
    },

    visibleTrackShares() {
      if (!this.data?.ranking_track_shares) return []
      return this.showAllTrackShares ? this.data.ranking_track_shares : this.data.ranking_track_shares.slice(0, 10)
    },

    platforms() {
      return PLATFORMS
    },

    visibleSearchQueries() {
      if (!this.data?.ranking_search_queries) return []
      return this.showAllSearchQueries ? this.data.ranking_search_queries : this.data.ranking_search_queries.slice(0, 10)
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

    layerDetailUrl(layerId) {
      const novaPath = this.card.nova_path || '/nova'
      return `${novaPath}/resources/layers/${layerId}`
    },

    layerBarSegments(row) {
      const max = Math.max(...this.visibleLayerRanking.map((r) => r.total), 1)
      const breakdown = row.breakdown || []

      const segments = PLATFORMS.map(({ lib, color }) => {
        const entry = breakdown.find((b) => b.lib === lib)
        const value = entry ? entry.total : 0
        return { lib, color, widthPercent: (value / max) * 100 }
      }).filter((seg) => seg.widthPercent > 0)

      return segments.map((seg, i) => ({ ...seg, isLast: i === segments.length - 1 }))
    },

    layerBarTooltipRows(row) {
      const breakdown = row.breakdown || []
      return PLATFORMS
        .map(({ lib, label, color }) => {
          const entry = breakdown.find((b) => b.lib === lib)
          return entry ? { lib, label, color, total: entry.total } : null
        })
        .filter(Boolean)
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
      const suffix = this.card.mode === 'global' ? 'global' : this.card.layer_id
      link.download = `layer-analytics-${suffix}.png`
      link.href     = canvas.toDataURL('image/png')
      link.click()
    },
  },
}
</script>
