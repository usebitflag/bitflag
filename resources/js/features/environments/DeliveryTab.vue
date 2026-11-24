<script setup lang="ts">
import Card from '../../components/ui/Card.vue';
</script>

<template>
  <div class="grid max-w-[820px] gap-4" data-test="environment-delivery">
    <Card title="Delivery">
      <p class="text-text/70 text-sm">
        Nothing published yet. SDKs using this environment's keys get an empty snapshot and return
        your fallback values.
      </p>
    </Card>
  </div>
</template>
