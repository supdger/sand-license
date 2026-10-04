<template>
  <ElDialog
    :model-value="modelValue"
    :title="label"
    width="min(560px, 94vw)"
    :close-on-click-modal="false"
    :close-on-press-escape="!busy"
    :show-close="!busy"
    @update:model-value="close"
  >
    <p>操作对象：{{ row?.name || row?.prefix || row?.product_name || row?.id }}</p>
    <ElAlert :title="impact" type="warning" :closable="false" />
    <p v-if="row?.seat_available_time">
      已知席位可用时间：{{ timeLabel(row.seat_available_time) }}
    </p>
    <ElForm ref="form" :model="draft" label-position="top" class="reason">
      <ElFormItem v-if="action === 'release' || action === 'reset'" label="新设备资格">
        <ElCheckbox v-model="draft.issue_enrollment_ticket" :disabled="busy || uncertain">
          生成换设备资格（仅显示一次，仍需等待席位可用）
        </ElCheckbox>
      </ElFormItem>
      <ElFormItem
        v-if="action === 'ticket'"
        label="需要重新签发的资格编号（可选）"
        prop="reissue_ticket_id"
        :rules="[{ pattern: /^$|^[1-9]\d*$/, message: '请输入有效的资格编号' }]"
      >
        <ElInput
          v-model="draft.reissue_ticket_id"
          :disabled="busy || uncertain"
          placeholder="仅丢失未使用的资格时填写，否则留空"
        />
        <p>重新签发会废止原资格。已使用的资格不能重签，也不会增加许可设备额度。</p>
      </ElFormItem>
      <ElFormItem
        label="操作理由"
        prop="reason"
        :rules="[{ required: true, whitespace: true, message: '请填写操作理由' }]"
      >
        <ElInput
          v-model="draft.reason"
          type="textarea"
          :rows="3"
          maxlength="500"
          :disabled="busy || uncertain"
        />
      </ElFormItem>
    </ElForm>
    <ElAlert v-if="failure" :title="failure" type="error" :closable="false" />
    <p v-if="operationId && uncertain">请求编号：{{ operationId }}</p>
    <template #footer>
      <ElButton :disabled="busy" @click="close(false)">{{
        uncertain ? '关闭并刷新记录' : '取消'
      }}</ElButton>
      <ElButton
        v-if="uncertain && resource === 'code' && action === 'reissue'"
        :loading="busy"
        @click="recover"
        >查询重签结果</ElButton
      >
      <ElButton
        v-if="
          uncertain &&
          (action === 'ticket' || (resource === 'activation' && draft.issue_enrollment_ticket))
        "
        :loading="busy"
        @click="retryTicket"
        >按同一请求编号重试</ElButton
      >
      <ElButton type="primary" :loading="busy" :disabled="uncertain" @click="submit"
        >确认{{ label }}</ElButton
      >
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { computed, reactive, ref, watch } from 'vue'
  import type { FormInstance } from 'element-plus'
  import { act, issueStatus } from '../api'
  import type { Resource, ResourceRow, SensitiveAction, ActionResult } from '../api/types'
  import { errorMessage, requestId, timeLabel } from '../api/presentation'
  const props = defineProps<{
    modelValue: boolean
    resource: Resource
    action: SensitiveAction
    row?: ResourceRow
  }>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    completed: [result: ActionResult]
    refresh: []
  }>()
  const labels: Record<SensitiveAction, string> = {
    publish: '发布',
    reissue: '重新签发',
    revoke: '撤销',
    suspend: '暂停',
    release: '释放设备',
    reset: '重置设备',
    ticket: '签发新设备资格'
  }
  const label = computed(() => labels[props.action])
  const impact = computed(
    () =>
      ({
        publish: '发布后产品编码与许可受众、套餐版本内容固定；调整套餐请创建新版本。',
        reissue: '旧授权码将立即失效，新码仅显示一次。已经兑换的授权码不能重新签发。',
        revoke: '撤销后将拒绝后续使用。已签发的本地租约最晚在原到期时间失效。',
        suspend: '暂停后将拒绝续签和新的使用；已签租约最晚在原到期时间失效。',
        release: '旧设备立即停止续签。席位需等最后租约到期后才可重用，最长可能需要 15 分钟。',
        reset: '旧设备立即停止续签。不会延长许可期限，席位需等最后租约到期后才可重用。',
        ticket:
          '仅为现有权益添加设备，不另发权益或延长期限。设备额度已满时不能签发，尚在等待释放的席位不能提前使用。'
      })[props.action]
  )
  const form = ref<FormInstance>()
  const draft = reactive({ reason: '', issue_enrollment_ticket: false, reissue_ticket_id: '' })
  const busy = ref(false)
  const failure = ref('')
  const uncertain = ref(false)
  const operationId = ref('')
  watch(
    () => props.modelValue,
    (value) => {
      if (value) {
        draft.reason = ''
        draft.issue_enrollment_ticket = false
        draft.reissue_ticket_id = ''
        failure.value = ''
        uncertain.value = false
        operationId.value = requestId()
        form.value?.clearValidate()
      }
    }
  )
  function close(value: boolean) {
    if (busy.value) return
    if (uncertain.value) emit('refresh')
    emit('update:modelValue', value)
  }
  async function submit() {
    if (busy.value || uncertain.value || !props.row || !form.value) return
    if (!(await form.value.validate().catch(() => false))) return
    busy.value = true
    failure.value = ''
    try {
      const result = await act(props.resource, props.action, {
        id: props.row.id,
        ...(props.resource === 'activation'
          ? {
              activation_id: props.row.id,
              issue_enrollment_ticket: draft.issue_enrollment_ticket
            }
          : {}),
        ...(props.action === 'ticket'
          ? {
              entitlement_id: props.row.id,
              reissue_ticket_id: draft.reissue_ticket_id || undefined
            }
          : {}),
        request_id: operationId.value,
        reason: draft.reason.trim()
      })
      emit('completed', result)
      emit('update:modelValue', false)
    } catch (error) {
      uncertain.value = true
      failure.value = `${errorMessage(error)} 请关闭后刷新记录，确认实际状态再决定下一步。`
    } finally {
      busy.value = false
    }
  }
  function retryTicket() {
    if (
      busy.value ||
      !(
        props.action === 'ticket' ||
        (props.resource === 'activation' && draft.issue_enrollment_ticket)
      )
    )
      return
    uncertain.value = false
    void submit()
  }
  async function recover() {
    if (busy.value) return
    busy.value = true
    try {
      const result = await issueStatus(operationId.value)
      if (result.code_id) {
        emit('completed', result)
        emit('update:modelValue', false)
      } else if (result.state === 'not_found') {
        uncertain.value = false
        failure.value = '尚无完成记录。再次确认将沿用本次请求编号。'
      } else {
        failure.value = '结果尚未完成，请稍后再次查询。'
      }
    } catch (error) {
      failure.value = `${errorMessage(error)} 请求编号已保留，请稍后再查询。`
    } finally {
      busy.value = false
    }
  }
</script>

<style scoped>
  .reason {
    margin-top: 16px;
  }
</style>
