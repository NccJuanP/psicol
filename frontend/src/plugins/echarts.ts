import { BarChart, LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { use } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'

// Only what the dashboard draws, so the bundle stays small.
use([CanvasRenderer, BarChart, LineChart, GridComponent, TooltipComponent, LegendComponent])

/**
 * Chart tokens. Series colors are the first two slots of the validated categorical order
 * (dataviz reference palette: blue, orange); "overdue" uses the app's negative status color and
 * always carries a text label. Text stays in ink colors, never series colors.
 */
export const CHART = {
  series1: '#2a78d6',
  series2: '#eb6834',
  overdue: '#c62828',
  onTime: '#8fb3dc',
  text: '#52514e',
  grid: '#e6e6e3',
  font: 'Roboto, -apple-system, "Segoe UI", sans-serif',
} as const

export const formatNumber = (value: number): string => value.toLocaleString('es-CO')

export const baseOption = {
  textStyle: { fontFamily: CHART.font, color: CHART.text },
  tooltip: {
    confine: true,
    backgroundColor: '#fff',
    borderColor: CHART.grid,
    textStyle: { color: '#0b0b0b' },
    valueFormatter: (value: unknown) => (typeof value === 'number' ? formatNumber(value) : String(value)),
  },
  animationDuration: 300,
}
