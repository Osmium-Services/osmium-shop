/**
 * Product Images Management
 * Handles drag-and-drop uploads and image management for product edit page
 */
const ProductImages = {
    config: {
        productId: 0,
        brandPath: '',
        modelPath: '',
        imagesBasePath: '',
        adminBasePath: window.adminBasePath || '/sg2-manage/'
    },

    /**
     * Initialize the image manager
     */
    init(options) {
        this.config = { ...this.config, ...options };
        this.setupDropZones();
        // Images are pre-populated from PHP, no need to load via AJAX
    },

    /**
     * Set up drag-and-drop zones for all image slots
     */
    setupDropZones() {
        document.querySelectorAll('.image-drop-zone').forEach(zone => {
            const slot = zone.dataset.slot;

            // Prevent default drag behaviors
            ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                zone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                });
            });

            // Highlight on drag enter/over
            ['dragenter', 'dragover'].forEach(eventName => {
                zone.addEventListener(eventName, () => {
                    zone.classList.add('drag-active');
                });
            });

            // Remove highlight on drag leave/drop
            ['dragleave', 'drop'].forEach(eventName => {
                zone.addEventListener(eventName, () => {
                    zone.classList.remove('drag-active');
                });
            });

            // Handle drop
            zone.addEventListener('drop', (e) => {
                const files = e.dataTransfer.files;
                if (files.length > 0) {
                    this.uploadImage(slot, files[0]);
                }
            });

            // Handle click to open file picker
            zone.addEventListener('click', (e) => {
                // Ignore clicks on delete button
                if (e.target.closest('.btn-delete-image')) return;

                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/jpeg,image/png,image/gif,image/webp';
                input.onchange = (ev) => {
                    if (ev.target.files.length > 0) {
                        this.uploadImage(slot, ev.target.files[0]);
                    }
                };
                input.click();
            });
        });

        // Set up delete buttons
        document.querySelectorAll('.btn-delete-image').forEach(btn => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const slot = btn.dataset.slot;
                if (await adminConfirm('Delete this image?')) {
                    this.deleteImage(slot);
                }
            });
        });
    },

    /**
     * Load current images for the product
     */
    async loadCurrentImages() {
        try {
            const response = await fetch(this.config.adminBasePath + 'shop/products/images/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_images',
                    product_id: this.config.productId,
                    brand_path: this.config.brandPath,
                    model_path: this.config.modelPath
                })
            });

            const result = await response.json();

            if (result.success && result.images) {
                Object.entries(result.images).forEach(([slot, imagePath]) => {
                    if (imagePath) {
                        this.showImagePreview(slot, this.config.imagesBasePath + imagePath);
                    }
                });
            }
        } catch (error) {
            console.error('Failed to load images:', error);
        }
    },

    /**
     * Upload an image to a slot
     */
    async uploadImage(slot, file) {
        const zone = document.querySelector(`.image-drop-zone[data-slot="${slot}"]`);
        if (!zone) return;

        // Show uploading state
        zone.classList.add('uploading');
        this.showUploadingState(zone);

        const formData = new FormData();
        formData.append('action', 'upload');
        formData.append('product_id', this.config.productId);
        formData.append('slot', slot);
        formData.append('brand_path', this.config.brandPath);
        formData.append('model_path', this.config.modelPath);
        formData.append('image', file);
        formData.append('token', window.csrfToken);

        try {
            const response = await fetch(this.config.adminBasePath + 'shop/products/images/action/', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success) {
                // Add cache buster to force reload
                const cacheBuster = '?t=' + Date.now();
                this.showImagePreview(slot, this.config.imagesBasePath + result.imagePath + cacheBuster);

                // Update derivation badges for main image uploads
                if (slot === 'main' && result.derivations) {
                    this.updateDerivationBadges(result.derivations);
                }
            } else {
                adminAlert('Upload failed: ' + (result.error || 'Unknown error'));
                this.showEmptyState(zone);
            }
        } catch (error) {
            console.error('Upload error:', error);
            adminAlert('Upload failed: ' + error.message);
            this.showEmptyState(zone);
        } finally {
            zone.classList.remove('uploading');
        }
    },

    /**
     * Delete an image from a slot
     */
    async deleteImage(slot) {
        const zone = document.querySelector(`.image-drop-zone[data-slot="${slot}"]`);
        if (!zone) return;

        zone.classList.add('uploading');

        try {
            const response = await fetch(this.config.adminBasePath + 'shop/products/images/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'delete',
                    product_id: this.config.productId,
                    slot: slot,
                    brand_path: this.config.brandPath,
                    model_path: this.config.modelPath,
                    csrf_token: window.csrfToken
                })
            });

            const result = await response.json();

            if (result.success) {
                this.showEmptyState(zone);
            } else {
                adminAlert('Delete failed: ' + (result.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Delete error:', error);
            adminAlert('Delete failed: ' + error.message);
        } finally {
            zone.classList.remove('uploading');
        }
    },

    /**
     * Show image preview in a drop zone
     */
    showImagePreview(slot, imagePath) {
        const zone = document.querySelector(`.image-drop-zone[data-slot="${slot}"]`);
        if (!zone) return;

        const emptyState = zone.querySelector('.empty-state');
        const previewState = zone.querySelector('.preview-state');
        const uploadingState = zone.querySelector('.uploading-state');
        const previewImg = zone.querySelector('.preview-image');
        const deleteBtn = zone.querySelector('.btn-delete-image');

        if (emptyState) emptyState.classList.add('d-none');
        if (uploadingState) uploadingState.classList.add('d-none');
        if (previewState) previewState.classList.remove('d-none');
        if (previewImg) previewImg.src = imagePath;
        if (deleteBtn) deleteBtn.classList.remove('d-none');
    },

    /**
     * Show empty state in a drop zone
     */
    showEmptyState(zone) {
        const emptyState = zone.querySelector('.empty-state');
        const previewState = zone.querySelector('.preview-state');
        const uploadingState = zone.querySelector('.uploading-state');
        const deleteBtn = zone.querySelector('.btn-delete-image');

        if (emptyState) emptyState.classList.remove('d-none');
        if (previewState) previewState.classList.add('d-none');
        if (uploadingState) uploadingState.classList.add('d-none');
        if (deleteBtn) deleteBtn.classList.add('d-none');
    },

    /**
     * Show uploading state in a drop zone
     */
    showUploadingState(zone) {
        const emptyState = zone.querySelector('.empty-state');
        const previewState = zone.querySelector('.preview-state');
        const uploadingState = zone.querySelector('.uploading-state');

        if (emptyState) emptyState.classList.add('d-none');
        if (previewState) previewState.classList.add('d-none');
        if (uploadingState) uploadingState.classList.remove('d-none');
    },

    /**
     * Update derivation badges (Large, Medium, Thumb) after main image upload
     */
    updateDerivationBadges(derivations) {
        const sizes = ['large', 'medium', 'thumb'];
        const labels = { large: 'Large', medium: 'Medium', thumb: 'Thumb' };

        // Find the badges container (next to main image drop zone)
        const mainZone = document.querySelector('.image-drop-zone[data-slot="main"]');
        if (!mainZone) return;

        const badgesContainer = mainZone.parentElement.querySelector('.d-flex.align-items-center.gap-3');
        if (!badgesContainer) return;

        // Update each size badge
        sizes.forEach(size => {
            const exists = derivations[size] ?? false;
            const badges = badgesContainer.querySelectorAll('.badge');

            badges.forEach(badge => {
                if (badge.textContent.includes(labels[size])) {
                    badge.className = exists ? 'badge bg-label-success' : 'badge bg-label-danger';
                    badge.innerHTML = `<i class="bx ${exists ? 'bx-check' : 'bx-x'} me-1"></i>${labels[size]}`;
                }
            });
        });

        // Update format badges (WebP, AVIF)
        const formats = ['webp', 'avif'];
        const formatLabels = { webp: 'WebP', avif: 'AVIF' };
        const allBadgeContainers = mainZone.parentElement.querySelectorAll('.d-flex.align-items-center.gap-3');
        const formatBadgesContainer = allBadgeContainers[1]; // Second container has format badges

        if (formatBadgesContainer) {
            formats.forEach(format => {
                const exists = derivations[format] ?? false;
                const badge = formatBadgesContainer.querySelector(`[data-format="${format}"]`);
                if (badge) {
                    badge.className = exists ? 'badge bg-label-success' : 'badge bg-label-warning';
                    badge.setAttribute('data-format', format);
                    const label = formatLabels[format];
                    badge.innerHTML = `<i class="bx ${exists ? 'bx-check' : 'bx-x'} me-1"></i>${label}`;
                }
            });
        }
    }
};
