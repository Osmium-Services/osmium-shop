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

// Toggle restock date when status selection changes
document.getElementById('status_id').addEventListener('change', function() {
    const restockWrapper = document.getElementById('restock_date_wrapper');
    restockWrapper.style.display = this.selectedOptions[0].dataset.restocking === '1' ? '' : 'none';
});

// Clear kit product selection
function clearKitProduct() {
    document.getElementById('kit_product_input').value = '';
    document.getElementById('kit_id').value = '';
}

// Product pickers (See also, Alternatives): a search box with its own result list instead of the browser's datalist,
// which behaves differently per browser and inside hidden tabs. Everything is delegated on document, so it works whatever
// order the tabs and scripts load in. The #kit_product_list options are only the source of the product names and IDs.
(function() {
    const MAX_RESULTS = 10;
    const normalise = text => text.replace(/\s+/g, ' ').trim().toLowerCase();
    let panel = null;
    let activeInput = null;
    let activeIndex = 0;

    const pickers = {
        kit_product_input: {
            exclude: () => [],
            pick(product) {
                document.getElementById('kit_id').value = product.id;
                document.getElementById('kit_product_input').value = product.label;
            },
        },
        alternatives_input: {
            exclude: () => Array.from(document.querySelectorAll('#alternatives_list [data-id]')).map(el => el.dataset.id),
            pick(product) {
                const input = document.getElementById('alternatives_input');
                input.value = '';
                addAlternative(product);
            },
        },
    };

    function products() {
        return Array.from(document.querySelectorAll('#kit_product_list option')).map(o => ({id: o.dataset.id, label: o.value}));
    }

    function addAlternative(product) {
        const list = document.getElementById('alternatives_list');
        if (!list || list.querySelector('[data-id="' + product.id + '"]')) return; // already listed

        const chip = document.createElement('span');
        chip.className = 'badge bg-label-primary d-inline-flex align-items-center';
        chip.dataset.id = product.id;
        chip.append(product.label);
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn-close ms-2';
        remove.setAttribute('data-alternative-remove', '');
        remove.setAttribute('aria-label', 'Remove');
        chip.append(remove);
        list.append(chip);
        syncAlternatives();
    }

    function syncAlternatives() {
        const hidden = document.getElementById('alternatives');
        if (!hidden) return;
        hidden.value = Array.from(document.querySelectorAll('#alternatives_list [data-id]')).map(el => el.dataset.id).join(',');
    }

    function matches(input) {
        const query = normalise(input.value);
        const excluded = pickers[input.id].exclude();
        const found = products().filter(p => !excluded.includes(p.id) && (query === '' || normalise(p.label).includes(query)));
        return found.slice(0, MAX_RESULTS);
    }

    function closePanel() {
        if (panel) panel.remove();
        panel = null;
        activeInput = null;
    }

    function openPanel(input) {
        const found = matches(input);
        if (!found.length) return closePanel();

        if (!panel) {
            panel = document.createElement('div');
            panel.className = 'list-group shadow product-picker-results';
            panel.style.cssText = 'position:fixed;z-index:2000;max-height:320px;overflow-y:auto;background-color:var(--bs-dropdown-bg, var(--bs-paper-bg, #fff));border-radius:6px;'; // Solid, so the page does not show through
            document.body.append(panel);
        }
        activeInput = input;
        activeIndex = 0;

        const box = input.getBoundingClientRect();
        panel.style.left = box.left + 'px';
        panel.style.top = (box.bottom + 2) + 'px';
        panel.style.width = box.width + 'px';

        panel.replaceChildren(...found.map((product, index) => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action' + (index === 0 ? ' active' : '');
            item.dataset.id = product.id;
            item.textContent = product.label;
            return item;
        }));
    }

    function highlight(index) {
        const items = panel ? Array.from(panel.children) : [];
        if (!items.length) return;
        activeIndex = (index + items.length) % items.length;
        items.forEach((item, i) => item.classList.toggle('active', i === activeIndex));
        items[activeIndex].scrollIntoView({block: 'nearest'});
    }

    function choose(input, id) {
        const product = products().find(p => p.id === id);
        if (!product) return;
        pickers[input.id].pick(product);
        closePanel();
    }

    const isPicker = el => el && el.id && Object.prototype.hasOwnProperty.call(pickers, el.id);

    document.addEventListener('input', e => {
        if (!isPicker(e.target)) return;
        if (e.target.id === 'kit_product_input' && e.target.value.trim() === '') document.getElementById('kit_id').value = ''; // Emptied by hand: no kit
        openPanel(e.target);
    });

    document.addEventListener('focusin', e => {
        if (isPicker(e.target)) openPanel(e.target);
    });

    document.addEventListener('keydown', e => {
        if (!isPicker(e.target)) return;
        if (e.key === 'Enter') {
            e.preventDefault(); // Enter must never save the whole product form
            const items = panel ? Array.from(panel.children) : [];
            if (items.length) choose(e.target, items[activeIndex].dataset.id);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (!panel) openPanel(e.target);
            highlight(activeIndex + (e.key === 'ArrowDown' ? 1 : -1));
        } else if (e.key === 'Escape' && panel) {
            e.stopImmediatePropagation(); // Escape elsewhere leaves the page, here it only closes the list
            closePanel();
        }
    }, true);

    document.addEventListener('mousedown', e => {
        const item = e.target.closest('.product-picker-results [data-id]');
        if (item && activeInput) {
            e.preventDefault(); // Keep focus in the search box
            choose(activeInput, item.dataset.id);
            return;
        }
        if (panel && !isPicker(e.target)) closePanel();
    });

    document.addEventListener('click', e => {
        const remove = e.target.closest('[data-alternative-remove]');
        if (!remove) return;
        remove.closest('[data-id]').remove();
        syncAlternatives();
    });

    window.addEventListener('resize', closePanel);
    window.addEventListener('scroll', closePanel, true);
})();

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
