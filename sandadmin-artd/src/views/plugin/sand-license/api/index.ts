import request from '@/utils/http'
import type { Page } from './useResourceList'
import type {
  Resource,
  ResourceRow,
  ResourceDetail,
  ListParams,
  ProductDraft,
  PlanDraft,
  MappingDraft,
  ActionInput,
  ActionResult,
  IssueInput,
  SensitiveAction
} from './types'

const prefix = '/app/sand-license/admin'
export function listResource(resource: Resource, params: ListParams, signal?: AbortSignal) {
  return request.get<Page<ResourceRow>>({
    url: `${prefix}/${resource}/index`,
    params,
    signal,
    showErrorMessage: false
  })
}
export function readResource(resource: Resource, id: string, signal?: AbortSignal) {
  return request.get<ResourceDetail>({
    url: `${prefix}/${resource}/read`,
    params: { id },
    signal,
    showErrorMessage: false
  })
}
export function saveProduct(data: ProductDraft) {
  return request.post<ResourceDetail>({ url: `${prefix}/product/save`, data })
}
export function savePlan(data: PlanDraft) {
  return request.post<ResourceDetail>({ url: `${prefix}/plan/save`, data })
}
export function saveMapping(data: MappingDraft) {
  return request.post<ResourceDetail>({ url: `${prefix}/sku-mapping/save`, data })
}
export function issueCode(data: IssueInput) {
  return request.post<ActionResult>({ url: `${prefix}/code/issue`, data, showErrorMessage: false })
}
export function issueStatus(id: string) {
  return request.get<ActionResult>({
    url: `${prefix}/code/status`,
    params: { request_id: id },
    showErrorMessage: false
  })
}
export function act(resource: Resource, action: SensitiveAction, data: ActionInput) {
  return request.post<ActionResult>({
    url: `${prefix}/${resource}/${action}`,
    data,
    showErrorMessage: false
  })
}
