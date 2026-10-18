// Statuses edit - keyboard shortcuts and presence tracking

document.addEventListener('DOMContentLoaded', function() {
    initializeKeyboardShortcuts();
});

function initializeKeyboardShortcuts() {
    document.addEventListener('keydown', e => {
        // Escape key navigates back (unless modal is open)
        if (e.key === 'Escape' && !document.querySelector('.modal.show')) {
            if (window.hasUnsavedChanges) {
                const warningModal = new bootstrap.Modal(document.getElementById('unsavedChangesModal'));
                warningModal.show();
                document.getElementById('unsavedChangesLeave').onclick = function() {
                    window.hasUnsavedChanges = false;
                    bootstrap.Modal.getInstance(document.getElementById('unsavedChangesModal')).hide();
                    window.location.href = adminBasePath + 'shop/statuses/';
                };
            } else {
                window.location.href = adminBasePath + 'shop/statuses/';
            }
        }
    });
}

// Initialize presence tracking (called from inline script in view)
function initStatusPresenceTracking(statusId) {
    initPresenceTracking(adminBasePath + 'shop/statuses/action/', statusId, {
        formSelector: '#status-form'
    });
}
