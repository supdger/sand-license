<template>
  <ElAlert
    v-if="!destination"
    title="暂无可访问的商业许可功能，请联系管理员分配对应权限。"
    type="info"
    :closable="false"
  />
</template>

<script setup lang="ts">
  import { computed, watch } from 'vue'
  import { useRoute, useRouter } from 'vue-router'
  import { useUserStore } from '@/store/modules/user'
  import { sections, permission } from '../api/catalog'
  defineOptions({ name: 'SandLicenseCenter' })
  const userStore = useUserStore()
  const route = useRoute()
  const router = useRouter()
  const destination = computed(() => {
    const buttons = userStore.getUserInfo.buttons ?? []
    return sections.find(
      (section) => buttons.includes('*') || buttons.includes(permission(section.key, 'index'))
    )
  })
  watch(
    destination,
    (section) => {
      if (section) {
        const path = route.path.replace(/\/index\/?$/, `/${section.key}`)
        if (path !== route.path) void router.replace({ path, query: route.query })
      }
    },
    { immediate: true }
  )
</script>
