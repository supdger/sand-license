<template>
  <ElDrawer
    :model-value="modelValue"
    :title="title"
    size="min(640px, 96vw)"
    :close-on-click-modal="!saving"
    :close-on-press-escape="!saving"
    :show-close="!saving"
    @update:model-value="close"
  >
    <ElForm
      ref="form"
      :model="draft"
      label-position="top"
      :disabled="saving"
      @submit.prevent="save"
    >
      <template v-if="resource === 'product'">
        <ElFormItem label="产品名称" prop="name" :rules="required('请输入产品名称')">
          <ElInput v-model="draft.name" maxlength="100" placeholder="例如：桌面编辑器" />
        </ElFormItem>
        <ElFormItem label="产品编码" prop="code" :rules="required('请输入产品编码')">
          <ElInput v-model="draft.code" placeholder="例如：desktop_editor，供客户端接入使用" />
        </ElFormItem>
        <ElFormItem label="所属组织编号" prop="organization_id" :rules="identifierRules">
          <ElInput v-model="draft.organization_id" placeholder="从 SandIAM 的组织信息中取得" />
        </ElFormItem>
        <ElFormItem label="应用编号" prop="application_id" :rules="identifierRules">
          <ElInput v-model="draft.application_id" placeholder="从 SandIAM 中已登记的应用取得" />
        </ElFormItem>
        <ElFormItem label="许可受众" prop="audience" :rules="required('请输入许可受众')">
          <ElInput v-model="draft.audience" placeholder="与客户端验签配置中的 audience 保持一致" />
        </ElFormItem>
        <ElAlert
          title="保存为草稿，核对后再发布。产品编码和受众发布后不可修改。"
          type="info"
          :closable="false"
        />
      </template>
      <template v-else>
        <ElFormItem label="所属产品" prop="product_id" :rules="required('请选择已发布产品')">
          <ResourceSelect
            v-model="draft.product_id"
            resource="product"
            :selected-label="row?.product_name"
          />
        </ElFormItem>
        <template v-if="resource === 'plan'">
          <ElFormItem label="套餐名称" prop="name" :rules="required('请输入套餐名称')">
            <ElInput v-model="draft.name" placeholder="例如：个人月度版" maxlength="100" />
          </ElFormItem>
          <ElFormItem label="套餐编码" prop="code" :rules="required('请输入套餐编码')">
            <ElInput v-model="draft.code" placeholder="例如：personal_monthly" />
          </ElFormItem>
          <ElFormItem label="版本">
            <ElInputNumber v-model="draft.revision" :min="1" :precision="0" />
          </ElFormItem>
          <ElFormItem label="授权类型">
            <ElRadioGroup v-model="draft.kind">
              <ElRadio value="desktop">软件设备许可</ElRadio>
              <ElRadio value="membership">会员权益</ElRadio>
            </ElRadioGroup>
          </ElFormItem>
          <ElFormItem label="有效期">
            <ElSpace wrap>
              <ElInputNumber v-model="draft.duration_value" :min="1" :max="10000" :precision="0" />
              <ElSelect v-model="draft.duration_unit" class="unit">
                <ElOption label="天" value="day" /><ElOption label="月" value="month" /><ElOption
                  label="年"
                  value="year"
                />
              </ElSelect>
            </ElSpace>
          </ElFormItem>
          <ElFormItem v-if="draft.kind === 'desktop'" label="同时使用的设备数">
            <ElInputNumber v-model="draft.seat_limit" :min="1" :max="10000" :precision="0" />
          </ElFormItem>
          <p class="hint">
            {{
              draft.kind === 'desktop'
                ? '首次兑换后开始计时；软件启动须联网，租约最长 15 分钟，建议每 5 分钟续签。'
                : '会员通过业务主体和已付周期取得权益，不使用卡密或设备绑定。'
            }}
          </p>
          <ElFormItem label="套餐功能">
            <div class="features">
              <div v-for="(feature, index) in features" :key="feature.keyId" class="feature-row">
                <ElInput
                  v-model="feature.key"
                  placeholder="功能编码，例如 export_pdf"
                  aria-label="功能编码"
                />
                <ElInput
                  v-model="feature.value"
                  placeholder="功能值，例如 true 或 10"
                  aria-label="功能值"
                />
                <ElButton @click="features.splice(index, 1)">移除</ElButton>
              </div>
              <ElButton @click="features.push({ keyId: requestId(), key: '', value: 'true' })"
                >添加功能</ElButton
              >
              <p class="hint">
                编码由产品接入方约定，以小写字母开头，可含数字、点、短横线及下划线；值支持
                true、false 或非负整数。
              </p>
            </div>
          </ElFormItem>
          <ElAlert
            title="发布后此版本固定。调整期限、设备数或功能时请创建新的套餐版本。"
            type="info"
            :closable="false"
          />
        </template>
        <template v-else>
          <ElFormItem label="对应套餐" prop="plan_id" :rules="required('请选择已发布套餐')">
            <ResourceSelect
              v-model="draft.plan_id"
              resource="plan"
              :product-id="draft.product_id"
              :disabled="!draft.product_id"
              :selected-label="row?.plan_name"
            />
          </ElFormItem>
          <ElFormItem label="渠道编码" prop="channel_code" :rules="required('请输入渠道编码')">
            <ElInput v-model="draft.channel_code" placeholder="由已配置的履约接入方提供" />
          </ElFormItem>
          <ElFormItem label="商品 SKU" prop="sku_code" :rules="required('请输入渠道商品 SKU')">
            <ElInput v-model="draft.sku_code" placeholder="与履约事件中的 sku_code 一致" />
          </ElFormItem>
          <ElFormItem label="启用映射">
            <ElSwitch v-model="draft.status" :active-value="1" :inactive-value="2" />
          </ElFormItem>
          <ElAlert
            title="映射决定可信购买事件发放哪个套餐，不在此配置商城或支付。"
            type="info"
            :closable="false"
          />
        </template>
      </template>
      <ElAlert v-if="failure" :title="failure" type="error" :closable="false" class="feedback" />
    </ElForm>
    <template #footer>
      <ElButton :disabled="saving" @click="close(false)">取消</ElButton>
      <ElButton type="primary" :loading="saving" @click="save">{{
        resource === 'sku-mapping' ? '保存映射' : '保存草稿'
      }}</ElButton>
    </template>
  </ElDrawer>
</template>

<script setup lang="ts">
  import { computed, nextTick, reactive, ref, watch } from 'vue'
  import type { FormInstance, FormItemRule } from 'element-plus'
  import { saveMapping, savePlan, saveProduct } from '../api'
  import type { EditableResource, Features, ResourceRow } from '../api/types'
  import { errorMessage, requestId } from '../api/presentation'
  import ResourceSelect from './ResourceSelect.vue'
  const props = defineProps<{
    modelValue: boolean
    resource: EditableResource
    row?: ResourceRow
  }>()
  const emit = defineEmits<{ 'update:modelValue': [value: boolean]; saved: [] }>()
  const form = ref<FormInstance>()
  const saving = ref(false)
  const failure = ref('')
  const title = computed(
    () =>
      `${props.row ? '编辑' : '创建'}${{ product: '产品', plan: '套餐', 'sku-mapping': '商品映射' }[props.resource]}`
  )
  const initial = () => ({
    organization_id: '',
    application_id: '',
    code: '',
    name: '',
    audience: '',
    product_id: '',
    plan_id: '',
    channel_code: '',
    sku_code: '',
    status: 1,
    revision: 1,
    kind: 'desktop' as 'desktop' | 'membership',
    duration_unit: 'month' as 'day' | 'month' | 'year',
    duration_value: 1,
    seat_limit: 1
  })
  const draft = reactive(initial())
  const features = ref<{ keyId: string; key: string; value: string }[]>([])
  const required = (message: string): FormItemRule[] => [
    { required: true, message, trigger: 'blur' }
  ]
  const identifierRules: FormItemRule[] = [
    { required: true, message: '请输入已登记的编号', trigger: 'blur' },
    { pattern: /^[1-9]\d*$/, message: '请输入十进制正整数编号', trigger: 'blur' }
  ]
  watch(
    () => props.modelValue,
    async (open) => {
      if (!open) return
      Object.assign(draft, initial())
      if (props.row) {
        for (const key of Object.keys(draft) as (keyof typeof draft)[]) {
          const value = props.row[key]
          if (value !== undefined) Object.assign(draft, { [key]: value })
        }
      }
      features.value = Object.entries(props.row?.features ?? {}).map(([key, value]) => ({
        keyId: requestId(),
        key,
        value: String(value)
      }))
      failure.value = ''
      await nextTick()
      form.value?.clearValidate()
    }
  )
  watch(
    () => draft.product_id,
    (value, old) => {
      if (old && value !== old) draft.plan_id = ''
    }
  )
  function close(value: boolean) {
    if (saving.value) return
    emit('update:modelValue', value)
  }
  function featureValues(): Features {
    const result: Features = {}
    for (const item of features.value) {
      const key = item.key.trim()
      if (
        !/^[a-z][a-z0-9_.-]{0,79}$/.test(key) ||
        ['prototype', 'constructor'].includes(key) ||
        Object.hasOwn(result, key)
      ) {
        throw new Error('功能编码格式不正确、重复或使用了保留名称。')
      }
      const value = item.value.trim()
      if (value === 'true' || value === 'false') result[key] = value === 'true'
      else if (/^\d+$/.test(value) && Number.isSafeInteger(Number(value)))
        result[key] = Number(value)
      else throw new Error('功能值只接受 true、false 或非负整数。')
    }
    return result
  }
  async function save() {
    if (saving.value || !form.value) return
    const valid = await form.value.validate().catch(() => false)
    if (!valid) return
    saving.value = true
    failure.value = ''
    try {
      const id = props.row?.id
      if (props.resource === 'product') {
        await saveProduct({
          id,
          organization_id: draft.organization_id,
          application_id: draft.application_id,
          code: draft.code.trim(),
          name: draft.name.trim(),
          audience: draft.audience.trim()
        })
      } else if (props.resource === 'plan') {
        await savePlan({
          id,
          product_id: draft.product_id,
          code: draft.code.trim(),
          name: draft.name.trim(),
          revision: draft.revision,
          kind: draft.kind,
          duration_unit: draft.duration_unit,
          duration_value: draft.duration_value,
          seat_limit: draft.seat_limit,
          features: featureValues()
        })
      } else {
        await saveMapping({
          id,
          product_id: draft.product_id,
          plan_id: draft.plan_id,
          channel_code: draft.channel_code.trim(),
          sku_code: draft.sku_code.trim(),
          status: draft.status
        })
      }
      emit('saved')
      emit('update:modelValue', false)
    } catch (error) {
      failure.value = errorMessage(error)
    } finally {
      saving.value = false
    }
  }
</script>

<style scoped>
  .unit {
    width: 88px;
  }
  .hint {
    color: var(--el-text-color-secondary);
    line-height: 1.6;
  }
  .features {
    width: 100%;
  }
  .feature-row {
    display: flex;
    gap: 8px;
    margin-bottom: 8px;
  }
  .feedback {
    margin-top: 16px;
  }
  @media (max-width: 520px) {
    .feature-row {
      flex-wrap: wrap;
    }
  }
</style>
