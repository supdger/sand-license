<template>
  <ElDialog
    :model-value="modelValue"
    :title="title"
    width="min(560px, 94vw)"
    :close-on-click-modal="false"
    @update:model-value="close"
  >
    <ElAlert title="仅本次显示，请现在保存" type="warning" :closable="false">
      {{
        kind === 'ticket'
          ? '关闭后无法再次查看。此资格仅用于为现有权益添加新设备，不会重新开通或延长权益。'
          : '关闭后无法再次查看。授权码未兑换时可明确重新签发，旧码随即失效；已兑换不能重签。'
      }}
    </ElAlert>
    <ElInput
      class="secret"
      :model-value="value"
      type="textarea"
      :rows="3"
      readonly
      aria-label="本次签发内容"
    />
    <p v-if="availableTime">设备席位可用时间：{{ timeLabel(availableTime) }}</p>
    <p role="status">{{ feedback }}</p>
    <template #footer>
      <ElButton @click="copy">复制</ElButton>
      <ElButton type="primary" @click="close(false)">已保存，关闭</ElButton>
    </template>
  </ElDialog>
</template>

<script setup lang="ts">
  import { ref, watch } from 'vue'
  import { copyText, timeLabel } from '../api/presentation'
  const props = withDefaults(
    defineProps<{
      modelValue: boolean
      value: string
      title: string
      availableTime?: string | null
      kind?: 'code' | 'ticket'
    }>(),
    { kind: 'code' }
  )
  const emit = defineEmits<{ 'update:modelValue': [value: boolean] }>()
  const feedback = ref('')
  watch(
    () => props.modelValue,
    () => {
      feedback.value = ''
    }
  )
  const close = (value: boolean) => emit('update:modelValue', value)
  async function copy() {
    feedback.value = (await copyText(props.value))
      ? '已复制，请粘贴到安全位置。'
      : '自动复制不可用，请选择上方文本并手动复制。'
  }
</script>

<style scoped>
  .secret {
    margin-top: 16px;
    font-family: monospace;
  }
</style>
