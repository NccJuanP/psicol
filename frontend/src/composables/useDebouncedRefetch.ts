import { onUnmounted } from 'vue'

/** Collapses a burst of realtime events into one refetch after `wait` ms of quiet. */
export function useDebouncedRefetch(refetch: () => unknown, wait: number): () => void {
  let timer: ReturnType<typeof setTimeout> | undefined
  onUnmounted(() => clearTimeout(timer))

  return () => {
    clearTimeout(timer)
    timer = setTimeout(refetch, wait)
  }
}
