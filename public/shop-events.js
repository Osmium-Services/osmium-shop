/**
 * Shop events - tells analytics/marketing services what the visitor looked at or bought.
 *
 * Markup contract (see THEME-CONTRACT.md):
 *   <script type="application/json" data-osmium-shop-event="view_item">{ ...json... }</script>
 *
 * Supported types: view_item (product page, from $product['shopEvent']) and purchase
 * (thank-you page, from $purchase). Each tag is turned into one event, once.
 *
 * Services listen for osmium:shop-event on document (detail: { type, ...the tag's JSON }).
 * A service that starts listening after this script has run (for example one deferred
 * until the browser is idle) replays window.osmiumShopEvents, the list of every event
 * fired so far.
 *
 * The add-to-cart event is not here: add-to-basket.js already fires osmium:basket-added.
 */
(function () {
    'use strict';

    window.osmiumShopEvents = window.osmiumShopEvents || [];

    function fire(type, data) {
        const detail = Object.assign({ type: type }, data);
        window.osmiumShopEvents.push(detail);
        document.dispatchEvent(new CustomEvent('osmium:shop-event', { detail: detail }));
    }

    function fireTagged() {
        document.querySelectorAll('script[data-osmium-shop-event]').forEach(function (tag) {
            if (tag.hasAttribute('data-osmium-fired')) return;
            tag.setAttribute('data-osmium-fired', '');

            try {
                fire(tag.getAttribute('data-osmium-shop-event'), JSON.parse(tag.textContent));
            } catch (e) {
                // Bad JSON from a theme must never break the page
            }
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fireTagged);
    else fireTagged();
})();
