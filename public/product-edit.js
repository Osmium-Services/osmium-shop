// Generate barcode from barcode field
function updateBarcode() {
    const barcodeValue = document.getElementById('barcode').value.trim();
    const barcodeDisplay = document.getElementById('barcode-display');

    if (barcodeValue) {
        try {
            JsBarcode("#barcode-display", barcodeValue, {
                format: "CODE128",
                width: 1.5,
                height: 40,
                displayValue: true,
                fontSize: 12,
                margin: 0
            });
            barcodeDisplay.style.display = '';
        } catch(e) {
            console.error('Barcode error:', e);
            barcodeDisplay.style.display = 'none';
        }
    } else {
        barcodeDisplay.style.display = 'none';
    }
}

// Initial barcode render
updateBarcode();

// Update barcode when field changes
document.getElementById('barcode').addEventListener('input', updateBarcode);

// Update hidden brand_id when datalist selection changes
document.getElementById('brand_input').addEventListener('input', function() {
    const input = this.value;
    const datalist = document.getElementById('brand_list');
    const options = datalist.querySelectorAll('option');

    for (const option of options) {
        if (option.value === input) {
            document.getElementById('brand_id').value = option.dataset.id;
            break;
        }
    }
});

// Update hidden status_id and toggle restock date when datalist selection changes
document.getElementById('status_input').addEventListener('input', function() {
    const input = this.value;
    const datalist = document.getElementById('status_list');
    const options = datalist.querySelectorAll('option');
    const restockWrapper = document.getElementById('restock_date_wrapper');

    for (const option of options) {
        if (option.value === input) {
            document.getElementById('status_id').value = option.dataset.id;
            // Show/hide restock date based on status restocking flag
            if (option.dataset.restocking === '1') {
                restockWrapper.style.display = '';
            } else {
                restockWrapper.style.display = 'none';
            }
            break;
        }
    }
});

// Update hidden kit_id when datalist selection changes
document.getElementById('kit_product_input').addEventListener('input', function() {
    const input = this.value;
    const datalist = document.getElementById('kit_product_list');
    const options = datalist.querySelectorAll('option');

    for (const option of options) {
        if (option.value === input) {
            document.getElementById('kit_id').value = option.dataset.id;
            return;
        }
    }
    // If no match found in datalist, clear the kit_id (user typed something that doesn't match)
    if (input.trim() === '') {
        document.getElementById('kit_id').value = '';
    }
});

// Clear kit product selection
function clearKitProduct() {
    document.getElementById('kit_product_input').value = '';
    document.getElementById('kit_id').value = '';
}

// Press Escape to go back to products list
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        e.preventDefault();
        e.stopPropagation();
        window.location.href = adminBasePath + 'shop/products/';
    }
}, true);

// Bi-directional price calculation
const taxRate = productEditConfig.taxRate;
const taxMultiplier = 1 + (taxRate / 100);

// Price fields
const priceExcInput = document.getElementById('price_exc_tax');
const priceIncInput = document.getElementById('price_inc_tax');

priceExcInput.addEventListener('input', function() {
    const priceExc = parseFloat(this.value) || 0;
    priceIncInput.value = (priceExc * taxMultiplier).toFixed(2);
});

priceIncInput.addEventListener('input', function() {
    const priceInc = parseFloat(this.value) || 0;
    priceExcInput.value = (priceInc / taxMultiplier).toFixed(2);
});

// RRP fields
const rrpExcInput = document.getElementById('rrp_exc_tax');
const rrpIncInput = document.getElementById('rrp_inc_tax');

rrpExcInput.addEventListener('input', function() {
    const rrpExc = parseFloat(this.value) || 0;
    rrpIncInput.value = (rrpExc * taxMultiplier).toFixed(2);
});

rrpIncInput.addEventListener('input', function() {
    const rrpInc = parseFloat(this.value) || 0;
    rrpExcInput.value = (rrpInc / taxMultiplier).toFixed(2);
});

// Format all price fields on blur
[priceExcInput, priceIncInput, rrpExcInput, rrpIncInput].forEach(input => {
    input.addEventListener('blur', function() {
        const value = parseFloat(this.value) || 0;
        this.value = value.toFixed(2);
    });
});

// Show price history modal
async function showPriceHistory() {
    const productId = productEditConfig.productId;
    const modal = new bootstrap.Modal(document.getElementById('priceHistoryModal'));
    const tbody = document.getElementById('priceHistoryBody');

    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><span class="spinner-border spinner-border-sm"></span> Loading...</td></tr>';
    modal.show();

    try {
        const response = await fetch(adminBasePath + 'shop/products/price-history/action/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get_product_history', product_id: productId, csrf_token: window.csrfToken })
        });

        const result = await response.json();

        if (result.success && result.history.length > 0) {
            // Rows are built with DOM calls: the username and change source are free text and must stay text
            const money = value => '\u00a3' + parseFloat(value).toFixed(2);
            tbody.replaceChildren(...result.history.map(h => {
                const row = document.createElement('tr');
                const cells = [h.changed_at, money(h.price_exc_tax_old), money(h.price_exc_tax_new), h.change_source, h.username || 'Unknown'];
                cells.forEach(text => {
                    const cell = document.createElement('td');
                    cell.textContent = text;
                    row.append(cell);
                });

                const undoneCell = document.createElement('td');
                if (h.undone_at) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-secondary';
                    badge.textContent = 'Undone';
                    undoneCell.append(badge);
                }
                row.append(undoneCell);

                return row;
            }));
        } else if (result.success) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No price history for this product</td></tr>';
        } else {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Failed to load history</td></tr>';
        }
    } catch (error) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading history</td></tr>';
    }
}

// Product action (for delete)
async function productAction(id, action, confirmMsg) {
    if (confirmMsg && !(await adminConfirm(confirmMsg))) {
        return false;
    }

    fetch(adminBasePath + 'shop/products/action/', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ id: id, action: action, csrf_token: window.csrfToken })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = adminBasePath + 'shop/products/';
        } else {
            adminAlert('Action failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        adminAlert('Action failed');
    });

    return false;
}

// Delete completely (undo creation - removes product, page and images)
async function deleteCompletely(id) {
    if (!(await adminConfirm('This will permanently delete this product, its page, and all images. This cannot be undone. Continue?'))) {
        return false;
    }

    fetch(adminBasePath + 'shop/products/action/', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ id: id, action: 'delete_completely', csrf_token: window.csrfToken })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = adminBasePath + 'shop/products/';
        } else {
            adminAlert('Delete failed: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        adminAlert('Delete failed');
    });

    return false;
}

// Product Categories CRUD
const ProductCategories = {
    productId: productEditConfig.productId,

    async add(categoryId, categoryLabel) {
        try {
            const response = await fetch(adminBasePath + 'shop/products/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'add',
                    product_id: this.productId,
                    category_id: categoryId,
                    csrf_token: window.csrfToken
                })
            });
            const result = await response.json();
            if (result.success) {
                // Add badge to UI
                const container = document.getElementById('product-categories');
                const dropdown = container.querySelector('.dropdown');
                const badge = document.createElement('span');
                badge.className = 'badge bg-label-primary d-flex align-items-center';
                badge.dataset.categoryId = categoryId;
                // Built with DOM calls: the category label is free text and must stay text
                const removeIcon = document.createElement('i');
                removeIcon.className = 'bx bx-x ms-1';
                removeIcon.style.cursor = 'pointer';
                removeIcon.dataset.call = 'ProductCategories.remove';
                removeIcon.dataset.args = JSON.stringify([categoryId]);
                badge.replaceChildren(document.createTextNode(categoryLabel), removeIcon);
                container.insertBefore(badge, dropdown);

                // Update dropdown item
                const dropdownItem = container.querySelector(`.dropdown-item[data-category-id="${categoryId}"]`);
                if (dropdownItem) {
                    dropdownItem.classList.add('disabled', 'text-muted');
                    const checkIcon = document.createElement('i');
                    checkIcon.className = 'bx bx-check me-1';
                    dropdownItem.replaceChildren(checkIcon, document.createTextNode(categoryLabel));
                    dropdownItem.onclick = () => false;
                }
            } else {
                adminAlert('Failed to add category: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            adminAlert('Failed to add category');
        }
    },

    async remove(categoryId) {
        try {
            const response = await fetch(adminBasePath + 'shop/products/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'remove',
                    product_id: this.productId,
                    category_id: categoryId,
                    csrf_token: window.csrfToken
                })
            });
            const result = await response.json();
            if (result.success) {
                // Remove badge from UI
                const badge = document.querySelector(`#product-categories .badge[data-category-id="${categoryId}"]`);
                const label = badge ? badge.childNodes[0].textContent.trim() : '';
                if (badge) badge.remove();

                // Update dropdown item
                const dropdownItem = document.querySelector(`#product-categories .dropdown-item[data-category-id="${categoryId}"]`);
                if (dropdownItem) {
                    dropdownItem.classList.remove('disabled', 'text-muted');
                    dropdownItem.textContent = label;
                    dropdownItem.onclick = () => { ProductCategories.add(categoryId, label); return false; };
                }
            } else {
                adminAlert('Failed to remove category: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            adminAlert('Failed to remove category');
        }
    }
};
window.ProductCategories = ProductCategories; // inline-actions.js finds data-call objects on window
