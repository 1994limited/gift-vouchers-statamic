<!--
    A voucher's status as a coloured badge: Active, Spent, Expired, Disabled,
    and Scheduled while it waits for its send date.
-->
<script>
import { Badge } from '@statamic/cms/ui';

const colours = { active: 'green', spent: 'gray', expired: 'amber', disabled: 'red' };

export default {
    components: { Badge },

    props: {
        status: { type: String, required: true },
        label: { type: String, required: true },
        scheduled: { type: Boolean, default: false },
    },

    computed: {
        colour() {
            return colours[this.status] ?? 'default';
        },
    },
};
</script>

<template>
    <span class="inline-flex flex-wrap items-center gap-1">
        <Badge :text="label" :color="colour" size="sm" />
        <Badge v-if="scheduled" :text="__('Scheduled')" color="blue" size="sm" />
    </span>
</template>
