<script setup lang="ts">
import { computed } from 'vue'
import VChart from 'vue-echarts'
import { CHART, baseOption, formatNumber } from '@/plugins/echarts'
import type { DashboardStats } from '@/types/api'

const props = defineProps<{ data: DashboardStats['trend'] }>()
const emit = defineEmits<{ select: [date: string] }>()

const shortDate = (date: string) => `${date.slice(8, 10)}/${date.slice(5, 7)}`

const option = computed(() => ({
  ...baseOption,
  legend: { top: 0, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 12, textStyle: { color: CHART.text } },
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  tooltip: { ...baseOption.tooltip, trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: CHART.text, type: 'dashed' } } },
  xAxis: {
    type: 'category',
    boundaryGap: false,
    data: props.data.map((row) => shortDate(row.date)),
    axisTick: { show: false },
    axisLine: { lineStyle: { color: CHART.grid } },
    axisLabel: { color: CHART.text, hideOverlap: true },
  },
  yAxis: { type: 'value', minInterval: 1, splitLine: { lineStyle: { color: CHART.grid } }, axisLabel: { color: CHART.text, formatter: formatNumber } },
  series: [
    {
      type: 'line',
      name: 'Creados',
      data: props.data.map((row) => row.created),
      lineStyle: { width: 2 },
      itemStyle: { color: CHART.series1 },
      symbol: 'circle',
      symbolSize: 8,
      showSymbol: false,
      emphasis: { focus: 'series' },
      cursor: 'pointer',
    },
    {
      type: 'line',
      name: 'Resueltos',
      data: props.data.map((row) => row.resolved),
      lineStyle: { width: 2 },
      itemStyle: { color: CHART.series2 },
      symbol: 'circle',
      symbolSize: 8,
      showSymbol: false,
      emphasis: { focus: 'series' },
    },
  ],
}))

// Only "created" has a listing equivalent (created_at range); resolved_at is not a list filter.
function onClick(event: { dataIndex: number; seriesName?: string }): void {
  if (event.seriesName === 'Creados') emit('select', props.data[event.dataIndex].date)
}
</script>

<template>
  <v-chart
    :option="option"
    autoresize
    class="chart"
    role="img"
    :aria-label="`Creados frente a resueltos en los últimos 30 días: ${data.reduce((s, r) => s + r.created, 0)} creados y ${data.reduce((s, r) => s + r.resolved, 0)} resueltos`"
    @click="onClick"
  />
</template>

<style scoped>
.chart {
  height: 280px;
}
</style>
