'use strict'

/**
 * @typedef {'ready'|'claimed'|'redeemed'|'revoked'|'expired'} ClaimState
 * @typedef {{state: ClaimState, reason_code?: 'refunded'|'chargeback'|'claim_revoked'|'',
 * product_name?: string, plan_name?: string, code?: string}} ClaimResult
 */
;(() => {
  const fragment = new URLSearchParams(location.hash.slice(1))
  const claimId = fragment.get('id') || ''
  let credential = fragment.get('credential') || ''
  history.replaceState(null, '', location.pathname)
  const element = (id) => document.getElementById(id)
  const state = element('state')
  const message = element('message')
  const secret = element('secret')
  const claimButton = element('claim')
  const reissueButton = element('reissue')
  const refreshButton = element('refresh')
  const confirmation = element('confirmation')
  let sessionKey = ''
  let busy = false
  let active = true
  let pendingRequest
  let knownState = ''

  function clearSecret() {
    secret.value = ''
    element('secret-panel').hidden = true
    element('copy-result').textContent = ''
  }

  /** @param {ClaimResult} result */
  function showStatus(result) {
    knownState = result.state
    const names = [result.product_name, result.plan_name].filter((name) => typeof name === 'string')
    element('product').textContent = names.join(' · ')
    const descriptions = {
      ready: ['可以领取', '领取后请立即保存授权码，在软件中兑换后开始计算许可期限。'],
      claimed: ['已领取', '授权码不再重复展示。如果此前没有保存，且尚未兑换，可明确重新签发。'],
      redeemed: ['已兑换', '该授权码已用于开通许可，不能重新签发。请在软件中查看现有许可。'],
      revoked: ['领取资格已撤销', '当前领取资格不可使用，请联系发放方核对处理结果。'],
      expired: ['领取资格已过期', '请联系发放方核对有效期并获取可用的领取链接。'],
      refunded: ['已退款', '对应购买已退款，不能再领取或重新签发授权码。'],
      chargeback: ['付款已拒付', '对应购买已停止履约，请联系发放方核对。']
    }
    const displayState =
      result.state === 'revoked' && ['refunded', 'chargeback'].includes(result.reason_code)
        ? result.reason_code
        : result.state
    const content = descriptions[displayState] || [
      '状态暂不可用',
      '请重新查询；若仍无法确认，请联系发放方。'
    ]
    state.textContent = content[0]
    message.textContent = content[1]
    claimButton.hidden = result.state !== 'ready'
    reissueButton.hidden = result.state !== 'claimed'
    refreshButton.hidden = false
  }

  async function request(action) {
    if (busy || !active) return
    busy = true
    const controller = new AbortController()
    pendingRequest = controller
    const timeout = setTimeout(() => controller.abort(), 15000)
    confirmation.hidden = true
    for (const button of document.querySelectorAll('button')) button.disabled = true
    clearSecret()
    try {
      const response = await fetch(
        `/api/sand-license/v1/claims/${encodeURIComponent(claimId)}/${action}`,
        {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'omit',
          cache: 'no-store',
          referrerPolicy: 'no-referrer',
          signal: controller.signal,
          body: JSON.stringify({
            claim_credential: credential,
            session_key: sessionKey,
            request_id: crypto.randomUUID()
          })
        }
      )
      const envelope = await response.json()
      if (!active) return
      if (!response.ok || !envelope || typeof envelope !== 'object' || envelope.code !== 200) {
        throw new Error('request_failed')
      }
      const result = envelope.data
      if (!result || typeof result !== 'object' || typeof result.state !== 'string')
        throw new Error('invalid_response')
      showStatus(result)
      if (action !== 'status' && typeof result.code === 'string' && result.code.length > 0) {
        secret.value = result.code
        element('secret-panel').hidden = false
        state.textContent = action === 'reissue' ? '新授权码已签发' : '领取成功'
        message.textContent = '请现在保存下方授权码。'
        secret.focus()
      }
    } catch {
      if (!active) return
      state.textContent = action === 'status' ? '暂时无法查询' : '尚未确认操作结果'
      message.textContent =
        action === 'status'
          ? '请检查网络后重新查询。若持续失败，请重新打开原领取链接或联系发放方。'
          : '请求可能已经完成。请先重新查询状态；若显示已领取但没有保存授权码，再选择重新签发。'
      claimButton.hidden = true
      reissueButton.hidden = true
      refreshButton.hidden = false
    } finally {
      clearTimeout(timeout)
      pendingRequest = undefined
      busy = false
      for (const button of document.querySelectorAll('button')) button.disabled = false
    }
  }

  claimButton.addEventListener('click', () => request('claim'))
  refreshButton.addEventListener('click', () => request('status'))
  reissueButton.addEventListener('click', () => {
    if (knownState !== 'claimed') return
    confirmation.hidden = false
    element('confirm-reissue').focus()
  })
  element('cancel-reissue').addEventListener('click', () => {
    confirmation.hidden = true
    reissueButton.focus()
  })
  element('confirm-reissue').addEventListener('click', () => {
    if (knownState === 'claimed') request('reissue')
  })
  element('copy').addEventListener('click', async () => {
    if (!secret.value) return
    try {
      await navigator.clipboard.writeText(secret.value)
      element('copy-result').textContent = '已复制，请粘贴到安全位置保存。'
    } catch {
      secret.focus()
      secret.select()
      element('copy-result').textContent = '自动复制不可用，已选中授权码，请手动复制。'
    }
  })
  window.addEventListener('pagehide', () => {
    active = false
    pendingRequest?.abort()
    credential = ''
    clearSecret()
  })
  window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return
    clearSecret()
    claimButton.hidden = true
    reissueButton.hidden = true
    refreshButton.hidden = true
    confirmation.hidden = true
    state.textContent = '请重新打开原领取链接'
    message.textContent = '为保护领取凭证，离开页面后不保留凭证和授权码。'
  })
  if (!/^[1-9][0-9]*$/.test(claimId) || !credential) {
    state.textContent = '请从原领取链接进入'
    message.textContent =
      '领取凭证不会保存在浏览器。刷新或关闭页面后，请重新打开发放方提供的完整领取链接。'
    return
  }
  try {
    const storageKey = `sand-license.claim-session.${claimId}`
    sessionKey = sessionStorage.getItem(storageKey) || crypto.randomUUID()
    sessionStorage.setItem(storageKey, sessionKey)
  } catch {
    state.textContent = '浏览器无法保持领取会话'
    message.textContent =
      '请允许本页使用会话存储后重新打开领取链接，避免领码后无法确认同一会话。会话存储不会保存领取凭证或授权码。'
    return
  }
  request('status')
})()
