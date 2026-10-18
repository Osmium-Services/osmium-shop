ProductImages.init({
    productId: productEditConfig.productId,
    brandPath: productEditConfig.brandPath,
    modelPath: productEditConfig.modelPath,
    imagesBasePath: '/assets/images'
});

// Description image save
async function saveDescrImage(input) {
    const field = input.dataset.field;
    const productId = input.dataset.productId;
    const value = input.value.trim();
    const row = input.closest('[id$="-image-row"]') || input.closest('.d-flex');
    const img = row?.querySelector('img');

    const response = await fetch(adminBasePath + 'shop/products/images/action/', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_descr_image', product_id: productId, field: field, value: value, csrf_token: window.csrfToken })
    });
    const result = await response.json();

    if (!result.success) {
        adminAlert(result.error || 'Failed');
        return;
    }
    if (img && value) img.src = '/assets/images' + value;
}

// Update preview image from input value
function updatePreview(input) {
    const row = input.closest('[id$="-image-row"]') || input.closest('.d-flex');
    const img = row?.querySelector('img');
    if (img && input.value.trim()) {
        img.src = '/assets/images' + input.value.trim();
    }
}

// Event delegation for description images
document.addEventListener('change', e => {
    if (e.target.matches('.descr-image-quick-select') && e.target.value) {
        const input = e.target.closest('.input-group').querySelector('.descr-image-input');
        input.value = e.target.value;
        updatePreview(input);
        saveDescrImage(input);
        e.target.value = '';
    }
});

document.addEventListener('click', e => {
    if (e.target.closest('.descr-image-clear')) {
        const input = e.target.closest('.input-group').querySelector('.descr-image-input');
        input.value = '';
        saveDescrImage(input);
    }
});

document.addEventListener('input', e => {
    if (e.target.matches('.descr-image-input')) {
        updatePreview(e.target);
    }
});

document.addEventListener('keydown', e => {
    if (e.target.matches('.descr-image-input') && e.key === 'Enter') {
        e.preventDefault();
        saveDescrImage(e.target);
    }
});

// Enable/disable image row based on description text
['descr2', 'descr3'].forEach(id => {
    const textarea = document.getElementById(id);
    const row = document.getElementById(id + '-image-row');
    if (!textarea || !row) return;

    textarea.addEventListener('input', () => {
        const hasText = textarea.value.trim().length > 0;
        row.classList.toggle('opacity-50', !hasText);
        row.querySelectorAll('input, select, button').forEach(el => el.disabled = !hasText);
    });
});
