<template>
  <ElDrawer
    :model-value="modelValue"
    title="查看详情"
    size="min(780px, 96vw)"
    @update:model-value="close"
  >
    <div v-loading="loading">
      <ElAlert v-if="failure" :title="failure" type="error" :closable="false" />
      <ElButton v-if="failure" @click="load">重新加载</ElButton>
      <template v-if="record">
        <ElButton
          v-if="
            resource === 'entitlement' && record.kind === 'desktop' && record.state === 'active'
          "
          v-permission="permission('entitlement', 'ticket')"
          type="primary"
          class="device-action"
          @click="emit('ticket', record)"
          >添加设备资格</ElButton
        >
        <ElDescriptions :column="1" border>
          <ElDescriptionsItem v-for="item in detailFields" :key="item.prop" :label="item.label">
            {{ display(record, item.prop) }}
          </ElDescriptionsItem>
        </ElDescriptions>
        <template v-if="record.features && Object.keys(record.features).length">
          <h3>套餐功能</h3>
          <ElDescriptions :column="1" border>
            <ElDescriptionsItem
              v-for="(value, key) in record.features"
              :key="key"
              :label="String(key)"
            >
              {{ value }}
            </ElDescriptionsItem>
          </ElDescriptions>
        </template>
        <template v-if="record.grants">
          <h3>权益来源</h3>
          <p>每笔来源独立计时。某笔退款只撤销该来源；取消续期保留已付周期。</p>
          <ElTable :data="record.grants" empty-text="暂无权益来源">
            <ElTableColumn prop="source_code" label="来源" min-width="140" />
            <ElTableColumn prop="state" label="状态" min-width="90">
              <template #default="{ row }">{{ stateLabel(row.state) }}</template>
            </ElTableColumn>
            <ElTableColumn prop="start_time" label="开始时间" min-width="220">
              <template #default="{ row }">{{ timeLabel(row.start_time) }}</template>
            </ElTableColumn>
            <ElTableColumn prop="expire_time" label="结束时间" min-width="220">
              <template #default="{ row }">{{ timeLabel(row.expire_time) }}</template>
            </ElTableColumn>
          </ElTable>
        </template>
        <template v-if="record.activations">
          <h3>设备</h3>
          <ElTable :data="record.activations" empty-text="尚无设备激活">
            <ElTableColumn prop="name" label="设备名称" min-width="140" />
            <ElTableColumn prop="state" label="状态">
              <template #default="{ row }">{{ stateLabel(row.state) }}</template>
            </ElTableColumn>
            <ElTableColumn prop="seat_available_time" label="席位可用时间" min-width="220">
              <template #default="{ row }">{{ timeLabel(row.seat_available_time) }}</template>
            </ElTableColumn>
          </ElTable>
        </template>
        <p v-if="record.server_time" class="hint">
          状态查询时间：{{ timeLabel(record.server_time) }}
        </p>
      </template>
    </div>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue'
  import { readResource } from '../api'
  import type { Resource, ResourceDetail } from '../api/types'
  import { display, fields, permission, type Field } from '../api/catalog'
  import { errorMessage, stateLabel, timeLabel } from '../api/presentation'
  const props = defineProps<{ modelValue: boolean; resource: Resource; id: string }>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    ticket: [record: ResourceDetail]
  }>()
  const record = shallowRef<ResourceDetail>()
  const loading = ref(false)
  const failure = ref('')
  let sequence = 0
  let controller: AbortController | undefined
  const extraFields: Field[] = [
    { prop: 'id', label: '编号' },
    { prop: 'subject_code', label: '业务主体' },
    { prop: 'prefix', label: '授权码前缀' },
    { prop: 'start_time', label: '开始时间' },
    { prop: 'expire_time', label: '到期时间' },
    { prop: 'cycle_start_time', label: '购买周期开始' },
    { prop: 'cycle_expire_time', label: '购买周期结束' },
    { prop: 'cancelled_time', label: '取消续期时间' },
    { prop: 'released_time', label: '设备释放时间' },
    { prop: 'seat_available_time', label: '席位可用时间' },
    { prop: 'reason_code', label: '原因' },
    { prop: 'create_time', label: '创建时间' }
  ]
  const detailFields = computed(() => {
    const selected = [...fields[props.resource]]
    for (const item of extraFields) {
      if (
        record.value?.[item.prop] !== undefined &&
        !selected.some((current) => current.prop === item.prop)
      )
        selected.push(item)
    }
    return selected
  })
  async function load() {
    const current = ++sequence
    controller?.abort()
    controller = new AbortController()
    record.value = undefined
    failure.value = ''
    loading.value = true
    try {
      const result = await readResource(props.resource, props.id, controller.signal)
      if (current === sequence) record.value = result
    } catch (error) {
      if (current === sequence) failure.value = errorMessage(error)
    } finally {
      if (current === sequence) loading.value = false
    }
  }
  function close(value: boolean) {
    emit('update:modelValue', value)
  }
  watch(
    () => [props.modelValue, props.id, props.resource],
    () => {
      if (props.modelValue && props.id) void load()
      else {
        ++sequence
        controller?.abort()
        record.value = undefined
        loading.value = false
      }
    }
  )
  onBeforeUnmount(() => {
    ++sequence
    controller?.abort()
  })
</script>

<style scoped>
  .hint {
    color: var(--el-text-color-secondary);
  }
  h3 {
    margin-top: 24px;
  }
  .device-action {
    margin-bottom: 16px;
  }
</style>
