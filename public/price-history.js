let selectedBatchTimestamp = null;
let undoModal = null;

document.addEventListener('DOMContentLoaded', function() {
    undoModal = new bootstrap.Modal(document.getElementById('undoModal'));

    document.getElementById('confirmUndo').addEventListener('click', async function() {
        if (!selectedBatchTimestamp) return;

        this.disabled = true;
        this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Undoing...';

        try {
            const response = await fetch(adminBasePath + 'shop/products/price-history/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'undo_batch',
                    timestamp: selectedBatchTimestamp
                })
            });

            const result = await response.json();

            if (result.success) {
                undoModal.hide();
                window.location.reload();
            } else {
                let message = result.error || 'Failed to undo import';
                if (result.products && result.products.length > 0) {
                    message += '\n\n' + result.products.join('\n');
                }
                adminAlert(message);
                this.disabled = false;
                this.innerHTML = '<i class="bx bx-undo me-1"></i> Undo Import';
            }
        } catch (error) {
            adminAlert('An error occurred');
            this.disabled = false;
            this.innerHTML = '<i class="bx bx-undo me-1"></i> Undo Import';
        }
    });
});

function toggleBatch(index) {
    const row = document.getElementById('batch' + index);
    const chevron = document.getElementById('chevron' + index);

    if (row.style.display === 'none') {
        row.style.display = 'table-row';
        chevron.classList.replace('bx-chevron-right', 'bx-chevron-down');
    } else {
        row.style.display = 'none';
        chevron.classList.replace('bx-chevron-down', 'bx-chevron-right');
    }
}

function undoBatch(timestamp) {
    selectedBatchTimestamp = timestamp;
    undoModal.show();
}

async function undoManual(id) {
    if (!(await adminConfirm('Undo this manual price change?'))) return;

    try {
        const response = await fetch(adminBasePath + 'shop/products/price-history/action/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'undo_manual',
                id: id
            })
        });

        const result = await response.json();

        if (result.success) {
            window.location.reload();
        } else {
            adminAlert(result.error || 'Failed to undo change');
        }
    } catch (error) {
        adminAlert('An error occurred');
    }
}
