/**
 * Discount edit form JavaScript - toggles the product picker for per-product
 * discounts and the code/usage-limit fields for coupon discounts, and mirrors
 * the current product selection as removable chips, since the selected
 * options are otherwise easy to miss inside a long scrolling list.
 */

$(document).ready(function() {
    const typeSelect = document.getElementById('type');
    const productScopeRow = document.getElementById('product-scope-row');
    const couponScopeRow = document.getElementById('coupon-scope-row');
    const minSpendRow = document.getElementById('min-spend-row');
    const productSelect = document.getElementById('product_ids');
    const chipsContainer = document.getElementById('selected-products-chips');
    const codeInput = document.getElementById('code');

    if (typeSelect && productScopeRow && couponScopeRow) {
        typeSelect.addEventListener('change', function() {
            const isProductType = typeSelect.value === 'product';
            const isCouponType = typeSelect.value === 'coupon';
            productScopeRow.style.display = isProductType ? '' : 'none';
            couponScopeRow.style.display = isCouponType ? '' : 'none';
            if (minSpendRow) minSpendRow.style.display = isCouponType || typeSelect.value === 'sitewide' ? '' : 'none';
        });
    }

    if (codeInput) {
        codeInput.addEventListener('input', function() {
            codeInput.value = codeInput.value.toUpperCase();
        });
    }

    // Selected options in a long multi-select are easy to miss, so each list gets removable chips above it
    function mirrorAsChips(select, container, emptyText) {
        if (!select || !container) return;

        function renderChips() {
            const selectedOptions = [...select.selectedOptions];

            container.innerHTML = selectedOptions.length
                ? ''
                : `<span class="text-muted">${emptyText}</span>`;

            selectedOptions.forEach(option => {
                const chip = document.createElement('span');
                chip.className = 'badge bg-label-primary d-inline-flex align-items-center gap-1';
                chip.style.fontSize = '0.9rem';
                chip.textContent = option.text.trim();

                const removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.className = 'btn-close btn-close-sm';
                removeButton.style.fontSize = '0.6rem';
                removeButton.setAttribute('aria-label', `Remove ${option.text.trim()}`);
                removeButton.addEventListener('click', function() {
                    option.selected = false;
                    renderChips();
                });

                chip.appendChild(removeButton);
                container.appendChild(chip);
            });
        }

        select.addEventListener('change', renderChips);
        renderChips();
    }

    mirrorAsChips(productSelect, chipsContainer, 'No products selected yet');
    mirrorAsChips(document.getElementById('category_ids'), document.getElementById('selected-categories-chips'), 'No categories selected');
    mirrorAsChips(document.getElementById('brand_ids'), document.getElementById('selected-brands-chips'), 'No brands selected');
});
