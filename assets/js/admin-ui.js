(function () {
    'use strict';

    var prefersDark = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    var bodyReady = false;
    var config = window.MusederRestoreOneAdminUI || {};
    var themePreference = config.theme || 'auto';
    var features = (config && config.features) ? config.features : { restoreV2: true, animations: true, extendedLog: false };

    function resolveTheme(value) {
        if (value === 'auto') {
            if (prefersDark && typeof prefersDark.matches === 'boolean') {
                return prefersDark.matches ? 'dark' : 'light';
            }
            return 'light';
        }
        return value === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        var mode = resolveTheme(theme);
        document.documentElement.setAttribute('data-bl-theme', mode === 'dark' ? 'dark' : 'light');
        if (document.body) {
            document.body.classList.add('backup-lite-admin');
            bodyReady = true;
        }
    }

    function attachBodyClass() {
        if (!bodyReady && document.body) {
            document.body.classList.add('backup-lite-admin');
            bodyReady = true;
        }
    }

    function initTheme() {
        if (document.body) {
            applyTheme(themePreference);
        } else {
            document.addEventListener('DOMContentLoaded', function () {
                applyTheme(themePreference);
            });
        }

        if (prefersDark && typeof prefersDark.addEventListener === 'function') {
            prefersDark.addEventListener('change', function (event) {
                if (themePreference === 'auto') {
                    applyTheme(event.matches ? 'dark' : 'light');
                }
            });
        }
    }

    initTheme();

    window.BackupLiteUI = window.BackupLiteUI || {};
    window.BackupLiteUI.setTheme = function (value) {
        themePreference = value || 'auto';
        applyTheme(value);
    };

    window.BackupLiteUI.getTheme = function () {
        return document.documentElement.getAttribute('data-bl-theme') || 'light';
    };

    document.addEventListener('DOMContentLoaded', attachBodyClass);

    // Utility helpers
    window.BackupLiteUI.toggleClass = function (selector, className, enable) {
        var nodes = document.querySelectorAll(selector);
        nodes.forEach(function (node) {
            if (enable) {
                node.classList.add(className);
            } else {
                node.classList.remove(className);
            }
        });
    };

    window.BackupLiteUI.setStepState = function (selector, state) {
        var node = document.querySelector(selector);
        if (!node) {
            return;
        }
        node.dataset.blState = state;
    };

    window.BackupLiteUI.autoScroll = function (selector) {
        var node = document.querySelector(selector);
        if (node) {
            node.scrollTop = node.scrollHeight;
        }
    };

    function getAddonConfig() {
        return window.MusederRestoreOneAddon || window.MusederRestoreOnePro || {};
    }

    // ============================================
    // Feature unavailable modal
    // ============================================

    var proUpgradeModal = null;
    var proFeatureNotice = null;

    function createProModal() {
        if (proUpgradeModal) {
            return proUpgradeModal;
        }

        var modal = document.createElement('div');
        modal.className = 'backup-lite-pro-modal';
        modal.innerHTML = [
            '<div class="backup-lite-pro-modal-content">',
            '  <div class="backup-lite-pro-modal-header">',
            '    <h2>' + (getAddonConfig().strings ? getAddonConfig().strings.modalTitle : 'Optional add-on setting unavailable') + '</h2>',
            '    <p class="backup-lite-pro-modal-subtitle">' + (getAddonConfig().strings ? getAddonConfig().strings.modalSubtitle : 'This control is reserved for an optional add-on.') + '</p>',
            '    <p class="backup-lite-pro-modal-feature"></p>',
            '  </div>',
            '  <div class="backup-lite-pro-modal-footer">',
            '    <button class="backup-lite-pro-modal-close">' + (getAddonConfig().strings ? getAddonConfig().strings.close : 'Close') + '</button>',
            '    <button class="backup-lite-pro-modal-upgrade">' + (getAddonConfig().strings ? getAddonConfig().strings.upgrade : 'OK') + '</button>',
            '  </div>',
            '</div>'
        ].join('');

        if (document.body) {
            document.body.appendChild(modal);
            proUpgradeModal = modal;
        } else {
            // Wait for body to be available
            document.addEventListener('DOMContentLoaded', function () {
                if (document.body && !proUpgradeModal) {
                    document.body.appendChild(modal);
                    proUpgradeModal = modal;
                }
            });
            return null;
        }

        // Close button
        var closeBtn = modal.querySelector('.backup-lite-pro-modal-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                hideProModal();
            });
        }

        // Secondary button: only shown when the current edition exposes a destination URL.
        var upgradeBtn = modal.querySelector('.backup-lite-pro-modal-upgrade');
        if (upgradeBtn) {
            var upgradeUrl = (getAddonConfig().upgradeUrl) || '';
            if (!upgradeUrl) {
                upgradeBtn.style.display = 'none';
            } else {
                upgradeBtn.addEventListener('click', function () {
                    window.open(upgradeUrl, '_blank');
                });
            }
        }

        // Close on backdrop click
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                hideProModal();
            }
        });

        // Close on ESC key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                hideProModal();
            }
        });

        proFeatureNotice = modal.querySelector('.backup-lite-pro-modal-feature');

        return modal;
    }

    function formatFeatureLabel(featureKey) {
        if (!featureKey) {
            return '';
        }
        return featureKey.replace(/[_-]+/g, ' ').replace(/\b\w/g, function (match) {
            return match.toUpperCase();
        });
    }

    function showProModal(featureKey) {
        var modal = createProModal();
        if (!modal) {
            return;
        }

        if (proFeatureNotice) {
            var label = formatFeatureLabel(featureKey);
            if (label) {
                var template = (getAddonConfig().strings && getAddonConfig().strings.featureLocked) ? getAddonConfig().strings.featureLocked : 'Setting "%s" is reserved for an optional add-on.';
                proFeatureNotice.textContent = template.replace('%s', label);
                proFeatureNotice.style.display = 'block';
            } else {
                proFeatureNotice.textContent = '';
                proFeatureNotice.style.display = 'none';
            }
        }

        setTimeout(function () {
            modal.classList.add('active');
        }, 10);
    }

    function hideProModal() {
        if (proUpgradeModal) {
            proUpgradeModal.classList.remove('active');
        }
    }

    // Expose functions globally
    window.BackupLiteUI.showProModal = showProModal;
    window.BackupLiteUI.hideProModal = hideProModal;
    window.BackupLiteUI.openProUpgradeModal = showProModal;

    // =============================
    // Feature Toggles (FREE)
    // =============================
    function applyFeatureToggles() {
        // UI Animations
        var off = !(features && features.animations);
        document.documentElement.setAttribute('data-bl-anim', off ? 'off' : 'on');
        if (off) {
            document.body.classList.add('bl-anim-off');
        } else {
            document.body.classList.remove('bl-anim-off');
        }

        // Restore Center v2
        var r2 = !!(features && features.restoreV2);
        document.documentElement.setAttribute('data-bl-restore-v2', r2 ? 'on' : 'off');
        if (!r2) {
            document.body.classList.add('bl-restore-v2-off');
        } else {
            document.body.classList.remove('bl-restore-v2-off');
        }

        // Extended Log Preview
        if (features && features.extendedLog) {
            var pre = document.querySelector('pre.log-preview');
            if (pre) pre.classList.add('bl-log-preview--extended');
            document.body.classList.add('bl-extended-log-on');
        } else {
            var pre2 = document.querySelector('pre.log-preview');
            if (pre2) pre2.classList.remove('bl-log-preview--extended');
            document.body.classList.remove('bl-extended-log-on');
        }
    }

    // Expose runtime API to apply features without reload
    window.BackupLiteUI.applyFeatures = function (next) {
        features = Object.assign({}, features, next || {});
        applyFeatureToggles();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', applyFeatureToggles);
    } else {
        applyFeatureToggles();
    }
})();


