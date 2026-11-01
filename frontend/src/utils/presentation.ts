import type { IncidentStatus, Priority } from '@/types/api'

/** Color and icon per status and priority; labels always come from the catalogs. */
export const STATUS_LOOK: Record<IncidentStatus, { color: string; icon: string }> = {
  open: { color: 'blue-7', icon: 'radio_button_unchecked' },
  in_progress: { color: 'indigo-6', icon: 'autorenew' },
  on_hold: { color: 'amber-9', icon: 'pause_circle_outline' },
  resolved: { color: 'teal-7', icon: 'task_alt' },
  closed: { color: 'grey-7', icon: 'lock' },
  cancelled: { color: 'blue-grey-5', icon: 'block' },
}

export const PRIORITY_LOOK: Record<Priority, { color: string; icon: string }> = {
  low: { color: 'grey-7', icon: 'keyboard_double_arrow_down' },
  medium: { color: 'blue-7', icon: 'drag_handle' },
  high: { color: 'orange-9', icon: 'keyboard_double_arrow_up' },
  critical: { color: 'red-8', icon: 'priority_high' },
}

/** UI name of each transition (docs/PLAN.md section 4); one command, several actions. */
export function transitionLabel(from: IncidentStatus, to: IncidentStatus): string {
  if (to === 'in_progress') return from === 'on_hold' ? 'Reanudar' : from === 'resolved' ? 'Reabrir' : 'Iniciar atención'
  return { on_hold: 'Poner en espera', resolved: 'Resolver', closed: 'Cerrar', cancelled: 'Cancelar', open: 'Abrir' }[to]
}

export const transitionIcon: Record<IncidentStatus, string> = {
  open: 'radio_button_unchecked',
  in_progress: 'play_arrow',
  on_hold: 'pause',
  resolved: 'task_alt',
  closed: 'lock',
  cancelled: 'block',
}
