<template>
  <ElDrawer
    :model-value="modelValue"
    title="手工发码"
    size="min(560px, 96vw)"
    :close-on-click-modal="!busy"
    :close-on-press-escape="!busy"
    :show-close="!busy"
    @update:model-value="close"
  >
    <ElForm
      ref="form"
      :model="draft"
      label-position="top"
      :disabled="busy || pending || Boolean(operationId)"
    >
      <ElFormItem
        label="产品"
        prop="product_id"
        :rules="[{ required: true, message: '请选择产品' }]"
      >
        <ResourceSelect v-model="draft.product_id" resource="product" />
      </ElFormItem>
      <ElFormItem
        label="套餐"
        prop="plan_id"
        :rules="[{ required: true, message: '请选择软件许可套餐' }]"
      >
        <ResourceSelect
          v-model="draft.plan_id"
          resource="plan"
          :product-id="draft.product_id"
          :disabled="!draft.product_id"
          desktop-only
        />
      </ElFormItem>
    </ElForm>
    <ElAlert type="info" title="授权码仅签发时展示一次" :closable="false">
      首次兑换后开始计时。请在发码成功后立即复制保存；关闭后只能查看前缀与状态。
    </ElAlert>
    <ElAlert
      v-if="notice"
      :title="notice"
      :type="pending ? 'warning' : 'info'"
      :closable="false"
      class="notice"
    />
    <p v-if="operationId" class="request">请求编号：{{ operationId }}</p>
    <p v-if="recoveredCode">
      授权码编号：{{ recoveredCode }}。请回到列表查找该记录，未兑换时可明确重新签发。
    </p>
    <template #footer>
      <ElButton :disabled="busy" @click="close(false)">关闭</ElButton>
      <ElButton v-if="pending" :loading="busy" @click="recover">查询本次发码结果</ElButton>
      <ElButton v-else-if="!recoveredCode" type="primary" :loading="busy" @click="issue"
        >签发授权码</ElButton
      >
    </template>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { nextTick, reactive, ref, watch } from 'vue'
  import type { FormInstance } from 'element-plus'
  import { issueCode, issueStatus } from '../api'
  import type { ActionResult, IssueInput } from '../api/types'
  import { errorMessage, requestId } from '../api/presentation'
  import ResourceSelect from './ResourceSelect.vue'
  const props = defineProps<{ modelValue: boolean }>()
  const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    issued: [result: ActionResult]
  }>()
  const storageKey = 'sand-license.pending-manual-issue'
  const form = ref<FormInstance>()
  const draft = reactive({ product_id: '', plan_id: '' })
  const busy = ref(false)
  const pending = ref(false)
  const notice = ref('')
  const operationId = ref('')
  const recoveredCode = ref('')
  function clearStored() {
    try {
      sessionStorage.removeItem(storageKey)
    } catch {
      notice.value = '操作已完成，但浏览器未能清除请求记录。再次进入时可查询同一结果。'
    }
  }
  watch(
    () => props.modelValue,
    async (open) => {
      if (!open) return
      draft.product_id = ''
      draft.plan_id = ''
      notice.value = ''
      pending.value = false
      recoveredCode.value = ''
      operationId.value = ''
      try {
        const stored: unknown = JSON.parse(sessionStorage.getItem(storageKey) || 'null')
        if (
          stored &&
          typeof stored === 'object' &&
          'request_id' in stored &&
          typeof stored.request_id === 'string' &&
          'product_id' in stored &&
          typeof stored.product_id === 'string' &&
          'plan_id' in stored &&
          typeof stored.plan_id === 'string'
        ) {
          draft.product_id = stored.product_id
          draft.plan_id = stored.plan_id
          operationId.value = stored.request_id
          pending.value = true
          notice.value = '发现本页尚未确认的发码请求。请先查询结果，避免重复发码。'
        }
      } catch {
        notice.value = '浏览器不能读取恢复记录。发码前请确认没有待核对的请求。'
      }
      await nextTick()
      form.value?.clearValidate()
    }
  )
  watch(
    () => draft.product_id,
    (value, previous) => {
      if (previous && value !== previous && !pending.value) draft.plan_id = ''
    }
  )
  function close(value: boolean) {
    if (!busy.value) emit('update:modelValue', value)
  }
  async function issue() {
    if (busy.value || pending.value || !form.value) return
    if (!(await form.value.validate().catch(() => false))) return
    const input: IssueInput = { ...draft, request_id: operationId.value || requestId() }
    operationId.value = input.request_id
    try {
      // Only the non-secret operation reference is persisted, never its result.
      sessionStorage.setItem(storageKey, JSON.stringify(input))
    } catch {
      notice.value = '浏览器无法保存本次请求编号。请允许会话存储后再发码，以便网络中断时恢复。'
      return
    }
    busy.value = true
    pending.value = true
    notice.value = ''
    try {
      const result = await issueCode(input)
      clearStored()
      pending.value = false
      emit('issued', result)
      if (result.code) emit('update:modelValue', false)
      else {
        recoveredCode.value = result.code_id || ''
        notice.value = '本次请求已有记录，明码不会再次展示。'
      }
    } catch (error) {
      notice.value = `${errorMessage(error)} 请先查询本次发码结果；不要另发一张。`
    } finally {
      busy.value = false
    }
  }
  async function recover() {
    if (busy.value || !operationId.value) return
    busy.value = true
    try {
      const result = await issueStatus(operationId.value)
      if (result.code_id) {
        recoveredCode.value = result.code_id
        clearStored()
        pending.value = false
        notice.value = '已查到本次发码记录。明码无法恢复，未兑换时可在列表中重新签发。'
        emit('issued', result)
      } else if (result.state === 'not_found') {
        pending.value = false
        notice.value = '尚无完成记录。可使用保留的同一请求编号重试，服务端会防止重复发放。'
      } else {
        notice.value = '本次请求尚未完成，请稍后再次查询。'
      }
    } catch (error) {
      notice.value = `${errorMessage(error)} 本次请求编号已保留，可稍后继续查询。`
    } finally {
      busy.value = false
    }
  }
</script>

<style scoped>
  .notice {
    margin-top: 16px;
  }
  .request {
    overflow-wrap: anywhere;
    color: var(--el-text-color-secondary);
  }
</style>
