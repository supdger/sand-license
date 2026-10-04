import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createRenderer, h } from 'vue'
import { useResourceList, type Page } from '../api/useResourceList'

interface Row {
  id: string
}
interface Params {
  query: string
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (failure: Error) => void
  const promise = new Promise<T>((yes, no) => {
    resolve = yes
    reject = no
  })
  return { promise, resolve, reject }
}
function fixture() {
  const pending: ReturnType<typeof deferred<Page<Row>>>[] = []
  const signals: AbortSignal[] = []
  let state!: ReturnType<typeof useResourceList<Row, Params>>
  const renderer = createRenderer<object, object>({
    patchProp() {},
    insert() {},
    remove() {},
    setText() {},
    setElementText() {},
    createElement: () => ({}),
    createText: () => ({}),
    createComment: () => ({}),
    parentNode: () => null,
    nextSibling: () => null
  })
  const app = renderer.createApp({
    setup() {
      state = useResourceList<Row, Params>((_params, signal) => {
        const request = deferred<Page<Row>>()
        pending.push(request)
        signals.push(signal)
        return request.promise
      })
      return () => h('span')
    }
  })
  app.mount({})
  return { state, pending, signals, close: () => app.unmount() }
}
const page = (id: string): Page<Row> => ({
  data: [{ id }],
  current_page: 1,
  per_page: 20,
  total: 1,
  server_time: '2026-10-03T00:00:00Z'
})

test('an old failure cannot clear a newer successful query or change its error', async () => {
  const { state, pending, signals, close } = fixture()
  const old = state.load({ query: 'old' })
  const current = state.load({ query: 'new' })
  assert.equal(signals[0].aborted, true)
  pending[1].resolve(page('new'))
  await current
  pending[0].reject(new Error('old request failed'))
  await old
  assert.deepEqual(state.rows.value, [{ id: 'new' }])
  assert.equal(state.error.value, '')
  assert.equal(state.loading.value, false)
  close()
})

test('an old success cannot end a new loading state; unmount invalidates pending completion', async () => {
  const { state, pending, close } = fixture()
  const old = state.load({ query: 'old' })
  const current = state.load({ query: 'new' })
  pending[0].resolve(page('old'))
  await old
  assert.equal(state.loading.value, true)
  assert.deepEqual(state.rows.value, [])
  close()
  pending[1].resolve(page('new'))
  await current
  assert.deepEqual(state.rows.value, [])
  assert.equal(state.loading.value, false)
})
