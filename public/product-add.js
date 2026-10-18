document.addEventListener('DOMContentLoaded', function() {
    const modal = new bootstrap.Modal(document.getElementById('wizardModal'));
    const form = document.getElementById('wizard-form');
    let currentStep = 1;
    const totalSteps = 3;

    // Start wizard
    document.getElementById('start-wizard').addEventListener('click', () => {
        modal.show();
        document.getElementById('brand_input').focus();
    });

    // Navigation
    document.getElementById('wizard-next').addEventListener('click', nextStep);
    document.getElementById('wizard-back').addEventListener('click', prevStep);

    function nextStep() {
        if (!validateStep(currentStep)) return;

        if (currentStep < totalSteps) {
            currentStep++;
            updateWizard();
        }
    }

    function prevStep() {
        if (currentStep > 1) {
            currentStep--;
            updateWizard();
        }
    }

    function validateStep(step) {
        const pane = document.querySelector(`.wizard-pane[data-step="${step}"]`);
        const inputs = pane.querySelectorAll('input[required], select[required]');
        let valid = true;

        inputs.forEach(input => {
            if (!input.value.trim()) {
                input.classList.add('is-invalid');
                valid = false;
            } else {
                input.classList.remove('is-invalid');
            }
        });

        // Special validation for brand_id
        if (step === 1 && !document.getElementById('brand_id').value) {
            document.getElementById('brand_input').classList.add('is-invalid');
            valid = false;
        }

        return valid;
    }

    function updateWizard() {
        // Update panes
        document.querySelectorAll('.wizard-pane').forEach(pane => {
            pane.classList.remove('active');
            if (parseInt(pane.dataset.step) === currentStep) {
                pane.classList.add('active');
            }
        });

        // Update step indicators
        document.querySelectorAll('.wizard-step').forEach(step => {
            const stepNum = parseInt(step.dataset.step);
            step.classList.remove('active', 'completed');
            if (stepNum === currentStep) {
                step.classList.add('active');
            } else if (stepNum < currentStep) {
                step.classList.add('completed');
            }
        });

        // Update buttons
        document.getElementById('wizard-back').style.display = currentStep > 1 ? '' : 'none';
        document.getElementById('wizard-next').style.display = currentStep < totalSteps ? '' : 'none';
        document.getElementById('wizard-submit').style.display = currentStep === totalSteps ? '' : 'none';

        // Update summary on final step
        if (currentStep === totalSteps) {
            updateSummary();
        }
    }

    function updateSummary() {
        const brand = document.getElementById('brand_input').value;
        const model = document.getElementById('model_name').value;
        const category = document.getElementById('category_id');
        const price = document.getElementById('price_inc_tax').value;

        document.getElementById('summary-title').textContent = brand + ' ' + model;
        document.getElementById('summary-category').textContent = category.options[category.selectedIndex]?.text || '-';
        document.getElementById('summary-price').textContent = price ? '£' + price + ' inc VAT' : '-';
    }

    // Brand selection
    document.getElementById('brand_input').addEventListener('input', function() {
        const input = this.value;
        const datalist = document.getElementById('brand_list');
        const options = datalist.querySelectorAll('option');

        let found = false;
        for (const option of options) {
            if (option.value === input) {
                document.getElementById('brand_id').value = option.dataset.id;
                found = true;
                break;
            }
        }

        if (!found) {
            document.getElementById('brand_id').value = '';
        }

        updateUrlPreview();
    });

    // Model name change
    document.getElementById('model_name').addEventListener('input', updateUrlPreview);

    function updateUrlPreview() {
        const brand = document.getElementById('brand_input').value.trim();
        const model = document.getElementById('model_name').value.trim();

        if (brand || model) {
            const base = brand + '-' + model;
            const slug = base
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-|-$/g, '');
            document.getElementById('url-preview').textContent = '/' + slug + '/';
        } else {
            document.getElementById('url-preview').textContent = '/model-name/';
        }
    }

    // Status selection
    document.getElementById('status_input').addEventListener('input', function() {
        const input = this.value;
        const datalist = document.getElementById('status_list');
        const options = datalist.querySelectorAll('option');

        for (const option of options) {
            if (option.value === input) {
                document.getElementById('status_id').value = option.dataset.id;
                break;
            }
        }
    });

    // Parent page selection
    document.getElementById('parent_page_search').addEventListener('input', function() {
        const input = this.value;
        const datalist = document.getElementById('parent_pages_list');
        const options = datalist.querySelectorAll('option');
        let foundId = '';

        for (const option of options) {
            if (option.value === input) {
                foundId = option.dataset.id;
                break;
            }
        }

        document.getElementById('parent_page_id').value = foundId;
    });

    // Bidirectional price calculation
    const taxRate = productAddConfig.taxRate;
    const taxMultiplier = 1 + (taxRate / 100);
    let isCalculating = false;

    document.getElementById('price_exc_tax').addEventListener('input', function() {
        if (isCalculating) return;
        isCalculating = true;
        const priceExc = parseFloat(this.value) || 0;
        document.getElementById('price_inc_tax').value = priceExc ? (priceExc * taxMultiplier).toFixed(2) : '';
        isCalculating = false;
        updateSummary();
    });

    document.getElementById('price_inc_tax').addEventListener('input', function() {
        if (isCalculating) return;
        isCalculating = true;
        const priceInc = parseFloat(this.value) || 0;
        document.getElementById('price_exc_tax').value = priceInc ? (priceInc / taxMultiplier).toFixed(2) : '';
        isCalculating = false;
        updateSummary();
    });

    // Format prices to 2 decimal places on blur
    document.getElementById('price_exc_tax').addEventListener('blur', function() {
        if (this.value) this.value = parseFloat(this.value).toFixed(2);
    });
    document.getElementById('price_inc_tax').addEventListener('blur', function() {
        if (this.value) this.value = parseFloat(this.value).toFixed(2);
    });

    // Enter key navigation
    document.querySelectorAll('#wizard-form input').forEach(input => {
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (currentStep < totalSteps) {
                    nextStep();
                } else {
                    form.submit();
                }
            }
        });
    });

    // Escape to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            window.location.href = adminBasePath + 'shop/products/';
        }
    });
});
