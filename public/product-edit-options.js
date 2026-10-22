const ProductOptions = {
    productId: productEditConfig.productId,
    container: null,
    noOptionsMsg: null,
    typeModal: null,
    valueModal: null,
    productThumbs: {},
    optionTypes: [],

    init() {
        this.container = document.getElementById('option-types-container');
        this.noOptionsMsg = document.getElementById('no-options-message');
        this.typeModal = new bootstrap.Modal(document.getElementById('optionTypeModal'));
        this.valueModal = new bootstrap.Modal(document.getElementById('optionValueModal'));

        document.querySelectorAll('#productList option').forEach(option => {
            this.productThumbs[option.dataset.id] = option.dataset.thumb || '';
        });

        document.getElementById('btn-add-option-type').addEventListener('click', () => this.showTypeModal());
        document.getElementById('optionTypeSave').addEventListener('click', () => this.saveType());
        document.getElementById('optionValueSave').addEventListener('click', () => this.saveValue());
        document.getElementById('optionTypeDependsOnType').addEventListener('change', (e) => {
            this.renderDependsOnValueCheckboxes(e.target.value, []);
        });

        // Copy from product handlers
        document.getElementById('btn-copy-options').addEventListener('click', () => this.showCopyModal());
        document.getElementById('copyOptionsConfirm').addEventListener('click', () => this.copyFromProduct());
        document.getElementById('copySourceProduct').addEventListener('input', function() {
            const datalist = document.getElementById('productListCopy');
            const options = datalist.querySelectorAll('option');
            let foundId = '';
            for (const option of options) {
                if (option.value === this.value) {
                    foundId = option.dataset.id;
                    break;
                }
            }
            document.getElementById('copySourceProductId').value = foundId;
        });

        // Handle linked product selection from datalist
        document.getElementById('optionTypeLinkedProduct').addEventListener('input', function() {
            if (!this.value.trim()) {
                document.getElementById('optionTypeLinkedProductId').value = '';
                return;
            }
            const datalist = document.getElementById('productList');
            const options = datalist.querySelectorAll('option');
            let foundId = '';
            for (const option of options) {
                if (option.value === this.value) {
                    foundId = option.dataset.id;
                    break;
                }
            }
            document.getElementById('optionTypeLinkedProductId').value = foundId;
        });

        // Handle default-option product selection from datalist
        document.getElementById('optionTypeDefaultLinkedProduct').addEventListener('input', function() {
            if (!this.value.trim()) {
                document.getElementById('optionTypeDefaultLinkedProductId').value = '';
                return;
            }
            const datalist = document.getElementById('productList');
            const options = datalist.querySelectorAll('option');
            let foundId = '';
            for (const option of options) {
                if (option.value === this.value) {
                    foundId = option.dataset.id;
                    break;
                }
            }
            document.getElementById('optionTypeDefaultLinkedProductId').value = foundId;
        });

        // Handle linked product selection from datalist (option value level)
        document.getElementById('optionValueLinkedProduct').addEventListener('input', function() {
            if (!this.value.trim()) {
                document.getElementById('optionValueLinkedProductId').value = '';
                return;
            }
            const datalist = document.getElementById('productList');
            const options = datalist.querySelectorAll('option');
            let foundId = '';
            for (const option of options) {
                if (option.value === this.value) {
                    foundId = option.dataset.id;
                    break;
                }
            }
            document.getElementById('optionValueLinkedProductId').value = foundId;
        });

        // Event delegation for option actions
        this.container.addEventListener('click', (e) => {
            const target = e.target.closest('.btn-edit-type, .btn-delete-type, .btn-add-value, .btn-edit-value, .btn-delete-value');
            if (!target) return;

            e.preventDefault();

            if (target.classList.contains('btn-edit-type')) {
                this.showTypeModal(target.dataset.typeId);
            } else if (target.classList.contains('btn-delete-type')) {
                this.deleteType(target.dataset.typeId);
            } else if (target.classList.contains('btn-add-value')) {
                this.showValueModal(null, target.dataset.typeId);
            } else if (target.classList.contains('btn-edit-value')) {
                this.showValueModal(target.dataset.valueId, target.dataset.typeId);
            } else if (target.classList.contains('btn-delete-value')) {
                this.deleteValue(target.dataset.valueId);
            }
        });

        this.loadOptions();
    },

    async loadOptions() {
        try {
            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'get_options', product_id: this.productId })
            });
            const result = await response.json();

            if (result.success) {
                this.renderOptions(result.optionTypes);
            } else {
                console.error('Options API error:', result.error);
                this.noOptionsMsg.innerHTML = '<div class="text-danger">Error: ' + this.escapeHtml(result.error || 'Unknown error') + '</div>';
                this.noOptionsMsg.classList.remove('d-none');
            }
        } catch (error) {
            console.error('Failed to load options:', error);
            this.noOptionsMsg.innerHTML = '<div class="text-danger">Failed to load options: ' + this.escapeHtml(error.message) + '</div>';
            this.noOptionsMsg.classList.remove('d-none');
        }
    },

    renderOptions(optionTypes) {
        this.optionTypes = optionTypes || [];

        if (!optionTypes || optionTypes.length === 0) {
            this.noOptionsMsg.classList.remove('d-none');
            return;
        }

        this.noOptionsMsg.classList.add('d-none');

        // Clear existing content except the no-options message
        const existingTypes = this.container.querySelectorAll('.option-type-card');
        existingTypes.forEach(el => el.remove());

        optionTypes.forEach(type => {
            const typeHtml = this.renderTypeCard(type);
            this.container.insertAdjacentHTML('beforeend', typeHtml);
        });

        this.initSortable();
    },

    initSortable() {
        const self = this;

        // Sortable for option types
        new Sortable(this.container, {
            handle: '.drag-handle-type',
            animation: 150,
            ghostClass: 'bg-body-tertiary',
            draggable: '.option-type-card',
            onEnd: function() {
                const cards = self.container.querySelectorAll('.option-type-card');
                const items = [];
                cards.forEach((card, index) => {
                    items.push({ id: card.dataset.typeId, sort: index });
                });
                self.saveOrder('reorder_types', items);
            }
        });

        // Sortable for values within each type
        this.container.querySelectorAll('.sortable-values').forEach(tbody => {
            new Sortable(tbody, {
                handle: '.drag-handle-value',
                animation: 150,
                ghostClass: 'table-primary',
                onEnd: function() {
                    const rows = tbody.querySelectorAll('tr[data-value-id]');
                    const items = [];
                    rows.forEach((row, index) => {
                        items.push({ id: row.dataset.valueId, sort: index });
                    });
                    self.saveOrder('reorder_values', items);
                }
            });
        });
    },

    async saveOrder(action, items) {
        try {
            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: action, items: items, csrf_token: window.csrfToken })
            });
            const result = await response.json();
            if (!result.success) {
                console.error('Failed to save order:', result.error);
            }
        } catch (error) {
            console.error('Failed to save order:', error);
        }
    },

    renderTypeCard(type) {
        const valuesHtml = type.values && type.values.length > 0
            ? type.values.map(v => this.renderValueRow(v, type.id)).join('')
            : '<tr><td colspan="7" class="text-center text-muted py-3">No values defined</td></tr>';

        return `
            <div class="option-type-card border rounded mb-3" data-type-id="${type.id}" data-default="${this.escapeHtml(type.default_option_descr || '')}" data-linked-id="${type.linked_product_id || ''}" data-default-linked-id="${type.default_linked_product_id || ''}" data-linked-descr="${this.escapeHtml(type.linked_product_descr || '')}" data-linked-image-slot="${this.escapeHtml(type.linked_image_slot || '')}">
                <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
                    <div class="d-flex align-items-center">
                        <span class="drag-handle-type me-2" style="cursor: grab;" title="Drag to reorder"><i class="bx bx-menu text-muted"></i></span>
                        <strong>${this.escapeHtml(type.type_label)}</strong>
                        ${type.default_option_descr ? `<span class="text-muted ms-2">(Default: ${this.escapeHtml(type.default_option_descr)})</span>` : ''}
                        ${type.linked_image_slot ? `<i class="bx bxs-image-alt text-info ms-2" title="Default option swaps main image to: ${this.escapeHtml(type.linked_image_slot)}"></i>` : ''}
                        ${type.depends_on_value_ids && type.depends_on_value_ids.length > 0 ? `<i class="bx bx-hide text-warning ms-2" title="Hidden until: ${this.escapeHtml(this.describeDependency(type.depends_on_value_ids))}"></i>` : ''}
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary btn-add-value" data-type-id="${type.id}">
                            <i class="bx bx-plus"></i> Add Value
                        </button>
                        <div class="dropdown">
                            <button type="button" class="btn btn-sm btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                                <i class="bx bx-slider"></i>
                            </button>
                            <div class="dropdown-menu dropdown-menu-end">
                                <a class="dropdown-item btn-edit-type" href="#" data-type-id="${type.id}">
                                    <i class="bx bx-edit-alt me-1"></i> Edit
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item text-danger btn-delete-type" href="#" data-type-id="${type.id}">
                                    <i class="bx bx-trash me-1"></i> Delete
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="option-values-container" data-type-id="${type.id}">
                    <table class="table table-sm mb-0">
                        <thead style="background-color: var(--bs-tertiary-bg);">
                            <tr style="white-space: nowrap;">
                                <th></th>
                                <th style="width: 100%;">Description</th>
                                <th>Premium +/-</th>
                                <th>RRP ex VAT</th>
                                <th>Weight +/-</th>
                                <th>Reference</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="sortable-values" data-type-id="${type.id}">
                            ${valuesHtml}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    },

    renderValueRow(value, typeId) {
        const hasLinkedProduct = !!value.linked_product_id;
        const linkedThumb = hasLinkedProduct ? (this.productThumbs[value.linked_product_id] || '') : '';
        const missingClass = hasLinkedProduct && !linkedThumb ? ' linked-thumb-missing' : '';
        const imgHtml = linkedThumb
            ? `<img src="${this.escapeHtml(linkedThumb)}" alt="" class="rounded" loading="lazy" style="width: 24px; height: 24px; object-fit: contain; vertical-align: middle; background: #fff;" data-img-fallback="linked-thumb" title="Linked to a product">
                <img src="${this.escapeHtml(linkedThumb)}" alt="" class="linked-thumb-preview" loading="lazy" data-img-fallback="linked-thumb">`
            : '';
        const thumbHtml = hasLinkedProduct
            ? `<span class="linked-thumb-wrap me-2${missingClass}">
                ${imgHtml}
                <span class="linked-thumb-placeholder-small rounded" title="Linked product has no image"><i class="bx bxs-camera"></i></span>
                <span class="linked-thumb-placeholder-preview rounded"><i class="bx bxs-camera"></i></span>
               </span>`
            : '';
        const hasLinkedImage = !!value.linked_image_slot;
        const linkedImageIcon = hasLinkedImage
            ? `<i class="bx bxs-image-alt text-info me-1" title="Swaps main image to: ${this.escapeHtml(value.linked_image_slot)}"></i>`
            : '';
        return `
            <tr data-value-id="${value.id}" data-linked-id="${value.linked_product_id || ''}" data-linked-image-slot="${this.escapeHtml(value.linked_image_slot || '')}">
                <td class="drag-handle-value" style="cursor: grab;" title="Drag to reorder"><i class="bx bx-menu text-muted"></i></td>
                <td>${thumbHtml}${linkedImageIcon}${this.escapeHtml(value.option_descr)}</td>
                <td>&pound;${parseFloat(value.option_premium).toFixed(2)}</td>
                <td>${value.option_rrp > 0 ? '&pound;' + parseFloat(value.option_rrp).toFixed(2) : '-'}</td>
                <td>${value.option_packed_weight > 0 ? this.escapeHtml(value.option_packed_weight) + ' kg' : '-'}</td>
                <td style="white-space: nowrap;">${this.escapeHtml(value.option_reference || '-')}</td>
                <td class="text-end">
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                            <i class="bx bx-slider"></i>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end">
                            <a class="dropdown-item btn-edit-value" href="#" data-value-id="${value.id}" data-type-id="${typeId}">
                                <i class="bx bx-edit-alt me-1"></i> Edit
                            </a>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-danger btn-delete-value" href="#" data-value-id="${value.id}">
                                <i class="bx bx-trash me-1"></i> Delete
                            </a>
                        </div>
                    </div>
                </td>
            </tr>
        `;
    },

    showTypeModal(typeId = null) {
        document.getElementById('optionTypeId').value = typeId || '';
        document.getElementById('optionTypeModalTitle').textContent = typeId ? 'Edit Option Type' : 'Add Option Type';

        this.populateDependsOnTypeOptions(typeId);

        if (typeId) {
            const card = this.container.querySelector(`.option-type-card[data-type-id="${typeId}"]`);
            document.getElementById('optionTypeLabel').value = card.querySelector('strong').textContent;
            document.getElementById('optionTypeDefault').value = card.dataset.default || '';
            document.getElementById('optionTypeLinkedProductId').value = card.dataset.linkedId || '';
            document.getElementById('optionTypeDefaultLinkedProductId').value = card.dataset.defaultLinkedId || '';
            const defaultLinkedId = card.dataset.defaultLinkedId;
            const defaultLinkedOption = defaultLinkedId ? document.querySelector(`#productList option[data-id="${defaultLinkedId}"]`) : null;
            document.getElementById('optionTypeDefaultLinkedProduct').value = defaultLinkedOption ? defaultLinkedOption.value : '';
            document.getElementById('optionTypeLinkedDescr').value = card.dataset.linkedDescr || '';
            // Find product name from datalist if we have an ID
            const linkedId = card.dataset.linkedId;
            if (linkedId) {
                const option = document.querySelector(`#productList option[data-id="${linkedId}"]`);
                document.getElementById('optionTypeLinkedProduct').value = option ? option.value : '';
            } else {
                document.getElementById('optionTypeLinkedProduct').value = '';
            }
            document.getElementById('optionTypeLinkedImageSlot').value = card.dataset.linkedImageSlot || '';
            this.prefillDependsOn(typeId);
        } else {
            document.getElementById('optionTypeLabel').value = '';
            document.getElementById('optionTypeDefault').value = '';
            document.getElementById('optionTypeDefaultLinkedProduct').value = '';
            document.getElementById('optionTypeDefaultLinkedProductId').value = '';
            document.getElementById('optionTypeLinkedProduct').value = '';
            document.getElementById('optionTypeLinkedProductId').value = '';
            document.getElementById('optionTypeLinkedDescr').value = '';
            document.getElementById('optionTypeLinkedImageSlot').value = '';
            document.getElementById('optionTypeDependsOnType').value = '';
            document.getElementById('optionTypeDependsOnValues').innerHTML = '';
        }

        this.typeModal.show();
    },

    // Fills the "only show when..." dropdown with every OTHER option type on this product
    populateDependsOnTypeOptions(excludeTypeId) {
        const select = document.getElementById('optionTypeDependsOnType');
        select.innerHTML = '<option value="">Always show</option>';

        this.optionTypes
            .filter(t => String(t.id) !== String(excludeTypeId))
            .forEach(t => {
                select.insertAdjacentHTML('beforeend', `<option value="${t.id}">${this.escapeHtml(t.type_label)}</option>`);
            });
    },

    // Renders the checkbox list of a source type's values, checking any ids already in checkedValueIds
    renderDependsOnValueCheckboxes(sourceTypeId, checkedValueIds) {
        const container = document.getElementById('optionTypeDependsOnValues');

        if (!sourceTypeId) {
            container.innerHTML = '';
            return;
        }

        const sourceType = this.optionTypes.find(t => String(t.id) === String(sourceTypeId));
        if (!sourceType || !sourceType.values || sourceType.values.length === 0) {
            container.innerHTML = '<div class="form-text">This option has no values yet</div>';
            return;
        }

        const checkedIds = checkedValueIds.map(String);
        container.innerHTML = sourceType.values.map(v => `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" value="${v.id}" id="dependsOnValue${v.id}" ${checkedIds.includes(String(v.id)) ? 'checked' : ''}>
                <label class="form-check-label" for="dependsOnValue${v.id}">${this.escapeHtml(v.option_descr)}</label>
            </div>
        `).join('');
    },

    // When editing a type that already has dependencies, find which other type owns those
    // values and pre-select it, then check the matching value boxes
    prefillDependsOn(typeId) {
        const type = this.optionTypes.find(t => String(t.id) === String(typeId));
        const dependsOnValueIds = (type && type.depends_on_value_ids) || [];

        document.getElementById('optionTypeDependsOnType').value = '';
        document.getElementById('optionTypeDependsOnValues').innerHTML = '';

        if (dependsOnValueIds.length === 0) return;

        const sourceType = this.optionTypes.find(t =>
            (t.values || []).some(v => dependsOnValueIds.includes(v.id))
        );
        if (!sourceType) return;

        document.getElementById('optionTypeDependsOnType').value = sourceType.id;
        this.renderDependsOnValueCheckboxes(sourceType.id, dependsOnValueIds);
    },

    async saveType() {
        const typeId = document.getElementById('optionTypeId').value;
        const typeLabel = document.getElementById('optionTypeLabel').value.trim();
        const defaultDescr = document.getElementById('optionTypeDefault').value.trim();
        const linkedProductText = document.getElementById('optionTypeLinkedProduct').value.trim();
        const linkedProductId = linkedProductText ? (document.getElementById('optionTypeLinkedProductId').value || null) : null;
        const defaultLinkedText = document.getElementById('optionTypeDefaultLinkedProduct').value.trim();
        const defaultLinkedProductId = defaultLinkedText ? (document.getElementById('optionTypeDefaultLinkedProductId').value || null) : null;
        const linkedProductDescr = linkedProductText ? document.getElementById('optionTypeLinkedDescr').value.trim() : '';
        const linkedImageSlot = document.getElementById('optionTypeLinkedImageSlot').value || null;
        const dependsOnType = document.getElementById('optionTypeDependsOnType').value;
        const dependsOnValueIds = dependsOnType
            ? Array.from(document.querySelectorAll('#optionTypeDependsOnValues input:checked')).map(cb => parseInt(cb.value, 10))
            : [];

        if (!typeLabel) {
            adminAlert('Label is required');
            return;
        }

        try {
            const payload = typeId
                ? { action: 'update_type', type_id: typeId, type_label: typeLabel, default_option_descr: defaultDescr, linked_product_id: linkedProductId, default_linked_product_id: defaultLinkedProductId, linked_product_descr: linkedProductDescr, linked_image_slot: linkedImageSlot, depends_on_value_ids: dependsOnValueIds, csrf_token: window.csrfToken }
                : { action: 'add_type', product_id: this.productId, type_label: typeLabel, default_option_descr: defaultDescr, linked_product_id: linkedProductId, default_linked_product_id: defaultLinkedProductId, linked_product_descr: linkedProductDescr, linked_image_slot: linkedImageSlot, depends_on_value_ids: dependsOnValueIds, csrf_token: window.csrfToken };

            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.success) {
                this.typeModal.hide();
                this.loadOptions();
            } else {
                adminAlert(result.error || 'Failed to save option type');
            }
        } catch (error) {
            console.error('Failed to save type:', error);
            adminAlert('Failed to save option type');
        }
    },

    async deleteType(typeId) {
        if (!(await adminConfirm('Delete this option type and all its values?'))) return;

        try {
            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_type', type_id: typeId, csrf_token: window.csrfToken })
            });
            const result = await response.json();

            if (result.success) {
                this.loadOptions();
            } else {
                adminAlert(result.error || 'Failed to delete option type');
            }
        } catch (error) {
            console.error('Failed to delete type:', error);
            adminAlert('Failed to delete option type');
        }
    },

    showValueModal(valueId, typeId) {
        document.getElementById('optionValueId').value = valueId || '';
        document.getElementById('optionValueTypeId').value = typeId;
        document.getElementById('optionValueModalTitle').textContent = valueId ? 'Edit Option Value' : 'Add Option Value';

        if (valueId) {
            const row = this.container.querySelector(`tr[data-value-id="${valueId}"]`);
            const cells = row.querySelectorAll('td');
            document.getElementById('optionValueDescr').value = cells[1].textContent;
            document.getElementById('optionValuePremium').value = cells[2].textContent.replace('£', '');
            document.getElementById('optionValueRrp').value = cells[3].textContent === '-' ? '0' : cells[3].textContent.replace('£', '');
            document.getElementById('optionValueWeight').value = cells[4].textContent === '-' ? '0' : cells[4].textContent.replace(' kg', '');
            document.getElementById('optionValueRef').value = cells[5].textContent === '-' ? '' : cells[5].textContent;
            document.getElementById('optionValueLinkedProductId').value = row.dataset.linkedId || '';
            // Find product name from datalist if we have an ID
            const linkedId = row.dataset.linkedId;
            if (linkedId) {
                const option = document.querySelector(`#productList option[data-id="${linkedId}"]`);
                document.getElementById('optionValueLinkedProduct').value = option ? option.value : '';
            } else {
                document.getElementById('optionValueLinkedProduct').value = '';
            }
            document.getElementById('optionValueLinkedImageSlot').value = row.dataset.linkedImageSlot || '';
        } else {
            document.getElementById('optionValueDescr').value = '';
            document.getElementById('optionValuePremium').value = '0';
            document.getElementById('optionValueRrp').value = '0';
            document.getElementById('optionValueWeight').value = '0';
            document.getElementById('optionValueRef').value = '';
            document.getElementById('optionValueLinkedProductId').value = '';
            document.getElementById('optionValueLinkedProduct').value = '';
            document.getElementById('optionValueLinkedImageSlot').value = '';
        }

        this.valueModal.show();
    },

    async saveValue() {
        const valueId = document.getElementById('optionValueId').value;
        const typeId = document.getElementById('optionValueTypeId').value;
        const descr = document.getElementById('optionValueDescr').value.trim();
        const premium = parseFloat(document.getElementById('optionValuePremium').value) || 0;
        const rrp = parseFloat(document.getElementById('optionValueRrp').value) || 0;
        const weight = parseFloat(document.getElementById('optionValueWeight').value) || 0;
        const ref = document.getElementById('optionValueRef').value.trim();
        const linkedProductText = document.getElementById('optionValueLinkedProduct').value.trim();
        const linkedProductId = linkedProductText ? (document.getElementById('optionValueLinkedProductId').value || null) : null;
        const linkedImageSlot = document.getElementById('optionValueLinkedImageSlot').value || null;

        if (!descr) {
            adminAlert('Description is required');
            return;
        }

        try {
            const payload = valueId
                ? { action: 'update_value', value_id: valueId, option_descr: descr, option_premium: premium, option_rrp: rrp, option_packed_weight: weight, option_reference: ref, linked_product_id: linkedProductId, linked_image_slot: linkedImageSlot, csrf_token: window.csrfToken }
                : { action: 'add_value', type_id: typeId, option_descr: descr, option_premium: premium, option_rrp: rrp, option_packed_weight: weight, option_reference: ref, linked_product_id: linkedProductId, linked_image_slot: linkedImageSlot, csrf_token: window.csrfToken };

            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();

            if (result.success) {
                this.valueModal.hide();
                this.loadOptions();
            } else {
                adminAlert(result.error || 'Failed to save option value');
            }
        } catch (error) {
            console.error('Failed to save value:', error);
            adminAlert('Failed to save option value');
        }
    },

    async deleteValue(valueId) {
        if (!(await adminConfirm('Delete this option value?'))) return;

        try {
            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_value', value_id: valueId, csrf_token: window.csrfToken })
            });
            const result = await response.json();

            if (result.success) {
                this.loadOptions();
            } else {
                adminAlert(result.error || 'Failed to delete option value');
            }
        } catch (error) {
            console.error('Failed to delete value:', error);
            adminAlert('Failed to delete option value');
        }
    },

    // Safe in element text and in quoted attributes: the div trick leaves quotes alone.
    escapeHtml(text) {
        return String(text == null ? '' : text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    },

    describeDependency(valueIds) {
        const labels = [];
        this.optionTypes.forEach(t => {
            (t.values || []).forEach(v => {
                if (valueIds.includes(v.id)) labels.push(v.option_descr);
            });
        });
        return labels.join(' or ') || 'a linked option is selected';
    },

    showCopyModal() {
        document.getElementById('copySourceProduct').value = '';
        document.getElementById('copySourceProductId').value = '';
        this.copyModal = new bootstrap.Modal(document.getElementById('copyOptionsModal'));
        this.copyModal.show();
    },

    async copyFromProduct() {
        const sourceProductId = document.getElementById('copySourceProductId').value;
        if (!sourceProductId) {
            adminAlert('Please select a source product');
            return;
        }
        if (!(await adminConfirm('This will replace all existing options. Continue?'))) return;

        try {
            const response = await fetch(adminBasePath + 'shop/products/options/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'copy_from_product',
                    source_product_id: sourceProductId,
                    target_product_id: this.productId,
                    csrf_token: window.csrfToken
                })
            });
            const result = await response.json();
            if (result.success) {
                this.copyModal.hide();
                this.loadOptions();
                adminAlert(`Copied ${result.types_copied} option type(s) with ${result.values_copied} value(s).`);
            } else {
                adminAlert(result.error || 'Failed to copy options');
            }
        } catch (error) {
            console.error('Copy options failed:', error);
            adminAlert('Failed to copy options');
        }
    }
};

document.addEventListener('DOMContentLoaded', () => ProductOptions.init());
