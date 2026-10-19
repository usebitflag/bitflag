<script setup lang="ts">
import {
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuPortal,
  DropdownMenuRoot,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from 'reka-ui';
import { computed } from 'vue';

export interface MenuItem {
  key: string;
  label: string;
  disabled?: boolean;
  /** Destructive items are listed below a divider. */
  destructive?: boolean;
}

const props = defineProps<{ items: MenuItem[]; label: string }>();
const emit = defineEmits<{ select: [key: string] }>();

const regular = computed(() => props.items.filter((item) => !item.destructive));
const destructive = computed(() => props.items.filter((item) => item.destructive));

const itemClass =
  'data-[highlighted]:bg-hover data-[disabled]:opacity-45 flex h-8 cursor-default items-center rounded-sm px-2 text-sm outline-none';
</script>

<template>
  <DropdownMenuRoot>
    <DropdownMenuTrigger as-child :aria-label="label">
      <slot name="trigger" />
    </DropdownMenuTrigger>
    <DropdownMenuPortal>
      <DropdownMenuContent
        align="end"
        :side-offset="4"
        class="bg-popover border-divider shadow-md z-50 min-w-44 rounded-md border p-1"
        data-test="menu"
      >
        <DropdownMenuItem
          v-for="item in regular"
          :key="item.key"
          :disabled="item.disabled"
          :class="itemClass"
          :data-test="`menu-item-${item.key}`"
          @select="emit('select', item.key)"
        >
          {{ item.label }}
        </DropdownMenuItem>
        <DropdownMenuSeparator
          v-if="destructive.length && regular.length"
          class="bg-divider my-1 h-px"
        />
        <DropdownMenuItem
          v-for="item in destructive"
          :key="item.key"
          :disabled="item.disabled"
          :class="[itemClass, 'text-danger-text']"
          :data-test="`menu-item-${item.key}`"
          @select="emit('select', item.key)"
        >
          {{ item.label }}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenuPortal>
  </DropdownMenuRoot>
</template>
