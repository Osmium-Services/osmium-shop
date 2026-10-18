/**
 * Product Categories - Tree management with lock-based concurrent editing
 */

// ========================================
// Configuration
// ========================================

const purifyConfig = {
    ALLOWED_TAGS: ['p', 'br', 'b', 'strong', 'i', 'em', 'u', 'ul', 'ol', 'li', 'a', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'],
    ALLOWED_ATTR: ['href', 'target', 'class', 'style']
};

function sanitizeContent(content) {
    let clean = DOMPurify.sanitize(content, purifyConfig);
    clean = clean.replace(/<b>/g, '<strong>').replace(/<\/b>/g, '</strong>');
    clean = clean.replace(/<i>/g, '<em>').replace(/<\/i>/g, '</em>');
    return clean;
}

// ========================================
// Category Manager
// ========================================

const CategoryManager = {
    categories: [],
    modal: null,
    sortableInstances: [],
    collapsedCategories: new Set(JSON.parse(localStorage.getItem('collapsedCategories') || '[]')),

    init() {
        this.modal = new bootstrap.Modal(document.getElementById('categoryModal'));
        this.setupImagePreviews();
        this.initSummernote();
        this.loadCategories();
    },

    initSummernote() {
        $('#categoryDescription').summernote({
            height: 200,
            toolbar: [
                ['style', ['bold', 'italic']],
                ['view', ['codeview']]
            ],
            callbacks: {
                onBlur: function() {
                    const content = $('#categoryDescription').summernote('code');
                    const clean = sanitizeContent(content);
                    if (content !== clean) {
                        $('#categoryDescription').summernote('code', clean);
                    }
                }
            }
        });
    },

    buildImagePath(name) {
        if (!name) return null;
        if (name.startsWith('http') || name.startsWith('/')) return name;
        return `/assets/images/shop/categories/${name}/${name}.jpg`;
    },

    buildSocialImagePath(name) {
        if (!name) return null;
        if (name.startsWith('http') || name.startsWith('/')) return name;
        return `/assets/images/social/${name}.jpg`;
    },

    updateImagePreview(inputId, previewId, buildPath = true) {
        const value = document.getElementById(inputId).value.trim();
        const preview = document.getElementById(previewId);
        const img = preview.querySelector('img');

        if (value) {
            const src = buildPath ? this.buildImagePath(value) : this.buildSocialImagePath(value);
            img.src = src;
            preview.style.display = 'block';
            img.onerror = () => preview.style.display = 'none';
        } else {
            preview.style.display = 'none';
        }
    },

    setupImagePreviews() {
        document.getElementById('categoryImage').addEventListener('input', () => {
            this.updateImagePreview('categoryImage', 'imagePreview', true);
        });

        document.getElementById('categorySocialImage').addEventListener('input', () => {
            this.updateImagePreview('categorySocialImage', 'socialImagePreview', false);
        });
    },

    async loadCategories() {
        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_categories',
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                this.categories = data.categories;
                this.render();
            } else {
                adminAlert('Error loading categories: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error loading categories');
            console.error(error);
        }
    },

    buildTree(categories, parentId = null) {
        return categories
            .filter(c => (c.parent_id || null) == parentId)
            .sort((a, b) => a.sort - b.sort)
            .map(cat => ({
                ...cat,
                children: this.buildTree(categories, cat.id)
            }));
    },

    render() {
        const container = document.getElementById('categoryTree');
        const emptyState = document.getElementById('emptyState');
        const tree = this.buildTree(this.categories);

        if (tree.length === 0) {
            container.innerHTML = '';
            emptyState.style.display = 'block';
            return;
        }

        emptyState.style.display = 'none';
        container.innerHTML = this.renderList(tree, null);
        this.initSortable();
    },

    renderList(items, parentId) {
        if (items.length === 0) return '';

        let html = `<ul class="category-list" data-parent-id="${parentId || ''}">`;
        for (const item of items) {
            html += this.renderItem(item);
        }
        html += '</ul>';
        return html;
    },

    renderItem(item) {
        const childCount = (item.children || []).length;
        const productCount = parseInt(item.product_count);
        const hasChildren = childCount > 0;
        const hasProducts = productCount > 0;
        const isHidden = !hasChildren && !hasProducts;
        const hiddenTitle = isHidden ? 'title="Not visible on website - no products or subcategories"' : '';
        const hiddenClass = isHidden ? 'category-hidden' : '';

        const productBadge = productCount > 0 ?
            `<span class="badge bg-warning rounded-pill" title="${productCount} product${productCount !== 1 ? 's' : ''}"><i class="bx bx-package" style="color: #8B4513;"></i> ${productCount}</span>` :
            '';
        const subcatBadge = childCount > 0 ?
            `<span class="badge bg-info rounded-pill" title="${childCount} subcategor${childCount !== 1 ? 'ies' : 'y'}"><i class="bx bx-category"></i> ${childCount}</span>` :
            '';
        const emptyBadge = !productCount && !childCount ?
            '<span class="badge bg-label-secondary rounded-pill" title="No products or subcategories"><i class="bx bx-ghost"></i></span>' :
            '';

        const isCollapsed = this.collapsedCategories.has(item.id);
        const toggleIcon = hasChildren ?
            `<span class="category-toggle ${isCollapsed ? 'collapsed' : ''}" data-call="CategoryManager.toggleCollapse" ${dataArgs(item.id)} data-id="${item.id}"><i class="bx bx-chevron-down"></i></span>` :
            '';

        const childrenHtml = hasChildren ?
            `<div class="category-children ${isCollapsed ? 'collapsed' : ''}" data-parent-id="${item.id}">${this.renderList(item.children, item.id)}</div>` :
            '';

        const categoryUrls = window.categoriesConfig.categoryUrls;

        return `
            <li class="category-item" data-id="${item.id}">
                <div class="category-row">
                    ${toggleIcon}
                    <span class="drag-handle"><i class="bx bx-menu"></i></span>
                    <span class="category-label ${hiddenClass}" ${hiddenTitle}>${this.escapeHtml(item.label)}</span>
                    ${productBadge}
                    ${subcatBadge}
                    ${emptyBadge}
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-icon dropdown-toggle hide-arrow" data-bs-toggle="dropdown">
                            <i class="bx bx-slider" style="font-size: 1.25rem;"></i>
                        </button>
                        <div class="dropdown-menu">
                            <a class="dropdown-item" href="#" data-call="CategoryManager.showEditModal" ${dataArgs(item.id)}>
                                <i class="bx bx-edit-alt me-1"></i> Edit
                            </a>
                            <a class="dropdown-item" href="#" data-call="CategoryManager.showAddModal" ${dataArgs(item.id)}>
                                <i class="bx bx-plus me-1"></i> Add Subcategory
                            </a>
                            ${hasProducts ? `<a class="dropdown-item" href="#" data-call="CategoryManager.showProductOrderModal" ${dataArgs(item.id, item.label)}>
                                <i class="bx bx-sort-alt-2 me-1"></i> Order Products
                            </a>` : ''}
                            ${categoryUrls[item.id] ? `<a class="dropdown-item" href="${categoryUrls[item.id]}" target="_blank">
                                <i class="bx bx-link-external me-1"></i> View on Website
                            </a>` : ''}
                            <div class="dropdown-divider"></div>
                            ${hasChildren || hasProducts
                                ? `<span class="dropdown-item text-danger" style="opacity: 0.6; cursor: not-allowed;" title="${hasChildren ? childCount + ' subcategor' + (childCount === 1 ? 'y' : 'ies') : productCount + ' product(s)'} in this category">
                                    <i class="bx bxs-lock me-1"></i> Delete
                                </span>`
                                : `<a class="dropdown-item text-danger" href="#" data-call="CategoryManager.delete" ${dataArgs(item.id)}>
                                    <i class="bx bx-trash me-1"></i> Delete
                                </a>`
                            }
                        </div>
                    </div>
                </div>
                ${childrenHtml}
            </li>
        `;
    },

    initSortable() {
        this.sortableInstances.forEach(s => s.destroy());
        this.sortableInstances = [];

        document.querySelectorAll('.category-list').forEach(list => {
            const sortable = new Sortable(list, {
                group: 'categories',
                animation: 150,
                fallbackOnBody: true,
                swapThreshold: 0.65,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost',
                chosenClass: 'sortable-chosen',
                onEnd: () => this.saveOrder()
            });
            this.sortableInstances.push(sortable);
        });
    },

    async saveOrder() {
        const items = [];
        document.querySelectorAll('.category-list').forEach(list => {
            const parentId = list.dataset.parentId || null;
            list.querySelectorAll(':scope > .category-item').forEach((item, index) => {
                items.push({
                    id: parseInt(item.dataset.id),
                    parent_id: parentId ? parseInt(parentId) : null,
                    sort: index
                });
            });
        });

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reorder',
                    items,
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                this.showBackupBar();
            } else {
                adminAlert('Error saving order: ' + data.error);
                this.loadCategories();
            }
        } catch (error) {
            adminAlert('Error saving order');
            console.error(error);
            this.loadCategories();
        }
    },

    async acquireLock() {
        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'acquire_lock',
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (!data.success) {
                adminAlert('Error: ' + data.error);
                return false;
            }

            if (data.locked) {
                adminAlert(`Categories are being edited by ${data.user_name}. Please try again later.`);
                return false;
            }

            if (data.backup_created) {
                this.showBackupBar();
            }

            return true;
        } catch (error) {
            adminAlert('Error acquiring lock');
            console.error(error);
            return false;
        }
    },

    async showAddModal(parentId = null) {
        if (!await this.acquireLock()) return;

        document.getElementById('categoryModalTitle').textContent = 'Add Category';
        document.getElementById('categoryId').value = '';
        document.getElementById('categoryLabel').value = '';
        document.getElementById('categoryTitle').value = '';
        document.getElementById('categorySubtitle').value = '';
        $('#categoryDescription').summernote('code', '');
        document.getElementById('categoryImage').value = '';
        document.getElementById('categorySocialImage').value = '';
        document.getElementById('imagePreview').style.display = 'none';
        document.getElementById('socialImagePreview').style.display = 'none';

        this.populateParentDropdown(null, parentId);
        this.modal.show();
    },

    async showEditModal(id) {
        if (!await this.acquireLock()) return;

        const category = this.categories.find(c => c.id == id);
        if (!category) return;

        document.getElementById('categoryModalTitle').textContent = 'Edit Category';
        document.getElementById('categoryId').value = category.id;
        document.getElementById('categoryLabel').value = category.label || '';
        document.getElementById('categoryTitle').value = category.title || '';
        document.getElementById('categorySubtitle').value = category.subtitle || '';
        $('#categoryDescription').summernote('code', category.description || '');
        document.getElementById('categoryImage').value = category.image || '';
        document.getElementById('categorySocialImage').value = category.social_image || '';

        this.updateImagePreview('categoryImage', 'imagePreview', true);
        this.updateImagePreview('categorySocialImage', 'socialImagePreview', false);

        this.populateParentDropdown(id, category.parent_id);
        this.modal.show();
    },

    populateParentDropdown(excludeId, selectedId) {
        const select = document.getElementById('categoryParent');
        select.innerHTML = '<option value="">None (Top Level)</option>';

        const getPath = (id, path = []) => {
            const cat = this.categories.find(c => c.id == id);
            if (!cat) return path;
            path.unshift(cat.label);
            if (cat.parent_id) return getPath(cat.parent_id, path);
            return path;
        };

        const getDescendants = (id) => {
            const descendants = [];
            const findChildren = (parentId) => {
                this.categories.filter(c => c.parent_id == parentId).forEach(c => {
                    descendants.push(c.id);
                    findChildren(c.id);
                });
            };
            findChildren(id);
            return descendants;
        };

        const excludeIds = excludeId ? [parseInt(excludeId), ...getDescendants(excludeId)] : [];

        this.categories
            .filter(c => !excludeIds.includes(parseInt(c.id)))
            .map(c => ({
                ...c,
                path: getPath(c.id).join(' > ')
            }))
            .sort((a, b) => a.path.localeCompare(b.path))
            .forEach(cat => {
                const option = document.createElement('option');
                option.value = cat.id;
                option.textContent = cat.path;
                if (cat.id == selectedId) option.selected = true;
                select.appendChild(option);
            });
    },

    async save() {
        const id = document.getElementById('categoryId').value;
        const label = document.getElementById('categoryLabel').value.trim();
        const title = document.getElementById('categoryTitle').value.trim();
        const subtitle = document.getElementById('categorySubtitle').value.trim();
        let description = $('#categoryDescription').summernote('code');
        description = DOMPurify.sanitize(description, purifyConfig);
        const parentId = document.getElementById('categoryParent').value || null;
        const image = document.getElementById('categoryImage').value.trim();
        const socialImage = document.getElementById('categorySocialImage').value.trim();

        if (!label) {
            adminAlert('Label is required');
            return;
        }

        const action = id ? 'update' : 'add';
        const payload = {
            action,
            label,
            title,
            subtitle,
            description,
            parent_id: parentId,
            image,
            social_image: socialImage
        };

        if (id) payload.id = parseInt(id);
        payload.csrf_token = window.csrfToken;

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();

            if (data.success) {
                this.modal.hide();
                this.loadCategories();
                this.showBackupBar();
            } else {
                adminAlert('Error: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error saving category');
            console.error(error);
        }
    },

    showBackupBar() {
        if (window.categoriesConfig.hasOwnBackup) return;
        window.categoriesConfig.hasOwnBackup = true;
        window.hasUnsavedChanges = true;

        const container = document.getElementById('categoryPageContainer');
        if (!container) return;
        const existingBar = document.getElementById('backupBar');
        if (existingBar) return;

        const bar = document.createElement('div');
        bar.id = 'backupBar';
        bar.className = 'alert alert-secondary d-flex justify-content-between align-items-center mb-4';
        bar.setAttribute('role', 'alert');
        bar.innerHTML = `
            <div>
                <i class="bx bx-history me-2"></i>
                <strong>Editing in progress.</strong> Your changes are saved, but you can restore the original state if needed.
                <small class="d-block" style="opacity: 0.8;">Other users are blocked from editing. Click "Save" when finished, or "Restore" to undo all changes.<br>After 30 mins of inactivity, the lock releases and your changes remain but can no longer be restored.</small>
            </div>
            <div class="text-nowrap">
                <button type="button" class="btn btn-success btn-sm me-2" data-call="CategoryManager.releaseLock">
                    <i class="bx bx-check me-1"></i> Save
                </button>
                <button type="button" class="btn btn-warning btn-sm" data-call="CategoryManager.restoreBackup">
                    <i class="bx bx-undo me-1"></i> Restore
                </button>
            </div>
        `;

        const header = container.querySelector('.d-flex.justify-content-between');
        container.insertBefore(bar, header);
    },

    async delete(id) {
        const category = this.categories.find(c => c.id == id);
        if (!category) return;

        if (!(await adminConfirm(`Are you sure you want to delete "${category.label}"?`))) {
            return;
        }

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    id,
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                location.reload();
            } else {
                adminAlert('Error: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error deleting category');
            console.error(error);
        }
    },

    async restore(id) {
        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'restore',
                    id,
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                location.reload();
            } else {
                adminAlert('Error: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error restoring category');
            console.error(error);
        }
    },

    async permanentDelete(id, label) {
        if (!(await adminConfirm(`Are you sure you want to PERMANENTLY delete "${label}"? This cannot be undone.`))) {
            return;
        }

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'permanent_delete',
                    id,
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                location.reload();
            } else {
                adminAlert('Error: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error permanently deleting category');
            console.error(error);
        }
    },

    toggleCollapse(id) {
        if (this.collapsedCategories.has(id)) {
            this.collapsedCategories.delete(id);
        } else {
            this.collapsedCategories.add(id);
        }
        localStorage.setItem('collapsedCategories', JSON.stringify([...this.collapsedCategories]));

        const toggle = document.querySelector(`.category-toggle[data-id="${id}"]`);
        const children = document.querySelector(`.category-children[data-parent-id="${id}"]`);
        if (toggle) toggle.classList.toggle('collapsed');
        if (children) children.classList.toggle('collapsed');
    },

    async restoreBackup() {
        if (!(await adminConfirm('Restore to original state? All your changes will be undone.'))) {
            return;
        }

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'restore_backup',
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                window.hasUnsavedChanges = false;
                location.reload();
            } else {
                adminAlert('Error: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error restoring backup');
            console.error(error);
        }
    },

    async releaseLock() {
        if (!(await adminConfirm('Save and finish editing? Your changes will be kept and other users can edit.'))) {
            return;
        }

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'release_lock',
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (data.success) {
                window.hasUnsavedChanges = false;
                location.reload();
            } else {
                adminAlert('Error: ' + data.error);
            }
        } catch (error) {
            adminAlert('Error releasing lock');
            console.error(error);
        }
    },

    // Product ordering
    productOrderModal: null,
    productOrderSortable: null,
    currentOrderCategoryId: null,

    async showProductOrderModal(categoryId, categoryName) {
        this.currentOrderCategoryId = categoryId;

        if (!this.productOrderModal) {
            this.productOrderModal = new bootstrap.Modal(document.getElementById('productOrderModal'));
        }

        document.getElementById('productOrderCategoryName').textContent = categoryName;

        document.getElementById('productOrderLoading').style.display = 'block';
        document.getElementById('productOrderEmpty').style.display = 'none';
        document.getElementById('productOrderList').innerHTML = '';

        this.productOrderModal.show();

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_category_products',
                    category_id: categoryId,
                })
            });
            const data = await response.json();

            document.getElementById('productOrderLoading').style.display = 'none';

            if (data.success && data.products.length > 0) {
                this.renderProductOrderList(data.products);
            } else if (data.success && data.products.length === 0) {
                document.getElementById('productOrderEmpty').style.display = 'block';
            } else {
                adminAlert('Error loading products: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            document.getElementById('productOrderLoading').style.display = 'none';
            adminAlert('Error loading products');
            console.error(error);
        }
    },

    renderProductOrderList(products) {
        const list = document.getElementById('productOrderList');
        list.innerHTML = products.map((product, index) => {
            const isAvailable = product.product_available == 1;
            const hasPrice = parseFloat(product.price_exc_tax) > 0;
            const isHidden = !isAvailable || !hasPrice;
            const hiddenClass = isHidden ? 'product-hidden' : '';

            const reasons = [];
            if (!isAvailable) reasons.push('Unavailable');
            if (!hasPrice) reasons.push('No price');
            const reasonBadge = isHidden ?
                `<span class="badge bg-secondary ms-2" title="Not shown on frontend">${reasons.join(', ')}</span>` :
                '';

            return `
            <li class="list-group-item product-order-item ${hiddenClass}" data-product-id="${product.id}">
                <i class="bx bx-menu drag-handle"></i>
                <span class="position-badge">${index + 1}</span>
                <img src="${this.escapeHtml(product.thumbnail)}"
                     alt="${this.escapeHtml(product.title)}"
                     class="product-thumb"
                     data-img-fallback="hide">
                <div class="product-info">
                    <div class="product-name" title="${this.escapeHtml(product.title)}">
                        ${this.escapeHtml(product.title)}${reasonBadge}
                    </div>
                    <div class="product-price">£${parseFloat(product.price_exc_tax).toFixed(2)}</div>
                </div>
            </li>
        `
        }).join('');

        this.initProductOrderSortable();
    },

    initProductOrderSortable() {
        if (this.productOrderSortable) {
            this.productOrderSortable.destroy();
        }

        const list = document.getElementById('productOrderList');
        this.productOrderSortable = new Sortable(list, {
            handle: '.drag-handle',
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            onEnd: () => {
                list.querySelectorAll('.product-order-item').forEach((item, index) => {
                    item.querySelector('.position-badge').textContent = index + 1;
                });
                this.saveProductOrder();
            }
        });
    },

    async saveProductOrder() {
        const list = document.getElementById('productOrderList');
        const items = [];
        list.querySelectorAll('.product-order-item').forEach((item, index) => {
            items.push({
                product_id: parseInt(item.dataset.productId),
                sort: index + 1
            });
        });

        try {
            const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reorder_products',
                    category_id: this.currentOrderCategoryId,
                    items: items,
                    csrf_token: window.csrfToken
                })
            });
            const data = await response.json();

            if (!data.success) {
                adminAlert('Error saving order: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            adminAlert('Error saving order');
            console.error(error);
        }
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
};

// ========================================
// Lock Polling
// ========================================

async function checkLockRelease() {
    try {
        const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'check_lock'
            })
        });
        const result = await response.json();

        if (result.success && !result.locked) {
            location.reload();
        }
    } catch (error) {
        console.error('Lock check error:', error);
    }
}

async function checkLockAcquired() {
    if (window.categoriesConfig.hasOwnBackup) return;

    try {
        const response = await fetch(window.adminBasePath + 'shop/categories/action/', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'check_lock'
            })
        });
        const result = await response.json();

        if (result.success && result.locked) {
            location.reload();
        }
    } catch (error) {
        console.error('Lock check error:', error);
    }
}

// ========================================
// Utilities
// ========================================

function loadScript(src) {
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

function waitForJQuery(callback) {
    if (typeof $ !== 'undefined') {
        callback();
    } else {
        setTimeout(() => waitForJQuery(callback), 50);
    }
}

// ========================================
// Initialization
// ========================================

window.hasUnsavedChanges = window.categoriesConfig.hasOwnBackup;

document.addEventListener('DOMContentLoaded', () => {
    if (window.categoriesConfig.isLockedByOther) {
        setInterval(checkLockRelease, 10000);
        return;
    }

    waitForJQuery(() => {
        CategoryManager.init();
    });

    setInterval(checkLockAcquired, 10000);
});

// Export for onclick attributes
window.CategoryManager = CategoryManager;
