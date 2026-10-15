/*
 * Gift Vouchers: the live voucher preview ({{ gift_vouchers:preview }}).
 *
 * Fills the preview card in from the voucher form as the shopper types:
 * the amount, who it's for and from, and the design. Also makes buttons
 * with data-gift-vouchers-amount="25.00" fill in the amount box.
 * Text goes in with textContent, never as markup. No dependencies.
 */
(() => {
    const ready = (fn) => (document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', fn) : fn());

    ready(() => {
        document.querySelectorAll('[data-gift-vouchers-preview]').forEach((card) => {
            const form = card.dataset.form
                ? document.getElementById(card.dataset.form)
                : document.querySelector('form[action*="/!/gift-vouchers/cart/add"]');

            if (!form) return;

            const money = new Intl.NumberFormat(card.dataset.locale || undefined, { style: 'currency', currency: card.dataset.currency || 'GBP' });
            const designs = JSON.parse(card.dataset.designs || '{}');
            const art = card.querySelector('[data-preview-art]');
            const field = (name) => form.querySelector(`[name="${name}"]`);

            // Read the amount box as the server does (Money::parse): an
            // optional currency symbol or code, the site's decimal separator,
            // thousands only in groups of three, and nothing else.
            const decimal = card.dataset.decimal === ',' ? ',' : '.';
            const group = decimal === '.' ? ',' : '.';
            const amountOf = (raw) => {
                const value = String(raw).trim()
                    .replace(/^(?:[A-Z]{3}|[^\w\s]{1,3})\s*/u, '')
                    .replace(/\s*(?:[A-Z]{3}|[^\w\s]{1,3})$/u, '')
                    .replace(/[\s  ]/gu, '');

                if (!/^[\d.,]+$/.test(value)) return null;
                if (/^\d+\.\d{2}$/.test(value)) return parseFloat(value); // "25.00", as quick amounts write it

                const [whole, fraction, extra] = value.split(decimal);
                if (extra !== undefined || (fraction !== undefined && !/^\d{1,2}$/.test(fraction))) return null;
                if (whole.includes(group) && !new RegExp(`^\\d{1,3}(\\${group}\\d{3})+$`).test(whole)) return null;

                return parseFloat(whole.split(group).join('') + (fraction !== undefined ? `.${fraction}` : ''));
            };

            const put = (name, label, value) => {
                const el = card.querySelector(`[data-preview="${name}"]`);
                if (!el) return;
                el.textContent = '';
                if (!value) return;
                el.append(`${label} `);
                const strong = document.createElement('strong');
                strong.textContent = value;
                el.append(strong);
            };

            const update = () => {
                const amount = field('amount');
                const amountEl = card.querySelector('[data-preview="amount"]');
                if (amount && amountEl) {
                    const value = amountOf(amount.value);
                    amountEl.textContent = value === null || !Number.isFinite(value) ? '—' : money.format(value);
                    form.querySelectorAll('[data-gift-vouchers-amount]').forEach((button) => {
                        button.setAttribute('aria-pressed', String(parseFloat(button.dataset.giftVouchersAmount) === value));
                    });
                }

                put('to', card.dataset.forLabel, field('recipient_name')?.value.trim());
                put('from', card.dataset.fromLabel, field('sender_name')?.value.trim());

                const chosen = form.querySelector('[name="design"]:checked') || form.querySelector('select[name="design"]');
                const design = chosen && designs[chosen.value];
                if (design) {
                    card.classList.remove('gv-engraved', 'gv-image', 'gv-plain');
                    card.classList.add(`gv-${design.kind}`);
                    card.style.setProperty('--gv-colour', design.colour);
                    const title = card.querySelector('[data-preview="title"]');
                    if (title && design.title) title.textContent = design.title;
                    if (art) {
                        art.hidden = !design.artwork;
                        if (design.artwork && art.getAttribute('src') !== design.artwork) art.src = design.artwork;
                    }
                }
            };

            form.addEventListener('click', (event) => {
                const button = event.target.closest('[data-gift-vouchers-amount]');
                if (!button || !field('amount')) return;
                event.preventDefault();
                field('amount').value = button.dataset.giftVouchersAmount;
                field('amount').dispatchEvent(new Event('input', { bubbles: true }));
            });

            form.querySelectorAll('[data-gift-vouchers-amount]').forEach((button) => (button.hidden = false));
            form.addEventListener('input', update);
            form.addEventListener('change', update);
            update();
        });
    });
})();
