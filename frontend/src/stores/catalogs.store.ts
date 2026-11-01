import { defineStore } from 'pinia'
import { ref } from 'vue'
import { catalogsApi } from '@/api/catalogs.api'
import type { Catalogs, IncidentStatus, IncidentType, Priority } from '@/types/api'

/** Loaded once after login; labels always come from the backend enums. */
export const useCatalogsStore = defineStore('catalogs', () => {
  const catalogs = ref<Catalogs | null>(null)
  let loading: Promise<void> | null = null

  function load(): Promise<void> {
    loading ??= catalogsApi
      .get()
      .then((data) => {
        catalogs.value = data
      })
      .catch((error: unknown) => {
        loading = null // allow a retry
        throw error
      })
    return loading
  }

  const label = <T extends string>(items: { value: T; label: string }[] | undefined, value: T) =>
    items?.find((item) => item.value === value)?.label ?? value

  return {
    catalogs,
    load,
    priorityLabel: (value: Priority) => label(catalogs.value?.priorities, value),
    statusLabel: (value: IncidentStatus) => label(catalogs.value?.statuses, value),
    typeLabel: (value: IncidentType) => label(catalogs.value?.types, value),
  }
})
