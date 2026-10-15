<!--
    Create a voucher by hand: a goodwill gesture, a competition prize, a
    replacement for a lost card.
-->
<script>
import { Head, Link, useForm } from '@statamic/cms/inertia';
import { Button, Checkbox, Field, Header, Input, Panel, Select, Textarea } from '@statamic/cms/ui';
import icon from '../../brand/nav-icon.svg?raw';

export default {
    components: { Button, Checkbox, Field, Head, Header, Input, Link, Panel, Select, Textarea },

    props: {
        sites: { type: Array, required: true },
        designs: { type: Array, default: () => [] },
        defaultExpiry: { type: String, default: null },
        today: { type: String, required: true },
        limits: { type: Object, required: true },
        urls: { type: Object, required: true },
    },

    setup(props) {
        const form = useForm({
            site: props.sites[0]?.handle ?? null,
            design: props.designs[0]?.value ?? null,
            amount: '',
            recipient_name: '',
            recipient_email: '',
            sender_name: '',
            message: '',
            send_on: '',
            expires_at: props.defaultExpiry ?? '',
            code: '',
            note: '',
            send: false,
        });

        return { form };
    },

    computed: {
        icon: () => icon,

        currency() {
            return this.sites.find((site) => site.handle === this.form.site)?.currency ?? '';
        },

        siteOptions() {
            return this.sites.map((site) => ({ value: site.handle, label: `${site.name} (${site.currency})` }));
        },
    },

    methods: {
        submit() {
            this.form.post(this.urls.store);
        },
    },
};
</script>

<template>
    <Head :title="__('Create Voucher')" />

    <div class="mx-auto max-w-3xl">
        <div class="mb-2 text-sm">
            <Link :href="urls.index" class="text-gray-600 hover:underline! dark:text-gray-400!">← {{ __('Gift Vouchers') }}</Link>
        </div>

        <Header :title="__('Create Voucher')" :icon="icon" />

        <form class="space-y-6" @submit.prevent="submit">
            <Panel :heading="__('Voucher')">
                <div class="grid gap-4 p-4 sm:grid-cols-2">
                    <Field v-if="sites.length > 1" class="sm:col-span-2" :label="__('Site')" :instructions="__('The voucher is in this site’s currency.')" :error="form.errors.site">
                        <Select v-model="form.site" :options="siteOptions" option-label="label" option-value="value" />
                    </Field>
                    <Field :label="__('Amount (:currency)', { currency })" :error="form.errors.amount" required>
                        <Input v-model="form.amount" inputmode="decimal" placeholder="25.00" />
                    </Field>
                    <Field :label="__('Use by')" :instructions="__('Leave empty for no expiry date.')" :error="form.errors.expires_at">
                        <Input v-model="form.expires_at" type="date" :min="today" />
                    </Field>
                    <Field v-if="designs.length > 1" class="sm:col-span-2" :label="__('Design')" :error="form.errors.design">
                        <Select v-model="form.design" :options="designs" option-label="label" option-value="value" />
                    </Field>
                    <Field class="sm:col-span-2" :label="__('Code')" :instructions="__('Leave empty for a random code (best). Or type one, 6 to 32 letters and numbers.')" :error="form.errors.code">
                        <Input v-model="form.code" class="font-mono" autocomplete="off" />
                    </Field>
                </div>
            </Panel>

            <Panel :heading="__('Recipient')">
                <div class="grid gap-4 p-4 sm:grid-cols-2">
                    <Field :label="__('Name')" :error="form.errors.recipient_name">
                        <Input v-model="form.recipient_name" :limit="limits.name" />
                    </Field>
                    <Field :label="__('Email')" :error="form.errors.recipient_email">
                        <Input v-model="form.recipient_email" type="email" />
                    </Field>
                    <Field :label="__('From')" :instructions="__('Shown as who the voucher is from. Leave empty for the site’s name.')" :error="form.errors.sender_name">
                        <Input v-model="form.sender_name" :limit="limits.name" />
                    </Field>
                    <Field :label="__('Send on')" :instructions="__('Leave empty to send straight away.')" :error="form.errors.send_on">
                        <Input v-model="form.send_on" type="date" :min="today" />
                    </Field>
                    <Field class="sm:col-span-2" :label="__('Message')" :error="form.errors.message">
                        <Textarea v-model="form.message" :limit="limits.message" elastic />
                    </Field>
                    <div class="sm:col-span-2">
                        <Checkbox v-model="form.send" :label="__('Email the voucher to the recipient')" :description="__('Now, or on the send date. You can also email it later from the voucher’s page.')" />
                    </div>
                </div>
            </Panel>

            <Panel :heading="__('Note')">
                <div class="p-4">
                    <Field :label="__('Why it was made')" :instructions="__('Kept in the voucher’s history. Customers don’t see it.')" :error="form.errors.note">
                        <Input v-model="form.note" :limit="500" />
                    </Field>
                </div>
            </Panel>

            <div class="flex gap-2">
                <Button type="submit" variant="primary" :loading="form.processing" :text="__('Create Voucher')" />
                <Button :href="urls.index" :text="__('Cancel')" />
            </div>
        </form>
    </div>
</template>
