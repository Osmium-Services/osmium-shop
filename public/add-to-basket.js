/**
 * Add to basket - the product page's "add to cart" link.
 *
 * Markup contract (see THEME-CONTRACT.md):
 *   <form class="buybutton" data-native-basket-token="<?= $template['formToken'] ?>">
 *       <input id="qty" type="number" value="1">                       (optional, default 1)
 *       <input id="productpr" value="...">                             (optional option string)
 *       <select class="product-option-select">                         (zero or more)
 *           <option data-value-id="12">...</option>
 *       </select>
 *       <a href="#" class="native-add-to-cart" data-product-id="34">add to cart</a>
 *   </form>
 *
 * The server prices the basket from product_id and option_value_ids; nothing computed
 * in the browser is trusted.
 *
 * Events, both fired on the link and bubbling, so a theme can replace the defaults:
 *   osmium:basket-added  detail: the API response. preventDefault() stops the redirect.
 *   osmium:basket-error  detail: { message }. preventDefault() stops the alert().
 *
 * Optional: data-basket-url on the link overrides the page visitors are sent to (default /basket/).
 */
(function () {
    'use strict';

    const API_URL = '/api/checkout-basket/';
    const DEFAULT_BASKET_URL = '/basket/';
    const GENERIC_ERROR = 'Could not add this to your basket.';

    function selectedOptionValueIds(form) {
        const ids = [];
        form.querySelectorAll('.product-option-select').forEach(function (select) {
            const selected = select.options[select.selectedIndex];
            const valueId = selected ? selected.getAttribute('data-value-id') : null;
            if (valueId) ids.push(parseInt(valueId, 10));
        });
        return ids;
    }

    function fail(link, message) {
        const notPrevented = link.dispatchEvent(new CustomEvent('osmium:basket-error', {
            bubbles: true,
            cancelable: true,
            detail: { message: message },
        }));
        if (notPrevented) window.alert(message);
    }

    function addToBasket(link) {
        const form = link.closest('form');
        if (!form) return;

        const qtyField = form.querySelector('#qty');
        const optionsField = form.querySelector('#productpr');

        const payload = {
            action: 'add',
            product_id: link.getAttribute('data-product-id'),
            quantity: qtyField ? (parseInt(qtyField.value, 10) || 1) : 1,
            product_options: optionsField ? optionsField.value : null,
            option_value_ids: selectedOptionValueIds(form),
            csrf_token: form.getAttribute('data-native-basket-token'),
        };

        link.setAttribute('aria-disabled', 'true');

        fetch(API_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                link.removeAttribute('aria-disabled');
                if (!data.success) {
                    fail(link, data.error || GENERIC_ERROR);
                    return;
                }
                const notPrevented = link.dispatchEvent(new CustomEvent('osmium:basket-added', {
                    bubbles: true,
                    cancelable: true,
                    detail: data,
                }));
                if (notPrevented) window.location.href = link.getAttribute('data-basket-url') || DEFAULT_BASKET_URL;
            })
            .catch(function () {
                link.removeAttribute('aria-disabled');
                fail(link, GENERIC_ERROR + ' Please try again.');
            });
    }

    document.addEventListener('click', function (event) {
        const link = event.target.closest('.native-add-to-cart');
        if (!link) return;

        event.preventDefault();
        if (link.getAttribute('aria-disabled') === 'true') return; // a request is already in flight
        addToBasket(link);
    });
})();
