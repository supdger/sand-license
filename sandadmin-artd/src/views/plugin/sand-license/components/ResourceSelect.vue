<template>
  <ElSelect
    :model-value="modelValue || undefined"
    :placeholder="placeholder"
    :disabled="disabled"
    filterable
    remote
    clearable
    :remote-method="search"
    :loading="loading"
    @update:model-value="select"
    @visible-change="opened"
  >
    <ElOption v-for="row in choices" :key="row.id" :value="row.id" :label="label(row)" />
    <template #empty>
      <p class="option-hint">{{ error || '没有匹配结果，请先创建并发布，或更换搜索词。' }}</p>
    </template>
    <template #footer>
      <span v-if="total > choices.length">结果较多，请输入更完整的名称或编码搜索。</span>
    </template>
  </ElSelect>
</template>

<script setup lang="ts">
  import { onBeforeUnmount, ref, shallowRef, watch } from 'vue'
  import { listResource } from '../api'
  import type { ResourceRow } from '../api/types'
  import { errorMessage } from '../api/presentation'
  const props = withDefaults(
    defineProps<{
      modelValue: string
      resource: 'product' | 'plan'
      productId?: string
      placeholder?: string
      disabled?: boolean
      selectedLabel?: string
      desktopOnly?: boolean
    }>(),
    { placeholder: '输入名称或编码搜索' }
  )
  const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
  const choices = shallowRef<ResourceRow[]>([])
  const loading = ref(false)
  const error = ref('')
  const total = ref(0)
  let version = 0
  let controller: AbortController | undefined
  function label(row: ResourceRow) {
    return `${row.name || row.plan_name || row.code || row.id}${row.revision ? ` · 第 ${row.revision} 版` : ''}`
  }
  const select = (value: string | undefined) => emit('update:modelValue', value || '')
  async function search(keywords: string) {
    const current = ++version
    controller?.abort()
    controller = new AbortController()
    loading.value = true
    error.value = ''
    try {
      const result = await listResource(
        props.resource,
        {
          page: 1,
          limit: 50,
          product_id: props.productId,
          state: 'published',
          keywords,
          kind: props.desktopOnly ? 'desktop' : undefined
        },
        controller.signal
      )
      if (current !== version) return
      choices.value = result.data
      total.value = result.total
    } catch (failure) {
      if (current !== version) return
      error.value = errorMessage(failure)
      choices.value = []
    } finally {
      if (current === version) loading.value = false
    }
  }
  function opened(value: boolean) {
    if (value) void search('')
  }
  watch(
    () => [props.productId, props.resource],
    () => {
      ++version
      controller?.abort()
      choices.value = []
      error.value = ''
      loading.value = false
    }
  )
  watch(
    () => [props.modelValue, props.selectedLabel],
    () => {
      if (
        props.modelValue &&
        props.selectedLabel &&
        !choices.value.some((row) => row.id === props.modelValue)
      ) {
        choices.value = [...choices.value, { id: props.modelValue, name: props.selectedLabel }]
      }
    },
    { immediate: true }
  )
  onBeforeUnmount(() => {
    ++version
    controller?.abort()
  })
</script>

<style scoped>
  .option-hint {
    padding: 12px;
    color: var(--el-text-color-secondary);
  }
</style>
