(function () {
    const root = document.getElementById('checkout-basket');
    const container = document.getElementById('checkout-basket-lines');
    const feedback = document.querySelector('.basket-feedback');
    const csrfToken = root.getAttribute('data-csrf-token');

    function showFeedback(message) {
        feedback.textContent = message;
        feedback.hidden = false;
    }

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
            container.innerHTML =
                '<div class="row"><div class="col text-center">' +
                '<p>Your basket is empty.</p>' +
                '<p><a href="/shop/">Browse the shop</a></p>' +
                '</div></div>';
            return;
        }

        let rows = '';
        lines.forEach(function (line) {
            rows +=
                '<tr>' +
                '<td>' +
                '<div class="basket_line">' +
                (line.thumbImage
                    ? '<img class="basket_line_thumb" src="' + escapeHtml(line.thumbImage) + '"' +
                      (line.thumbImageRetina ? ' srcset="' + escapeHtml(line.thumbImageRetina) + ' 2x"' : '') +
                      ' alt="' + escapeHtml(line.product_title) + '" width="64" height="64" loading="lazy">'
                    : '') +
                '<div class="basket_line_detail">' +
                '<strong>' + escapeHtml(line.product_title) + '</strong>' +
                (line.product_ref ? '<br><small class="text-muted">' + escapeHtml(line.product_ref) + '</small>' : '') +
                (line.option_signature ? '<br><small class="text-muted">' + escapeHtml(line.option_signature) + '</small>' : '') +
                '</div>' +
                '</div>' +
                '</td>' +
                '<td class="text-right">' + currency(line.unit_price_inc_tax) +
                '<br><small class="text-muted">' + currency(line.unit_price_exc_tax) + ' exc. VAT</small>' +
                '</td>' +
                '<td>' +
                '<label class="sr-only" for="qty-' + line.id + '">Quantity</label>' +
                '<input class="form-control basket-qty-input" type="number" min="0" max="99" ' +
                'id="qty-' + line.id + '" value="' + escapeHtml(line.quantity) + '" data-basket-qty="' + line.id + '">' +
                '</td>' +
                '<td class="text-right">' + currency(line.line_total_inc_tax) + '</td>' +
                '<td><button type="button" class="btn btn-link basket-remove-btn" data-basket-remove="' + line.id + '">Remove</button></td>' +
                '</tr>';
        });

        // The basket has no postcode, so it carries no delivery figure at all —
        // VAT/Total here are product-only; delivery is resolved at checkout.
        let productTax = 0;
        let productTotal = 0;
        lines.forEach(function (line) {
            productTax += line.line_tax_amount;
            productTotal += line.line_total_inc_tax;
        });

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

        container.innerHTML =
            '<div class="row"><div class="col">' +
            '<table class="table basket-table">' +
            '<thead><tr>' +
            '<th scope="col">Item</th>' +
            '<th scope="col" class="text-right">Price</th>' +
            '<th scope="col">Quantity</th>' +
            '<th scope="col" class="text-right">Total</th>' +
            '<th scope="col"><span class="sr-only">Remove</span></th>' +
            '</tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
            '</table>' +
            '</div></div>' +
            '<div class="row justify-content-end">' +
            '<div class="col-md-5">' +
            (data.discounts_enabled ? renderCouponSection(data) : '') +
            '<table class="table basket-totals">' +
            '<tbody>' +
            '<tr><th scope="row">Subtotal (exc. VAT)</th><td class="text-right">' + currency(hasDiscount ? grossSubtotal : data.subtotal_exc_tax) + '</td></tr>' +
            discountRow +
            '<tr><th scope="row">VAT</th><td class="text-right">' + currency(productTax) + '</td></tr>' +
            '<tr><th scope="row">Total</th><td class="text-right"><strong>' + currency(productTotal) + '</strong></td></tr>' +
            '</tbody>' +
            '</table>' +
            '<p><small>Delivery is calculated at checkout.</small></p>' +
            '<div class="red_button">' +
            '<a href="/checkout/" style="display:inline-block; padding:0 3em; font-weight:bold;">checkout</a>' +
            '</div>' +
            '<p class="mt-3"><a href="/shop/">Continue shopping</a></p>' +
            '</div></div>';

        container.querySelectorAll('[data-basket-qty]').forEach(function (input) {
            input.addEventListener('change', function () {
                const itemId = parseInt(input.getAttribute('data-basket-qty'), 10);
                const quantity = parseInt(input.value, 10) || 0;
                postAction({ action: 'update_quantity', item_id: itemId, quantity: quantity, csrf_token: csrfToken })
                    .then(handleResponse);
            });
        });

        container.querySelectorAll('[data-basket-remove]').forEach(function (button) {
            button.addEventListener('click', function () {
                const itemId = parseInt(button.getAttribute('data-basket-remove'), 10);
                postAction({ action: 'remove', item_id: itemId, csrf_token: csrfToken })
                    .then(handleResponse);
            });
        });

        const applyButton = container.querySelector('[data-coupon-apply]');
        if (applyButton) {
            applyButton.addEventListener('click', function () {
                const input = container.querySelector('[data-coupon-input]');
                const code = input ? input.value.trim() : '';
                if (!code) return;

                postAction({ action: 'apply_coupon', code: code, csrf_token: csrfToken })
                    .then(handleResponse);
            });
        }

        const removeButton = container.querySelector('[data-coupon-remove]');
        if (removeButton) {
            removeButton.addEventListener('click', function () {
                postAction({ action: 'remove_coupon', csrf_token: csrfToken })
                    .then(handleResponse);
            });
        }
    }

    function renderCouponSection(data) {
        const applied = data.discount && data.discount.type === 'coupon';

        if (applied) {
            return '<div class="basket-coupon mb-3 d-flex align-items-center justify-content-between">' +
                '<span>Coupon <strong>' + escapeHtml(data.discount.code) + '</strong> applied</span>' +
                '<button type="button" class="btn btn-link btn-sm" data-coupon-remove>Remove</button>' +
                '</div>';
        }

        // A saved code that has dropped below its minimum spend: say why it is not taking money off, and let it go
        if (data.coupon_notice) {
            return '<div class="basket-coupon mb-3">' +
                '<div class="d-flex align-items-center justify-content-between">' +
                '<span>Coupon <strong>' + escapeHtml(data.coupon_notice.code) + '</strong> not applied</span>' +
                '<button type="button" class="btn btn-link btn-sm" data-coupon-remove>Remove</button>' +
                '</div>' +
                '<small class="text-muted" data-coupon-notice>' + escapeHtml(data.coupon_notice.message) + '</small>' +
                '</div>';
        }

        return '<div class="basket-coupon mb-3 d-flex gap-2">' +
            '<label class="sr-only" for="basket-coupon-input">Coupon code</label>' +
            '<input type="text" class="form-control" id="basket-coupon-input" data-coupon-input placeholder="Coupon code">' +
            '<button type="button" class="btn btn-outline-secondary" data-coupon-apply>Apply</button>' +
            '</div>';
    }

    function postAction(body) {
        return fetch('/api/checkout-basket/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).then(function (response) { return response.json(); });
    }

    function handleResponse(data) {
        if (!data.success) {
            showFeedback(data.error || 'Could not update your basket. Please reload the page.');
            return;
        }
        feedback.hidden = true;
        render(data);
    }

    postAction({ action: 'get' }).then(function (data) {
        if (!data.success) {
            showFeedback('Could not load your basket. Please reload the page.');
            return;
        }
        render(data);
    }).catch(function () {
        showFeedback('Could not load your basket. Please reload the page.');
    });
})();
