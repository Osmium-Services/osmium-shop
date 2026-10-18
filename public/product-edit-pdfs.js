const ProductPDFs = {
    brandPath: productEditConfig.brandPath,
    modelPath: productEditConfig.modelPath,
    pdfLabels: {
        brochure: 'Brochure',
        datasheet: 'Datasheet',
        instructions: 'Instructions'
    },

    init() {
        // Setup drop zones
        document.querySelectorAll('.pdf-drop-zone').forEach(zone => {
            const pdfType = zone.dataset.pdfType;
            const fileInput = zone.querySelector('.pdf-file-input');

            // Click to upload
            zone.addEventListener('click', (e) => {
                if (e.target.closest('.btn-delete-pdf') || e.target.closest('a')) return;
                fileInput.click();
            });

            // File selected
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    this.upload(fileInput.files[0], pdfType, zone);
                    fileInput.value = '';
                }
            });

            // Drag and drop
            zone.addEventListener('dragover', (e) => {
                e.preventDefault();
                zone.style.borderColor = '#696cff';
                zone.style.backgroundColor = 'rgba(105, 108, 255, 0.04)';
            });

            zone.addEventListener('dragleave', () => {
                zone.style.borderColor = '';
                zone.style.backgroundColor = '';
            });

            zone.addEventListener('drop', (e) => {
                e.preventDefault();
                zone.style.borderColor = '';
                zone.style.backgroundColor = '';
                if (e.dataTransfer.files.length > 0) {
                    const file = e.dataTransfer.files[0];
                    if (file.type === 'application/pdf') {
                        this.upload(file, pdfType, zone);
                    } else {
                        adminAlert('Please upload a PDF file');
                    }
                }
            });
        });

        // Delete buttons
        document.querySelectorAll('.btn-delete-pdf').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const pdfType = btn.dataset.pdfType;
                if (await adminConfirm('Delete this PDF?')) {
                    this.delete(pdfType, btn.closest('.pdf-drop-zone'));
                }
            });
        });
    },

    async upload(file, pdfType, zone) {
        const existsDiv = zone.querySelector('.pdf-exists');
        const emptyDiv = zone.querySelector('.pdf-empty');
        const uploadingDiv = zone.querySelector('.pdf-uploading');

        // Show uploading state
        if (existsDiv) existsDiv.classList.add('d-none');
        if (emptyDiv) emptyDiv.classList.add('d-none');
        uploadingDiv.classList.remove('d-none');

        const formData = new FormData();
        formData.append('action', 'pdf_upload');
        formData.append('csrf_token', window.csrfToken);
        formData.append('pdf', file);
        formData.append('pdf_type', pdfType);
        formData.append('brand_path', this.brandPath);
        formData.append('model_path', this.modelPath);

        try {
            const response = await fetch(adminBasePath + 'shop/products/action/', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            uploadingDiv.classList.add('d-none');

            if (result.success) {
                // Update UI to show PDF exists
                zone.innerHTML = `
                    <div class="pdf-exists">
                        <i class="bx bxs-file-pdf text-danger" style="font-size: 2.5rem;"></i>
                        <p class="mb-2 fw-medium">${this.pdfLabels[pdfType]}</p>
                        <div class="btn-group btn-group-sm">
                            <a href="${result.pdfPath}" target="_blank" class="btn btn-outline-primary">
                                <i class="bx bx-show"></i> View
                            </a>
                            <button type="button" class="btn btn-outline-danger btn-delete-pdf" data-pdf-type="${pdfType}">
                                <i class="bx bx-trash"></i>
                            </button>
                        </div>
                    </div>
                    <div class="pdf-uploading d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Uploading...</span>
                        </div>
                        <p class="mb-0 mt-2">Uploading...</p>
                    </div>
                    <input type="file" class="pdf-file-input d-none" accept="application/pdf" data-pdf-type="${pdfType}">
                `;
                // Re-init this zone
                this.init();
            } else {
                // Show empty state again
                if (emptyDiv) emptyDiv.classList.remove('d-none');
                adminAlert(result.error || 'Upload failed');
            }
        } catch (error) {
            uploadingDiv.classList.add('d-none');
            if (emptyDiv) emptyDiv.classList.remove('d-none');
            adminAlert('Upload failed: ' + error.message);
        }
    },

    async delete(pdfType, zone) {
        try {
            const response = await fetch(adminBasePath + 'shop/products/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'pdf_delete',
                    csrf_token: window.csrfToken,
                    pdf_type: pdfType,
                    brand_path: this.brandPath,
                    model_path: this.modelPath
                })
            });
            const result = await response.json();

            if (result.success) {
                // Update UI to show empty state
                const icons = { brochure: 'bx-book-open', datasheet: 'bx-spreadsheet', instructions: 'bx-file' };
                zone.innerHTML = `
                    <div class="pdf-empty">
                        <i class="bx ${icons[pdfType]} text-muted" style="font-size: 2rem;"></i>
                        <p class="mb-1 fw-medium">${this.pdfLabels[pdfType]}</p>
                        <small class="text-muted">Drop PDF or click to upload</small>
                    </div>
                    <div class="pdf-uploading d-none">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Uploading...</span>
                        </div>
                        <p class="mb-0 mt-2">Uploading...</p>
                    </div>
                    <input type="file" class="pdf-file-input d-none" accept="application/pdf" data-pdf-type="${pdfType}">
                `;
                // Re-init this zone
                this.init();
            } else {
                adminAlert(result.error || 'Delete failed');
            }
        } catch (error) {
            adminAlert('Delete failed: ' + error.message);
        }
    }
};

document.addEventListener('DOMContentLoaded', () => ProductPDFs.init());

// Regenerate thumbnails from large image
async function regenerateImages() {
    const btn = document.getElementById('btn-regenerate-images');
    const originalHtml = btn.innerHTML;

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

    try {
        const response = await fetch(window.adminBasePath + 'shop/products/images/action/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'regenerate',
                product_id: productEditConfig.productId,
                brand_path: productEditConfig.brandPath,
                model_path: productEditConfig.modelPath,
                csrf_token: window.csrfToken
            })
        });

        const result = await response.json();

        if (result.success) {
            adminAlert('Generated ' + result.files.length + ' image files. Refresh to see updated images.');
            location.reload();
        } else {
            adminAlert('Failed: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error:', error);
        adminAlert('Failed to regenerate images');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    }
}
