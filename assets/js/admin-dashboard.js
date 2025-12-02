/**
 * Dashboard-specific JavaScript functionality.
 * Handles Latest Logs toggle functionality and Activity chart.
 */
(function () {
    'use strict';

    /**
     * Handle toggle click using event delegation.
     */
    function handleToggleClick(event) {
        var button = event.target.closest('.backup-lite-toggle-logs');
        if (!button) {
            return;
        }

        // Prevent default link behavior
        event.preventDefault();
        event.stopPropagation();

        var container = button.closest('.backup-lite-latest-logs');
        if (!container) {
            return;
        }

        var list = container.querySelector('.backup-lite-log-list');
        if (!list) {
            return;
        }

        var collapsed = list.getAttribute('data-collapsed') === 'true';
        var newState = collapsed ? 'false' : 'true';

        list.setAttribute('data-collapsed', newState);

        var expanded = !collapsed;
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');

        // Update button text using localized strings
        if (typeof backupLiteDashboard !== 'undefined') {
            button.textContent = expanded
                ? backupLiteDashboard.hideExtraLogsLabel
                : backupLiteDashboard.showAllLogsLabel;
        } else {
            // Fallback if localization is not available
            button.textContent = expanded
                ? 'Hide extra logs'
                : 'Show all logs';
        }
    }

    // Use event delegation for toggle button
    // Initialize on DOM ready to ensure elements exist
    function init() {
        // Handle toggle button clicks
        document.addEventListener('click', handleToggleClick);
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

