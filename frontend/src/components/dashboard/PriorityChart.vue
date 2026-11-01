<script setup lang="ts">
import { computed } from 'vue'
import VChart from 'vue-echarts'
import { CHART, baseOption, formatNumber } from '@/plugins/echarts'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { DashboardStats, Priority } from '@/types/api'

const props = defineProps<{ data: DashboardStats['by_priority'] }>()
const emit = defineEmits<{ select: [priority: Priority, overdueOnly: boolean] }>()
const catalogs = useCatalogsStore()

// ECharts draws the first category at the bottom, so API order (low → critical) puts critical on top.
// Each bar splits into overdue (highlighted) and the rest.
const rows = computed(() => props.data)

const option = computed(() => ({
  ...baseOption,
  legend: { top: 0, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 12, textStyle: { color: CHART.text } },
  grid: { left: 8, right: 16, top: 32, bottom: 8, containLabel: true },
  tooltip: { ...baseOption.tooltip, trigger: 'axis', axisPointer: { type: 'shadow' } },
  xAxis: {
    type: 'value',
    splitLine: { lineStyle: { color: CHART.grid } },
    axisLabel: { color: CHART.text, formatter: formatNumber, hideOverlap: true },
  },
  yAxis: {
    type: 'category',
    data: rows.value.map((row) => catalogs.priorityLabel(row.priority)),
    axisTick: { show: false },
    axisLine: { show: false },
    axisLabel: { color: CHART.text },
  },
  series: [
    {
      type: 'bar',
      name: 'Vencidos',
      stack: 'total',
      data: rows.value.map((row) => row.overdue),
      barMaxWidth: 18,
      itemStyle: { color: CHART.overdue, borderColor: '#fff', borderWidth: 1 },
      cursor: 'pointer',
    },
    {
      type: 'bar',
      name: 'Resto',
      stack: 'total',
      data: rows.value.map((row) => row.count - row.overdue),
      barMaxWidth: 18,
      itemStyle: { color: CHART.onTime, borderColor: '#fff', borderWidth: 1, borderRadius: [0, 4, 4, 0] },
      cursor: 'pointer',
    },
  ],
}))
</script>

<template>
  <v-chart
    :option="option"
    autoresize
    class="chart"
    role="img"
    :aria-label="`Incidentes por prioridad: ${data.map((r) => `${catalogs.priorityLabel(r.priority)} ${r.count}, ${r.overdue} vencidos`).join('; ')}`"
    @click="
      (e: { dataIndex: number; seriesName?: string }) =>
        emit('select', rows[e.dataIndex].priority, e.seriesName === 'Vencidos')
    "
  />
</template>

<style scoped>
.chart {
  height: 260px;
}
</style>
