(function() {
    let copyTechnicalModal = null;
    const sourceInput = document.getElementById('copyTechnicalSourceProduct');

    // Event listeners for copy buttons
    document.querySelectorAll('.btn-copy-technical').forEach(btn => {
        btn.addEventListener('click', function() {
            const field = this.dataset.field;
            const fieldName = field === 'techspec' ? 'Technical Specifications' : 'FAQ';

            // Switch datalist based on field
            const datalistId = field === 'techspec' ? 'productListTechspec' : 'productListFaq';
            sourceInput.setAttribute('list', datalistId);

            document.getElementById('copyTechnicalFieldName').textContent = fieldName;
            document.getElementById('copyTechnicalField').value = field;
            sourceInput.value = '';
            document.getElementById('copyTechnicalSourceProductId').value = '';

            copyTechnicalModal = new bootstrap.Modal(document.getElementById('copyTechnicalModal'));
            copyTechnicalModal.show();
        });
    });

    // Handle product selection from datalist
    sourceInput.addEventListener('input', function() {
        const field = document.getElementById('copyTechnicalField').value;
        const datalistId = field === 'techspec' ? 'productListTechspec' : 'productListFaq';
        const datalist = document.getElementById(datalistId);
        const options = datalist.querySelectorAll('option');
        let foundId = '';
        for (const option of options) {
            if (option.value === this.value) {
                foundId = option.dataset.id;
                break;
            }
        }
        document.getElementById('copyTechnicalSourceProductId').value = foundId;
    });

    // Copy confirmation
    document.getElementById('copyTechnicalConfirm').addEventListener('click', async function() {
        const sourceProductId = document.getElementById('copyTechnicalSourceProductId').value;
        const field = document.getElementById('copyTechnicalField').value;

        if (!sourceProductId) {
            adminAlert('Please select a source product');
            return;
        }

        if (!(await adminConfirm('This will replace the current content. Continue?'))) return;

        try {
            const response = await fetch(adminBasePath + 'shop/products/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_technical',
                    source_product_id: sourceProductId,
                    csrf_token: window.csrfToken
                })
            });
            const result = await response.json();

            if (result.success) {
                // Update Summernote editor with new content
                const content = result[field] || '';
                $('#' + field).summernote('code', content);
                copyTechnicalModal.hide();
                adminAlert('Content copied successfully!');
            } else {
                adminAlert(result.error || 'Failed to copy content');
            }
        } catch (error) {
            console.error('Copy technical content failed:', error);
            adminAlert('Failed to copy content');
        }
    });
})();
