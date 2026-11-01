<script setup lang="ts">
import { computed } from 'vue'
import VChart from 'vue-echarts'
import { CHART, baseOption } from '@/plugins/echarts'
import { useCatalogsStore } from '@/stores/catalogs.store'
import type { DashboardStats, IncidentStatus } from '@/types/api'

const props = defineProps<{ data: DashboardStats['by_status'] }>()
const emit = defineEmits<{ select: [status: IncidentStatus] }>()
const catalogs = useCatalogsStore()

// One hue: the axis labels already name each bar, so color carries no identity here.
const option = computed(() => ({
  ...baseOption,
  grid: { left: 8, right: 48, top: 8, bottom: 8, containLabel: true },
  tooltip: { ...baseOption.tooltip, trigger: 'item' },
  // Each bar carries its value, so the value axis would only repeat it (and collide on phones).
  xAxis: { type: 'value', splitLine: { lineStyle: { color: CHART.grid } }, axisLabel: { show: false } },
  yAxis: {
    type: 'category',
    inverse: true, // lifecycle order top to bottom
    data: props.data.map((row) => catalogs.statusLabel(row.status)),
    axisTick: { show: false },
    axisLine: { show: false },
    axisLabel: { color: CHART.text },
  },
  series: [
    {
      type: 'bar',
      name: 'Incidentes',
      data: props.data.map((row) => row.count),
      barMaxWidth: 18,
      itemStyle: { color: CHART.series1, borderRadius: [0, 4, 4, 0] },
      label: { show: true, position: 'right', color: CHART.text, formatter: ({ value }: { value: number }) => value.toLocaleString('es-CO') },
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
    :aria-label="`Incidentes por estado: ${data.map((r) => `${catalogs.statusLabel(r.status)} ${r.count}`).join(', ')}`"
    @click="(e: { dataIndex: number }) => emit('select', data[e.dataIndex].status)"
  />
</template>

<style scoped>
.chart {
  height: 260px;
}
</style>
