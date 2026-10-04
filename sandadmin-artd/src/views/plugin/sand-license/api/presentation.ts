export function timeLabel(value: string | null | undefined): string {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '时间不可用'
  return `${new Intl.DateTimeFormat('zh-CN', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  }).format(date)}（本地时间）`
}

const states: Record<string, string> = {
  draft: '草稿',
  published: '已发布',
  issued: '未兑换',
  redeemed: '已兑换',
  revoked: '已撤销',
  expired: '已过期',
  active: '有效',
  suspended: '已暂停',
  released: '已释放',
  pending: '待处理',
  paid: '已支付',
  refunded: '已退款',
  chargeback: '已拒付',
  cancelled: '已取消续期',
  ready: '待领取',
  claimed: '已领取',
  succeeded: '已完成',
  not_found: '尚未完成'
}

export function stateLabel(value: string | null | undefined): string {
  return value ? (states[value] ?? value) : '—'
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : '请求未完成，请检查连接后重试。'
}

export function requestId(): string {
  return crypto.randomUUID()
}

export async function copyText(value: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(value)
    return true
  } catch {
    return false
  }
}
