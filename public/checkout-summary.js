(function () {
    const summary = document.getElementById('checkout-order-summary');
    const emptyBlock = document.querySelector('[data-checkout-empty]');
    const formRow = document.querySelector('[data-checkout-form-row]');

    function currency(amount) {
        return (window.shopCurrencySign || '£') + Number(amount).toFixed(2);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function render(data) {
        const lines = data.lines || [];

        if (lines.length === 0) {
            if (formRow) formRow.hidden = true;
            if (emptyBlock) emptyBlock.hidden = false;
            return;
        }

        let rows = '';
        lines.forEach(function (line) {
            rows +=
                '<tr>' +
                '<td>' +
                '<div class="checkout_line">' +
                (line.thumbImage
                    ? '<img class="checkout_line_thumb" src="' + escapeHtml(line.thumbImage) + '"' +
                      (line.thumbImageRetina ? ' srcset="' + escapeHtml(line.thumbImageRetina) + ' 2x"' : '') +
                      ' alt="' + escapeHtml(line.product_title) + '" width="48" height="48" loading="lazy">'
                    : '') +
                '<div>' + escapeHtml(line.quantity) + ' &times; ' + escapeHtml(line.product_title) +
                (line.option_signature ? '<br><small class="text-muted">' + escapeHtml(line.option_signature) + '</small>' : '') +
                '</div>' +
                '</div>' +
                '</td>' +
                '<td class="text-right">' + currency(line.line_total_inc_tax) + '</td>' +
                '</tr>';
        });

        const deliveryAmount = data.delivery_configured
            ? (data.free_delivery ? 'Free' : currency(data.delivery_exc_tax))
            : 'unavailable';

        // subtotal_exc_tax is already net of any discount, so the gross
        // figure shown above the discount row is reconstructed by adding
        // the saving back — showing the discounted subtotal a second time
        // next to "Discount" would look like it was subtracted twice.
        const hasDiscount = data.total_discount_amount > 0;
        const grossSubtotal = data.subtotal_exc_tax + data.total_discount_amount;

        const discountRow = hasDiscount
            ? '<tr><th scope="row">Discount' + (data.discount && data.discount.name ? ' (' + escapeHtml(data.discount.name) + ')' : '') + '</th>' +
              '<td class="text-right">&minus;' + currency(data.total_discount_amount) + '</td></tr>'
            : '';

        const couponNotice = data.coupon_notice
            ? '<tr><td colspan="2"><small class="text-muted" data-coupon-notice>' + escapeHtml(data.coupon_notice.message) + '</small></td></tr>'
            : '';

        summary.innerHTML =
            '<table class="table checkout-order-summary"><tbody>' + rows +
            '<tr><th scope="row">Subtotal (exc. VAT)</th><td class="text-right">' + currency(hasDiscount ? grossSubtotal : data.subtotal_exc_tax) + '</td></tr>' +
            discountRow +
            couponNotice +
            '<tr><th scope="row">Delivery (exc. VAT)</th><td class="text-right" data-delivery-amount>' + deliveryAmount + '</td></tr>' +
            '<tr><th scope="row">VAT</th><td class="text-right" data-tax-amount>' + currency(data.tax_amount) + '</td></tr>' +
            '<tr><th scope="row">Total</th><td class="text-right"><strong data-total-amount>' + currency(data.total_inc_tax) + '</strong></td></tr>' +
            '</tbody></table>';
    }

    fetch('/api/checkout-basket/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ action: 'get' }),
    })
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (!data.success) {
                summary.innerHTML = '<p>Could not load your order. Please reload the page.</p>';
                return;
            }
            render(data);
        })
        .catch(function () {
            summary.innerHTML = '<p>Could not load your order. Please reload the page.</p>';
        });
})();
