const { test } = require('node:test')
const assert = require('node:assert/strict')
const { readFileSync } = require('node:fs')
const { resolve } = require('node:path')
const vm = require('node:vm')

const source = readFileSync(
  resolve(__dirname, '../../../../../../plugin/sand-license/public/claim/claim.js'),
  'utf8'
)
const buttonIds = ['claim', 'reissue', 'refresh', 'copy', 'confirm-reissue', 'cancel-reissue']

function page(responses, hash = '#id=123&credential=sample-high-entropy-credential') {
  const nodes = new Map()
  const listeners = new Map()
  const requests = []
  const storage = new Map()
  const history = []
  let focused = ''
  function element(id) {
    if (!nodes.has(id))
      nodes.set(id, {
        value: '',
        textContent: '',
        hidden: ['claim', 'reissue', 'refresh', 'secret-panel', 'confirmation'].includes(id),
        disabled: false,
        handlers: {},
        addEventListener(event, callback) {
          this.handlers[event] = callback
        },
        focus() {
          focused = id
        },
        select() {
          this.selected = true
        }
      })
    return nodes.get(id)
  }
  const sandbox = {
    URLSearchParams,
    AbortController,
    setTimeout,
    clearTimeout,
    location: { hash, pathname: '/license/claim' },
    history: { replaceState: (...args) => history.push(args) },
    document: { getElementById: element, querySelectorAll: () => buttonIds.map(element) },
    window: { addEventListener: (event, callback) => listeners.set(event, callback) },
    navigator: {
      clipboard: {
        writeText: async () => {
          throw new Error('clipboard denied')
        }
      }
    },
    sessionStorage: {
      getItem: (key) => storage.get(key) || null,
      setItem: (key, value) => storage.set(key, value)
    },
    crypto: { randomUUID: () => 'test-random-identifier' },
    fetch: async (url, init) => {
      requests.push({ url, ...init })
      const next = await responses.shift()
      if (next instanceof Error) throw next
      return { ok: true, json: async () => ({ code: 200, data: next }) }
    }
  }
  vm.runInNewContext(source, sandbox)
  return {
    element,
    requests,
    storage,
    history,
    listeners,
    focused: () => focused,
    click: (id) => element(id).handlers.click(),
    settle: () => new Promise(setImmediate)
  }
}

test('fragment credential is removed, posted only, and never stored; successful claim exposes code once', async () => {
  const app = page([
    { state: 'ready' },
    { state: 'claimed', code: 'one-time-code' },
    { state: 'claimed' }
  ])
  await app.settle()
  assert.equal(app.history[0][2], '/license/claim')
  assert.equal(app.requests[0].url, '/api/sand-license/v1/claims/123/status')
  assert.equal(app.requests[0].method, 'POST')
  assert.equal(JSON.parse(app.requests[0].body).claim_credential, 'sample-high-entropy-credential')
  assert.equal(app.requests[0].referrerPolicy, 'no-referrer')
  assert.equal(app.requests[0].cache, 'no-store')
  assert.equal(app.element('claim').hidden, false)
  await app.click('claim')
  assert.equal(app.element('secret').value, 'one-time-code')
  assert.equal(app.element('secret-panel').hidden, false)
  for (const value of app.storage.values()) {
    assert.ok(!value.includes('credential'))
    assert.ok(!value.includes('one-time-code'))
  }
  await app.click('refresh')
  assert.equal(app.element('secret').value, '')
  assert.equal(app.element('reissue').hidden, false)
})

test('lost claim response requires status lookup then explicit reissue confirmation', async () => {
  const app = page([
    { state: 'ready' },
    new Error('connection lost'),
    { state: 'claimed' },
    { state: 'claimed', code: 'replacement' }
  ])
  await app.settle()
  await app.click('claim')
  assert.match(app.element('message').textContent, /先重新查询状态/)
  assert.equal(app.element('claim').hidden, true)
  assert.equal(app.element('reissue').hidden, true)
  await app.click('refresh')
  app.click('reissue')
  assert.equal(app.element('confirmation').hidden, false)
  assert.equal(app.requests.length, 3)
  await app.click('confirm-reissue')
  await app.settle()
  assert.equal(app.requests[3].url, '/api/sand-license/v1/claims/123/reissue')
  assert.equal(app.element('secret').value, 'replacement')
})

test('redeemed, revoked, expired, and refunded results do not offer reissue', async () => {
  for (const state of ['redeemed', 'revoked', 'expired', 'refunded', 'chargeback']) {
    const app = page([{ state }])
    await app.settle()
    assert.equal(app.element('claim').hidden, true)
    assert.equal(app.element('reissue').hidden, true)
    await app.click('confirm-reissue')
    assert.equal(app.requests.length, 1)
  }
})

test('missing fragment does not query any sensitive resource', async () => {
  const app = page([], '')
  await app.settle()
  assert.equal(app.requests.length, 0)
  assert.match(app.element('state').textContent, /原领取链接/)
})

test('revoked claim preserves a readable refund reason without offering reissue', async () => {
  const app = page([{ state: 'revoked', reason_code: 'refunded' }])
  await app.settle()
  assert.equal(app.element('state').textContent, '已退款')
  assert.equal(app.element('reissue').hidden, true)
})

test('clipboard failure allows manual copying, leaving page clears plaintext', async () => {
  const app = page([{ state: 'ready' }, { state: 'claimed', code: 'copy-me' }])
  await app.settle()
  await app.click('claim')
  await app.click('copy')
  assert.equal(app.element('secret').selected, true)
  assert.match(app.element('copy-result').textContent, /手动复制/)
  app.listeners.get('pagehide')()
  assert.equal(app.element('secret').value, '')
  app.listeners.get('pageshow')({ persisted: true })
  assert.equal(app.element('refresh').hidden, true)
  assert.match(app.element('state').textContent, /原领取链接/)
})

test('double submission is blocked and a response after leaving cannot restore plaintext', async () => {
  let complete
  const delayed = new Promise((resolve) => {
    complete = resolve
  })
  const app = page([{ state: 'ready' }, delayed])
  await app.settle()
  const request = app.click('claim')
  await app.click('claim')
  assert.equal(app.requests.length, 2)
  app.listeners.get('pagehide')()
  assert.equal(app.requests[1].signal.aborted, true)
  complete({ state: 'claimed', code: 'must-not-appear' })
  await request
  assert.equal(app.element('secret').value, '')
  assert.equal(app.element('secret-panel').hidden, true)
})
