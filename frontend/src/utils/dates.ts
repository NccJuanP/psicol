// S-08: the API speaks UTC; the interface shows and reads America/Bogota.
const ZONE = 'America/Bogota'
const OFFSET = '-05:00' // Colombia has no daylight saving time, so the offset is fixed

const dateTimeFormat = new Intl.DateTimeFormat('es-CO', { timeZone: ZONE, dateStyle: 'medium', timeStyle: 'short' })
const dateFormat = new Intl.DateTimeFormat('es-CO', { timeZone: ZONE, dateStyle: 'medium' })
const partsFormat = new Intl.DateTimeFormat('en-CA', {
  timeZone: ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  hourCycle: 'h23',
})

export const formatDateTime = (iso: string | null | undefined): string => (iso ? dateTimeFormat.format(new Date(iso)) : '—')

export const formatDate = (iso: string | null | undefined): string => (iso ? dateFormat.format(new Date(iso)) : '—')

/** UTC ISO → "YYYY-MM-DDTHH:mm" in Bogota, the value of an <input type="datetime-local">. */
export function toLocalInput(iso: string): string {
  const p = Object.fromEntries(partsFormat.formatToParts(new Date(iso)).map((part) => [part.type, part.value]))
  return `${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}`
}

/** "YYYY-MM-DDTHH:mm" typed as Bogota time → UTC ISO for the API. */
export const fromLocalInput = (value: string): string => new Date(`${value}:00${OFFSET}`).toISOString()
