<!--
    Store → Gift Vouchers: every voucher, newest first, with a search on
    code, recipient or order number, a status filter, and what's still
    owed on active vouchers.
-->
<script>
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Button, Header, Input, Panel, Select, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows } from '@statamic/cms/ui';
import StatusBadge from '../components/StatusBadge.vue';
import icon from '../../brand/nav-icon.svg?raw';

export default {
    components: { Alert, Button, Head, Header, Input, Link, Panel, Select, StatusBadge, Table, TableCell, TableColumn, TableColumns, TableRow, TableRows },

    props: {
        search: { type: String, default: null },
        status: { type: String, default: null },
        statuses: { type: Array, required: true },
        vouchers: { type: Array, required: true },
        pagination: { type: Object, required: true },
        outstanding: { type: Array, required: true },
        priceHookInstalled: { type: Boolean, default: true },
        canCreate: { type: Boolean, default: false },
        urls: { type: Object, required: true },
    },

    data() {
        return { query: this.search ?? '', filter: this.status, timer: null };
    },

    computed: {
        icon: () => icon,

        statusOptions() {
            return [{ value: null, label: __('All statuses') }, ...this.statuses];
        },

        exportUrl() {
            return this.urls.export + this.queryString();
        },

        filtered() {
            return Boolean(this.search || this.status);
        },
    },

    watch: {
        query() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.reload(), 300);
        },

        filter() {
            this.reload();
        },
    },

    methods: {
        params(page = 1) {
            const params = {};
            if (this.query) params.search = this.query;
            if (this.filter) params.status = this.filter;
            if (page > 1) params.page = page;
            return params;
        },

        queryString(page = 1) {
            const query = new URLSearchParams(this.params(page)).toString();
            return query ? `?${query}` : '';
        },

        reload() {
            router.get(this.urls.index, this.params(), { preserveState: true, replace: true });
        },

        pageUrl(page) {
            return this.urls.index + this.queryString(page);
        },
    },
};
</script>

<template>
    <Head :title="__('Gift Vouchers')" />

    <div class="mx-auto max-w-6xl">
        <Header :title="__('Gift Vouchers')" :icon="icon">
            <Button :href="exportUrl" :text="__('Download CSV')" icon="download" />
            <Button v-if="canCreate" :href="urls.create" variant="primary" :text="__('Create Voucher')" />
        </Header>

        <Alert
            v-if="!priceHookInstalled"
            class="mb-6"
            variant="warning"
            :heading="__('Vouchers aren’t being priced')"
            :text="__('Something has replaced Cargo’s price hook since Gift Vouchers set it, so vouchers where the shopper chooses the amount will be priced wrongly. See Troubleshooting in the Gift Vouchers docs.')"
        />

        <div v-if="outstanding.length" class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Panel v-for="row in outstanding" :key="row.currency">
                <div class="px-4 py-3">
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __('Still to spend (:currency)', { currency: row.currency }) }}</div>
                    <div class="mt-1 text-2xl font-semibold tabular-nums">{{ row.amount }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400!">{{ __(row.count === 1 ? 'on 1 active voucher' : 'on :count active vouchers', { count: row.count }) }}</div>
                </div>
            </Panel>
        </div>

        <div class="mb-3 flex flex-col gap-3 sm:flex-row">
            <div class="grow">
                <Input v-model="query" icon="magnifying-glass" :placeholder="__('Search by code, recipient or order number')" clearable :aria-label="__('Search')" />
            </div>
            <div class="sm:w-56">
                <Select v-model="filter" :options="statusOptions" option-label="label" option-value="value" :aria-label="__('Status')" />
            </div>
        </div>

        <Panel>
            <div v-if="vouchers.length" class="overflow-x-auto">
                <Table>
                    <TableColumns>
                        <TableColumn>{{ __('Code') }}</TableColumn>
                        <TableColumn>{{ __('Recipient') }}</TableColumn>
                        <TableColumn class="text-right">{{ __('Amount') }}</TableColumn>
                        <TableColumn class="text-right">{{ __('Balance') }}</TableColumn>
                        <TableColumn>{{ __('Status') }}</TableColumn>
                        <TableColumn class="text-right">{{ __('Created') }}</TableColumn>
                    </TableColumns>
                    <TableRows>
                        <TableRow v-for="voucher in vouchers" :key="voucher.id">
                            <TableCell>
                                <Link :href="voucher.url" class="font-mono font-medium whitespace-nowrap hover:underline!">{{ voucher.code }}</Link>
                                <div v-if="voucher.source === 'manual'" class="text-xs text-gray-600 dark:text-gray-400!">{{ __('Created by hand') }}</div>
                            </TableCell>
                            <TableCell>
                                <div v-if="voucher.recipient_name" class="truncate">{{ voucher.recipient_name }}</div>
                                <div v-if="voucher.recipient_email" class="truncate text-sm text-gray-600 dark:text-gray-400!">{{ voucher.recipient_email }}</div>
                                <span v-if="!voucher.recipient_name && !voucher.recipient_email" class="text-gray-500">—</span>
                            </TableCell>
                            <TableCell class="text-right tabular-nums whitespace-nowrap">{{ voucher.initial_amount }}</TableCell>
                            <TableCell class="text-right font-medium tabular-nums whitespace-nowrap">{{ voucher.balance }}</TableCell>
                            <TableCell><StatusBadge :status="voucher.status" :label="voucher.status_label" :scheduled="voucher.scheduled" /></TableCell>
                            <TableCell class="text-right whitespace-nowrap text-gray-600 dark:text-gray-400!" :title="voucher.created_at">{{ voucher.created_ago }}</TableCell>
                        </TableRow>
                    </TableRows>
                </Table>
            </div>

            <div v-else class="px-6 py-10 text-center">
                <p v-if="filtered" class="text-gray-600 dark:text-gray-400!">{{ __('No vouchers match.') }}</p>
                <template v-else>
                    <p class="font-medium">{{ __('No gift vouchers yet') }}</p>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400!">{{ __('Vouchers appear here when someone buys one and their order is paid. You can also create one by hand.') }}</p>
                </template>
            </div>
        </Panel>

        <nav v-if="pagination.last_page > 1" class="mt-4 flex items-center justify-between text-sm" :aria-label="__('Pages')">
            <Link v-if="pagination.page > 1" :href="pageUrl(pagination.page - 1)" class="hover:underline!">← {{ __('Newer') }}</Link>
            <span v-else />
            <span class="text-gray-600 dark:text-gray-400!">{{ __(':page of :last', { page: pagination.page, last: pagination.last_page }) }} · {{ __(':total vouchers', { total: pagination.total }) }}</span>
            <Link v-if="pagination.page < pagination.last_page" :href="pageUrl(pagination.page + 1)" class="hover:underline!">{{ __('Older') }} →</Link>
            <span v-else />
        </nav>
    </div>
</template>
