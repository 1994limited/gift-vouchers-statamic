<!--
    One voucher: its balance, who it's for, where it came from, its
    history, and (with permission) changing it.
-->
<script>
import { Head, Link, router, useForm } from '@statamic/cms/inertia';
import { Button, Field, Header, Input, Panel, Select, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Textarea } from '@statamic/cms/ui';
import StatusBadge from '../components/StatusBadge.vue';
import icon from '../../brand/nav-icon.svg?raw';

export default {
    components: { Button, Field, Head, Header, Input, Link, Panel, Select, StatusBadge, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows, Textarea },

    props: {
        voucher: { type: Object, required: true },
        transactions: { type: Array, required: true },
        canEdit: { type: Boolean, default: false },
        limits: { type: Object, required: true },
        urls: { type: Object, required: true },
    },

    setup(props) {
        const adjust = useForm({ direction: 'add', amount: '', note: '' });

        const details = useForm({
            recipient_name: props.voucher.recipient_name ?? '',
            recipient_email: props.voucher.recipient_email ?? '',
            sender_name: props.voucher.sender_name ?? '',
            message: props.voucher.message ?? '',
            expires_at: props.voucher.expires_on ?? '',
            notes: props.voucher.notes ?? '',
        });

        return { adjust, details };
    },

    data() {
        return { code: null, revealing: false, editing: false };
    },

    computed: {
        icon: () => icon,

        directions() {
            return [
                { value: 'add', label: __('Add to the balance') },
                { value: 'remove', label: __('Take from the balance') },
            ];
        },
    },

    methods: {
        async reveal() {
            this.revealing = true;

            try {
                const response = await fetch(this.urls.code, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': Statamic.$config.get('csrfToken') },
                });

                this.code = (await response.json()).code ?? __('The code can’t be shown (the app key has changed).');
            } finally {
                this.revealing = false;
            }
        },

        submitAdjustment() {
            this.adjust.post(this.urls.adjust, {
                preserveScroll: true,
                onSuccess: () => this.adjust.reset(),
            });
        },

        saveDetails() {
            this.details.patch(this.urls.update, {
                preserveScroll: true,
                onSuccess: () => (this.editing = false),
            });
        },

        toggle() {
            if (!this.voucher.is_disabled && !confirm(__('Disable this voucher? It can’t be spent until it’s enabled again.'))) {
                return;
            }

            router.post(this.voucher.is_disabled ? this.urls.enable : this.urls.disable, {}, { preserveScroll: true });
        },

        send() {
            if (!confirm(__('Email this voucher to :email?', { email: this.voucher.email_to }))) {
                return;
            }

            router.post(this.urls.send, {}, { preserveScroll: true });
        },
    },
};
</script>

<template>
    <Head :title="voucher.code" />

    <div class="mx-auto max-w-5xl">
        <div class="mb-2 text-sm">
            <Link :href="urls.index" class="text-gray-600 hover:underline! dark:text-gray-400!">← {{ __('Gift Vouchers') }}</Link>
        </div>

        <Header :icon="icon">
            <template #title>
                <span class="flex flex-wrap items-center gap-3">
                    <span class="font-mono">{{ voucher.code }}</span>
                    <StatusBadge :status="voucher.status" :label="voucher.status_label" :scheduled="voucher.scheduled" />
                </span>
            </template>

            <template v-if="canEdit">
                <Button :href="urls.print" target="_blank" :text="__('Print')" icon="printer" />
                <Button v-if="voucher.email_to" :text="voucher.sent_at ? __('Email again') : __('Email now')" icon="mail" @click="send" />
                <Button :variant="voucher.is_disabled ? 'primary' : 'danger'" :text="voucher.is_disabled ? __('Enable') : __('Disable')" @click="toggle" />
            </template>
        </Header>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <Panel>
                    <div class="grid gap-4 p-4 sm:grid-cols-3">
                        <div>
                            <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Balance') }}</div>
                            <div class="text-3xl font-semibold tabular-nums">{{ voucher.balance }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Original amount') }}</div>
                            <div class="text-lg tabular-nums">{{ voucher.initial_amount }}</div>
                        </div>
                        <div>
                            <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Spent') }}</div>
                            <div class="text-lg tabular-nums">{{ voucher.spent }}</div>
                        </div>
                    </div>

                    <div v-if="canEdit" class="border-t border-gray-200 px-4 py-3 dark:border-gray-700!">
                        <div v-if="code" class="flex flex-wrap items-center gap-3">
                            <Input :model-value="code" read-only copyable class="max-w-sm font-mono" :aria-label="__('Code')" />
                        </div>
                        <Button v-else size="sm" :loading="revealing" :text="__('Show code')" icon="eye" @click="reveal" />
                    </div>
                </Panel>

                <Panel v-if="canEdit" :heading="__('Change the balance')">
                    <form class="grid gap-4 p-4 sm:grid-cols-2" @submit.prevent="submitAdjustment">
                        <Field :label="__('Change')" :error="adjust.errors.direction">
                            <Select v-model="adjust.direction" :options="directions" option-label="label" option-value="value" />
                        </Field>
                        <Field :label="__('Amount (:currency)', { currency: voucher.currency })" :error="adjust.errors.amount" required>
                            <Input v-model="adjust.amount" inputmode="decimal" placeholder="10.00" />
                        </Field>
                        <Field class="sm:col-span-2" :label="__('Why')" :instructions="__('Kept in the voucher’s history.')" :error="adjust.errors.note" required>
                            <Input v-model="adjust.note" :limit="500" />
                        </Field>
                        <div class="sm:col-span-2">
                            <Button type="submit" variant="primary" :loading="adjust.processing" :text="__('Change balance')" />
                        </div>
                    </form>
                </Panel>

                <Panel :heading="__('History')">
                    <div class="overflow-x-auto">
                        <Table>
                            <TableColumns>
                                <TableColumn>{{ __('When') }}</TableColumn>
                                <TableColumn>{{ __('What') }}</TableColumn>
                                <TableColumn class="text-right">{{ __('Amount') }}</TableColumn>
                                <TableColumn class="text-right">{{ __('Balance') }}</TableColumn>
                            </TableColumns>
                            <TableRows>
                                <TableRow v-for="transaction in transactions" :key="transaction.id">
                                    <TableCell class="whitespace-nowrap text-gray-600 dark:text-gray-400!" :title="transaction.date_iso">{{ transaction.date }}</TableCell>
                                    <TableCell>
                                        <div class="font-medium">
                                            {{ transaction.label }}
                                            <template v-if="transaction.order">
                                                · <a v-if="transaction.order.url" :href="transaction.order.url" class="hover:underline!">{{ __('Order #:number', { number: transaction.order.number }) }}</a>
                                            </template>
                                        </div>
                                        <div v-if="transaction.note" class="text-sm text-gray-600 dark:text-gray-400!">{{ transaction.note }}</div>
                                        <div v-if="transaction.user" class="text-xs text-gray-600 dark:text-gray-400!">{{ __('by :user', { user: transaction.user }) }}</div>
                                    </TableCell>
                                    <TableCell class="text-right tabular-nums whitespace-nowrap" :class="transaction.is_credit ? 'text-green-700 dark:text-green-400!' : ''">{{ transaction.amount }}</TableCell>
                                    <TableCell class="text-right tabular-nums whitespace-nowrap">{{ transaction.balance_after }}</TableCell>
                                </TableRow>
                            </TableRows>
                        </Table>
                    </div>
                </Panel>
            </div>

            <div class="space-y-6">
                <Panel :heading="__('Details')">
                    <form v-if="editing" class="space-y-4 p-4" @submit.prevent="saveDetails">
                        <Field :label="__('Recipient')" :error="details.errors.recipient_name">
                            <Input v-model="details.recipient_name" :limit="limits.name" />
                        </Field>
                        <Field :label="__('Recipient email')" :error="details.errors.recipient_email">
                            <Input v-model="details.recipient_email" type="email" />
                        </Field>
                        <Field :label="__('From')" :error="details.errors.sender_name">
                            <Input v-model="details.sender_name" :limit="limits.name" />
                        </Field>
                        <Field :label="__('Message')" :error="details.errors.message">
                            <Textarea v-model="details.message" :limit="limits.message" elastic />
                        </Field>
                        <Field :label="__('Use by')" :instructions="__('Leave empty for no expiry date.')" :error="details.errors.expires_at">
                            <Input v-model="details.expires_at" type="date" />
                        </Field>
                        <Field :label="__('Notes')" :instructions="__('Only shown here.')" :error="details.errors.notes">
                            <Textarea v-model="details.notes" elastic />
                        </Field>
                        <div class="flex gap-2">
                            <Button type="submit" variant="primary" :loading="details.processing" :text="__('Save')" />
                            <Button :text="__('Cancel')" @click="editing = false" />
                        </div>
                    </form>

                    <dl v-else class="space-y-3 p-4 text-sm">
                        <div>
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Recipient') }}</dt>
                            <dd>{{ voucher.recipient_name || '—' }}<span v-if="voucher.recipient_email" class="block text-gray-600 dark:text-gray-400!">{{ voucher.recipient_email }}</span></dd>
                        </div>
                        <div v-if="voucher.sender_name">
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('From') }}</dt>
                            <dd>{{ voucher.sender_name }}</dd>
                        </div>
                        <div v-if="voucher.message">
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Message') }}</dt>
                            <dd class="whitespace-pre-line">{{ voucher.message }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Design') }}</dt>
                            <dd>{{ voucher.design }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Use by') }}</dt>
                            <dd>{{ voucher.expires_at || __('No expiry date') }}</dd>
                        </div>
                        <div>
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Email') }}</dt>
                            <dd>
                                <template v-if="voucher.sent_at">{{ __('Sent :date', { date: voucher.sent_at }) }}</template>
                                <template v-else-if="voucher.send_on">{{ __('Will be sent on :date', { date: voucher.send_on }) }}</template>
                                <template v-else>{{ __('Not sent') }}</template>
                                <span v-if="voucher.email_to" class="block text-gray-600 dark:text-gray-400!">{{ voucher.email_to }}</span>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Came from') }}</dt>
                            <dd>
                                <a v-if="voucher.order && voucher.order.url" :href="voucher.order.url" class="hover:underline!">{{ __('Order #:number', { number: voucher.order.number }) }}</a>
                                <template v-else-if="voucher.order">{{ __('An order that has been deleted') }}</template>
                                <template v-else>{{ voucher.created_by ? __('Created by :user', { user: voucher.created_by }) : __('Created by hand') }}</template>
                                <span class="block text-gray-600 dark:text-gray-400!">{{ voucher.created_at_formatted }}</span>
                            </dd>
                        </div>
                        <div v-if="voucher.notes">
                            <dt class="text-gray-600 dark:text-gray-400!">{{ __('Notes') }}</dt>
                            <dd class="whitespace-pre-line">{{ voucher.notes }}</dd>
                        </div>
                        <div v-if="canEdit" class="pt-1">
                            <Button size="sm" :text="__('Edit details')" @click="editing = true" />
                        </div>
                    </dl>
                </Panel>
            </div>
        </div>
    </div>
</template>
