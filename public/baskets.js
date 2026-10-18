/**
 * Baskets Admin - "Clean up old baskets" header button on the Abandoned list
 * (the lists themselves are handled by list-view.js).
 */

(function() {
    'use strict';

    async function cleanupOldBaskets() {
        const days = prompt('Delete abandoned baskets older than how many days?', '30');
        if (days === null) return;

        const daysNum = parseInt(days, 10);
        if (isNaN(daysNum) || daysNum < 1) {
            adminAlert('Please enter a valid number of days');
            return;
        }

        if (!(await adminConfirm('Delete all abandoned baskets older than ' + daysNum + ' days?'))) return;

        try {
            const response = await fetch(window.adminBasePath + 'shop/baskets/action/', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'delete_old', days: daysNum, csrf_token: window.csrfToken })
            });
            const data = await response.json();
            if (!data.success) {
                adminAlert('Cleanup failed: ' + (data.error || 'Unknown error'));
                return;
            }

            await adminAlert('Deleted ' + data.deleted + ' old basket(s)');
            location.reload();
        } catch (error) {
            console.error('Error:', error);
            adminAlert('Cleanup failed');
        }
    }

    document.addEventListener('list:action', (event) => {
        if (event.detail.action === 'cleanup_old') cleanupOldBaskets();
    });
})();
