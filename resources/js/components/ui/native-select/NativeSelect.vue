<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { ChevronDown } from "@lucide/vue"
import { useVModel } from "@vueuse/core"
import { cn } from "@/lib/utils"

defineOptions({ inheritAttrs: false })

const props = defineProps<{
  defaultValue?: string | number
  modelValue?: string | number | null
  class?: HTMLAttributes["class"]
}>()

const emits = defineEmits<{
  (e: "update:modelValue", payload: string | number | null): void
}>()

const modelValue = useVModel(props, "modelValue", emits, {
  passive: true,
  defaultValue: props.defaultValue,
})
</script>

<template>
  <div :class="cn('relative w-full', props.class)">
    <select
      v-model="modelValue"
      v-bind="$attrs"
      data-slot="native-select"
      class="border-input dark:bg-input/30 h-9 w-full appearance-none rounded-md border bg-transparent py-1 pr-9 pl-3 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm [&>option]:bg-popover [&>option]:text-popover-foreground"
    >
      <slot />
    </select>
    <ChevronDown class="text-muted-foreground pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2" />
  </div>
</template>
