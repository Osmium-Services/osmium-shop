// Products list: Duplicate (menu item rendered by ListView, fires list:action).
// Everything else on the list (search/sort, collapse state, presence, delete/
// restore/discontinue) is handled by list-view.js.

async function duplicateProduct(id) {
    try {
        const response = await fetch(window.adminBasePath + 'shop/products/action/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id, action: 'duplicate', csrf_token: window.csrfToken })
        });
        const data = await response.json();

        if (data.success && data.newProductId) {
            window.location.href = window.adminBasePath + 'shop/products/edit/?id=' + data.newProductId;
        } else {
            adminAlert('Action failed: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        adminAlert('Action failed');
    }
}

document.addEventListener('list:action', function(event) {
    const { action, id } = event.detail;
    if (action === 'duplicate') duplicateProduct(id);
});
