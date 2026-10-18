// Initialize parent page search field with current value
(function() {
    const parentPageId = productEditConfig.parentPageId;
    const parentSearchField = document.getElementById('parentPageSearch');

    if (parentSearchField && parentPageId) {
        const options = document.querySelectorAll('#parentPagesList option');
        for (const opt of options) {
            if (opt.dataset.id == parentPageId) {
                parentSearchField.value = opt.value;
                break;
            }
        }
    }

    // Update hidden field when user selects from datalist
    if (parentSearchField) {
        parentSearchField.addEventListener('input', function() {
            const val = this.value;
            const options = document.querySelectorAll('#parentPagesList option');
            let foundId = '';

            for (const opt of options) {
                if (opt.value === val) {
                    foundId = opt.dataset.id;
                    break;
                }
            }

            document.getElementById('parentPageId').value = foundId;
        });
    }
})();
