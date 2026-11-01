import { ref } from 'vue'
import { isApiError } from '@/api/http'

/**
 * Maps a 422 VALIDATION_ERROR onto form fields (D-23). Use as
 * `<q-input :error="!!errors.title" :error-message="errors.title" @update:model-value="clearField('title')" />`.
 */
export function useServerErrors() {
  const errors = ref<Record<string, string>>({})

  /** Returns true when the error was a validation error and its messages are now on the fields. */
  function setFromError(error: unknown): boolean {
    errors.value = {}
    if (!isApiError(error) || error.code !== 'VALIDATION_ERROR' || !error.errors) return false

    errors.value = Object.fromEntries(Object.entries(error.errors).map(([field, messages]) => [field, messages[0]]))
    return true
  }

  function clearField(field: string): void {
    if (field in errors.value) {
      const { [field]: _removed, ...rest } = errors.value
      errors.value = rest
    }
  }

  return { errors, setFromError, clearField, clear: () => (errors.value = {}) }
}
