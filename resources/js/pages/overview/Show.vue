<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PageHeader from '../../components/ui/PageHeader.vue';
import PortalLayout from '../../layouts/PortalLayout.vue';

defineOptions({ layout: PortalLayout });
defineProps<{ heading: string }>();
</script>

<template>
  <Head title="Overview" />
  <PageHeader :title="heading" />
</template>
