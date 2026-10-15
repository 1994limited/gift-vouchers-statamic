<!--
    The Gift Vouchers panel on a Cargo order: vouchers it was paid with, and
    vouchers bought on it. Read only.
-->
<script setup>
import { Fieldtype } from '@statamic/cms';
import { Description } from '@statamic/cms/ui';
import StatusBadge from './StatusBadge.vue';

const emit = defineEmits(Fieldtype.emits);
const props = defineProps(Fieldtype.props);
const { expose } = Fieldtype.use(emit, props);
defineExpose(expose);
</script>

<template>
    <div class="space-y-5 text-sm">
        <Description v-if="!value.used.length && !value.issued.length">
            {{ __('No gift vouchers were used or bought on this order.') }}
        </Description>

        <section v-if="value.used.length">
            <h3 class="mb-2 font-medium">{{ __('Paid with') }}</h3>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700!">
                <li v-for="(voucher, index) in value.used" :key="index" class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                    <span>
                        <a v-if="voucher.url" :href="voucher.url" class="font-mono hover:underline!">{{ voucher.code }}</a>
                        <span v-else class="font-mono">{{ voucher.code }}</span>
                        <span v-if="voucher.returned" class="text-gray-600 dark:text-gray-400!"> · {{ __(':amount put back', { amount: voucher.returned }) }}</span>
                    </span>
                    <span class="font-medium tabular-nums">{{ voucher.paid }}</span>
                </li>
            </ul>
            <Description v-if="value.refundable" class="mt-2">
                {{ __(':amount can be refunded to vouchers with Refund to gift voucher.', { amount: value.refundable }) }}
            </Description>
        </section>

        <section v-if="value.issued.length">
            <h3 class="mb-2 font-medium">{{ __('Vouchers bought') }}</h3>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700!">
                <li v-for="(voucher, index) in value.issued" :key="index" class="flex flex-wrap items-baseline justify-between gap-2 py-2">
                    <span class="min-w-0">
                        <a v-if="voucher.url" :href="voucher.url" class="font-mono hover:underline!">{{ voucher.code }}</a>
                        <span v-else class="font-mono">{{ voucher.code }}</span>
                        <span v-if="voucher.recipient" class="text-gray-600 dark:text-gray-400!"> · {{ voucher.recipient }}</span>
                        <span v-if="voucher.scheduled" class="block text-gray-600 dark:text-gray-400!">{{ __('Will be emailed on :date', { date: voucher.scheduled }) }}</span>
                    </span>
                    <span class="flex items-center gap-2">
                        <StatusBadge :status="voucher.status" :label="voucher.status_label" />
                        <span class="tabular-nums">{{ voucher.balance }} / {{ voucher.amount }}</span>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
