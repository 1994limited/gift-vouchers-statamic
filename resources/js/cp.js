import Index from './pages/Index.vue';
import Show from './pages/Show.vue';
import Create from './pages/Create.vue';
import GiftVoucherOrderFieldtype from './components/GiftVoucherOrderFieldtype.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('gift-vouchers::Index', Index);
    Statamic.$inertia.register('gift-vouchers::Show', Show);
    Statamic.$inertia.register('gift-vouchers::Create', Create);
    Statamic.$components.register('gift_voucher_order-fieldtype', GiftVoucherOrderFieldtype);
});
