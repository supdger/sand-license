import { onBeforeUnmount, ref, shallowRef } from 'vue'
import { errorMessage } from './presentation'

export interface Page<T> {
  current_page: number
  per_page: number
  total: number
  data: T[]
  server_time: string
}

/**
 * Owns only this plugin's asynchronous list state; display and HTTP stay in
 * SandAdmin. Every completion branch checks ownership of the current request.
 */
export function useResourceList<T, P>(
  fetchPage: (params: P, signal: AbortSignal) => Promise<Page<T>>
) {
  const rows = shallowRef<T[]>([])
  const loading = ref(false)
  const error = ref('')
  const total = ref(0)
  const serverTime = ref('')
  let sequence = 0
  let controller: AbortController | undefined

  async function load(params: P): Promise<void> {
    const current = ++sequence
    controller?.abort()
    const pending = new AbortController()
    controller = pending
    loading.value = true
    error.value = ''
    try {
      const page = await fetchPage(params, pending.signal)
      if (current !== sequence) return
      rows.value = page.data
      total.value = page.total
      serverTime.value = page.server_time
    } catch (failure) {
      if (current !== sequence) return
      rows.value = []
      total.value = 0
      error.value = errorMessage(failure)
    } finally {
      if (current === sequence) loading.value = false
    }
  }

  function clear(): void {
    ++sequence
    controller?.abort()
    rows.value = []
    total.value = 0
    loading.value = false
    error.value = ''
    serverTime.value = ''
  }

  onBeforeUnmount(clear)
  return { rows, loading, error, total, serverTime, load, clear }
}
