export type Resource =
  | 'product'
  | 'plan'
  | 'code'
  | 'entitlement'
  | 'activation'
  | 'sku-mapping'
  | 'fulfillment'
  | 'membership'
  | 'event'
export type EditableResource = 'product' | 'plan' | 'sku-mapping'
export type FeatureValue = number | boolean
export type Features = Record<string, FeatureValue>

/** Fields from the resource projection; absent columns are not applicable. */
export interface ResourceRow {
  id: string
  state?: string
  status?: number
  organization_id?: string
  application_id?: string
  product_id?: string
  product_name?: string
  plan_id?: string
  plan_name?: string
  code?: string
  name?: string
  audience?: string
  revision?: number
  kind?: 'desktop' | 'membership'
  duration_unit?: 'day' | 'month' | 'year'
  duration_value?: number
  seat_limit?: number
  used_seats?: number
  features?: Features
  prefix?: string
  claim_id?: string | null
  entitlement_id?: string
  subject_code?: string
  channel_code?: string
  sku_code?: string
  order_id?: string
  order_item_id?: string
  payment_cycle_id?: string
  quantity?: number
  source_code?: string
  action?: string
  object_type?: string
  object_id?: string
  actor_ref?: string
  request_id?: string
  reason_code?: string
  start_time?: string | null
  expire_time?: string | null
  cycle_start_time?: string | null
  cycle_expire_time?: string | null
  cancelled_time?: string | null
  released_time?: string | null
  lease_expire_time?: string | null
  seat_available_time?: string | null
  create_time?: string | null
  update_time?: string | null
  published_time?: string | null
  revoked_time?: string | null
}

export interface ResourceDetail extends ResourceRow {
  server_time: string
  grants?: ResourceRow[]
  activations?: ResourceRow[]
  intervals?: { start_time: string; expire_time: string }[]
}

export interface ListParams {
  page: number
  limit: number
  keywords?: string
  product_id?: string
  state?: string
  kind?: 'desktop' | 'membership'
}

export interface ProductDraft {
  id?: string
  organization_id: string
  application_id: string
  code: string
  name: string
  audience: string
}

export interface PlanDraft {
  id?: string
  product_id: string
  code: string
  name: string
  revision: number
  kind: 'desktop' | 'membership'
  duration_unit: 'day' | 'month' | 'year'
  duration_value: number
  seat_limit: number
  features: Features
}

export interface MappingDraft {
  id?: string
  product_id: string
  plan_id: string
  channel_code: string
  sku_code: string
  status: number
}

export interface ActionResult {
  state: string
  id?: string
  code_id?: string
  code?: string
  prefix?: string
  ticket_id?: string
  enrollment_ticket?: string
  request_id: string
  server_time: string
  seat_available_time?: string | null
}

export interface IssueInput {
  product_id: string
  plan_id: string
  request_id: string
  expire_time?: string
}

export interface ActionInput {
  id: string
  activation_id?: string
  entitlement_id?: string
  issue_enrollment_ticket?: boolean
  reissue_ticket_id?: string
  reason: string
  request_id: string
}

export type SensitiveAction =
  | 'publish'
  | 'reissue'
  | 'revoke'
  | 'suspend'
  | 'release'
  | 'reset'
  | 'ticket'
