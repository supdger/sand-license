import type { Resource, ResourceRow } from './types'
import { stateLabel, timeLabel } from './presentation'

export const sections: { key: Resource; label: string; help: string; states: string[] }[] = [
  {
    key: 'product',
    label: '软件产品',
    help: '创建产品并发布后，可为它配置套餐。产品编码与许可受众用于客户端接入。',
    states: ['draft', 'published']
  },
  {
    key: 'plan',
    label: '套餐与功能',
    help: '套餐保存为草稿，发布后版本固定。调整许可内容时创建新版本。',
    states: ['draft', 'published']
  },
  {
    key: 'code',
    label: '卡密发放',
    help: '手工签发的软件授权码仅显示一次。列表只保留前缀；未兑换的丢失码可以重新签发。',
    states: ['issued', 'redeemed', 'revoked', 'expired']
  },
  {
    key: 'entitlement',
    label: '已发放权益',
    help: '查看实际开通的许可期限和设备额度；软件许可从首次兑换起算。',
    states: ['active', 'suspended', 'revoked', 'expired']
  },
  {
    key: 'activation',
    label: '设备激活',
    help: '释放或重置后，旧设备立即停止续签，席位在最后一次租约到期后可再次使用。',
    states: ['active', 'released', 'revoked']
  },
  {
    key: 'sku-mapping',
    label: '商品映射',
    help: '把可信渠道的商品 SKU 对应到已发布套餐，供通用履约接口发放。',
    states: []
  },
  {
    key: 'fulfillment',
    label: '履约记录',
    help: '按订单项与计费周期核对支付、退款和取消记录；同一次购买不会重复发放。',
    states: ['pending', 'paid', 'refunded', 'chargeback']
  },
  {
    key: 'membership',
    label: '会员权益',
    help: '会员归属于产品中的业务主体。查看每笔来源周期；取消续期不撤销当前已付权益，退款只影响对应来源。',
    states: ['active', 'expired', 'revoked']
  },
  {
    key: 'event',
    label: '操作记录',
    help: '核对操作者、动作、对象与请求编号。记录不包含授权码、领取凭证或私钥。',
    states: []
  }
]

export type Field = { prop: keyof ResourceRow; label: string; minWidth?: number }
const field = (prop: keyof ResourceRow, label: string, minWidth = 140): Field => ({
  prop,
  label,
  minWidth
})
export const fields: Record<Resource, Field[]> = {
  product: [
    field('name', '产品'),
    field('code', '产品编码'),
    field('audience', '许可受众'),
    field('state', '状态')
  ],
  plan: [
    field('name', '套餐'),
    field('product_name', '产品'),
    field('revision', '版本', 80),
    field('kind', '类型'),
    field('duration_value', '有效期', 100),
    field('seat_limit', '设备数', 90),
    field('state', '状态')
  ],
  code: [
    field('prefix', '授权码前缀'),
    field('product_name', '产品'),
    field('plan_name', '套餐'),
    field('state', '状态'),
    field('create_time', '签发时间', 230)
  ],
  entitlement: [
    field('id', '权益编号'),
    field('product_name', '产品'),
    field('plan_name', '套餐'),
    field('state', '状态'),
    field('expire_time', '到期时间', 230),
    field('seat_limit', '设备数', 90)
  ],
  activation: [
    field('name', '设备名称'),
    field('product_name', '产品'),
    field('entitlement_id', '权益编号'),
    field('state', '状态'),
    field('lease_expire_time', '最后租约到期', 230),
    field('seat_available_time', '席位可用时间', 230)
  ],
  'sku-mapping': [
    field('channel_code', '渠道'),
    field('sku_code', '商品 SKU'),
    field('product_name', '产品'),
    field('plan_name', '套餐'),
    field('status', '是否启用', 100)
  ],
  fulfillment: [
    field('order_id', '订单'),
    field('order_item_id', '订单项'),
    field('payment_cycle_id', '计费周期'),
    field('product_name', '产品'),
    field('state', '支付状态'),
    field('cancelled_time', '取消续期时间', 230)
  ],
  membership: [
    field('subject_code', '业务主体'),
    field('product_name', '产品'),
    field('state', '权益状态'),
    field('start_time', '开始时间', 230),
    field('expire_time', '到期时间', 230)
  ],
  event: [
    field('action', '动作'),
    field('object_type', '对象类型'),
    field('object_id', '对象编号'),
    field('actor_ref', '操作者'),
    field('request_id', '请求编号', 240),
    field('create_time', '操作时间', 230)
  ]
}

export function display(row: ResourceRow, prop: keyof ResourceRow): string {
  const value = row[prop]
  if (prop.endsWith('_time')) return timeLabel(typeof value === 'string' ? value : null)
  if (prop === 'state') return stateLabel(row.state)
  if (prop === 'status') return value === 1 ? '启用' : value === 2 ? '停用' : '—'
  if (prop === 'kind')
    return value === 'desktop' ? '软件许可' : value === 'membership' ? '会员权益' : '—'
  if (prop === 'duration_value')
    return `${row.duration_value ?? '—'} ${row.duration_unit === 'day' ? '天' : row.duration_unit === 'year' ? '年' : '月'}`
  if (value === undefined || value === null || typeof value === 'object') return '—'
  return String(value)
}

export function permission(resource: Resource, action: string): string {
  return `sand_license:${resource.replace('-', '_')}:${action}`
}
