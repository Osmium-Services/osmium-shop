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
    const productSelect = document.getElementById('product_ids');
    const chipsContainer = document.getElementById('selected-products-chips');
    const codeInput = document.getElementById('code');

    if (typeSelect && productScopeRow && couponScopeRow) {
        typeSelect.addEventListener('change', function() {
            const isProductType = typeSelect.value === 'product';
            const isCouponType = typeSelect.value === 'coupon';
            productScopeRow.style.display = isProductType ? '' : 'none';
            couponScopeRow.style.display = isCouponType ? '' : 'none';
        });
    }

    const valueTypeSelect = document.getElementById('value_type');
    const valueField = document.getElementById('value-field');
    const valueInput = document.getElementById('value');

    // Free delivery takes nothing off the goods, so it has no amount to enter, and it only works as a coupon
    function syncValueType() {
        if (!valueTypeSelect || !valueField || !valueInput) return;

        const isFreeDelivery = valueTypeSelect.value === 'free_delivery';
        valueField.style.display = isFreeDelivery ? 'none' : '';
        valueInput.required = !isFreeDelivery;
    }

    if (valueTypeSelect) {
        valueTypeSelect.addEventListener('change', syncValueType);
        syncValueType();
    }

    if (codeInput) {
        codeInput.addEventListener('input', function() {
            codeInput.value = codeInput.value.toUpperCase();
        });
    }

    if (!productSelect || !chipsContainer) return;

    function renderChips() {
        const selectedOptions = [...productSelect.selectedOptions];

        chipsContainer.innerHTML = selectedOptions.length
            ? ''
            : '<span class="text-muted">No products selected yet</span>';

        selectedOptions.forEach(option => {
            const chip = document.createElement('span');
            chip.className = 'badge bg-label-primary d-inline-flex align-items-center gap-1';
            chip.style.fontSize = '0.9rem';
            chip.textContent = option.text;

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'btn-close btn-close-sm';
            removeButton.style.fontSize = '0.6rem';
            removeButton.setAttribute('aria-label', `Remove ${option.text}`);
            removeButton.addEventListener('click', function() {
                option.selected = false;
                renderChips();
            });

            chip.appendChild(removeButton);
            chipsContainer.appendChild(chip);
        });
    }

    productSelect.addEventListener('change', renderChips);
    renderChips();
});
