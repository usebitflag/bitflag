<script setup lang="ts">
// A 44px outlined tile that holds one icon, used above a heading on the sign-up and recovery screens.
</script>

<template>
  <span
    class="text-primary ring-selection grid h-11 w-11 place-items-center rounded-[12px] ring-1 ring-inset"
    data-test="icon-tile"
  >
    <slot />
  </span>
</template>
