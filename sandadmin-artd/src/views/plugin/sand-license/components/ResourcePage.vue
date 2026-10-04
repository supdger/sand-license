<template>
  <div class="license-page art-full-height">
    <ElAlert
      v-if="!canAccess"
      title="暂无此功能的访问权限，请联系管理员分配对应权限。"
      type="info"
      :closable="false"
    />
    <template v-else>
      <p class="help">{{ currentSection.help }}</p>
      <SaSearchBar
        v-model="search"
        label-position="top"
        :show-expand="false"
        @search="searchRows"
        @reset="resetSearch"
      >
        <ElCol :xs="24" :sm="12" :md="8" :lg="6">
          <ElFormItem label="名称、编码或编号" prop="keywords">
            <ElInput
              v-model="search.keywords"
              clearable
              placeholder="输入关键词"
              @keyup.enter="searchRows"
            />
          </ElFormItem>
        </ElCol>
        <ElCol v-if="resource !== 'product'" :xs="24" :sm="12" :md="8" :lg="6">
          <ElFormItem label="产品" prop="product_id">
            <ResourceSelect v-if="canReadProducts" v-model="search.product_id" resource="product" />
            <ElInput v-else v-model="search.product_id" clearable placeholder="输入产品编号" />
          </ElFormItem>
        </ElCol>
        <ElCol v-if="currentSection.states.length" :xs="24" :sm="12" :md="8" :lg="6">
          <ElFormItem label="状态" prop="state">
            <ElSelect v-model="search.state" clearable placeholder="全部状态">
              <ElOption
                v-for="state in currentSection.states"
                :key="state"
                :value="state"
                :label="stateLabel(state)"
              />
            </ElSelect>
          </ElFormItem>
        </ElCol>
      </SaSearchBar>
      <ElCard class="art-table-card" shadow="never">
        <ArtTableHeader :loading="loading" layout="refresh,size,settings" @refresh="refresh">
          <template #left>
            <ElButton
              v-if="editableResource"
              v-permission="permission(resource, 'save')"
              type="primary"
              @click="openEditor()"
            >
              {{
                resource === 'product'
                  ? '创建产品'
                  : resource === 'plan'
                    ? '创建套餐版本'
                    : '添加商品映射'
              }}
            </ElButton>
            <ElButton
              v-if="resource === 'code'"
              v-permission="permission('code', 'issue')"
              type="primary"
              @click="issuerOpen = true"
              >手工发码</ElButton
            >
          </template>
        </ArtTableHeader>
        <ElAlert
          v-if="listError"
          :title="listError"
          type="error"
          :closable="false"
          class="feedback"
        >
          <ElButton text @click="refresh">重新加载</ElButton>
        </ElAlert>
        <ElAlert
          v-if="resultNotice"
          :title="resultNotice"
          type="info"
          closable
          class="feedback"
          @close="resultNotice = ''"
        />
        <ArtTable
          row-key="id"
          :data="rows"
          :columns="columns"
          :loading="loading"
          :pagination="{ current: page, size: limit, total }"
          empty-text="暂无记录。可调整筛选，或使用上方操作创建。"
          @pagination:current-change="changePage"
          @pagination:size-change="changeSize"
        >
          <template v-for="item in currentFields" :key="item.prop" #[item.prop]="{ row }">
            {{ display(row, item.prop) }}
          </template>
          <template #operation="{ row }">
            <ElSpace wrap>
              <ElButton
                v-permission="permission(resource, 'read')"
                link
                type="primary"
                @click="view(row)"
                >详情</ElButton
              >
              <ElButton
                v-if="editableResource && (resource === 'sku-mapping' || row.state === 'draft')"
                v-permission="permission(resource, 'save')"
                link
                type="primary"
                @click="openEditor(row)"
                >编辑</ElButton
              >
              <ElButton
                v-if="(resource === 'product' || resource === 'plan') && row.state === 'draft'"
                v-permission="permission(resource, 'publish')"
                link
                type="primary"
                @click="openAction('publish', row)"
                >发布</ElButton
              >
              <ElButton
                v-if="resource === 'code' && row.state === 'issued' && !row.claim_id"
                v-permission="permission(resource, 'reissue')"
                link
                type="primary"
                @click="openAction('reissue', row)"
                >重新签发</ElButton
              >
              <ElButton
                v-if="resource === 'code' && row.state === 'issued' && !row.claim_id"
                v-permission="permission(resource, 'revoke')"
                link
                type="danger"
                @click="openAction('revoke', row)"
                >撤销</ElButton
              >
              <span
                v-if="resource === 'code' && row.state === 'issued' && row.claim_id"
                class="help"
                >由买家在原领取页重签</span
              >
              <ElButton
                v-if="resource === 'entitlement' && row.state === 'active'"
                v-permission="permission(resource, 'suspend')"
                link
                type="warning"
                @click="openAction('suspend', row)"
                >暂停</ElButton
              >
              <ElButton
                v-if="
                  resource === 'entitlement' && row.kind === 'desktop' && row.state === 'active'
                "
                v-permission="permission(resource, 'ticket')"
                link
                type="primary"
                @click="openAction('ticket', row)"
                >签发新设备资格</ElButton
              >
              <ElButton
                v-if="
                  resource === 'entitlement' &&
                  (row.state === 'active' || row.state === 'suspended')
                "
                v-permission="permission(resource, 'revoke')"
                link
                type="danger"
                @click="openAction('revoke', row)"
                >撤销</ElButton
              >
              <template v-if="resource === 'activation' && row.state === 'active'">
                <ElButton
                  v-permission="permission(resource, 'release')"
                  link
                  type="primary"
                  @click="openAction('release', row)"
                  >释放</ElButton
                >
                <ElButton
                  v-permission="permission(resource, 'reset')"
                  link
                  type="warning"
                  @click="openAction('reset', row)"
                  >重置设备</ElButton
                >
              </template>
            </ElSpace>
          </template>
        </ArtTable>
        <p v-if="serverTime" class="updated">
          状态查询时间：{{ timeLabel(serverTime) }}。到达席位可用时间后，请刷新确认。
        </p>
      </ElCard>
    </template>
    <CatalogEditor
      v-if="active && canAccess && editableResource"
      v-model="editorOpen"
      :resource="editableResource"
      :row="selected"
      @saved="saved"
    />
    <CodeIssuer
      v-if="active && canAccess && resource === 'code'"
      v-model="issuerOpen"
      @issued="completed"
    />
    <ActionDialog
      v-if="active && canAccess"
      v-model="actionOpen"
      :resource="resource"
      :action="selectedAction"
      :row="selected"
      @completed="completed"
      @refresh="refresh"
    />
    <DetailDrawer
      v-if="active && canAccess"
      v-model="detailOpen"
      :resource="resource"
      :id="selected?.id || ''"
      @ticket="detailTicket"
    />
    <SecretResult
      v-model="secretOpen"
      :value="secretValue"
      :title="secretTitle"
      :kind="secretKind"
      :available-time="secretAvailableTime"
    />
  </div>
</template>

<script setup lang="ts">
  import {
    computed,
    onActivated,
    onBeforeUnmount,
    onDeactivated,
    ref,
    shallowRef,
    watch
  } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import type { ColumnOption } from '@/types/component'
  import { useUserStore } from '@/store/modules/user'
  import { listResource } from '../api'
  import { useResourceList } from '../api/useResourceList'
  import type {
    Resource,
    EditableResource,
    ResourceRow,
    SensitiveAction,
    ActionResult,
    ListParams
  } from '../api/types'
  import { display, fields, permission, sections } from '../api/catalog'
  import { stateLabel, timeLabel } from '../api/presentation'
  import ResourceSelect from '../components/ResourceSelect.vue'
  import CatalogEditor from '../components/CatalogEditor.vue'
  import CodeIssuer from '../components/CodeIssuer.vue'
  import ActionDialog from '../components/ActionDialog.vue'
  import DetailDrawer from '../components/DetailDrawer.vue'
  import SecretResult from '../components/SecretResult.vue'
  defineOptions({ name: 'SandLicenseResourcePage' })
  const props = defineProps<{ resource: Resource }>()
  const resource = computed(() => props.resource)
  const route = useRoute()
  const router = useRouter()
  const routeName = route.name
  const active = ref(true)
  const userStore = useUserStore()
  const hasPermission = (target: Resource) => {
    const buttons = userStore.getUserInfo.buttons ?? []
    return buttons.includes('*') || buttons.includes(permission(target, 'index'))
  }
  const canAccess = computed(() => hasPermission(resource.value))
  const canReadProducts = computed(() => hasPermission('product'))
  // Each route supplies a fixed, typed resource; there is no route-to-product fallback.
  const currentSection = computed(() => sections.find((item) => item.key === resource.value)!)
  const currentFields = computed(() => fields[resource.value])
  const columns = computed<ColumnOption[]>(() => [
    ...currentFields.value.map((item) => ({ ...item, useSlot: true })),
    {
      prop: 'operation',
      label: '操作',
      minWidth: 210,
      fixed: 'right',
      useSlot: true
    }
  ])
  const editableResource = computed<EditableResource | undefined>(() => {
    const value = resource.value
    return value === 'product' || value === 'plan' || value === 'sku-mapping' ? value : undefined
  })
  const search = ref({ keywords: '', product_id: '', state: '' })
  const page = ref(1)
  const limit = ref(20)
  const {
    rows,
    loading,
    error: listError,
    total,
    serverTime,
    load,
    clear
  } = useResourceList<ResourceRow, ListParams & { resource: Resource }>((params, signal) => {
    const { resource: target, ...query } = params
    return listResource(target, query, signal)
  })
  const selected = shallowRef<ResourceRow>()
  const editorOpen = ref(false)
  const issuerOpen = ref(false)
  const actionOpen = ref(false)
  const detailOpen = ref(false)
  const selectedAction = ref<SensitiveAction>('publish')
  const resultNotice = ref('')
  const secretOpen = ref(false)
  const secretValue = ref('')
  const secretTitle = ref('')
  const secretKind = ref<'code' | 'ticket'>('code')
  const secretAvailableTime = ref<string | null>()
  function refresh() {
    if (!active.value || !canAccess.value || route.name !== routeName) return
    void load({
      resource: resource.value,
      page: page.value,
      limit: limit.value,
      keywords: search.value.keywords || undefined,
      product_id: search.value.product_id || undefined,
      state: search.value.state || undefined
    })
  }
  function syncQuery() {
    const query = {
      ...route.query,
      keywords: search.value.keywords || undefined,
      product_id: search.value.product_id || undefined,
      state: search.value.state || undefined,
      page: page.value > 1 ? String(page.value) : undefined,
      limit: limit.value !== 20 ? String(limit.value) : undefined
    }
    if (router.resolve({ query }).fullPath === route.fullPath) refresh()
    else void router.replace({ query })
  }
  function resetSearch() {
    search.value = { keywords: '', product_id: '', state: '' }
    page.value = 1
    syncQuery()
  }
  function searchRows() {
    page.value = 1
    syncQuery()
  }
  function changePage(value: number) {
    page.value = value
    syncQuery()
  }
  function changeSize(value: number) {
    limit.value = value
    page.value = 1
    syncQuery()
  }
  function leavePage() {
    active.value = false
    clear()
    selected.value = undefined
    editorOpen.value = false
    issuerOpen.value = false
    detailOpen.value = false
    actionOpen.value = false
    resultNotice.value = ''
    secretOpen.value = false
    secretValue.value = ''
    secretAvailableTime.value = undefined
  }
  function openEditor(row?: ResourceRow) {
    selected.value = row
    editorOpen.value = true
  }
  function view(row: ResourceRow) {
    selected.value = row
    detailOpen.value = true
  }
  function openAction(action: SensitiveAction, row: ResourceRow) {
    selected.value = row
    selectedAction.value = action
    actionOpen.value = true
  }
  function detailTicket(row: ResourceRow) {
    detailOpen.value = false
    openAction('ticket', row)
  }
  function saved() {
    if (!active.value || !canAccess.value) return
    resultNotice.value = '已保存，请核对列表中的记录。'
    refresh()
  }
  function completed(result: ActionResult) {
    if (!active.value || !canAccess.value) return
    if (result.code || result.enrollment_ticket) {
      secretValue.value = result.code || result.enrollment_ticket || ''
      secretTitle.value = result.code ? '授权码签发成功' : '新设备资格已签发'
      secretKind.value = result.code ? 'code' : 'ticket'
      secretAvailableTime.value = result.seat_available_time
      secretOpen.value = true
    }
    resultNotice.value =
      result.ticket_id && !result.enrollment_ticket
        ? `设备资格编号：${result.ticket_id}。本次请求已有结果，明文不能恢复；若尚未使用，可在“签发新设备资格”中填写此编号明确重签。`
        : result.code_id && !result.code
          ? `授权码编号：${result.code_id}，状态：${stateLabel(result.state)}。明码不能恢复；未兑换时可在列表中明确重签。`
          : result.seat_available_time
            ? `操作已完成。席位可用时间：${timeLabel(result.seat_available_time)}，到时请刷新确认。`
            : `操作结果：${stateLabel(result.state)}。`
    refresh()
  }
  watch(secretOpen, (open) => {
    if (!open) {
      secretValue.value = ''
      secretAvailableTime.value = undefined
    }
  })
  function restoreQuery() {
    if (route.name !== routeName || !active.value) return
    if (!canAccess.value) {
      leavePage()
      active.value = true
      return
    }
    const text = (value: unknown) => (typeof value === 'string' ? value : '')
    const positive = (value: unknown, fallback: number) => {
      const number = Number(text(value))
      return Number.isSafeInteger(number) && number > 0 ? number : fallback
    }
    search.value = {
      keywords: text(route.query.keywords),
      product_id: text(route.query.product_id),
      state: currentSection.value.states.includes(text(route.query.state))
        ? text(route.query.state)
        : ''
    }
    page.value = positive(route.query.page, 1)
    limit.value = positive(route.query.limit, 20)
    refresh()
  }
  watch(() => [route.fullPath, canAccess.value] as const, restoreQuery, { immediate: true })
  onActivated(() => {
    active.value = true
    restoreQuery()
  })
  onDeactivated(leavePage)
  onBeforeUnmount(leavePage)
</script>

<style scoped>
  .license-page {
    gap: 12px;
  }
  .help,
  .updated {
    color: var(--el-text-color-secondary);
    line-height: 1.6;
  }
  .help {
    margin: 0 0 12px;
  }
  .updated {
    font-size: 12px;
  }
  .feedback {
    margin-bottom: 12px;
  }
</style>
