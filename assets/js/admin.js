var backupJobContext = {
    current: null,
    timer: null,
    lastNudge: 0
};

// Backup progress display helper.
// Legacy mode can gently smooth stalls; contract mode should follow backend as-is.
var backupProgressSmoother = {
    display: 0,
    server: 0,
    lastServer: null,
    lastChangeAt: 0,
    stage: '',
    status: '',
    jobMeta: null,
    timer: null,
    animTimer: null,
    target: 0,
    stop: function () {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
        if (this.animTimer) {
            window.clearInterval(this.animTimer);
            this.animTimer = null;
        }
    },
    stopStall: function () {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    },
    stopAnim: function () {
        if (this.animTimer) {
            window.clearInterval(this.animTimer);
            this.animTimer = null;
        }
    },
    isPclzipRepack: function (job) {
        if (!job) {
            return false;
        }
        return String(job.pack_method || '') === 'pclzip' && !!job.repack_attempted;
    },
    capForStage: function (stage, status, job) {
        stage = String(stage || '');
        status = String(status || '');
        if (status === 'completed' || stage === 'completed') return 100;
        if (status === 'failed' || status === 'cancelled') return 100;
        if (stage === 'preparing' || stage === 'pending') return 10;
        if (stage === 'packing' && this.isPclzipRepack(job)) return 85;
        if (stage === 'packing') return 95;
        if (stage === 'finalizing') return 99;
        return 99;
    },
    apply: function (serverProgress, stage, status, job, updateFn, options) {
        var now = Date.now();
        options = options || {};
        var allowStallSmoothing = !!options.allowStallSmoothing;
        var sp = Number(serverProgress || 0);
        if (isNaN(sp) || sp < 0) sp = 0;
        if (sp > 100) sp = 100;
        stage = String(stage || '');
        status = String(status || '');

        if (this.lastServer === null) {
            this.lastServer = sp;
            this.server = sp;
            this.display = sp;
            this.lastChangeAt = now;
            this.stage = stage;
            this.status = status;
            this.jobMeta = job || null;
            this.target = sp;
            return this.display;
        }

        var stageChanged = stage !== this.stage;
        var statusChanged = status !== this.status;
        var progressChanged = sp !== this.lastServer;
        if (stageChanged || statusChanged || progressChanged) {
            this.stage = stage;
            this.status = status;
            this.jobMeta = job || null;
            this.server = sp;
            this.lastServer = sp;
            this.lastChangeAt = now;
            // New server snapshot: stop any timers, then optionally animate large forward jumps.
            this.stopStall();
            this.stopAnim();

            // Never go backwards; never exceed server progress.
            var current = Number(this.display || 0);
            if (isNaN(current) || current < 0) current = 0;
            current = Math.min(100, Math.max(0, current));
            this.target = Math.max(current, sp);

            var delta = this.target - current;
            if (typeof updateFn === 'function' && delta >= 6 && status !== 'completed' && status !== 'failed' && status !== 'cancelled') {
                var self = this;
                // Smooth large jumps to avoid "stuck then suddenly 80%" feel.
                this.animTimer = window.setInterval(function () {
                    var remaining = (self.target || 0) - (self.display || 0);
                    if (remaining <= 0.05) {
                        self.display = self.target || 0;
                        updateFn(self.display);
                        self.stopAnim();
                        return;
                    }
                    // Adaptive speed: bigger jumps animate faster, still smooth.
                    var ratePerSec = 6 + Math.min(14, remaining); // 6–20 %/s
                    var step = ratePerSec * 0.1; // tick=100ms
                    self.display = Math.min(self.target || 0, (self.display || 0) + step);
                    updateFn(self.display);
                }, 100);
                return this.display;
            }

            // Small delta: apply immediately.
            this.display = this.target;
            return this.display;
        }

        // Optional legacy stall smoothing when server snapshots are sparse.
        if (!allowStallSmoothing) {
            this.stopStall();
            this.display = Math.max(this.display || 0, sp);
            return this.display;
        }

        // Legacy smoothing mode: only smooth when running and below stage cap.
        var stallAfterMs = 8000;
        var stepEveryMs = 2000;
        var stepDelta = 0.2;
        var cap = this.capForStage(stage, status, job);
        var epsilon = 0.1;
        var capMax = Math.max(0, cap - epsilon);

        // In compatibility repack mode, avoid synthetic near-complete smoothing.
        // Always follow server progress directly to prevent misleading 94.9% display.
        if (this.isPclzipRepack(job)) {
            this.stopStall();
            this.display = Math.max(this.display || 0, sp);
            this.display = Math.min(this.display, capMax);
            return this.display;
        }

        this.display = Math.max(this.display || 0, sp);
        if (this.display >= capMax || cap >= 100 || sp >= 100) {
            this.stopStall();
            return this.display;
        }

        if ((now - (this.lastChangeAt || 0)) >= stallAfterMs) {
            if (!this.timer && typeof updateFn === 'function') {
                var self = this;
                this.timer = window.setInterval(function () {
                    var capNow = self.capForStage(self.stage, self.status, self.jobMeta);
                    var capNowMax = Math.max(0, capNow - epsilon);
                    // Never go backwards; never exceed cap.
                    self.display = Math.max(self.display || 0, self.server || 0);
                    var next = self.display + stepDelta;
                    next = Math.min(next, capNowMax);
                    next = Math.max(next, self.server || 0);
                    self.display = next;
                    updateFn(self.display);
                    if (self.display >= capNowMax || capNow >= 100) {
                        self.stopStall();
                    }
                }, stepEveryMs);
            }
        }

        return this.display;
    }
};

// Backup timer for elapsed time display
var musederRestoreOneTimer = {
    startTime: null,
    intervalId: null,
    displayEl: null,
    
    start: function() {
        this.stop(); // Clear any existing timer
        this.startTime = Date.now();
        this.displayEl = document.getElementById('backup-elapsed-time');
        if (!this.displayEl) {
            return;
        }
        this.displayEl.style.display = '';
        this.update();
        this.intervalId = window.setInterval(function() {
            musederRestoreOneTimer.update();
        }, 1000);
    },
    
    stop: function() {
        if (this.intervalId) {
            window.clearInterval(this.intervalId);
            this.intervalId = null;
        }
        if (this.displayEl) {
            this.update(); // Final update
        }
    },
    
    update: function() {
        if (!this.displayEl || !this.startTime) {
            return;
        }
        var elapsed = Math.floor((Date.now() - this.startTime) / 1000);
        var formatted = this.formatDuration(elapsed);
        this.displayEl.textContent = 'Elapsed: ' + formatted;
    },
    
    formatDuration: function(seconds) {
        if (seconds < 0) {
            return '00:00';
        }
        var hours = Math.floor(seconds / 3600);
        var minutes = Math.floor((seconds % 3600) / 60);
        var secs = seconds % 60;
        
        if (hours > 0) {
            return String(hours).padStart(2, '0') + ':' + 
                   String(minutes).padStart(2, '0') + ':' + 
                   String(secs).padStart(2, '0');
        }
        return String(minutes).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    },
    
    reset: function() {
        this.stop();
        this.startTime = null;
        if (this.displayEl) {
            this.displayEl.style.display = 'none';
            this.displayEl.textContent = '';
        }
    }
};

(function ($) {
    'use strict';

    const settings = window.MusederRestoreOneAdmin || {};
    const strings = settings.strings || {};
    var backupModeStatus = null;
    function getString(key, fallback) {
        if (strings && Object.prototype.hasOwnProperty.call(strings, key) && strings[key]) {
            return strings[key];
        }
        return fallback || '';
    }
    const messages = $('#museder-restoreone-messages');
    const spinnerMarkup = '<span class="spinner is-active"></span>';

    function showMessage(type, title, text) {
        messages.removeClass('is-success is-error').addClass('is-visible');

        if (type === 'success') {
            messages.addClass('is-success');
        } else if (type === 'error') {
            messages.addClass('is-error');
        }

        const titleHtml = title ? '<strong>' + title + '</strong>' : '';
        const textHtml = text ? '<span>' + text + '</span>' : '';
        messages.html(titleHtml + textHtml);
    }

    function handleError(response) {
        const message = (response && response.message) ? response.message : (strings.errorGeneric || '');
        showMessage('error', strings.errorTitle || '', message);
    }

    function showToast(message, type) {
        if (!window.Toastify) {
            return;
        }

        var background = '#3b82f6';
        if (typeof type === 'string') {
            var lower = type.toLowerCase();
            if ('success' === lower) {
                background = '#10b981';
            } else if ('error' === lower) {
                background = '#ef4444';
            } else if ('warning' === lower) {
                background = '#f59e0b';
            } else if ('info' === lower) {
                background = '#3b82f6';
            } else if (lower.indexOf('#') === 0) {
                background = type;
            }
        }

        window.Toastify({
            text: message,
            gravity: 'top',
            position: 'right',
            // Toastify deprecates backgroundColor; use style.background to avoid console warning.
            style: { background: background },
            duration: 3000
        }).showToast();
    }

    /**
     * Collect third-party notices on Backup Lite pages to prevent layout shifts.
     *
     * Policy: move all third-party notices into a collapsible container, except WordPress core
     * "update-nag" notices which remain visible.
     */
    function collectThirdPartyNotices() {
        try {
            var wrap = document.querySelector('.wrap.museder-restoreone-admin');
            if (!wrap) {
                return;
            }

            var wpbody = document.getElementById('wpbody-content');
            if (!wpbody) {
                return;
            }

            var candidates = [];

            // 1) Notices above our wrap (typical WP notice placement).
            var children = Array.prototype.slice.call(wpbody.children || []);
            for (var i = 0; i < children.length; i++) {
                var el = children[i];
                if (el === wrap) {
                    break;
                }
                if (!el || el.nodeType !== 1) {
                    continue;
                }
                if (el.classList && (el.classList.contains('notice') || el.classList.contains('updated') || el.classList.contains('update-nag') || el.classList.contains('error'))) {
                    candidates.push(el);
                }
            }

            // 2) Notices rendered inside our wrap by other plugins/themes.
            var inside = wrap.querySelectorAll('.notice, .updated, .update-nag, .error');
            for (var k = 0; k < inside.length; k++) {
                candidates.push(inside[k]);
            }

            if (!candidates.length) {
                return;
            }

            // Deduplicate.
            var unique = [];
            var seen = new Set();
            for (var d = 0; d < candidates.length; d++) {
                var node = candidates[d];
                if (!node || node.nodeType !== 1) {
                    continue;
                }
                if (seen.has(node)) {
                    continue;
                }
                seen.add(node);
                unique.push(node);
            }

            var hidden = [];
            for (var j = 0; j < unique.length; j++) {
                var notice = unique[j];

                // Never move our own plugin message box.
                if (notice.id === 'museder-restoreone-messages' || notice.classList.contains('museder-restoreone-messages')) {
                    continue;
                }

                // Keep core update nags visible.
                if (notice.classList.contains('update-nag')) {
                    continue;
                }

                hidden.push(notice);
            }

            if (!hidden.length) {
                return;
            }

            var existing = document.getElementById('bl-hidden-notices');
            var wasOpen = false;
            if (existing && existing.tagName && existing.tagName.toLowerCase() === 'details') {
                wasOpen = !!existing.open;
            }
            if (existing && existing.parentNode) {
                existing.parentNode.removeChild(existing);
            }

            var details = document.createElement('details');
            details.id = 'bl-hidden-notices';
            details.className = 'bl-hidden-notices';
            details.open = wasOpen;

            var summary = document.createElement('summary');
            var label = getString('hiddenNoticesSummary', 'Hidden notices (%d)').replace('%d', String(hidden.length));
            summary.textContent = label;
            // Force toggle to avoid other scripts preventing native <details> behavior.
            summary.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                details.open = !details.open;
            }, true);

            var list = document.createElement('div');
            list.className = 'bl-hidden-notices__list';

            details.appendChild(summary);
            details.appendChild(list);

            // Insert at top of plugin wrap.
            wrap.insertBefore(details, wrap.firstChild);

            hidden.forEach(function (node) {
                list.appendChild(node);
            });
        } catch (e) {
            // Never break admin page due to notice handling.
        }
    }

    /**
     * Observe admin notices that may be inserted after initial page load and collect them.
     */
    function observeThirdPartyNotices() {
        var wrap = document.querySelector('.wrap.museder-restoreone-admin');
        var wpbody = document.getElementById('wpbody-content');
        if (!wrap || !wpbody || !window.MutationObserver) {
            return;
        }

        var pending = null;
        var schedule = function () {
            if (pending) {
                window.clearTimeout(pending);
            }
            pending = window.setTimeout(function () {
                pending = null;
                collectThirdPartyNotices();
            }, 150);
        };

        var observer = new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var m = mutations[i];
                if (!m || !m.addedNodes || !m.addedNodes.length) {
                    continue;
                }
                // If any added node looks like a notice or contains notices, schedule a collect.
                for (var j = 0; j < m.addedNodes.length; j++) {
                    var node = m.addedNodes[j];
                    if (!node || node.nodeType !== 1) {
                        continue;
                    }
                    if (node.classList && (node.classList.contains('notice') || node.classList.contains('updated') || node.classList.contains('update-nag') || node.classList.contains('error'))) {
                        schedule();
                        return;
                    }
                    if (node.querySelector && node.querySelector('.notice, .updated, .update-nag, .error')) {
                        schedule();
                        return;
                    }
                }
            }
        });

        observer.observe(wpbody, { childList: true, subtree: true });
    }

    function showCompletionOverlay(options) {
        var config = options || {};
        var icon = config.icon || '✅';
        var title = config.title || strings.successTitle || 'Operation completed';
        var message = config.message || '';
        var actionHref = config.actionHref || '';
        var actionText = config.actionText || strings.downloadLabel || 'Download';
        var actionCallback = typeof config.actionCallback === 'function' ? config.actionCallback : null;
        var confirmText = config.confirmText || '';
        var autoClose = typeof config.autoClose === 'number' ? config.autoClose : 0;
        var type = config.type || 'success'; // 'success' or 'error'

        // Check if we already have a final result (prevent duplicate modals)
        // This check is especially important for restore operations
        if (typeof restoreMonitor !== 'undefined' && restoreMonitor.hasFinalResult) {
            // If this is a success modal but we already have a failure result, don't show it
            if (type === 'success' && restoreMonitor.lastStatus === 'failed') {
                console.log('[Backup Lite] Skipping success modal - already have failure result');
                return;
            }
            // If this is a failure modal but we already have a success result, don't show it
            if (type === 'error' && restoreMonitor.lastStatus === 'success') {
                console.log('[Backup Lite] Skipping failure modal - already have success result');
                return;
            }
        }

        var existing = document.querySelector('.bl-completion-overlay');
        if (existing && existing.parentNode) {
            existing.parentNode.removeChild(existing);
        }

        var overlay = document.createElement('div');
        overlay.className = 'bl-completion-overlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-live', 'assertive');

        var dialog = document.createElement('div');
        dialog.className = 'bl-completion-dialog';
        if (type === 'error') {
            dialog.classList.add('bl-completion-error');
        }

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'bl-completion-close';
        closeBtn.setAttribute('data-bl-completion-close', '1');
        closeBtn.textContent = strings.close || 'Close';
        dialog.appendChild(closeBtn);

        var iconEl = document.createElement('div');
        iconEl.className = 'bl-completion-icon';
        iconEl.textContent = icon;
        dialog.appendChild(iconEl);

        var titleEl = document.createElement('div');
        titleEl.className = 'bl-completion-title';
        titleEl.textContent = title;
        dialog.appendChild(titleEl);

        if (message) {
            var messageEl = document.createElement('div');
            messageEl.className = 'bl-completion-message';
            // Allow multi-line messages (e.g. skipped files summary) without using HTML injection.
            messageEl.style.whiteSpace = 'pre-line';
            messageEl.textContent = message;
            dialog.appendChild(messageEl);
        }

        if (actionHref || actionCallback || confirmText) {
            var actionsEl = document.createElement('div');
            actionsEl.className = 'bl-completion-actions';

            if (actionHref && actionHref !== '') {
                var actionBtn = document.createElement('button');
                actionBtn.type = 'button';
                actionBtn.className = 'button button-primary';
                actionBtn.textContent = actionText;
                
                // Check if download link is expired before allowing download
                actionBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    if (!actionHref || actionHref === '') {
                        alert(strings.downloadUnavailable || 'Download link is not available. Please download from the backup library.');
                        // Optionally redirect to backups page
                        if (window.location.href.indexOf('page=museder-restoreone-backups') === -1) {
                            var backupsUrl = window.location.href.replace(/page=[^&]*/, 'page=museder-restoreone-backups');
                            if (backupsUrl === window.location.href) {
                                backupsUrl += (window.location.href.indexOf('?') === -1 ? '?' : '&') + 'page=museder-restoreone-backups';
                            }
                            window.location.href = backupsUrl;
                        }
                        return false;
                    }

                    // Some URLs are HTML-escaped when returned from PHP (e.g. &amp; / &#038;).
                    // Decode them before parsing or navigating, otherwise WP will treat them as invalid params.
                    var decodedHref = String(actionHref).replace(/&amp;/g, '&').replace(/&#038;/g, '&');
                    
                    try {
                        // Use window.location.origin as base for relative URLs
                        var url = new URL(decodedHref, window.location.origin);
                        var expires = parseInt(url.searchParams.get('expires'), 10);
                        if (expires && expires < Math.floor(Date.now() / 1000)) {
                            alert(strings.downloadExpired || 'Your download link has expired. Please download from the backup library.');
                            // Optionally redirect to backups page
                            if (window.location.href.indexOf('page=museder-restoreone-backups') === -1) {
                                var backupsUrl2 = window.location.href.replace(/page=[^&]*/, 'page=museder-restoreone-backups');
                                if (backupsUrl2 === window.location.href) {
                                    backupsUrl2 += (window.location.href.indexOf('?') === -1 ? '?' : '&') + 'page=museder-restoreone-backups';
                                }
                                window.location.href = backupsUrl2;
                            }
                            return false;
                        }
                    } catch (err) {
                        // If URL parsing fails, allow the download to proceed
                        // This handles nonce-based download URLs or relative URLs
                        console.warn('[Backup Lite] Could not parse download URL, allowing download to proceed', err);
                    }
                    
                    // Use current window to trigger download (not new tab)
                    window.location.href = decodedHref;
                });
                
                actionsEl.appendChild(actionBtn);
            } else if (actionCallback) {
                var actionBtn2 = document.createElement('button');
                actionBtn2.type = 'button';
                actionBtn2.className = 'button button-primary';
                actionBtn2.textContent = actionText;
                actionBtn2.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();
                    try {
                        actionCallback();
                    } catch (err) {
                        // No-op: overlay action should never crash UI.
                    }
                });
                actionsEl.appendChild(actionBtn2);
            }

            if (confirmText) {
                var confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = actionHref ? 'button' : 'button button-primary';
                confirmBtn.textContent = confirmText;
                confirmBtn.addEventListener('click', function () {
                    teardown();
                });
                actionsEl.appendChild(confirmBtn);
            }

            dialog.appendChild(actionsEl);
        }

        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        function teardown() {
            document.removeEventListener('keydown', onKeyDown);
            if (overlay && overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
            
            // Reset progress bar and restore state when overlay is closed
            // This ensures the UI is ready for the next restore
            if (typeof window.MusederRestoreOneUI !== 'undefined' && typeof window.MusederRestoreOneUI.resetRestoreProgress === 'function') {
                window.MusederRestoreOneUI.resetRestoreProgress();
            }
            
            // Refresh page when overlay is closed to ensure clean state
            if (typeof window.location !== 'undefined') {
                // Small delay to allow reset function to execute
                setTimeout(function() {
                    window.location.reload();
                }, 100);
            }
        }

        function onKeyDown(event) {
            if (event.key === 'Escape') {
                teardown();
            }
        }

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay || event.target.dataset.blCompletionClose !== undefined) {
                teardown();
            }
        });

        closeBtn.addEventListener('click', teardown);
        document.addEventListener('keydown', onKeyDown);

        window.requestAnimationFrame(function () {
            overlay.classList.add('is-visible');
        });

        if (autoClose > 0) {
            setTimeout(teardown, autoClose);
        }
    }

    function renderLogTable(logs) {
        var tableBody = document.getElementById('bl-log-table-body');
        if (!tableBody) {
            return false;
        }

        tableBody.innerHTML = '';

        if (!logs.length) {
            var emptyRow = document.createElement('tr');
            emptyRow.className = 'bl-empty-row';
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 4;
            emptyCell.textContent = strings.noLogs || 'No log entries yet.';
            emptyRow.appendChild(emptyCell);
            tableBody.appendChild(emptyRow);
            return true;
        }

        logs.forEach(function (log) {
            var row = document.createElement('tr');
            row.dataset.log = log.name;

            var nameCell = document.createElement('td');
            nameCell.textContent = log.name;

            var modifiedCell = document.createElement('td');
            modifiedCell.textContent = log.modified || '';

            var sizeCell = document.createElement('td');
            sizeCell.textContent = log.size || '';

            var actionsCell = document.createElement('td');
            var menu = document.createElement('details');
            menu.className = 'bl-actions-menu';

            var trigger = document.createElement('summary');
            trigger.className = 'bl-actions-trigger';
            trigger.setAttribute('aria-label', 'Log actions');
            trigger.textContent = '⋮';

            var list = document.createElement('div');
            list.className = 'bl-actions-list';

            var viewBtn = document.createElement('button');
            viewBtn.type = 'button';
            viewBtn.className = 'button';
            viewBtn.dataset.logAction = 'view';
            viewBtn.dataset.log = log.name;
            viewBtn.textContent = strings.viewLog || 'Preview';

            var downloadLink = document.createElement('button');
            downloadLink.type = 'button';
            downloadLink.className = 'button';
            downloadLink.dataset.logAction = 'download';
            downloadLink.dataset.log = log.name;
            downloadLink.textContent = strings.downloadLog || 'Download';

            var deleteBtn = document.createElement('button');
            deleteBtn.type = 'button';
            deleteBtn.className = 'button';
            deleteBtn.dataset.logAction = 'delete';
            deleteBtn.dataset.log = log.name;
            deleteBtn.textContent = strings.deleteLog || 'Delete';

            list.appendChild(viewBtn);
            list.appendChild(downloadLink);
            list.appendChild(deleteBtn);

            menu.appendChild(trigger);
            menu.appendChild(list);
            actionsCell.appendChild(menu);

            row.appendChild(nameCell);
            row.appendChild(modifiedCell);
            row.appendChild(sizeCell);
            row.appendChild(actionsCell);

            tableBody.appendChild(row);
        });

        return true;
    }

    function refreshLogs() {
        return $.post(settings.ajaxUrl, {
            action: 'museder_restoreone_fetch_logs',
            nonce: settings.nonce
        }).done(function (resp) {
            if (!resp.success) {
                return;
            }

            const logs = resp.data.logs || [];

            if (renderLogTable(logs)) {
                return;
            }

            const list = $('.museder-restoreone-logs ul');
            if (!list.length) {
                return;
            }

            if (!logs.length) {
                list.html('<li>' + (strings.noLogs || '') + '</li>');
                return;
            }

            const items = logs.map(function (log) {
                return '<li><a href="' + log.download_url + '">' + log.name + '</a></li>';
            });

            list.html(items.join(''));
        });
    }

    // Function to reset restore progress bar and state
    function resetRestoreProgress() {
        // Reset restore monitor state
        if (typeof restoreMonitor !== 'undefined') {
            restoreMonitor.jobId = null;
            restoreMonitor.archive = null;
            restoreMonitor.hasFinalResult = false;
            restoreMonitor.lastStatus = null;
        }
        // Reset progress bar to 0%
        var progressBar = document.querySelector('.restore-progress .progress-bar-fill');
        var progressFill = document.getElementById('restore-progress-fill');
        var progressText = document.getElementById('restore-progress-text');
        var progressContainer = document.getElementById('restore-progress-container');
        var waitingMessage = document.getElementById('restore-waiting-message');
        
        if (progressFill) {
            progressFill.style.width = '0%';
        }
        if (progressBar) {
            progressBar.style.width = '0%';
        }
        if (progressText) {
            progressText.textContent = '0%';
        }
        if (progressContainer) {
            progressContainer.style.display = 'none';
        }
        if (waitingMessage) {
            waitingMessage.style.display = 'block';
        }
        
        // Reset status elements
        var statusIcon = document.getElementById('restore-status-icon');
        var statusTitle = document.getElementById('restore-status-title');
        var statusTitleText = document.getElementById('restore-status-title-text');
        var statusMessage = document.getElementById('restore-status-message');
        var progressStatus = document.querySelector('.restore-progress .progress-status');
        
        if (statusIcon) {
            statusIcon.textContent = '';
        }
        if (statusTitle) {
            statusTitle.style.color = '';
            statusTitle.textContent = '';
        }
        if (statusTitleText) {
            statusTitleText.textContent = '';
        }
        if (statusMessage) {
            statusMessage.textContent = '';
        }
        if (progressStatus) {
            progressStatus.textContent = '';
        }
    }

    window.MusederRestoreOneUI = {
        showMessage: showMessage,
        handleError: handleError,
        refreshLogs: refreshLogs,
        settings: settings,
        strings: strings,
        spinner: spinnerMarkup,
        showToast: showToast,
        showCompletionOverlay: showCompletionOverlay,
        resetRestoreProgress: resetRestoreProgress
    };

    // Reduced minimum polling delay from 2500ms to 1500ms for better responsiveness
    // Dynamic polling interval based on backup progress
    // Default: 2 seconds, adjusted based on progress:
    // - 0-10%: 2 seconds (frequent, user needs to see progress)
    // - 10-90%: 3 seconds (reduce requests)
    // - 90-99%: 1.5 seconds (frequent, preparing for completion)
    backupJobContext.pollDelay = Math.max(2000, (settings.jobPollingInterval || 2.0) * 1000);
    backupJobContext.getPollDelay = function(percentage) {
        if (typeof percentage !== 'number' || isNaN(percentage)) {
            return backupJobContext.pollDelay;
        }
        
        if (percentage < 10) {
            // Early stage: 2 seconds (frequent, user needs to see progress)
            return 2000;
        } else if (percentage >= 10 && percentage < 90) {
            // Middle stage: 3 seconds (reduce requests)
            return 3000;
        } else {
            // Near completion (90-99%): 1.5 seconds (frequent, preparing for completion)
            return 1500;
        }
    };

    var backupFormEl = null;
    var backupProgressContainerEl = document.getElementById('backup-progress-container');
    var backupProgressEl = document.getElementById('backup-progress-fill');
    var backupProgressText = document.getElementById('backup-progress-text');
    var backupSubmitBtn = null;
    var backupCancelBtn = document.getElementById('bl-backup-cancel-btn');

    function setBackupFormElements(formEl) {
        backupFormEl = formEl;
        backupSubmitBtn = backupFormEl ? backupFormEl.querySelector('button[type="submit"]') : null;
        resetBackupProgress();
        setBackupCancelable(false);

        // Bind cancel button once.
        if (backupCancelBtn && !backupCancelBtn.dataset.blBound) {
            backupCancelBtn.dataset.blBound = '1';
            backupCancelBtn.addEventListener('click', function (e) {
                if (e && typeof e.preventDefault === 'function') {
                    e.preventDefault();
                }
                cancelBackupJob();
            });
        }
    }

    function resetBackupProgress() {
        if (backupProgressContainerEl) {
            backupProgressContainerEl.classList.remove('is-indeterminate');
            backupProgressContainerEl.setAttribute('aria-valuenow', '0');
            backupProgressContainerEl.setAttribute('aria-valuetext', '0%');
        }
        if (backupProgressEl) {
            backupProgressEl.style.width = '0%';
        }
        if (backupProgressText) {
            backupProgressText.textContent = '0%';
        }
        musederRestoreOneTimer.reset(); // Reset elapsed time timer
    }

    function updateBackupProgress(percent, options) {
        options = options || {};
        var mode = String(options.mode || 'determinate');
        var isIndeterminate = mode === 'indeterminate';

        if (backupProgressContainerEl) {
            if (isIndeterminate) {
                backupProgressContainerEl.classList.add('is-indeterminate');
                backupProgressContainerEl.removeAttribute('aria-valuenow');
                backupProgressContainerEl.setAttribute(
                    'aria-valuetext',
                    strings.progressMeasuringAria || 'Measuring progress'
                );
            } else {
                backupProgressContainerEl.classList.remove('is-indeterminate');
            }
        }

        if (!backupProgressEl) {
            return;
        }

        if (isIndeterminate) {
            backupProgressEl.style.width = '100%';
            if (backupProgressText) {
                backupProgressText.textContent = strings.progressMeasuring || 'Measuring...';
            }
            return;
        }

        // Keep one decimal for smoother bar motion while avoiding float artifacts.
        var value = Math.max(0, Math.min(100, percent || 0));
        value = Math.round(value * 10) / 10;
        backupProgressEl.style.width = value + '%';
        if (backupProgressContainerEl) {
            backupProgressContainerEl.setAttribute('aria-valuenow', String(value));
            backupProgressContainerEl.setAttribute('aria-valuetext', value + '%');
        }
        if (backupProgressText) {
            backupProgressText.textContent = value + '%';
        }
    }

    function setBackupBusy(isBusy) {
        if (!backupSubmitBtn) {
            return;
        }
        if (!backupSubmitBtn.dataset.originalLabel) {
            backupSubmitBtn.dataset.originalLabel = backupSubmitBtn.textContent;
        }
        backupSubmitBtn.disabled = !!isBusy;
        backupSubmitBtn.textContent = isBusy
            ? (strings.jobButtonBusy || backupSubmitBtn.dataset.originalLabel)
            : (strings.jobButtonIdle || backupSubmitBtn.dataset.originalLabel);
    }

    function setBackupCancelable(active) {
        if (!backupCancelBtn) {
            return;
        }
        if (active) {
            backupCancelBtn.style.display = '';
            backupCancelBtn.disabled = false;
        } else {
            backupCancelBtn.style.display = 'none';
            backupCancelBtn.disabled = true;
        }
    }

    function cancelBackupJob() {
        if (!backupJobContext.current || !backupJobContext.current.id || !settings.nonce) {
            setBackupCancelable(false);
            return;
        }

        if (backupCancelBtn) {
            backupCancelBtn.disabled = true;
        }

        var payload = new FormData();
        payload.append('action', 'museder_restoreone_cancel_backup_job');
        payload.append('nonce', settings.nonce);
        payload.append('job_id', backupJobContext.current.id);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json || json.success !== true) {
                throw json && json.data ? json.data : json;
            }
            stopBackupJobPolling();
            setBackupBusy(false);
            backupJobContext.current = null;
            setBackupCancelable(false);
            musederRestoreOneTimer.stop(); // Stop elapsed time timer
            resetBackupProgress();
            // Clear the processing banner/message without requiring a full page reload.
            if (messages && messages.length) {
                messages.removeClass('is-visible is-success is-error');
                messages.empty();
            }
            var statusEl = document.getElementById('bl-backup-mode-status');
            if (statusEl) {
                statusEl.textContent = '';
            }
            showToast(strings.jobCancelSuccess || 'Backup cancelled.', 'warning');
            verifyCancelledJobState(payload.get('job_id'));
        }).catch(function (error) {
            var message = (error && error.message) ? error.message : (strings.jobCancelFailed || 'Unable to cancel backup.');
            showToast(message, 'error');
            setBackupCancelable(!!(backupJobContext.current && backupJobContext.current.id));
        });
    }

    function verifyCancelledJobState(jobId) {
        if (!jobId || !settings.nonce || !settings.ajaxUrl) {
            return;
        }
        window.setTimeout(function () {
            var payload = new FormData();
            payload.append('action', 'museder_restoreone_get_job_status');
            payload.append('nonce', settings.nonce);
            payload.append('job_id', String(jobId));

            fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('Network response was not ok: ' + response.status);
                }
                return response.json();
            }).then(function (json) {
                if (!json || !json.success || !json.data || !json.data.job) {
                    return;
                }
                var latest = json.data.job;
                if (String(latest.id || '') === String(jobId) && String(latest.status || '') === 'running') {
                    showToast(strings.jobCancelStillRunning || 'Cancellation requested, but this job is still running. Please refresh and check WP-Cron status.', 'warning');
                }
            }).catch(function () {
                // Best-effort verification only.
            });
        }, 3000);
    }

    function appendBackupOptions(payload) {
        if (!backupFormEl) {
            return;
        }

        // Performance options (available in Free & Pro)
        var modeField = backupFormEl.querySelector('#bl-backup-mode');
        if (modeField && modeField.value) {
            payload.append('backup_mode', String(modeField.value));
        }

        var smartExcludeField = backupFormEl.querySelector('#bl-backup-smart-exclude');
        if (smartExcludeField && smartExcludeField.value) {
            payload.append('backup_smart_exclude', String(smartExcludeField.value));
        }

        var customExcludesField = backupFormEl.querySelector('#bl-backup-custom-excludes');
        if (customExcludesField && typeof customExcludesField.value === 'string' && customExcludesField.value.trim()) {
            payload.append('backup_custom_excludes', customExcludesField.value);
        }

        // PRO options
        var roAddon = window.MusederRestoreOneAddon || window.MusederRestoreOnePro;
        if (!roAddon || !roAddon.isPro) {
            return;
        }

        var labelField = backupFormEl.querySelector('#bl-backup-label');
        if (labelField && labelField.value) {
            payload.append('backup_label', labelField.value);
        }

        var encryptToggle = backupFormEl.querySelector('#bl-backup-encrypt');
        if (encryptToggle && encryptToggle.checked) {
            payload.append('backup_encrypt', '1');
        }

        var dualToggle = backupFormEl.querySelector('#bl-backup-dual');
        if (dualToggle && dualToggle.checked) {
            payload.append('backup_dual', '1');
        }

        var cloudSelect = backupFormEl.querySelector('#bl-backup-cloud');
        if (cloudSelect && cloudSelect.options) {
            var selected = Array.from(cloudSelect.selectedOptions || [])
                .map(function (opt) { return opt.value; })
                .filter(Boolean);
            if (selected.length) {
                payload.append('backup_cloud', JSON.stringify(selected));
            }
        }
    }

    function setBackupStatusMessage(message, type) {
        if (!message) {
            return;
        }
        var rendered = message;
        if ('loading' === type) {
            rendered += ' ' + spinnerMarkup;
        }
        showMessage('', strings.runningTitle || '', rendered);
    }

    function handleJobResponse(job) {
        backupJobContext.current = job;
        var rawPct = (typeof job.overall_progress === 'number') ? job.overall_progress : (job.percentage || 0);
        var hasProgressContract = typeof job.progress_mode === 'string';
        var isIndeterminate = hasProgressContract && job.progress_mode === 'indeterminate';
        var progressMode = isIndeterminate ? 'indeterminate' : 'determinate';
        var allowStallSmoothing = !hasProgressContract;
        var smoothed = backupProgressSmoother.apply(
            rawPct,
            job.stage || '',
            job.status || '',
            job,
            function (p) { updateBackupProgress(p, { mode: progressMode }); },
            { allowStallSmoothing: allowStallSmoothing }
        );
        updateBackupProgress(smoothed, { mode: progressMode });
        setBackupCancelable(true);
        updateBackupModeStatus(job);

        if ('completed' === job.status) {
            backupProgressSmoother.stop();
            setBackupCancelable(false);
            finishBackupJob(job);
            return;
        }

        if ('failed' === job.status) {
            backupProgressSmoother.stop();
            stopBackupJobPolling();
            musederRestoreOneTimer.stop(); // Stop elapsed time timer
            setBackupBusy(false);
            handleError({ message: job.message || strings.jobFailed || strings.errorGeneric });
            backupJobContext.current = null;
            setBackupCancelable(false);
            return;
        }

        if ('cancelled' === job.status) {
            backupProgressSmoother.stop();
            stopBackupJobPolling();
            musederRestoreOneTimer.stop(); // Stop elapsed time timer
            setBackupBusy(false);
            showMessage('', '', strings.jobCancelled || '');
            backupJobContext.current = null;
            setBackupCancelable(false);
            return;
        }

        var stageMessage = strings.jobProcessing || strings.runningMessage || '';
        if ('preparing' === job.stage || 'pending' === job.stage) {
            stageMessage = strings.jobPreparing || stageMessage;
        } else if ('packing' === job.stage) {
            if (String(job.pack_method || '') === 'pclzip' && job.repack_attempted) {
                var processedFiles = Number(job.processed_files || 0);
                var totalFiles = Number(job.total_files || 0);
                if (isNaN(processedFiles)) processedFiles = 0;
                if (isNaN(totalFiles)) totalFiles = 0;
                stageMessage = 'Compatibility repack in progress: ' + processedFiles + ' / ' + totalFiles + ' files';
            } else {
                stageMessage = strings.jobProcessing || stageMessage;
            }
        } else if ('finalizing' === job.stage || 'completed' === job.stage) {
            stageMessage = strings.jobFinalizing || stageMessage;
        }
        if (hasProgressContract) {
            var stageDone = Number(job.stage_done || 0);
            var stageTotal = Number(job.stage_total || 0);
            if (isIndeterminate) {
                stageMessage += ' (' + (strings.progressMeasuringSuffix || 'progress: measuring...') + ')';
            } else if (!isNaN(stageDone) && !isNaN(stageTotal) && stageTotal > 0) {
                stageMessage += ' (' + stageDone + ' / ' + stageTotal + ')';
            }
        }
        if (job.large_artifact_warnings && job.large_artifact_warnings.length) {
            stageMessage += '\nDetected large existing backup artifacts in scope. Consider Smart Exclude or custom excludes to speed up packing.';
        }
        setBackupStatusMessage(stageMessage, 'loading');

        maybeWatchdogNudge(job);
        if (!job.processing && job.id) {
            maybeNudgeBackupJob(job.id);
        }
    }

    // Watchdog: if cron/worker is not progressing, nudge via AJAX continue.
    backupJobContext.lastSeenBytes = backupJobContext.lastSeenBytes || null;
    backupJobContext.lastSeenAt = backupJobContext.lastSeenAt || 0;
    backupJobContext.lastWatchdogNudgeAt = backupJobContext.lastWatchdogNudgeAt || 0;

    function maybeWatchdogNudge(job) {
        if (!job || !job.id) {
            return;
        }
        if (job.status !== 'running' || job.stage !== 'packing') {
            return;
        }

        var nowMs = Date.now();
        var processedBytes = typeof job.processed_bytes === 'number' ? job.processed_bytes : parseInt(job.processed_bytes || 0, 10);
        if (isNaN(processedBytes)) {
            processedBytes = 0;
        }

        // Initialize baseline on first status.
        if (backupJobContext.lastSeenBytes === null) {
            backupJobContext.lastSeenBytes = processedBytes;
            backupJobContext.lastSeenAt = nowMs;
            return;
        }

        if (processedBytes > backupJobContext.lastSeenBytes) {
            backupJobContext.lastSeenBytes = processedBytes;
            backupJobContext.lastSeenAt = nowMs;
            return;
        }

        // If last_activity is old, treat as stuck even if job.processing=true.
        var lastActivity = typeof job.last_activity === 'number' ? job.last_activity : parseInt(job.last_activity || 0, 10);
        var lastActivityAgeMs = lastActivity > 0 ? Math.max(0, nowMs - (lastActivity * 1000)) : 0;

        var noProgressMs = nowMs - (backupJobContext.lastSeenAt || 0);
        var shouldNudge = (noProgressMs > 20000) || (lastActivityAgeMs > 60000);

        // Don't spam: at most once every 10s.
        if (shouldNudge && (nowMs - (backupJobContext.lastWatchdogNudgeAt || 0) > 10000)) {
            backupJobContext.lastWatchdogNudgeAt = nowMs;
            maybeNudgeBackupJob(job.id);
        }
    }

    function updateBackupModeStatus(job) {
        var statusEl = document.getElementById('bl-backup-mode-status');
        if (!statusEl) {
            return;
        }
        if (!job) {
            statusEl.textContent = '';
            return;
        }

        var mode = job.backup_mode || '';
        var smart = job.smart_exclude || '';
        var labelPrefix = strings.backupModeLabel || 'Mode';

        var modeLabel = strings.backupModeUnknown || '—';
        if ('fast' === mode) {
            modeLabel = strings.backupModeFast || 'Fast';
        } else if ('balanced' === mode) {
            modeLabel = strings.backupModeBalanced || 'Balanced';
        } else if (mode) {
            modeLabel = String(mode);
        }

        var smartLabel = '';
        if ('on' === smart) {
            smartLabel = strings.smartExcludeOn || 'Smart Exclude: On';
        } else if ('off' === smart) {
            smartLabel = strings.smartExcludeOff || 'Smart Exclude: Off';
        }

        var parts = [];
        parts.push(labelPrefix + ': ' + modeLabel);
        if (smartLabel) {
            parts.push(smartLabel);
        }

        if (job.auto_applied && job.large_site_detected && 'fast' === mode) {
            parts.push(strings.backupModeAutoSwitched || 'Auto enabled Fast mode for a large site.');
        }

        statusEl.textContent = parts.join(' · ');
    }

    function maybeNudgeBackupJob(jobId) {
        var now = Date.now();
        if (now - backupJobContext.lastNudge < backupJobContext.pollDelay) {
            return;
        }
        backupJobContext.lastNudge = now;

        var payload = new FormData();
        payload.append('action', 'museder_restoreone_continue_backup_job');
        payload.append('nonce', settings.nonce);
        payload.append('job_id', jobId);

        // Create AbortController for timeout handling
        // Use 30 seconds to match backend time budget (25s) + buffer
        const REQUEST_TIMEOUT_MS = 30000; // 30 seconds
        var controller = new AbortController();
        var timeoutId = setTimeout(function() {
            controller.abort();
        }, REQUEST_TIMEOUT_MS);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload,
            signal: controller.signal
        }).then(function (response) {
            clearTimeout(timeoutId);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        }).then(function (json) {
            clearTimeout(timeoutId);
            if (json && json.success && json.data && json.data.job) {
                handleJobResponse(json.data.job);
            }
        }).catch(function (error) {
            clearTimeout(timeoutId);
            // Improved error handling with clearer messages
            if (error.name === 'AbortError') {
                // Request was aborted by timeout - silent fallback for nudge
                // Nudge is best effort, don't log timeout errors
            } else if (error.message && !error.message.includes('timeout') && !error.message.includes('network')) {
                // Only log non-timeout/network errors
                console.warn('[Backup Lite] Nudge request failed:', error.message || error);
            }
        });
    }

    // Flag to prevent overlapping polling requests
    var backupLiteIsPolling = false;
    var backupLitePollTimer = null;
    var backupLitePollController = null; // Store controller for potential abort
    var backupLitePollAborted = false; // Track if current request was aborted

    function pollBackupJobStatus() {
        // Check if job exists and is still running
        if (!backupJobContext.current || !backupJobContext.current.id) {
            stopBackupJobPolling();
            return;
        }

        // Check if job is already finished
        if (backupJobContext.current.status && 
            ['completed', 'failed', 'cancelled'].includes(backupJobContext.current.status)) {
            stopBackupJobPolling();
            return;
        }

        // Prevent overlapping requests - if already polling, just return
        if (backupLiteIsPolling) {
            return;
        }
        
        // Set polling flag
        backupLiteIsPolling = true;
        backupLitePollAborted = false; // Reset abort flag

        // Abort previous request if still pending (shouldn't happen, but safety check)
        if (backupLitePollController) {
            backupLitePollController.abort();
            backupLitePollController = null;
        }

        // Create AbortController for timeout handling
        // Use 30 seconds to match backend time budget (25s) + buffer
        const REQUEST_TIMEOUT_MS = 30000; // 30 seconds
        backupLitePollController = new AbortController();
        var timeoutId = setTimeout(function() {
            if (backupLitePollController) {
                backupLitePollAborted = true; // Mark as aborted
                backupLitePollController.abort();
            }
        }, REQUEST_TIMEOUT_MS);

        // Use WordPress-standard AJAX approach with fetch API
        var payload = new FormData();
        // Keep the legacy server action name for maximum compatibility across hosts.
        payload.append('action', 'museder_restoreone_get_job_status');
        payload.append('nonce', settings.nonce);
        payload.append('job_id', backupJobContext.current.id);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload,
            signal: backupLitePollController.signal
        }).then(function (response) {
            clearTimeout(timeoutId);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        }).then(function (json) {
            clearTimeout(timeoutId);
            if (!json || !json.success || !json.data || !json.data.job) {
                throw json && json.data ? json.data : json;
            }
            var job = json.data.job;
            handleJobResponse(job);
            
            // Update polling delay based on progress
            if (job && typeof job.percentage === 'number') {
                backupJobContext.pollDelay = backupJobContext.getPollDelay(job.percentage);
            }
        }).catch(function (error) {
            clearTimeout(timeoutId);
            
            // Check if this was an abort (normal case - page reload, manual abort, timeout)
            var isAbort = false;
            if (error && (
                error.name === 'AbortError' || 
                (error.message && (
                    error.message === 'The user aborted a request.' ||
                    error.message === 'signal is aborted without reason' ||
                    error.message.includes('aborted')
                ))
            )) {
                isAbort = true;
            }
            
            // If aborted, treat as normal - don't log as error
            if (isAbort) {
                // Normal abort - use debug if available, but don't pollute console
                if (window.console && console.debug) {
                    console.debug('[Backup Lite] Polling aborted (normal)');
                }
                // Mark as aborted so finally block knows not to schedule next poll
                backupLitePollAborted = true;
                return;
            }
            
            // Network/timeout errors - log warning but continue polling
            if (error.message && (
                error.message.includes('timeout') || 
                error.message.includes('network') ||
                error.message.includes('Failed to fetch') ||
                error.message.includes('ERR_CONNECTION')
            )) {
                // Network/timeout error - continue polling but log warning
                if (window.console && console.warn) {
                    console.warn('[Backup Lite] Polling request failed, will retry: ' + error.message);
                }
            } else {
                // Other errors - log and stop polling
                if (window.console && console.error) {
                    console.error('[Backup Lite] Polling request failed:', error);
                }
                stopBackupJobPolling();
                setBackupBusy(false);
                handleError(error && error.message ? error : null);
                backupJobContext.current = null;
                return;
            }
        }).finally(function() {
            // Always reset the polling flag
            backupLiteIsPolling = false;
            backupLitePollController = null;
            
            // Only schedule next poll if:
            // 1. Request was NOT aborted (aborted requests should not trigger next poll)
            // 2. Job still exists and is still running
            // 3. Job is not in a finished state
            if (!backupLitePollAborted && 
                backupJobContext.current && 
                backupJobContext.current.id && 
                backupJobContext.current.status && 
                !['completed', 'failed', 'cancelled'].includes(backupJobContext.current.status)) {
                // Clear any existing timer
                if (backupJobContext.timer) {
                    clearTimeout(backupJobContext.timer);
                }
                // Schedule next poll after delay (dynamically adjusted based on progress)
                backupJobContext.timer = setTimeout(pollBackupJobStatus, backupJobContext.pollDelay);
            }
            
            // Reset abort flag for next request
            backupLitePollAborted = false;
        });
    }

    function scheduleBackupJobPolling(immediate) {
        // Stop any existing polling first
        stopBackupJobPolling();
        
        // Reset abort flag
        backupLitePollAborted = false;
        
        // Check if we have a valid job to poll
        if (!backupJobContext.current || !backupJobContext.current.id) {
            return;
        }
        
        if (immediate) {
            // Start polling immediately
            pollBackupJobStatus();
        } else {
            // Schedule first poll after delay
            backupJobContext.timer = setTimeout(pollBackupJobStatus, backupJobContext.pollDelay);
        }
    }

    function stopBackupJobPolling() {
        // Clear timer first
        if (backupJobContext.timer) {
            clearTimeout(backupJobContext.timer);
            backupJobContext.timer = null;
        }

        // Stop any smoothing timer.
        if (backupProgressSmoother) {
            backupProgressSmoother.stop();
        }
        
        // Abort any pending request
        if (backupLitePollController) {
            backupLitePollAborted = true; // Mark as aborted so finally block doesn't schedule next poll
            backupLitePollController.abort();
            backupLitePollController = null;
        }
        
        // Reset polling flag
        backupLiteIsPolling = false;
        backupLitePollAborted = false;
    }

    function startBackupJobRequest(event) {
        if (event && typeof event.preventDefault === 'function') {
            event.preventDefault();
        }

        if (!backupFormEl) {
            return false;
        }

        if (backupJobContext.current && backupJobContext.current.status && backupJobContext.current.status !== 'failed' && backupJobContext.current.status !== 'completed') {
            showToast(strings.jobButtonBusy || 'Backup in progress…', 'info');
            return false;
        }

        const payload = new FormData();
        payload.append('action', 'museder_restoreone_start_backup_job');
        payload.append('nonce', settings.nonce);
        appendBackupOptions(payload);

        setBackupBusy(true);
        resetBackupProgress();
        setBackupStatusMessage(strings.jobPreparing || strings.runningMessage || '', 'loading');

        // Create AbortController for timeout handling
        var controller = new AbortController();
        var timeoutId = setTimeout(function() {
            controller.abort();
        }, 60000); // 60 second timeout for initial request

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload,
            signal: controller.signal
        }).then(function (response) {
            clearTimeout(timeoutId);
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.status);
            }
            return response.json();
        }).then(function (json) {
            if (!json || !json.success || !json.data || !json.data.job) {
                throw json && json.data ? json.data : json;
            }
            // Normalize via the same handler used by polling for consistent UI behavior.
            handleJobResponse(json.data.job);
            musederRestoreOneTimer.start(); // Start elapsed time timer
            scheduleBackupJobPolling(true);
        }).catch(function (error) {
            clearTimeout(timeoutId);
            // If the initial request timed out, the job may still have been created and is preparing in background.
            // Try to recover by fetching the active job and resuming polling.
            var errorMessage = error && error.message ? error.message : null;
            var isTimeout = (error && error.name === 'AbortError') || (errorMessage && (
                errorMessage.includes('timeout') ||
                errorMessage.includes('network') ||
                errorMessage.includes('Failed to fetch')
            ));

            if (!isTimeout || !settings.nonce) {
                setBackupBusy(false);
                backupJobContext.current = null;
                resetBackupProgress();
                setBackupCancelable(false);
                if (isTimeout) {
                    errorMessage = 'Connection timeout. Please check your network connection and try again.';
                }
                handleError(errorMessage ? { message: errorMessage } : error);
                return;
            }

            // Keep UI in "busy" mode and attempt recovery.
            setBackupBusy(true);
            setBackupStatusMessage(strings.jobPreparing || strings.runningMessage || 'Preparing backup…', 'loading');
            showToast(strings.jobPreparing || 'Preparing backup…', 'info');

            var recoverPayload = new FormData();
            recoverPayload.append('action', 'museder_restoreone_get_active_backup_job');
            recoverPayload.append('nonce', settings.nonce);

            fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: recoverPayload
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                if (!json || json.success !== true || !json.data || !json.data.job) {
                    throw json && json.data ? json.data : json;
                }
                handleJobResponse(json.data.job);
                musederRestoreOneTimer.start();
                scheduleBackupJobPolling(true);
            }).catch(function (recoverError) {
                setBackupBusy(false);
                backupJobContext.current = null;
                resetBackupProgress();
                setBackupCancelable(false);
                handleError({ message: 'Connection timeout. Please refresh the page to check backup status.' });
            });
        });

        return false;
    }

    // Guard against duplicate completion overlays for the same job (e.g., due to retries/polling).
    var lastCompletedBackupJobId = null;

    function finishBackupJob(job) {
        if (job && job.id) {
            if (lastCompletedBackupJobId === job.id) {
                return;
            }
            lastCompletedBackupJobId = job.id;
        }
        stopBackupJobPolling();
        musederRestoreOneTimer.stop(); // Stop elapsed time timer
        setBackupBusy(false);
        backupJobContext.current = null;
        setBackupCancelable(false);
        updateBackupProgress(100);

        var overlayTitle = strings.backupOverlayTitle || strings.successTitle || 'Backup completed';
        var overlayMessage = (job && job.message) ? String(job.message) : (strings.jobComplete || strings.successBackup || 'Backup completed successfully.');

        // If files were skipped, provide a short, actionable summary in the completion overlay.
        if (job && Number(job.skipped_files || 0) > 0) {
            var lines = [];
            lines.push(overlayMessage);
            lines.push('');
            lines.push('Skipped files: ' + String(job.skipped_files));

            var reasons = job.skip_reasons || {};
            var reasonParts = [];
            try {
                Object.keys(reasons).forEach(function (key) {
                    if (!Object.prototype.hasOwnProperty.call(reasons, key)) {
                        return;
                    }
                    var v = Number(reasons[key] || 0);
                    if (v > 0) {
                        reasonParts.push(key + '=' + String(v));
                    }
                });
            } catch (e) {}
            if (reasonParts.length) {
                lines.push('Reasons: ' + reasonParts.join(', '));
            }
            if (reasons && Number(reasons.too_large || 0) > 0) {
                lines.push('Note: Single files larger than 2GB are skipped for safety.');
            }

            // Show a couple of examples to avoid confusion.
            var samples = job.diagnostic_samples || [];
            if (samples && samples.length) {
                var shown = 0;
                for (var i = 0; i < samples.length; i++) {
                    var s = samples[i] || {};
                    if (s.type === 'too_large' && s.path) {
                        if (shown === 0) {
                            lines.push('');
                            lines.push('Examples:');
                        }
                        shown++;
                        if (shown > 3) {
                            break;
                        }
                        var sizeText = '';
                        if (typeof s.size === 'number' && s.size > 0) {
                            sizeText = ' (' + Math.round(s.size / (1024 * 1024)) + ' MB)';
                        }
                        lines.push('- ' + String(s.path) + sizeText);
                    }
                }
            }

            overlayMessage = lines.join('\n');
        }

        showMessage('success', strings.successTitle || '', strings.jobComplete || strings.successBackup || '');
        refreshLogs();

        var overlayFunc = window.MusederRestoreOneUI && window.MusederRestoreOneUI.showCompletionOverlay
            ? window.MusederRestoreOneUI.showCompletionOverlay
            : showCompletionOverlay;

        // Ensure download_url is available and valid
        var downloadUrl = job.download_url || '';
        if (downloadUrl && downloadUrl !== '') {
            overlayFunc({
                icon: '📦',
                title: overlayTitle,
                message: overlayMessage,
                actionHref: downloadUrl,
                actionText: strings.downloadLabel || 'Download',
                autoClose: 0
            });
        } else {
            // If download URL is not available, show overlay without download button
            overlayFunc({
                icon: '📦',
                title: overlayTitle,
                message: overlayMessage + ' ' + (strings.downloadUnavailable || 'Download link will be available in the backup library.'),
                confirmText: strings.close || 'Got it',
                autoClose: 4000
            });
        }
    }

    // Backup form submission handled in DOM ready block.

    $(document).on('click', '.backup-lite-restore-existing', function (event) {
        event.preventDefault();

        const button = $(this);
        const filename = button.data('filename');

        if (!filename) {
            handleError({ message: strings.noFileSelected || 'No backup file selected.' });
            return;
        }

        if (!confirm(settings.confirmRestore || 'Are you sure you want to restore this backup? This will overwrite your current site.')) {
            return;
        }

        button.prop('disabled', true);
        showMessage('', strings.runningTitle || '', (strings.restoring || 'Restoring backup...') + spinnerMarkup);

        const payload = new FormData();
        payload.append('action', 'museder_restoreone_restore_existing');
        payload.append('nonce', settings.nonce);
        payload.append('filename', filename);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            button.prop('disabled', false);

            if (!json.success) {
                handleError(json.data || json);
                return;
            }

            const data = json.data || {};
            const message = data.message || strings.successRestore || 'Restore completed successfully.';
            showMessage('success', strings.successTitle || '', message);
            refreshLogs();
            showCompletionOverlay({
                icon: '♻️',
                title: strings.restoreOverlayTitle || strings.successTitle || 'Restore completed',
                message: data.overlay_message || strings.successRestore || 'Restore completed successfully.',
                autoClose: 3500
            });
            setTimeout(function () {
                location.reload();
            }, 3000);
        }).catch(function () {
            button.prop('disabled', false);
            handleError();
        });
    });

$(document).on('click', '.backup-lite-delete-backup', function (event) {
        event.preventDefault();

        const button = $(this);
        const filename = button.data('filename');

        if (!filename) {
            handleError({ message: strings.noFileSelected || 'No backup file selected.' });
            return;
        }

        if (!confirm(strings.confirmDelete || 'Are you sure you want to delete this backup? This action cannot be undone.')) {
            return;
        }

        button.prop('disabled', true);

        const payload = new FormData();
        payload.append('action', 'museder_restoreone_delete_backup');
        payload.append('nonce', settings.nonce);
        payload.append('filename', filename);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            button.prop('disabled', false);

            if (!json.success) {
                handleError(json.data || json);
                return;
            }

            const data = json.data || {};
            const message = data.message || 'Backup deleted successfully.';
            showMessage('success', strings.successTitle || '', message);
            
            // Reload page after successful deletion
            window.location.reload();
        }).catch(function () {
            button.prop('disabled', false);
            handleError();
        });
});

function initBackupLiteDomReady() {
    var localizedSettings = window.MusederRestoreOneAdmin || {};
    var strings = localizedSettings.strings || {};
    var currentPage = localizedSettings.page || '';
    var backupForm = document.getElementById('backup-lite-backup-form');
    var backupProgress = document.getElementById('backup-progress-fill');
    backupModeStatus = document.getElementById('bl-backup-mode-status');
    var restoreFormV2 = document.getElementById('backup-lite-restore-form-v2');
    var uploadProgress = document.getElementById('upload-progress');
    var uploadInterval = null;

    // On Backup Lite pages, collect non-critical third-party notices to avoid layout shifts.
    collectThirdPartyNotices();
    observeThirdPartyNotices();

    var showToast = function () {
        if (window.MusederRestoreOneUI && typeof window.MusederRestoreOneUI.showToast === 'function') {
            return window.MusederRestoreOneUI.showToast.apply(window.MusederRestoreOneUI, arguments);
        }
        return undefined;
    };

    var showCompletionOverlay = function () {
        if (window.MusederRestoreOneUI && typeof window.MusederRestoreOneUI.showCompletionOverlay === 'function') {
            return window.MusederRestoreOneUI.showCompletionOverlay.apply(window.MusederRestoreOneUI, arguments);
        }
        return undefined;
    };

    function animateProgress(targetEl, step, delay, onComplete) {
        if (!targetEl) {
            return null;
        }
        targetEl.style.width = '0%';
        var progressText = document.getElementById('backup-progress-text');
        var width = 0;
        var intervalId = window.setInterval(function () {
            width = Math.min(100, width + step);
            targetEl.style.width = width + '%';
            if (progressText) {
                progressText.textContent = Math.round(width) + '%';
            }
            if (width >= 100) {
                width = 100;
                targetEl.style.width = width + '%';
                if (progressText) {
                    progressText.textContent = '100%';
                }
                window.clearInterval(intervalId);
                if (typeof onComplete === 'function') {
                    // Small delay before calling onComplete to ensure visual completion
                    setTimeout(onComplete, 200);
                }
            }
        }, delay);
        return intervalId;
    }

    if (backupForm) {
        setBackupFormElements(backupForm);
        backupForm.addEventListener('submit', startBackupJobRequest);
    } else {
        resetBackupProgress();
    }

    if (backupCancelBtn) {
        backupCancelBtn.addEventListener('click', function () {
            if (!backupJobContext.current || !backupJobContext.current.id) {
                setBackupCancelable(false);
                return;
            }
            var confirmMessage = strings.jobCancelConfirm || 'Cancel the running backup job?';
            if (confirmMessage && !window.confirm(confirmMessage)) {
                return;
            }
            cancelBackupJob();
        });
    }

    if (localizedSettings.activeJob && localizedSettings.activeJob.id) {
        backupJobContext.current = localizedSettings.activeJob;
        setBackupBusy(true);
        updateBackupProgress(
            (typeof localizedSettings.activeJob.overall_progress === 'number')
                ? localizedSettings.activeJob.overall_progress
                : (localizedSettings.activeJob.percentage || 0),
            {
                mode: (localizedSettings.activeJob.progress_mode === 'indeterminate')
                    ? 'indeterminate'
                    : 'determinate'
            }
        );
        setBackupStatusMessage(strings.jobResuming || strings.runningMessage || '', 'loading');
        setBackupCancelable(true);
        musederRestoreOneTimer.start(); // Start elapsed time timer for resumed job
        scheduleBackupJobPolling(true);
    }

    if (restoreFormV2 && uploadProgress) {
        restoreFormV2.addEventListener('submit', function () {
            if (uploadInterval) {
                window.clearInterval(uploadInterval);
            }
            var strings = (window.MusederRestoreOneAdmin && window.MusederRestoreOneAdmin.strings) || {};
            uploadInterval = animateProgress(uploadProgress, 5, 250, function () {
                showToast('✅ ' + (strings.successRestore || 'Restore Successful!'), 'success');
            });
        });
    }

    function setupSearchReplaceToggle(toggleElement) {
        if (!toggleElement) {
            return;
        }
        var fields = toggleElement.closest('form').querySelector('.backup-lite-search-replace-fields');
        if (!fields) {
            var targetId = toggleElement.getAttribute('data-target');
            if (targetId) {
                fields = document.getElementById(targetId);
            }
        }
        if (!fields) {
            return;
        }
        toggleElement.addEventListener('change', function () {
            fields.hidden = !toggleElement.checked;
        });
    }

    // Listen for summary-ready event from chunk-upload-v2.js
document.addEventListener('backup-lite-summary-ready', function(event) {
    if (event.detail && event.detail.data) {
        var handleSummaryResponse = window.MusederRestoreOneUI && window.MusederRestoreOneUI.handleSummaryResponse;
        if (typeof handleSummaryResponse === 'function') {
            handleSummaryResponse(event.detail);
        } else {
            // If handleSummaryResponse is not yet available, wait a bit and try again
            setTimeout(function() {
                handleSummaryResponse = window.MusederRestoreOneUI && window.MusederRestoreOneUI.handleSummaryResponse;
                if (typeof handleSummaryResponse === 'function') {
                    handleSummaryResponse(event.detail);
                } else {
                    console.warn('[Backup Lite] handleSummaryResponse not available, reloading page');
                    window.location.reload();
                }
            }, 500);
        }
    }
});

function initRestoreCenter() {
        var restoreData = window.MusederRestoreOneRestore || {};
        // Get ajaxUrl from BackupLiteRestore, BackupLite (main settings), or fallback
        var ajaxUrl = restoreData.ajaxUrl 
            || settings.ajaxUrl 
            || (typeof window.ajaxurl !== 'undefined' ? window.ajaxurl : '');
        // Get nonce from BackupLiteRestore, BackupLite (main settings), or fallback
        var nonce = restoreData.ajaxNonce 
            || settings.nonce 
            || '';
        var refreshingNonce = null;
        if (!ajaxUrl) {
            console.error('Backup Lite: ajaxUrl not found');
            return;
        }

        var methodButtons = document.querySelectorAll('.restore-methods .method-tabs button');
        var methodPanels = document.querySelectorAll('.method-panel');
        var uploadInput = document.getElementById('restoreFile');
        var uploadButton = document.getElementById('uploadRestore');
        var existingSelect = document.getElementById('existingBackup');
        var existingButton = document.getElementById('selectRestore');
        var progressBar = document.querySelector('.restore-progress .progress-bar-fill');
        var currentProgress = 0; // Track current displayed progress for smooth transitions
        var progressAnimationId = null; // Track animation frame ID
        var progressStatus = document.querySelector('.restore-progress .progress-status');
        var startButton = document.getElementById('startRestore');
        var overwriteToggle = document.getElementById('overwriteData');
        var applyReplaceToggle = document.getElementById('applyReplace');
        var skipConfigToggle = document.getElementById('skipConfig');
        var autoBackupToggle = document.getElementById('autoBackup');
        var safeModeToggle = document.getElementById('safeMode');
        var filesOnlyWrap = document.getElementById('restore-files-only-wrap');
        var filesOnlyToggle = document.getElementById('filesOnly');
        var historyTable = document.getElementById('restoreHistory');
        var summaryContainer = document.getElementById('fileSummary');
        var wizardSteps = {
            upload: document.getElementById('restore-step-upload'),
            review: document.getElementById('restore-step-review'),
            execute: document.getElementById('restore-step-execute')
        };
        var stepStatusNodes = {
            upload: document.getElementById('step-upload-status'),
            review: document.getElementById('step-review-status'),
            execute: document.getElementById('step-execute-status')
        };
        var stepCards = {
            review: document.querySelector('[data-step-card="review"]'),
            execute: document.querySelector('[data-step-card="execute"]')
        };
        var reviewCompleted = !!(restoreData.summary && (restoreData.summary.name || restoreData.summary.size));
        var restoreCompletionShown = false;
        var hasAnalyzed = !!(restoreData.summary && (restoreData.summary.name || restoreData.summary.size));
        var restoreInProgress = false;
        var restoreCompleted = !!(restoreData.progress && restoreData.progress.done);
        var backupLiteRestoreFailureShown = false; // Flag to prevent duplicate failure modals
        
        // Restore monitor state - single source of truth for restore job status
        var restoreMonitor = {
            jobId: null,
            archive: null,
            hasFinalResult: false,  // Once true, no more modals should be shown
            lastStatus: null        // 'running' | 'success' | 'failed'
        };
        var isAnalyzing = false;
        var analysisError = false;
        var stepStrings = {
            uploadIdle: strings.stepUploadIdle || 'Choose a backup and run Step 1.',
            uploadProcessing: strings.stepUploadProcessing || 'Analyzing backup…',
            uploadDone: strings.stepUploadDone || 'Analysis complete. Continue to Step 2.',
            uploadError: strings.stepUploadError || 'Analysis failed. Try again.',
            reviewLocked: strings.stepReviewLocked || 'Complete Step 1 first to unlock these options.',
            reviewReady: strings.stepReviewReady || 'Options unlocked. Adjust restore behavior.',
            reviewDone: strings.stepReviewDone || 'Options saved. Continue to Step 3.',
            executeLocked: strings.stepExecuteLocked || 'Complete Steps 1 & 2 before starting the restore.',
            executeReady: strings.stepExecuteReady || 'Ready to start restore.',
            executeProcessing: strings.stepExecuteProcessing || 'Restore running…',
            executeDone: strings.stepExecuteDone || 'Restore finished. Review your site.'
        };
        var SIMPLE_UPLOAD_LIMIT = 10 * 1024 * 1024;
        // Faster default for large local uploads; caps-based tuning will clamp down if needed.
        var DEFAULT_CHUNK_SIZE_BYTES = 4 * 1024 * 1024;
        var DEFAULT_CONCURRENCY = 2;
        var MAX_CONCURRENCY = 4;
        var MIN_CHUNK_SIZE_BYTES = 512 * 1024; // 512KB
        var MAX_CHUNK_SIZE_BYTES = 16 * 1024 * 1024; // 16MB (balanced cap)
        var activeChunkSession = null;
        var uploadCancelRequested = false;
        var activeUploadControllers = [];
        var autotuneConfig = restoreData.autotune || (settings && settings.restoreAutotune ? settings.restoreAutotune : {}) || {};
        var autotuneStorageTtlMs = (autotuneConfig && autotuneConfig.ttlMs) ? parseInt(autotuneConfig.ttlMs, 10) : (7 * 24 * 60 * 60 * 1000);
        var chunkStrings = {
            preparing: strings.chunkPreparing || 'Preparing upload…',
            uploading: strings.chunkUploading || 'Uploading %1$s of %2$s (%3$s%)…',
            merging: strings.chunkMerging || 'Merging uploaded chunks…'
        };
        var activeRestoreJobId = null;
        var restoreJobPollTimer = null;
        var restoreJobPollStartTime = null;
        var restoreJobReached100Time = null;
        var restoreJobEstimatedProgress = null; // Estimated progress based on file size
        var restoreJobProgressTimer = null; // Timer for simulated progress
        var restoreJobFileSize = 0; // Backup file size in bytes
        var restoreJobEstimatedDuration = 0; // Estimated duration in milliseconds
        var restoreJobReached85Time = null; // Time when progress reached 85%
        // Polling should not hard-timeout for long-running restores; use last_tick to detect staleness instead.
        var RESTORE_JOB_POLL_TIMEOUT = 300000; // legacy (no longer used as hard stop)
        var RESTORE_JOB_STALE_THRESHOLD = 15 * 60 * 1000; // 15 minutes without last_tick => treat as stalled
        var restoreJobLastTickMs = 0;
        var restoreJobLastTickValueSec = 0;
        var restoreJobTickStaleSinceMs = 0;
        var restoreTickInFlight = false;
        var restoreTickLastAttemptMs = 0;
        var restoreTickFallbackActive = false;
        // If last_tick doesn't advance for a while, try an admin-ajax tick to push the job forward.
        var RESTORE_JOB_TICK_PUSH_THRESHOLD = 90 * 1000; // 90 seconds no tick => try push
        var RESTORE_JOB_TICK_PUSH_COOLDOWN = 15 * 1000; // min interval between pushes
        var restoreJobLastStatusAtMs = 0;
        var RESTORE_JOB_100_POLL_LIMIT = 60000; // 1 minute after reaching 100%
        var restoreMonitorPaused = false;
        var restoreAutoResumeInterval = null;
        var restoreAutoResumeToastShown = false;

        function pushRestoreJobTick(jobId) {
            if (!jobId) {
                return Promise.reject(new Error('missing_job_id'));
            }
            if (restoreTickInFlight) {
                return Promise.resolve(null);
            }
            var now = Date.now();
            if (restoreTickLastAttemptMs && (now - restoreTickLastAttemptMs) < RESTORE_JOB_TICK_PUSH_COOLDOWN) {
                return Promise.resolve(null);
            }
            restoreTickLastAttemptMs = now;
            restoreTickInFlight = true;
            var formData = prepareFormData('museder_restoreone_restore_tick');
            formData.append('job_id', jobId);
            formData.append('slice', '8');
            return ajaxRequest(formData).then(function (json) {
                restoreTickFallbackActive = true;
                return getJsonPayload(json) || {};
            }).finally(function () {
                restoreTickInFlight = false;
            });
        }

        function notifyError(payload) {
            if (typeof handleError === 'function') {
                handleError(payload);
            } else if (payload && payload.message) {
                alert(payload.message);
            } else {
                console.error('Restore error', payload);
            }
        }
        function formatString(template, values) {
            if (!template) {
                return '';
            }
            var output = template;
            var list = Array.isArray(values) ? values : [values];
            output = output.replace(/%([0-9]+)\$s/g, function (match, index) {
                var i = parseInt(index, 10) - 1;
                return typeof list[i] !== 'undefined' ? list[i] : match;
            });
            if (output.indexOf('%s') !== -1 && list.length) {
                output = output.replace('%s', list[0]);
            }
            return output;
        }
        function updateUploadStatus(message) {
            var node = stepStatusNodes.upload;
            if (!node) {
                return;
            }
            var textNode = node.querySelector('.status-text') || node;
            textNode.textContent = message || '';
        }
        function formatDurationMs(ms) {
            var totalSeconds = Math.max(0, Math.floor(ms / 1000));
            var hours = Math.floor(totalSeconds / 3600);
            var minutes = Math.floor((totalSeconds % 3600) / 60);
            var seconds = totalSeconds % 60;
            if (hours > 0) {
                return String(hours) + ':' + String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
            }
            return String(minutes) + ':' + String(seconds).padStart(2, '0');
        }
        function getStep1TimerKey() {
            return 'museder_restoreone_restore_step1_started_at';
        }
        function setStep1StartedNow() {
            try {
                sessionStorage.setItem(getStep1TimerKey(), String(Date.now()));
            } catch (e) {}
        }
        function clearStep1Started() {
            try {
                sessionStorage.removeItem(getStep1TimerKey());
            } catch (e) {}
        }
        function getStep1StartedAt() {
            try {
                var raw = sessionStorage.getItem(getStep1TimerKey());
                var v = raw ? parseInt(raw, 10) : 0;
                return isNaN(v) ? 0 : v;
            } catch (e) {
                return 0;
            }
        }
        function ensureStep1TimerNode() {
            var node = document.getElementById('bl-step1-timer');
            if (node) {
                return node;
            }
            var container = document.getElementById('step-upload-status');
            if (!container) {
                return null;
            }
            node = document.createElement('span');
            node.id = 'bl-step1-timer';
            node.style.marginLeft = '10px';
            node.style.fontSize = '12px';
            node.style.opacity = '0.85';
            container.appendChild(node);
            return node;
        }
        function updateStep1TimerDisplay(finalText) {
            var node = ensureStep1TimerNode();
            if (!node) {
                return;
            }
            if (finalText) {
                node.textContent = finalText;
                return;
            }
            var startedAt = getStep1StartedAt();
            if (!startedAt) {
                node.textContent = '';
                return;
            }
            node.textContent = '⏱ ' + formatDurationMs(Date.now() - startedAt);
        }
        function getStep3TimerKey(jobId) {
            return 'museder_restoreone_restore_step3_started_at_' + String(jobId || '');
        }
        function setStep3StartedAt(jobId, startedAtMs) {
            try {
                sessionStorage.setItem(getStep3TimerKey(jobId), String(startedAtMs));
            } catch (e) {}
        }
        function getStep3StartedAt(jobId) {
            try {
                var raw = sessionStorage.getItem(getStep3TimerKey(jobId));
                var v = raw ? parseInt(raw, 10) : 0;
                return isNaN(v) ? 0 : v;
            } catch (e) {
                return 0;
            }
        }
        function clearStep3StartedAt(jobId) {
            try {
                sessionStorage.removeItem(getStep3TimerKey(jobId));
            } catch (e) {}
        }
        function ensureStep3TimerNode() {
            var node = document.getElementById('bl-step3-timer');
            if (node) {
                return node;
            }
            var status = document.getElementById('restore-progress-status');
            if (!status) {
                return null;
            }
            node = document.createElement('div');
            node.id = 'bl-step3-timer';
            node.style.marginTop = '6px';
            node.style.fontSize = '12px';
            node.style.opacity = '0.85';
            status.parentNode.insertBefore(node, status.nextSibling);
            return node;
        }
        function updateStep3TimerDisplay(jobId) {
            var node = ensureStep3TimerNode();
            if (!node) {
                return;
            }
            if (!jobId) {
                node.textContent = '';
                return;
            }
            var startedAt = getStep3StartedAt(jobId);
            if (!startedAt) {
                node.textContent = '';
                return;
            }
            node.textContent = '⏱ ' + (strings && strings.elapsed ? strings.elapsed : 'Elapsed') + ': ' + formatDurationMs(Date.now() - startedAt);
        }
        function sleep(ms) {
            return new Promise(function (resolve) { setTimeout(resolve, ms); });
        }
        function clampInt(value, min, max) {
            var v = parseInt(value, 10);
            if (isNaN(v)) {
                v = min;
            }
            return Math.max(min, Math.min(max, v));
        }
        function safeHostKey() {
            try {
                return (window.location && window.location.host) ? String(window.location.host) : 'unknown';
            } catch (e) {
                return 'unknown';
            }
        }
        function getAutotuneKey() {
            return 'museder_restoreone_restore_autotune_' + safeHostKey();
        }
        function loadAutotune() {
            try {
                var raw = window.localStorage ? window.localStorage.getItem(getAutotuneKey()) : '';
                if (!raw) {
                    return null;
                }
                var data = JSON.parse(raw);
                if (!data || typeof data !== 'object') {
                    return null;
                }
                if (data.expiresAt && Date.now() > data.expiresAt) {
                    window.localStorage.removeItem(getAutotuneKey());
                    return null;
                }
                return data.value || null;
            } catch (e) {
                return null;
            }
        }
        function saveAutotune(value) {
            try {
                if (!window.localStorage) {
                    return;
                }
                var payload = {
                    expiresAt: Date.now() + autotuneStorageTtlMs,
                    value: value
                };
                window.localStorage.setItem(getAutotuneKey(), JSON.stringify(payload));
            } catch (e) {}
        }
        function classifyUploadError(error) {
            var status = (error && error.status) ? parseInt(error.status, 10) : 0;
            var text = (error && error.responseText) ? String(error.responseText) : '';
            var message = (error && error.message) ? String(error.message) : '';
            var combined = (message + ' ' + text).toLowerCase();
            if (status === 413 || combined.indexOf('request entity too large') !== -1 || combined.indexOf('payload too large') !== -1) {
                return 'too_large';
            }
            if (status >= 500 && status <= 599) {
                return 'server_error';
            }
            if (status === 0 && (combined.indexOf('networkerror') !== -1 || combined.indexOf('failed to fetch') !== -1)) {
                return 'network_error';
            }
            if (combined.indexOf('aborted') !== -1) {
                return 'aborted';
            }
            return 'unknown';
        }
        function fetchEnvCaps() {
            var fd = prepareFormData('museder_restoreone_restore_env_caps');
            return ajaxRequest(fd).then(function (json) {
                return getJsonPayload(json) || {};
            }).catch(function () {
                return {};
            });
        }
        function computeInitialTuneFromCaps(caps) {
            var limits = (caps && caps.limits) ? caps.limits : {};
            var postBytes = limits.post_max_size && limits.post_max_size.bytes ? parseInt(limits.post_max_size.bytes, 10) : 0;
            var uploadBytes = limits.upload_max_filesize && limits.upload_max_filesize.bytes ? parseInt(limits.upload_max_filesize.bytes, 10) : 0;
            var hardMax = 0;
            if (postBytes > 0 && uploadBytes > 0) {
                hardMax = Math.min(postBytes, uploadBytes);
            } else {
                hardMax = Math.max(postBytes, uploadBytes);
            }
            // Conservative headroom: keep chunk <= 80% of hardMax (FormData overhead & headers).
            var desiredMaxChunk = hardMax > 0 ? Math.floor(hardMax * 0.8) : 0;
            var maxChunk = desiredMaxChunk > 0 ? Math.min(MAX_CHUNK_SIZE_BYTES, desiredMaxChunk) : MAX_CHUNK_SIZE_BYTES;
            var chunkSize = DEFAULT_CHUNK_SIZE_BYTES;
            if (maxChunk > 0) {
                if (maxChunk >= (16 * 1024 * 1024)) {
                    chunkSize = 16 * 1024 * 1024;
                } else if (maxChunk >= (8 * 1024 * 1024)) {
                    chunkSize = 8 * 1024 * 1024;
                } else if (maxChunk >= (4 * 1024 * 1024)) {
                    chunkSize = 4 * 1024 * 1024;
                } else if (maxChunk >= (2 * 1024 * 1024)) {
                    chunkSize = 2 * 1024 * 1024;
                } else if (maxChunk >= (1024 * 1024)) {
                    chunkSize = 1024 * 1024;
                } else {
                    chunkSize = MIN_CHUNK_SIZE_BYTES;
                }
                chunkSize = clampInt(chunkSize, MIN_CHUNK_SIZE_BYTES, maxChunk);
            }
            var concurrency = DEFAULT_CONCURRENCY;
            concurrency = clampInt(concurrency, 1, MAX_CONCURRENCY);
            return { chunkSize: chunkSize, concurrency: concurrency, hardMax: hardMax };
        }
        function ajaxRequest(formData, options) {
            var opts = options || {};
            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData,
                signal: opts.signal
            }).then(function (res) {
                return res.text().then(function (text) {
                    var json = {};
                    // WordPress AJAX returns "0" when not logged in / action not allowed.
                    // Treat this as a special case so restore polling does not look like a hard failure.
                    if (typeof text === 'string' && text.trim() === '0') {
                        var wpAjaxZero = new Error('WP_AJAX_0');
                        wpAjaxZero.code = 'wp_ajax_zero';
                        wpAjaxZero.status = res.status || 400;
                        wpAjaxZero.responseText = text;
                        throw wpAjaxZero;
                    }
                    if (text) {
                        try {
                            json = JSON.parse(text);
                        } catch (error) {
                            var parseError = new Error(strings.errorGeneric || 'An unexpected error occurred. Check logs for details.');
                            parseError.payload = { message: text };
                            parseError.status = res.status;
                            parseError.responseText = text;
                            throw parseError;
                        }
                    }
                    if (!res.ok || (json && json.success === false)) {
                        var payload = json && json.data ? json.data : json;
                        var message = payload && payload.message ? payload.message : (strings.errorGeneric || 'An unexpected error occurred.');
                        var requestError = new Error(message);
                        requestError.payload = payload;
                        requestError.status = res.status;
                        requestError.responseText = text;
                        throw requestError;
                    }
                    if (typeof json.success === 'undefined') {
                        return { success: true, data: json };
                    }
                    return json;
                });
            });
        }

        function refreshAjaxNonce() {
            if (refreshingNonce) {
                return refreshingNonce;
            }
            var fd = new FormData();
            fd.append('action', 'museder_restoreone_refresh_nonce');
            if (nonce) {
                fd.append('nonce', nonce);
            }
            refreshingNonce = fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: fd
            }).then(function (res) {
                return res.text().then(function (text) {
                    var json = {};
                    if (text) {
                        try {
                            json = JSON.parse(text);
                        } catch (error) {
                            var parseError = new Error(strings.errorGeneric || 'Unable to refresh session. Please reload the page.');
                            parseError.responseText = text;
                            throw parseError;
                        }
                    }
                    if (!res.ok || !json || json.success !== true || !json.data || !json.data.nonce) {
                        var refreshError = new Error(strings.errorGeneric || 'Unable to refresh session. Please reload the page.');
                        refreshError.responseText = text;
                        throw refreshError;
                    }
                    nonce = json.data.nonce;
                    return nonce;
                });
            }).catch(function (error) {
                console.error('Backup Lite: failed to refresh AJAX nonce.', error);
                throw error;
            }).finally(function () {
                refreshingNonce = null;
            });
            return refreshingNonce;
        }
        
        // Fallback function to check completion from history when AJAX fails
        function checkRestoreCompletionFromHistory(jobId) {
            if (!jobId) {
                return;
            }
            
            // If we already have a final result, don't check history
            if (restoreMonitor.hasFinalResult) {
                console.log('[Backup Lite] Already have final result, skipping history check', { 
                    jobId, 
                    lastStatus: restoreMonitor.lastStatus 
                });
                return;
            }
            
            console.log('[Backup Lite] Checking completion from history as fallback', { jobId });
            
            // Try to get history directly via a simple fetch (without nonce if possible)
            // Or use the job status endpoint with fresh nonce
            var formData = prepareFormData('museder_restoreone_restore_job_status');
            formData.append('job_id', jobId);
            
            ajaxRequest(formData).then(function (json) {
                // Check if we already have a final result before processing
                if (restoreMonitor.hasFinalResult) {
                    console.log('[Backup Lite] Already have final result, skipping history processing', { 
                        jobId, 
                        lastStatus: restoreMonitor.lastStatus 
                    });
                    return;
                }
                
                var payload = getJsonPayload(json) || {};
                var job = payload.job || payload;
                
                // Check if job is complete
                if (job && (job.status === 'success' || job.status === 'completed')) {
                    // Mark as final result before showing modal
                    restoreMonitor.hasFinalResult = true;
                    restoreMonitor.lastStatus = 'success';
                    console.log('[Backup Lite] Detected completion from history fallback check', { jobId, status: job.status });
                    markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                    return;
                }
                
                // Check if job failed
                if (job && job.status === 'failed') {
                    // Mark as final result before showing modal
                    restoreMonitor.hasFinalResult = true;
                    restoreMonitor.lastStatus = 'failed';
                    console.log('[Backup Lite] Detected failure from history fallback check', { jobId, status: job.status });
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    // Force progress to 100% to show completion
                    if (currentProgress < 100) {
                        if (progressAnimationId) {
                            cancelAnimationFrame(progressAnimationId);
                            progressAnimationId = null;
                        }
                        currentProgress = 100;
                        var progressFillFailed = document.getElementById('restore-progress-fill');
                        var progressTextFailed = document.getElementById('restore-progress-text');
                        updateProgressDisplay(100, progressBar, progressFillFailed, progressTextFailed);
                    }
                    setProgress(100, job.message || (strings.errorGeneric || 'Restore failed.'), true);
                    syncWizard();
                    updateRestoreCancelState();
                    showToast('❌ ' + (job.message || strings.errorGeneric || 'Restore failed.'), 'error');
                    
                    // Show failure overlay
                    if (!restoreCompletionShown && !backupLiteRestoreFailureShown) {
                        restoreCompletionShown = true;
                        backupLiteRestoreFailureShown = true;
                        showCompletionOverlay({
                            icon: '❌',
                            title: strings.restoreFailed || 'Restore Failed',
                            message: job.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                            confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                            type: 'error'
                        });
                    }
                    return;
                }
                
                // Check history for success/failure entry
                // Use stored job info if job is null (job was deleted but restore completed)
                var storedJobInfo = window.musederRestoreOneRestoreJobInfo || {};
                var jobStartRaw = (job && (job.started_at_raw || job.created_at_raw)) || storedJobInfo.started_at_raw || storedJobInfo.created_at_raw || 0;
                var archiveMatch = storedJobInfo.archive || restoreMonitor.archive;
                
                if (payload.history && Array.isArray(payload.history) && payload.history.length > 0) {
                    // Filter history entries matching this job
                    var matches = payload.history.filter(function(item) {
                        // Match by job_id or archive filename
                        var matchesJobId = item.job_id === restoreMonitor.jobId;
                        var matchesArchive = archiveMatch && item.file === archiveMatch;
                        var matchesTimestamp = !jobStartRaw || (item.timestamp_raw && item.timestamp_raw >= jobStartRaw && (item.timestamp_raw - jobStartRaw) < 600);
                        return (matchesJobId || matchesArchive) && matchesTimestamp;
                    });
                    
                    if (matches.length === 0) {
                        console.warn('[Backup Lite] No matching history entries found for job', { jobId, archive: archiveMatch });
                        return;
                    }
                    
                    // Get the latest entry (highest timestamp_utc)
                    var latestHistory = matches.reduce(function(a, b) {
                        var aTime = a.timestamp_utc || a.timestamp_raw || 0;
                        var bTime = b.timestamp_utc || b.timestamp_raw || 0;
                        return aTime > bTime ? a : b;
                    });
                    
                    if (latestHistory && latestHistory.result === 'success') {
                        // Mark as final result before showing modal
                        restoreMonitor.hasFinalResult = true;
                        restoreMonitor.lastStatus = 'success';
                        console.log('[Backup Lite] Detected completion from history entry', { jobId, latestHistory });
                        markRestoreCompleted(job && job.message || (strings.restoreCompleted || 'Restore Completed.'));
                        return;
                    } else if (latestHistory && latestHistory.result === 'failed') {
                        // Mark as final result before showing modal
                        restoreMonitor.hasFinalResult = true;
                        restoreMonitor.lastStatus = 'failed';
                        console.log('[Backup Lite] Detected failure from history entry', { jobId, latestHistory });
                        stopRestoreJobMonitor();
                        restoreInProgress = false;
                        restoreCompleted = false;
                        if (startButton) {
                            startButton.disabled = false;
                        }
                        // Force progress to 100% to show completion
                        if (currentProgress < 100) {
                            if (progressAnimationId) {
                                cancelAnimationFrame(progressAnimationId);
                                progressAnimationId = null;
                            }
                            currentProgress = 100;
                            var progressFillFailed3 = document.getElementById('restore-progress-fill');
                            var progressTextFailed3 = document.getElementById('restore-progress-text');
                            updateProgressDisplay(100, progressBar, progressFillFailed3, progressTextFailed3);
                        }
                        setProgress(100, latestHistory.message || (strings.errorGeneric || 'Restore failed.'), true);
                        syncWizard();
                        updateRestoreCancelState();
                        showToast('❌ ' + (latestHistory.message || strings.errorGeneric || 'Restore failed.'), 'error');
                        
                        // Show failure overlay (only once)
                        if (!restoreCompletionShown && !backupLiteRestoreFailureShown) {
                            restoreCompletionShown = true;
                            backupLiteRestoreFailureShown = true;
                            showCompletionOverlay({
                                icon: '❌',
                                title: strings.restoreFailed || 'Restore Failed',
                                message: latestHistory.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                type: 'error'
                            });
                        }
                        return;
                    }
                }
            }).catch(function (error) {
                // WordPress AJAX "0" indicates session lost or not authorised (often due to expired login).
                // Do not mark restore as failed; the server-side job may still be running via cron.
                if (error && error.code === 'wp_ajax_zero') {
                    // Pause monitoring but keep jobId/state. Auto-resume after user re-logs in.
                    pauseRestoreJobMonitor();
                    // Keep progress UI as-is; only show a clear actionable message.
                    syncWizard();
                    updateRestoreCancelState();
                    if (!restoreAutoResumeToastShown) {
                        restoreAutoResumeToastShown = true;
                        showToast('⚠️ ' + (strings.sessionExpired || 'Your login session may have expired. Please re-login in another tab. Monitoring will auto-resume once you are logged in.'), 'warning');
                    }
                    console.warn('[Backup Lite] AJAX returned 0 (session/permission issue). Pausing monitor.', { jobId: jobId });
                    scheduleRestoreAutoResume(jobId);
                    return;
                }
                console.warn('[Backup Lite] History fallback check also failed:', error);
                // If history check also fails, and we've been polling for a while, don't assume failure
                // Instead, just reset state and show a message
                if (restoreJobPollStartTime && (Date.now() - restoreJobPollStartTime) > 120000) {
                    // If we've been polling for more than 2 minutes and all requests fail, reset state
                    console.warn('[Backup Lite] All status checks failed for 2+ minutes, resetting state to prevent stuck progress');
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    setProgress(0, '', false);
                    syncWizard();
                    updateRestoreCancelState();
                    // Show a warning toast instead of failure modal
                    showToast('⚠️ ' + (strings.errorGeneric || 'Could not confirm restore status. Please check logs manually.'), 'warning');
                }
            });
        }
        
        function markRestoreCompleted(message, meta) {
            // Check if we already have a final result (prevent duplicate modals)
            if (restoreMonitor.hasFinalResult && restoreMonitor.lastStatus !== 'success') {
                console.log('[Backup Lite] Already have final result, skipping completion display', { 
                    lastStatus: restoreMonitor.lastStatus 
                });
                return;
            }
            
            // Mark as final result before showing modal
            restoreMonitor.hasFinalResult = true;
            restoreMonitor.lastStatus = 'success';
            
            // Check if overlay is actually visible in the DOM
            var existingOverlay = document.querySelector('.bl-completion-overlay.is-visible');
            var overlayExists = existingOverlay && existingOverlay.parentNode;
            
            // If overlay is already visible, only update state if needed
            if (restoreCompletionShown && overlayExists) {
                // Overlay is already shown and visible - only update state if needed
                if (!restoreCompleted || restoreInProgress) {
                    restoreInProgress = false;
                    restoreCompleted = true;
                    setProgress(100, message || strings.restoreCompleted || 'Restore Completed.', true);
                    stopRestoreJobMonitor();
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    syncWizard();
                    updateRestoreCancelState();
                }
                console.log('[Backup Lite] Completion overlay already visible, skipping duplicate display');
                return;
            }
            
            // If restoreCompletionShown is true but overlay doesn't exist, check if it was just closed
            // If overlay was closed (user dismissed it), don't show it again
            if (restoreCompletionShown && !overlayExists) {
                // Check if there's a recent restore history entry that we've already shown
                // If so, don't show again (user already dismissed it)
                var restoreHistory = restoreData && restoreData.history ? restoreData.history : [];
                if (restoreHistory.length > 0) {
                    var latestHistory = restoreHistory[0];
                    if (latestHistory && latestHistory.result === 'success') {
                        var historyTime = latestHistory.timestamp_raw || 0;
                        if (historyTime) {
                            var storageKey = 'museder_restoreone_restore_shown_' + (latestHistory.file || '') + '_' + historyTime;
                            var alreadyShown = sessionStorage.getItem(storageKey);
                            if (alreadyShown) {
                                console.log('[Backup Lite] Completion was already shown and dismissed, not showing again');
                                restoreCompletionShown = false;
                                return;
                            }
                        }
                    }
                }
                console.log('[Backup Lite] Completion flag set but overlay not visible, resetting and showing overlay');
                restoreCompletionShown = false;
            }
            
            // Mark completion and show overlay
            console.log('[Backup Lite] Marking restore as completed', { message, restoreInProgress, restoreCompleted, activeRestoreJobId, overlayExists });
            setProgress(100, message || strings.restoreCompleted || 'Restore Completed.', true);
            stopRestoreJobMonitor();
            restoreInProgress = false;
            restoreCompleted = true;
            if (restoreMonitor && restoreMonitor.jobId) {
                clearStep3StartedAt(restoreMonitor.jobId);
            }
            updateStep3TimerDisplay(restoreMonitor ? restoreMonitor.jobId : null);
            activeRestoreJobId = null; // Clear active job ID to allow new restore
            if (startButton) {
                startButton.disabled = false;
            }
            syncWizard();
            updateRestoreCancelState();
            showToast('✅ ' + (strings.successRestore || 'Restore Completed!'), 'success');
            
            // Always show overlay when marking completion
            restoreCompletionShown = true;
            try {
                var safeMode = meta && meta.safe_mode_active;
                var prevCount = meta && typeof meta.prev_plugins_count !== 'undefined' ? meta.prev_plugins_count : 0;
                var overlayMessage = strings.restoreOverlayMessage || strings.successRestore || 'Your site has been restored successfully.';
                if (safeMode) {
                    overlayMessage += '\n\n' + (strings.safeModeOverlayHint || ('Safe mode is active: a snapshot of ' + String(prevCount || 0) + ' plugin(s) was saved. Verify your site, then exit safe mode to clear the notice.'));
                }
                showCompletionOverlay({
                    icon: '✅',
                    title: strings.restoreCompleted || 'Restore Completed',
                    message: overlayMessage,
                    actionText: safeMode ? (strings.exitSafeMode || 'Exit Safe Mode') : '',
                    actionCallback: safeMode ? function () {
                        var ajaxUrl = localizedSettings.ajaxUrl || '/wp-admin/admin-ajax.php';
                        var nonce = localizedSettings.nonce || '';
                        jQuery.ajax({
                            url: ajaxUrl,
                            type: 'POST',
                            data: {
                                action: 'museder_restoreone_exit_safe_mode',
                                nonce: nonce
                            },
                            success: function (response) {
                                if (response && response.success) {
                                    showToast('✅ ' + ((response.data && response.data.message) ? response.data.message : 'Safe mode exited.'), 'success');
                                    setTimeout(function () {
                                        window.location.reload();
                                    }, 800);
                                } else {
                                    showToast('❌ ' + ((response && response.data && response.data.message) ? response.data.message : 'Failed to exit safe mode.'), 'error');
                                }
                            },
                            error: function () {
                                showToast('❌ ' + (strings.errorGeneric || 'An error occurred. Please try again.'), 'error');
                            }
                        });
                    } : null,
                    confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it'
                });
                console.log('[Backup Lite] Completion overlay shown');
                
                // Verify overlay was actually added to DOM
                setTimeout(function() {
                    var verifyOverlay = document.querySelector('.bl-completion-overlay.is-visible');
                    if (!verifyOverlay) {
                        console.warn('[Backup Lite] Completion overlay not found in DOM after show, retrying...');
                        restoreCompletionShown = false;
                        // Retry once
                        try {
                            showCompletionOverlay({
                                icon: '✅',
                                title: strings.restoreCompleted || 'Restore Completed',
                                message: overlayMessage,
                                confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it'
                            });
                            restoreCompletionShown = true;
                            console.log('[Backup Lite] Completion overlay shown on retry');
                        } catch (retryError) {
                            console.error('[Backup Lite] Failed to show completion overlay on retry:', retryError);
                            restoreCompletionShown = false;
                        }
                    }
                }, 100);
            } catch (error) {
                console.error('[Backup Lite] Failed to show completion overlay:', error);
                // Reset flag so we can try again
                restoreCompletionShown = false;
            }
        }
        
        function startRestoreJobMonitor(job, fileSize) {
            if (!job || !job.id) {
                return;
            }
            
            // Prevent auto-resuming failed, cancelled, or completed jobs
            var jobStatus = job.status || '';
            if (jobStatus === 'failed' || jobStatus === 'cancelled' || jobStatus === 'success' || jobStatus === 'completed') {
                console.warn('[Backup Lite] Cannot start restore job monitor: job is already ' + jobStatus, { jobId: job.id, status: jobStatus });
                stopRestoreJobMonitor();
                restoreInProgress = false;
                restoreCompleted = false;
                activeRestoreJobId = null;
                if (startButton) {
                    startButton.disabled = false;
                }
                setProgress(0, '', false);
                syncWizard();
                updateRestoreCancelState();
                return;
            }
            
            activeRestoreJobId = job.id;
            restoreInProgress = true;
            restoreJobPollStartTime = Date.now();
            // Step 3 overall timer: prefer backend started_at_raw (seconds) if available.
            var overallStartedMs = 0;
            if (job.started_at_raw) {
                var s = parseInt(job.started_at_raw, 10);
                if (!isNaN(s) && s > 0) {
                    overallStartedMs = s * 1000;
                }
            }
            if (!overallStartedMs) {
                overallStartedMs = Date.now();
            }
            setStep3StartedAt(job.id, overallStartedMs);
            updateStep3TimerDisplay(job.id);
            restoreJobReached100Time = null;
            restoreJobReached85Time = null;
            restoreCompletionShown = false;
            restoreJobEstimatedProgress = 5; // Start at 5%
            restoreJobFileSize = fileSize || 0;
            
            // Initialize restore monitor state
            restoreMonitor.jobId = job.id;
            // Defensive: restoreData.summary may be null if Step 1 wasn't completed or page state was reset.
            var summaryName = (restoreData && restoreData.summary && restoreData.summary.name) ? restoreData.summary.name : null;
            restoreMonitor.archive = job.archive || summaryName || null;
            restoreMonitor.hasFinalResult = false;
            restoreMonitor.lastStatus = 'running';
            
            // Store job info for history matching when job is deleted
            window.musederRestoreOneRestoreJobInfo = {
                id: job.id,
                started_at_raw: job.started_at_raw || job.created_at_raw || 0,
                created_at_raw: job.created_at_raw || 0,
                archive: restoreMonitor.archive
            };
            
            // Calculate estimated duration based on file size
            // Estimate: ~10-50 MB/s processing speed (conservative estimate)
            // For 1GB file: ~20-100 seconds, use 60 seconds as baseline
            // Scale based on file size
            var baseSize = 100 * 1024 * 1024; // 100 MB baseline
            var baseDuration = 30000; // 30 seconds for 100 MB
            if (restoreJobFileSize > 0) {
                restoreJobEstimatedDuration = Math.max(60000, Math.min(600000, (restoreJobFileSize / baseSize) * baseDuration));
            } else {
                restoreJobEstimatedDuration = 180000; // Default 3 minutes if size unknown
            }
            
            var progressContainer = document.getElementById('restore-progress-container');
            var waitingMessage = document.getElementById('restore-waiting-message');
            if (progressContainer) {
                progressContainer.style.display = 'block';
            }
            if (waitingMessage) {
                waitingMessage.style.display = 'none';
            }
            syncWizard();
            updateRestoreCancelState();
            setProgress(5, job.message || (strings.runningMessage || 'Starting restore…'), false);
            
            // Start simulated progress from 5% to 85%
            startSimulatedProgress();
            
            // If job status is 'pending', try to trigger it immediately
            // Only trigger if job is not already failed, cancelled, or completed
            if (job.status === 'pending' && jobStatus !== 'failed' && jobStatus !== 'cancelled' && jobStatus !== 'success' && jobStatus !== 'completed') {
                // Trigger cron execution immediately via AJAX
                var triggerFormData = prepareFormData('museder_restoreone_trigger_restore_job');
                triggerFormData.append('job_id', job.id);
                ajaxRequest(triggerFormData).catch(function(err) {
                    // If trigger fails, continue with normal polling
                    console.warn('Could not trigger restore job immediately:', err);
                });
            }
            
            pollRestoreJob(job.id, true);
            if (restoreJobPollTimer) {
                clearInterval(restoreJobPollTimer);
            }
            restoreJobPollTimer = window.setInterval(function () {
                pollRestoreJob(job.id, false);
                updateStep3TimerDisplay(job.id);
            }, 5000);
            
            // Keep WordPress session alive during long restore operations
            // Use WordPress heartbeat API to prevent session expiration
            if (typeof window.wp !== 'undefined' && window.wp.heartbeat) {
                // Increase heartbeat interval to keep session alive
                var heartbeatInterval = setInterval(function() {
                    if (activeRestoreJobId === job.id && restoreInProgress) {
                        // Trigger heartbeat to keep session alive
                        if (window.wp.heartbeat && window.wp.heartbeat.connectNow) {
                            window.wp.heartbeat.connectNow();
                        } else if (window.wp && window.wp.heartbeat && typeof window.wp.heartbeat === 'function') {
                            // Fallback: try to trigger heartbeat manually
                            try {
                                var heartbeatData = { 'wp_autosave': false };
                                if (typeof jQuery !== 'undefined' && jQuery.heartbeat) {
                                    jQuery.heartbeat.enqueue('museder_restoreone_keep_alive', heartbeatData);
                                }
                            } catch (e) {
                                // Ignore heartbeat errors
                            }
                        }
                    } else {
                        // Stop heartbeat when restore is complete
                        clearInterval(heartbeatInterval);
                    }
                }, 30000); // Every 30 seconds
                
                // Store interval ID for cleanup
                if (!window.musederRestoreOneHeartbeatInterval) {
                    window.musederRestoreOneHeartbeatInterval = heartbeatInterval;
                }
            }

            startRestoreKeepAlive(job.id);
        }
        
        function startSimulatedProgress() {
            if (restoreJobProgressTimer) {
                clearInterval(restoreJobProgressTimer);
            }
            
            var startTime = Date.now();
            var startProgress = 5;
            var targetProgress = 85;
            var duration = restoreJobEstimatedDuration * 0.8; // Use 80% of estimated time to reach 85%
            
            restoreJobProgressTimer = window.setInterval(function() {
                if (!restoreInProgress || !activeRestoreJobId) {
                    clearInterval(restoreJobProgressTimer);
                    restoreJobProgressTimer = null;
                    return;
                }
                
                var elapsed = Date.now() - startTime;
                var progress = Math.min(targetProgress, startProgress + ((elapsed / duration) * (targetProgress - startProgress)));
                
                // Use ease-out cubic for smooth animation
                var t = Math.min(1, elapsed / duration);
                var eased = 1 - Math.pow(1 - t, 3);
                progress = startProgress + (eased * (targetProgress - startProgress));
                
                restoreJobEstimatedProgress = Math.min(85, progress);
                
                // Only update if we haven't reached 85% yet
                if (restoreJobEstimatedProgress < 85) {
                    setProgress(restoreJobEstimatedProgress, strings.restoreInProgress || 'Restore in Progress', false);
                } else if (restoreJobEstimatedProgress >= 85 && !restoreJobReached85Time) {
                    restoreJobReached85Time = Date.now();
                    setProgress(85, strings.restoreFinalizing || 'Finalizing restore…', false);
                    // Stop simulated progress, wait for actual job status
                    clearInterval(restoreJobProgressTimer);
                    restoreJobProgressTimer = null;
                }
            }, 100); // Update every 100ms for smooth animation
        }
        function stopRestoreJobMonitor() {
            if (restoreJobPollTimer) {
                clearInterval(restoreJobPollTimer);
                restoreJobPollTimer = null;
            }
            if (restoreJobProgressTimer) {
                clearInterval(restoreJobProgressTimer);
                restoreJobProgressTimer = null;
            }
            // Stop heartbeat interval if running
            if (window.musederRestoreOneHeartbeatInterval) {
                clearInterval(window.musederRestoreOneHeartbeatInterval);
                window.musederRestoreOneHeartbeatInterval = null;
            }
            if (window.musederRestoreOneRestoreKeepAliveInterval) {
                clearInterval(window.musederRestoreOneRestoreKeepAliveInterval);
                window.musederRestoreOneRestoreKeepAliveInterval = null;
            }
            if (restoreAutoResumeInterval) {
                clearInterval(restoreAutoResumeInterval);
                restoreAutoResumeInterval = null;
            }
            restoreMonitorPaused = false;
            restoreAutoResumeToastShown = false;
            activeRestoreJobId = null;
            // Keep timer record for history display if needed; clear only when job finishes/cancels.
            restoreJobPollStartTime = null;
            restoreJobReached100Time = null;
            restoreJobReached85Time = null;
            restoreJobEstimatedProgress = null;
            restoreJobFileSize = 0;
            restoreJobEstimatedDuration = 0;
            // Clear stored job info
            window.musederRestoreOneRestoreJobInfo = null;
            // Note: Don't reset restoreMonitor here - it should persist until next restore starts
        }

        // Pause monitoring without losing job id/state (used when session expires).
        function pauseRestoreJobMonitor() {
            if (restoreJobPollTimer) {
                clearInterval(restoreJobPollTimer);
                restoreJobPollTimer = null;
            }
            if (restoreJobProgressTimer) {
                clearInterval(restoreJobProgressTimer);
                restoreJobProgressTimer = null;
            }
            if (window.musederRestoreOneHeartbeatInterval) {
                clearInterval(window.musederRestoreOneHeartbeatInterval);
                window.musederRestoreOneHeartbeatInterval = null;
            }
            if (window.musederRestoreOneRestoreKeepAliveInterval) {
                clearInterval(window.musederRestoreOneRestoreKeepAliveInterval);
                window.musederRestoreOneRestoreKeepAliveInterval = null;
            }
            restoreMonitorPaused = true;
        }

        function startRestoreKeepAlive(jobId) {
            if (window.musederRestoreOneRestoreKeepAliveInterval) {
                return;
            }
            window.musederRestoreOneRestoreKeepAliveInterval = setInterval(function () {
                if (activeRestoreJobId === jobId && restoreInProgress) {
                    var ka = prepareFormData('museder_restoreone_keep_alive');
                    ajaxRequest(ka).catch(function () {});
                } else {
                    clearInterval(window.musederRestoreOneRestoreKeepAliveInterval);
                    window.musederRestoreOneRestoreKeepAliveInterval = null;
                }
            }, 60000);
        }

        function resumeRestoreJobMonitor(jobId) {
            if (!jobId) {
                return;
            }
            restoreMonitorPaused = false;
            restoreAutoResumeToastShown = false;
            // Restart polling and keep-alive using existing job id.
            pollRestoreJob(jobId, true);
            if (restoreJobPollTimer) {
                clearInterval(restoreJobPollTimer);
            }
            restoreJobPollTimer = window.setInterval(function () {
                pollRestoreJob(jobId, false);
                updateStep3TimerDisplay(jobId);
            }, 5000);
            startRestoreKeepAlive(jobId);
        }

        function scheduleRestoreAutoResume(jobId) {
            if (!jobId) {
                return;
            }
            if (restoreAutoResumeInterval) {
                return;
            }
            restoreAutoResumeInterval = setInterval(function () {
                refreshAjaxNonce().then(function () {
                    if (restoreAutoResumeInterval) {
                        clearInterval(restoreAutoResumeInterval);
                        restoreAutoResumeInterval = null;
                    }
                    resumeRestoreJobMonitor(jobId);
                }).catch(function () {});
            }, 10000);
        }
        function pollRestoreJob(jobId, silent) {
            if (!jobId) {
                return;
            }
            
            // If we already have a final result, stop polling
            if (restoreMonitor.hasFinalResult) {
                console.log('[Backup Lite] Already have final result, stopping polling', { 
                    jobId, 
                    lastStatus: restoreMonitor.lastStatus 
                });
                stopRestoreJobMonitor();
                return;
            }
            
            // Do NOT hard-timeout long restores. Only stop if we have no updates for a long time.
            var nowMs = Date.now();
            var lastSignalMs = Math.max(restoreJobLastTickMs || 0, restoreJobLastStatusAtMs || 0);
            if (lastSignalMs && (nowMs - lastSignalMs) > RESTORE_JOB_STALE_THRESHOLD) {
                // Stale: stop polling but don't mark as failed. User can check logs or refresh later.
                stopRestoreJobMonitor();
                restoreInProgress = false;
                if (startButton) {
                    startButton.disabled = false;
                }
                syncWizard();
                updateRestoreCancelState();
                console.warn('[Backup Lite] Restore job polling stale (no updates)', { jobId, lastSignalMs: lastSignalMs });
                showToast('⚠️ ' + (strings.errorGeneric || 'No progress updates received. Please refresh later or check logs.'), 'warning');
                return;
            }
            
            var formData = prepareFormData('museder_restoreone_restore_job_status');
            formData.append('job_id', jobId);
            ajaxRequest(formData).then(function (json) {
                // Check again if we have final result (may have been set by another poll)
                if (restoreMonitor.hasFinalResult) {
                    return;
                }
                
                var payload = getJsonPayload(json) || {};
                var job = payload.job || payload;
                restoreJobLastStatusAtMs = Date.now();
                
                // Check if nonce expired flag is set
                if (payload.nonce_expired) {
                    // Nonce expired but we got job status - refresh nonce and continue
                    refreshAjaxNonce().then(function() {
                        // Continue polling with fresh nonce
                        if (activeRestoreJobId === jobId) {
                            pollRestoreJob(jobId, silent);
                        }
                    }).catch(function(refreshError) {
                        console.warn('[Backup Lite] Failed to refresh nonce, continuing with current status:', refreshError);
                    });
                }
                
                // If job is null, check history to see if restore completed
                // This handles the case where job was deleted but restore completed
                if (!job) {
                    if (payload.history && Array.isArray(payload.history) && payload.history.length > 0) {
                        // Get the job start timestamp from stored job info
                        var storedJobInfo = window.musederRestoreOneRestoreJobInfo || {};
                        var jobStartRaw = storedJobInfo.started_at_raw || storedJobInfo.created_at_raw || 0;
                        
                        // Check latest history entry
                        var latestHistory = payload.history[0];
                        var historyTimestamp = latestHistory.timestamp_raw || 0;
                        
                        // Match history entry with job using timestamp (within 10 minutes window)
                        var isHistoryMatch = !jobStartRaw || (historyTimestamp && historyTimestamp >= jobStartRaw && (historyTimestamp - jobStartRaw) < 600);
                        
                        if (isHistoryMatch) {
                            if (latestHistory.result === 'success') {
                                console.log('[Backup Lite] Job not found but history shows success, marking as completed', { jobId, historyTimestamp, jobStartRaw });
                                markRestoreCompleted(strings.restoreCompleted || 'Restore Completed.');
                                return;
                            } else if (latestHistory.result === 'failed') {
                                console.log('[Backup Lite] Job not found but history shows failure, marking as failed', { jobId, historyTimestamp, jobStartRaw });
                                stopRestoreJobMonitor();
                                restoreInProgress = false;
                                restoreCompleted = false;
                                if (startButton) {
                                    startButton.disabled = false;
                                }
                                setProgress(100, latestHistory.message || (strings.errorGeneric || 'Restore failed.'), true);
                                syncWizard();
                                updateRestoreCancelState();
                                showToast('❌ ' + (latestHistory.message || strings.errorGeneric || 'Restore failed.'), 'error');
                                
                                if (!restoreCompletionShown) {
                                    restoreCompletionShown = true;
                                    showCompletionOverlay({
                                        icon: '❌',
                                        title: strings.restoreFailed || 'Restore Failed',
                                        message: latestHistory.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                        confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                        type: 'error'
                                    });
                                }
                                return;
                            }
                        }
                    }
                    // If no matching history found, continue polling (job might still be processing)
                    return;
                }
                
                var progress = job.progress || 0;
                var status = job.status || '';
                if (job.last_tick) {
                    var tickSeconds = parseInt(job.last_tick, 10);
                    if (!isNaN(tickSeconds) && tickSeconds > 0) {
                        restoreJobLastTickMs = tickSeconds * 1000;
                        // Track whether last_tick is advancing; if not, try a tick push after threshold.
                        if (restoreJobLastTickValueSec && tickSeconds <= restoreJobLastTickValueSec) {
                            if (!restoreJobTickStaleSinceMs) {
                                restoreJobTickStaleSinceMs = Date.now();
                            }
                        } else {
                            restoreJobLastTickValueSec = tickSeconds;
                            restoreJobTickStaleSinceMs = 0;
                        }
                    }
                }
                updateStep3TimerDisplay(jobId);

                // If we are running but last_tick isn't moving, try to push a slice via admin-ajax.
                if (
                    status === 'running'
                    && restoreJobTickStaleSinceMs
                    && (Date.now() - restoreJobTickStaleSinceMs) > RESTORE_JOB_TICK_PUSH_THRESHOLD
                ) {
                    pushRestoreJobTick(jobId).catch(function (err) {
                        // Best-effort only; polling continues.
                        console.warn('[Backup Lite] Restore tick push failed:', err);
                    });
                    // Avoid repeated pushes; next attempt governed by cooldown.
                }
                
                // If job is still pending after 10 seconds, try to trigger it again
                // Only trigger if job is not already failed, cancelled, or completed
                if (status === 'pending' && restoreJobPollStartTime && status !== 'failed' && status !== 'cancelled' && status !== 'success' && status !== 'completed') {
                    var timeSinceStart = Date.now() - restoreJobPollStartTime;
                    if (timeSinceStart > 10000) {
                        // Job has been pending for more than 10 seconds, try to trigger it
                        var triggerFormData = prepareFormData('museder_restoreone_trigger_restore_job');
                        triggerFormData.append('job_id', job.id);
                        ajaxRequest(triggerFormData).catch(function(err) {
                            console.warn('Could not trigger restore job:', err);
                        });
                    }
                }
                
                // Use simulated progress if we haven't reached 85% yet
                // Once we reach 85%, use actual job progress
                var displayProgress = restoreJobEstimatedProgress || 5;
                var isComplete = status === 'success' || status === 'completed';
                var isFailed = status === 'failed';
                
                // If we've reached 85% or job is complete/failed, use actual progress
                if (restoreJobReached85Time || isComplete || isFailed) {
                    displayProgress = Math.min(100, Math.max(85, progress));
                } else {
                    // Still using simulated progress, but don't exceed 85%
                    displayProgress = Math.min(85, displayProgress);
                }
                
                // Track when progress first reaches 100%
                if (displayProgress >= 100 && !restoreJobReached100Time) {
                    restoreJobReached100Time = Date.now();
                }
                
                // If job is complete or failed, force to 100% immediately
                if (isComplete || isFailed) {
                    displayProgress = 100;
                    // Stop simulated progress if still running
                    if (restoreJobProgressTimer) {
                        clearInterval(restoreJobProgressTimer);
                        restoreJobProgressTimer = null;
                    }
                    // Force progress to 100% immediately
                    if (currentProgress < 100) {
                        if (progressAnimationId) {
                            cancelAnimationFrame(progressAnimationId);
                            progressAnimationId = null;
                        }
                        currentProgress = 100;
                        var progressFill = document.getElementById('restore-progress-fill');
                        var progressText = document.getElementById('restore-progress-text');
                        updateProgressDisplay(100, progressBar, progressFill, progressText);
                    }
                }
                
                setProgress(displayProgress, job.message || (strings.runningMessage || ''), isComplete || isFailed);

                var completionMeta = {
                    safe_mode_active: !!payload.safe_mode_active,
                    prev_plugins_count: (typeof payload.prev_plugins_count !== 'undefined') ? payload.prev_plugins_count : 0
                };
                
                if (payload.history) {
                    // DISABLED: Restore History is now rendered server-side in PHP template
                    // renderHistory(payload.history);
                    
                    var latestHistory = payload.history.length ? payload.history[0] : null;
                    if (latestHistory) {
                        var historyTimestamp = latestHistory.timestamp_raw || 0;
                        var jobStartRaw = job.started_at_raw || job.created_at_raw || 0;
                        var isHistoryMatch = !jobStartRaw || (historyTimestamp && historyTimestamp >= jobStartRaw);
                        
                        // Check for failure in history (priority check - failure should be detected immediately)
                        if (!isComplete && !isFailed && latestHistory.result === 'failed' && isHistoryMatch) {
                            console.log('[Backup Lite] History shows failure, marking as failed', { 
                                historyTimestamp, 
                                jobStartRaw, 
                                latestHistory 
                            });
                            // Stop simulated progress if still running
                            if (restoreJobProgressTimer) {
                                clearInterval(restoreJobProgressTimer);
                                restoreJobProgressTimer = null;
                            }
                            // Force progress to 100% to show completion
                            if (currentProgress < 100) {
                                if (progressAnimationId) {
                                    cancelAnimationFrame(progressAnimationId);
                                    progressAnimationId = null;
                                }
                                currentProgress = 100;
                                var progressFillFailed = document.getElementById('restore-progress-fill');
                                var progressTextFailed = document.getElementById('restore-progress-text');
                                updateProgressDisplay(100, progressBar, progressFillFailed, progressTextFailed);
                            }
                            stopRestoreJobMonitor();
                            restoreInProgress = false;
                            restoreCompleted = false;
                            if (startButton) {
                                startButton.disabled = false;
                            }
                            setProgress(100, latestHistory.message || (strings.errorGeneric || 'Restore failed.'), true);
                            syncWizard();
                            updateRestoreCancelState();
                            showToast('❌ ' + (latestHistory.message || strings.errorGeneric || 'Restore failed.'), 'error');
                            
                            // Show failure overlay
                            if (!restoreCompletionShown) {
                                restoreCompletionShown = true;
                                showCompletionOverlay({
                                    icon: '❌',
                                    title: strings.restoreFailed || 'Restore Failed',
                                    message: latestHistory.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                    confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                    type: 'error'
                                });
                            }
                            return; // Stop polling
                        }
                        // Check for success in history
                        else if (!isComplete && !isFailed && latestHistory.result === 'success' && isHistoryMatch) {
                            console.log('[Backup Lite] History shows success, marking as completed', { 
                                historyTimestamp, 
                                jobStartRaw, 
                                latestHistory 
                            });
                            markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'), completionMeta);
                            return; // Stop polling
                        }
                    }
                }
                
                // Check for completion conditions
                // Priority 1: Explicit success/completed status
                if (status === 'success' || status === 'completed') {
                    // Mark as final result BEFORE showing modal
                    restoreMonitor.hasFinalResult = true;
                    restoreMonitor.lastStatus = 'success';
                    console.log('[Backup Lite] Job status is success/completed, marking as completed', { status, progress, jobId });
                    markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'), completionMeta);
                    return; // Stop polling
                } 
                // Priority 2: Progress is 100% - check history or wait for status update
                else if (progress >= 100) {
                    // Progress is 100% - check if we should wait for status update or assume completion
                    // Ensure progress bar shows 100%
                    if (currentProgress < 100) {
                        currentProgress = 100;
                        var progressFill3 = document.getElementById('restore-progress-fill');
                        var progressText3 = document.getElementById('restore-progress-text');
                        updateProgressDisplay(100, progressBar, progressFill3, progressText3);
                    }
                    
                    // CRITICAL: Check for failed status FIRST when at 100% to stop polling immediately
                    if (status === 'failed') {
                        // Mark as final result BEFORE showing modal
                        restoreMonitor.hasFinalResult = true;
                        restoreMonitor.lastStatus = 'failed';
                        console.log('[Backup Lite] Progress at 100% with failed status, stopping immediately', { status, progress, jobId });
                        // Stop simulated progress if still running
                        if (restoreJobProgressTimer) {
                            clearInterval(restoreJobProgressTimer);
                            restoreJobProgressTimer = null;
                        }
                        stopRestoreJobMonitor();
                        restoreInProgress = false;
                        restoreCompleted = false;
                        if (startButton) {
                            startButton.disabled = false;
                        }
                        setProgress(100, job.message || (strings.errorGeneric || 'Restore failed.'), true);
                        syncWizard();
                        updateRestoreCancelState();
                        showToast('❌ ' + (job.message || strings.errorGeneric || 'Restore failed.'), 'error');
                        
                        // Show failure overlay (only once)
                        if (!restoreCompletionShown && !backupLiteRestoreFailureShown) {
                            restoreCompletionShown = true;
                            backupLiteRestoreFailureShown = true;
                            showCompletionOverlay({
                                icon: '❌',
                                title: strings.restoreFailed || 'Restore Failed',
                                message: job.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                type: 'error'
                            });
                        }
                        return; // Stop polling immediately
                    }
                    
                    if (status === 'running' || status === '' || !status) {
                        // Status hasn't been updated yet - wait a bit for it to catch up
                        // Update UI to show "Finalizing" instead of "Restore running" when at 100%
                        var statusIcon = document.getElementById('restore-status-icon');
                        var statusTitle = document.getElementById('restore-status-title');
                        var statusTitleText = document.getElementById('restore-status-title-text');
                        var statusMessage = document.getElementById('restore-status-message');
                        
                        // Change status to "Finalizing" when at 100% but status still running
                        if (statusIcon) {
                            statusIcon.textContent = '⏳';
                        }
                        if (statusTitle) {
                            statusTitle.style.color = 'var(--bl-primary)';
                        }
                        if (statusTitleText) {
                            statusTitleText.textContent = strings.restoreFinalizing || 'Finalizing restore...';
                        } else if (statusTitle) {
                            statusTitle.textContent = strings.restoreFinalizing || 'Finalizing restore...';
                        }
                        if (statusMessage) {
                            statusMessage.textContent = strings.restoreFinalizingMessage || 'Completing final steps...';
                        }
                        
                        // When at 100%, immediately check history for failure/success status
                        // This ensures we detect failures as soon as possible
                        if (payload.history && payload.history.length > 0) {
                            var latestHistoryAt100 = payload.history[0];
                            if (latestHistoryAt100) {
                                var historyTimeAt100 = latestHistoryAt100.timestamp_raw || 0;
                                var jobStartAt100 = job.started_at_raw || job.created_at_raw || 0;
                                var isHistoryMatchAt100 = !jobStartAt100 || (historyTimeAt100 && historyTimeAt100 >= jobStartAt100);
                                
                                // Check for failure first (priority)
                                if (latestHistoryAt100.result === 'failed' && isHistoryMatchAt100) {
                                    // Mark as final result BEFORE showing modal
                                    restoreMonitor.hasFinalResult = true;
                                    restoreMonitor.lastStatus = 'failed';
                                    console.log('[Backup Lite] Progress at 100%, history shows failure, marking as failed immediately', { 
                                        latestHistoryAt100 
                                    });
                                    // Stop simulated progress if still running
                                    if (restoreJobProgressTimer) {
                                        clearInterval(restoreJobProgressTimer);
                                        restoreJobProgressTimer = null;
                                    }
                                    stopRestoreJobMonitor();
                                    restoreInProgress = false;
                                    restoreCompleted = false;
                                    if (startButton) {
                                        startButton.disabled = false;
                                    }
                                    setProgress(100, latestHistoryAt100.message || (strings.errorGeneric || 'Restore failed.'), true);
                                    syncWizard();
                                    updateRestoreCancelState();
                                    showToast('❌ ' + (latestHistoryAt100.message || strings.errorGeneric || 'Restore failed.'), 'error');
                                    
                                    // Show failure overlay (only once)
                                    if (!restoreCompletionShown && !backupLiteRestoreFailureShown) {
                                        restoreCompletionShown = true;
                                        backupLiteRestoreFailureShown = true;
                                        showCompletionOverlay({
                                            icon: '❌',
                                            title: strings.restoreFailed || 'Restore Failed',
                                            message: latestHistoryAt100.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                            confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                            type: 'error'
                                        });
                                    }
                                    return; // Stop polling immediately
                                }
                                // Check for success
                                else if (latestHistoryAt100.result === 'success' && isHistoryMatchAt100) {
                                    // Mark as final result BEFORE showing modal
                                    restoreMonitor.hasFinalResult = true;
                                    restoreMonitor.lastStatus = 'success';
                                    console.log('[Backup Lite] Progress at 100%, history shows success, marking as completed immediately', { 
                                        latestHistoryAt100 
                                    });
                                    markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                                    return; // Stop polling immediately
                                }
                            }
                        }
                        
                        if (restoreJobReached100Time) {
                            var timeAt100 = Date.now() - restoreJobReached100Time;
                            // If we've been at 100% for more than RESTORE_JOB_100_POLL_LIMIT (60 seconds), stop polling
                            if (timeAt100 > RESTORE_JOB_100_POLL_LIMIT) {
                                // Stop polling after timeout
                                console.warn('[Backup Lite] Progress at 100% for more than ' + (RESTORE_JOB_100_POLL_LIMIT / 1000) + ' seconds, stopping polling', { 
                                    timeAt100, 
                                    status, 
                                    progress,
                                    jobId
                                });
                                // CRITICAL: Check history one final time before giving up
                                checkRestoreCompletionFromHistory(jobId);
                                // Wait a moment for history check to complete
                                setTimeout(function() {
                                    // Only reset state if we still don't have a final result
                                    if (!restoreMonitor.hasFinalResult) {
                                        stopRestoreJobMonitor();
                                        restoreInProgress = false;
                                        restoreCompleted = false;
                                        activeRestoreJobId = null;
                                        if (startButton) {
                                            startButton.disabled = false;
                                        }
                                        syncWizard();
                                        updateRestoreCancelState();
                                        // Show warning toast instead of failure modal
                                        showToast('⚠️ ' + (strings.errorGeneric || 'Could not confirm restore status. Please check logs manually.'), 'warning');
                                    }
                                }, 1000);
                                return;
                            }
                            // If we've been at 100% for more than 3 seconds, check history and assume completion/failure
                            // Reduced from 5 seconds for faster feedback
                            if (timeAt100 > 3000) {
                                // Check history one more time before assuming completion
                                console.log('[Backup Lite] Progress at 100% for 3+ seconds, checking history before assuming completion', { 
                                    timeAt100, 
                                    status, 
                                    progress 
                                });
                                checkRestoreCompletionFromHistory(jobId);
                                // Wait a bit for history check to complete, then assume completion if no failure detected
                                setTimeout(function() {
                                    // Only assume completion if we haven't already shown failure overlay and don't have final result
                                    if (restoreMonitor.hasFinalResult) {
                                        return;
                                    }
                                    // Check if this is still the active job
                                    if (activeRestoreJobId !== jobId) {
                                        return;
                                    }
                                    var failureOverlay = document.querySelector('.bl-completion-overlay.is-visible[data-type="error"]');
                                    if (!failureOverlay) {
                                        // Mark as final result BEFORE showing modal
                                        restoreMonitor.hasFinalResult = true;
                                        restoreMonitor.lastStatus = 'success';
                                        markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                                    }
                                }, 500);
                                return;
                            }
                        } else {
                            // First time reaching 100%, record the time
                            restoreJobReached100Time = Date.now();
                            console.log('[Backup Lite] Progress reached 100%, checking history immediately', { status, progress });
                            // Check history immediately when we first reach 100%
                            checkRestoreCompletionFromHistory(jobId);
                        }
                    }
                    // Continue polling to catch status update, but only if we haven't reached timeout
                    if (restoreJobReached100Time && (Date.now() - restoreJobReached100Time) <= RESTORE_JOB_100_POLL_LIMIT) {
                        return;
                    } else {
                        // Timeout reached, stop polling
                        stopRestoreJobMonitor();
                        return;
                    }
                } else if (status === 'failed') {
                    // Stop simulated progress if still running
                    if (restoreJobProgressTimer) {
                        clearInterval(restoreJobProgressTimer);
                        restoreJobProgressTimer = null;
                    }
                    // Force progress to 100% to show completion
                    if (currentProgress < 100) {
                        if (progressAnimationId) {
                            cancelAnimationFrame(progressAnimationId);
                            progressAnimationId = null;
                        }
                        currentProgress = 100;
                        var progressFillFailed = document.getElementById('restore-progress-fill');
                        var progressTextFailed = document.getElementById('restore-progress-text');
                        updateProgressDisplay(100, progressBar, progressFillFailed, progressTextFailed);
                    }
                    
                    // Update status UI to show failure
                    var statusIcon = document.getElementById('restore-status-icon');
                    var statusTitle = document.getElementById('restore-status-title');
                    var statusTitleText = document.getElementById('restore-status-title-text');
                    var statusMessage = document.getElementById('restore-status-message');
                    
                    if (statusIcon) {
                        statusIcon.textContent = '❌';
                    }
                    if (statusTitle) {
                        statusTitle.style.color = 'var(--bl-error, #dc3232)';
                    }
                    if (statusTitleText) {
                        statusTitleText.textContent = strings.restoreFailed || 'Restore Failed';
                    } else if (statusTitle) {
                        statusTitle.textContent = strings.restoreFailed || 'Restore Failed';
                    }
                    if (statusMessage) {
                        statusMessage.textContent = job.message || (strings.errorGeneric || 'Restore failed.');
                    }
                    
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    syncWizard();
                    updateRestoreCancelState();
                    
                    // Show error toast
                    showToast('❌ ' + (job.message || strings.errorGeneric || 'Restore failed.'), 'error');
                    
                    // Show failure overlay
                    if (!restoreCompletionShown) {
                        restoreCompletionShown = true;
                        showCompletionOverlay({
                            icon: '❌',
                            title: strings.restoreFailed || 'Restore Failed',
                            message: job.message || strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                            confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                            type: 'error'
                        });
                    } else {
                        // If overlay was already shown, at least show error notification
                        notifyError({ message: job.message || (strings.errorGeneric || 'Restore failed.') });
                    }
                } else if (status === 'cancelled') {
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    syncWizard();
                    updateRestoreCancelState();
                    showToast(strings.restoreCancelSuccess || 'Restore cancelled.', 'warning');
                }
            }).catch(function (error) {
                // WordPress AJAX "0" indicates session lost or not authorised (often due to expired login).
                // Pause monitoring but keep jobId/state. Auto-resume after user re-logs in.
                if (error && error.code === 'wp_ajax_zero') {
                    pauseRestoreJobMonitor();
                    syncWizard();
                    updateRestoreCancelState();
                    if (!restoreAutoResumeToastShown) {
                        restoreAutoResumeToastShown = true;
                        showToast('⚠️ ' + (strings.sessionExpired || 'Your login session may have expired. Please re-login in another tab. Monitoring will auto-resume once you are logged in.'), 'warning');
                    }
                    console.warn('[Backup Lite] AJAX returned 0 (session/permission issue). Pausing monitor.', { jobId: jobId });
                    scheduleRestoreAutoResume(jobId);
                    return;
                }
                // Handle nonce expiration (invalid_nonce or 400/403/404 errors)
                var isNonceError = false;
                
                if (error && error.payload && error.payload.code === 'invalid_nonce') {
                    isNonceError = true;
                } else if (error && (error.status === 400 || error.status === 403 || error.status === 404)) {
                    // Check if response indicates nonce expiration
                    if (error.responseText) {
                        try {
                            var errorJson = JSON.parse(error.responseText);
                            if (errorJson && errorJson.data) {
                                if (errorJson.data.code === 'invalid_nonce' || errorJson.code === 'invalid_nonce') {
                                    isNonceError = true;
                                }
                            } else if (errorJson && errorJson.code === 'invalid_nonce') {
                                isNonceError = true;
                            }
                        } catch (e) {
                            // Not JSON, check for nonce-related text
                            if (error.responseText.indexOf('nonce') !== -1 || error.responseText.indexOf('nonces_expired') !== -1) {
                                isNonceError = true;
                            }
                        }
                    }
                    
                    // For 404 errors, it might be that WordPress AJAX hook is not registered
                    // In this case, directly check restore history to determine status
                    if (error.status === 404) {
                        console.warn('[Backup Lite] Received 404 error, checking restore history directly', { jobId, error });
                        // Try to check history directly by making a request without job_id
                        // This will return history which we can use to determine restore status
                        var historyCheckFormData = prepareFormData('museder_restoreone_restore_job_status');
                        // Don't append job_id - backend will return history only
                        ajaxRequest(historyCheckFormData).then(function(json) {
                            var payload = getJsonPayload(json) || {};
                            if (payload.history && payload.history.length > 0) {
                                var latestHistory = payload.history[0];
                                // Check if this restore job matches the latest history entry
                                if (latestHistory && latestHistory.result === 'success') {
                                    // Check if the timestamp matches (within 5 minutes)
                                    var historyTime = latestHistory.timestamp_raw || 0;
                                    var now = Math.floor(Date.now() / 1000);
                                    if (historyTime && (now - historyTime) < 300) {
                                        console.log('[Backup Lite] Found recent successful restore in history (404 fallback), marking as completed');
                                        markRestoreCompleted(strings.restoreCompleted || 'Restore Completed.');
                                        return;
                                    }
                                } else if (latestHistory && latestHistory.result === 'failed') {
                                    // Restore failed
                                    console.log('[Backup Lite] Found failed restore in history (404 fallback)');
                                    stopRestoreJobMonitor();
                                    restoreInProgress = false;
                                    restoreCompleted = false;
                                    if (startButton) {
                                        startButton.disabled = false;
                                    }
                                    setProgress(100, latestHistory.message || (strings.errorGeneric || 'Restore failed.'), true);
                                    syncWizard();
                                    updateRestoreCancelState();
                                    showToast('❌ ' + (strings.restoreFailed || 'Restore Failed'), 'error');
                                    if (!restoreCompletionShown) {
                                        restoreCompletionShown = true;
                                        showCompletionOverlay({
                                            icon: '❌',
                                            title: strings.restoreFailed || 'Restore Failed',
                                            message: latestHistory.message || (strings.errorGeneric || 'Restore failed. Please review the error log and try again.'),
                                            confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                            type: 'error'
                                        });
                                    }
                                    return;
                                }
                            }
                        }).catch(function(historyError) {
                            // If history check also fails, just log it
                            console.warn('[Backup Lite] History check failed after 404 error:', historyError);
                        });
                    }
                }
                
                if (isNonceError) {
                    console.log('[Backup Lite] Nonce expired, refreshing and retrying...', { jobId, error });
                    refreshAjaxNonce().then(function () {
                        if (activeRestoreJobId === jobId) {
                            // After refreshing nonce, immediately check job status again
                            // Also check history as fallback to detect completion
                            pollRestoreJob(jobId, silent);
                            
                            // Additional fallback: check history directly after nonce refresh
                            setTimeout(function() {
                                if (activeRestoreJobId === jobId) {
                                    checkRestoreCompletionFromHistory(jobId);
                                }
                            }, 1000);
                            
                            // Also check history immediately if we've been polling for a while
                            // This handles the case where restore completed during nonce expiration
                            if (restoreJobPollStartTime && (Date.now() - restoreJobPollStartTime) > 60000) {
                                // If we've been polling for more than 1 minute, check history immediately
                                checkRestoreCompletionFromHistory(jobId);
                            }
                        }
                    }).catch(function (refreshError) {
                        console.error('Backup Lite: unable to refresh nonce after failure.', refreshError);
                        // Even if refresh fails, try to check completion from history
                        if (activeRestoreJobId === jobId) {
                            checkRestoreCompletionFromHistory(jobId);
                        }
                    });
                    return;
                }
                
                // For other errors (including 404), distinguish between network errors and actual failures
                if (activeRestoreJobId === jobId) {
                    // Check if this is a network/technical error
                    var isNetworkError = false;
                    var errorMessage = error && error.message ? error.message.toLowerCase() : '';
                    var errorString = String(error).toLowerCase();
                    
                    if (errorMessage.indexOf('network') !== -1 || 
                        errorMessage.indexOf('timeout') !== -1 ||
                        errorMessage.indexOf('500') !== -1 ||
                        errorMessage.indexOf('failed to fetch') !== -1 ||
                        errorString.indexOf('network') !== -1 ||
                        errorString.indexOf('timeout') !== -1 ||
                        errorString.indexOf('500') !== -1) {
                        isNetworkError = true;
                    }
                    
                    if (isNetworkError) {
                        // For network/technical errors, stop polling and check history
                        if (!silent) {
                            console.warn('[Backup Lite] Restore job status network error, stopping polling and checking history:', error);
                        }
                        stopRestoreJobMonitor();
                        checkRestoreCompletionFromHistory(jobId);
                    } else {
                        // For other errors, log but don't assume failure
                        if (!silent) {
                            console.warn('[Backup Lite] Restore job status error (non-network), checking history as fallback:', error);
                        }
                        // Only check history if we haven't already shown a result
                        if (!restoreCompletionShown && !backupLiteRestoreFailureShown && !restoreMonitor.hasFinalResult) {
                            checkRestoreCompletionFromHistory(jobId);
                        }
                    }
                    
                    // If we've been polling for a while and getting consistent errors, check if restore completed
                    // Only show "could not confirm" message if we can't determine status
                    if (restoreJobPollStartTime && (Date.now() - restoreJobPollStartTime) > 120000) {
                        // If we've been polling for more than 2 minutes with errors, check history one more time
                        console.log('[Backup Lite] Polling timeout reached, checking history for final status');
                        setTimeout(function() {
                            if (activeRestoreJobId === jobId && !restoreMonitor.hasFinalResult) {
                                var historyCheckFormData = prepareFormData('museder_restoreone_restore_job_status');
                                ajaxRequest(historyCheckFormData).then(function(json) {
                                    var payload = getJsonPayload(json) || {};
                                    if (payload.history && payload.history.length > 0) {
                                        var latestHistory = payload.history[0];
                                        // Check if the timestamp matches (within 10 minutes to be safe)
                                        var historyTime = latestHistory.timestamp_raw || 0;
                                        var now = Math.floor(Date.now() / 1000);
                                        if (historyTime && (now - historyTime) < 600) {
                                            if (latestHistory && latestHistory.result === 'success') {
                                                // Restore completed successfully
                                                console.log('[Backup Lite] Timeout check: Found successful restore in history');
                                                markRestoreCompleted(strings.restoreCompleted || 'Restore Completed.');
                                                return;
                                            } else if (latestHistory && latestHistory.result === 'failed') {
                                                // Restore failed
                                                console.log('[Backup Lite] Timeout check: Found failed restore in history');
                                                stopRestoreJobMonitor();
                                                restoreInProgress = false;
                                                restoreCompleted = false;
                                                if (startButton) {
                                                    startButton.disabled = false;
                                                }
                                                setProgress(100, latestHistory.message || (strings.errorGeneric || 'Restore failed.'), true);
                                                syncWizard();
                                                updateRestoreCancelState();
                                                showToast('❌ ' + (strings.restoreFailed || 'Restore Failed'), 'error');
                                                if (!restoreCompletionShown && !backupLiteRestoreFailureShown) {
                                                    restoreCompletionShown = true;
                                                    backupLiteRestoreFailureShown = true;
                                                    restoreMonitor.hasFinalResult = true;
                                                    restoreMonitor.lastStatus = 'failed';
                                                    showCompletionOverlay({
                                                        icon: '❌',
                                                        title: strings.restoreFailed || 'Restore Failed',
                                                        message: strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                                        confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                                        type: 'error'
                                                    });
                                                }
                                            }
                                        }
                                    }
                                }).catch(function(historyError) {
                                    // If history check also fails, don't assume failure - just show message
                                    console.warn('[Backup Lite] History check failed after timeout, could not confirm final status:', historyError);
                                    stopRestoreJobMonitor();
                                    restoreInProgress = false;
                                    restoreCompleted = false;
                                    if (startButton) {
                                        startButton.disabled = false;
                                    }
                                    setProgress(100, strings.errorGeneric || 'Could not confirm restore status. Please check logs manually.', true);
                                    syncWizard();
                                    updateRestoreCancelState();
                                    showToast('⚠️ ' + (strings.errorGeneric || 'Could not confirm restore status. Please check logs manually.'), 'warning');
                                    // Don't show failure modal - just show toast message
                                });
                            }
                        }, 1000);
                    }
                } else if (error && error.status === 400 && error.payload && error.payload.message && error.payload.message.indexOf('Job identifier') !== -1) {
                    // Job ID was missing - try to check history to see if restore completed
                    // This handles the case where job_id was lost but restore might have completed
                    console.log('[Backup Lite] Job ID missing in request, checking history for completion');
                    // Try to get history without job_id
                    var historyFormData = prepareFormData('museder_restoreone_restore_job_status');
                    // Don't append job_id - backend will return history only
                    ajaxRequest(historyFormData).then(function(json) {
                        var payload = getJsonPayload(json) || {};
                        if (payload.history && payload.history.length > 0) {
                            var latestHistory = payload.history[0];
                            if (latestHistory && latestHistory.result === 'success') {
                                console.log('[Backup Lite] Found successful restore in history, marking as completed');
                                markRestoreCompleted(strings.restoreCompleted || 'Restore Completed.');
                            }
                        }
                    }).catch(function(historyError) {
                        // If history check also fails, just log it
                        if (!silent) {
                            console.warn('[Backup Lite] History check also failed:', historyError);
                        }
                    });
                } else {
                    // No active job, but we got an error - might be a stale request
                    // CRITICAL: If progress is at 100%, check history before resetting state
                    // This prevents showing error when restore actually completed
                    if (currentProgress >= 100 || displayProgress >= 100) {
                        console.log('[Backup Lite] Error at 100% progress, checking history before resetting state');
                        checkRestoreCompletionFromHistory(jobId);
                        // Wait a moment for history check to complete
                        setTimeout(function() {
                            // Only reset state if we still don't have a final result
                            if (!restoreMonitor.hasFinalResult) {
                                if (!silent) {
                                    console.warn('[Backup Lite] Restore job status failed and no active job, resetting state:', error);
                                }
                                stopRestoreJobMonitor();
                                restoreInProgress = false;
                                restoreCompleted = false;
                                activeRestoreJobId = null;
                                if (startButton) {
                                    startButton.disabled = false;
                                }
                                setProgress(0, '', false);
                                syncWizard();
                                updateRestoreCancelState();
                            }
                        }, 1000);
                        return;
                    }
                    // Reset state to prevent stuck progress (only if not at 100%)
                    if (!silent) {
                        console.warn('[Backup Lite] Restore job status failed and no active job, resetting state:', error);
                    }
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    activeRestoreJobId = null;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    setProgress(0, '', false);
                    syncWizard();
                    updateRestoreCancelState();
                }
                
                if (!silent) {
                    console.error('Restore job status failed:', error);
                }
            });
        }
        function abortChunkSession(sessionId) {
            if (!sessionId) {
                return;
            }
            var abortForm = prepareFormData('museder_restoreone_restore_chunk_abort');
            abortForm.append('session_id', sessionId);
            ajaxRequest(abortForm).catch(function () {});
        }
        function runLocalUpload(file) {
            if (!file) {
                return Promise.reject(new Error(strings.noFileSelected || 'Please choose a backup file first.'));
            }
            if (file.size <= SIMPLE_UPLOAD_LIMIT) {
                return runSimpleUpload(file);
            }
            return runChunkUpload(file);
        }
        function runSimpleUpload(file) {
            var formData = prepareFormData('museder_restoreone_restore_upload');
            formData.append('file', file);
            return ajaxRequest(formData).then(function (json) {
                handleSummaryResponse(json);
            });
        }
        function runChunkUpload(file) {
            uploadCancelRequested = false;
            activeUploadControllers = [];
            var uploadStats = {
                startedAt: Date.now(),
                uploadedChunks: 0,
                uploadedBytes: 0
            };

            function clearControllers() {
                activeUploadControllers = activeUploadControllers.filter(function (c) { return !!c; });
            }
            function abortAllControllers() {
                if (!activeUploadControllers || !activeUploadControllers.length) {
                    return;
                }
                activeUploadControllers.forEach(function (controller) {
                    try {
                        if (controller && typeof controller.abort === 'function') {
                            controller.abort();
                        }
                    } catch (e) {}
                });
                activeUploadControllers = [];
            }
            function queryChunkStatus(sessionId) {
                var fd = prepareFormData('museder_restoreone_restore_chunk_status');
                fd.append('session_id', sessionId);
                return ajaxRequest(fd).then(function (json) {
                    return getJsonPayload(json) || {};
                }).catch(function () {
                    return { received: [] };
                });
            }
            function startChunkSession(chunkSize) {
                var totalChunks = Math.max(1, Math.ceil(file.size / chunkSize));
            updateUploadStatus(chunkStrings.preparing);
            var prepareForm = prepareFormData('museder_restoreone_restore_chunk_prepare');
            prepareForm.append('filename', file.name);
            prepareForm.append('filesize', file.size);
                prepareForm.append('chunk_size', chunkSize);
            prepareForm.append('total_chunks', totalChunks);
            return ajaxRequest(prepareForm).then(function (json) {
                var payload = getJsonPayload(json) || {};
                var sessionId = payload.session_id;
                if (!sessionId) {
                    throw new Error(strings.errorGeneric || 'Unable to start chunk upload.');
                }
                    return { sessionId: sessionId, totalChunks: totalChunks };
                });
            }
            function uploadOneChunk(sessionId, chunkIndex, chunkSize, totalChunks, retryLeft, attempt) {
                if (uploadCancelRequested) {
                    var cancelled = new Error('Upload cancelled.');
                    cancelled.code = 'cancelled';
                    throw cancelled;
                }
                var start = chunkIndex * chunkSize;
                var end = Math.min(start + chunkSize, file.size);
                            var chunkBlob = file.slice(start, end);
                            var uploadForm = prepareFormData('museder_restoreone_restore_chunk_upload');
                            uploadForm.append('session_id', sessionId);
                            uploadForm.append('chunk_index', chunkIndex);
                            uploadForm.append('chunk', chunkBlob, file.name + '.part');

                var controller = null;
                if (typeof AbortController !== 'undefined') {
                    controller = new AbortController();
                    activeUploadControllers.push(controller);
                }
                clearControllers();

                return ajaxRequest(uploadForm, { signal: controller ? controller.signal : undefined }).then(function (res) {
                    uploadStats.uploadedChunks++;
                    uploadStats.uploadedBytes += Math.max(0, end - start);
                    return res;
                }).catch(function (error) {
                    // Nonce issues: try refresh once.
                    if (error && parseInt(error.status, 10) === 403) {
                        return refreshAjaxNonce().then(function () {
                            var retryForm = prepareFormData('museder_restoreone_restore_chunk_upload');
                            retryForm.append('session_id', sessionId);
                            retryForm.append('chunk_index', chunkIndex);
                            retryForm.append('chunk', chunkBlob, file.name + '.part');
                            return ajaxRequest(retryForm, { signal: controller ? controller.signal : undefined }).then(function (res2) {
                                uploadStats.uploadedChunks++;
                                uploadStats.uploadedBytes += Math.max(0, end - start);
                                return res2;
                            });
                        });
                    }
                    throw error;
                }).catch(function (error) {
                    var kind = classifyUploadError(error);
                    if (kind === 'aborted' || kind === 'cancelled') {
                        throw error;
                    }
                    if (kind === 'too_large') {
                        var tooLarge = new Error('Chunk too large');
                        tooLarge.code = 'too_large';
                        tooLarge.original = error;
                        throw tooLarge;
                    }
                    if (retryLeft <= 0) {
                        throw error;
                    }
                    var backoff = Math.min(8000, 500 * Math.pow(2, attempt || 0));
                    return sleep(backoff).then(function () {
                        return uploadOneChunk(sessionId, chunkIndex, chunkSize, totalChunks, retryLeft - 1, (attempt || 0) + 1);
                    });
                });
            }
            function buildMissingList(totalChunks, received) {
                var got = {};
                (received || []).forEach(function (idx) {
                    got[parseInt(idx, 10)] = true;
                });
                var missing = [];
                for (var i = 0; i < totalChunks; i++) {
                    if (!got[i]) {
                        missing.push(i);
                    }
                }
                return missing;
            }
            function uploadMissingConcurrent(sessionId, chunkSize, totalChunks, received, concurrency) {
                var missing = buildMissingList(totalChunks, received);
                var uploadedCount = (received && received.length) ? received.length : 0;
                var pointer = 0;
                concurrency = clampInt(concurrency, 1, MAX_CONCURRENCY);

                function updateProgressDisplayLocal() {
                    var percent = Math.min(99, Math.round((uploadedCount / totalChunks) * 100));
                    var elapsedSec = Math.max(0.001, (Date.now() - uploadStats.startedAt) / 1000);
                    var mbps = (uploadStats.uploadedBytes / (1024 * 1024)) / elapsedSec;
                    var speedText = isFinite(mbps) && mbps > 0 ? (' · ' + mbps.toFixed(1) + ' MB/s') : '';
                    updateUploadStatus(formatString(chunkStrings.uploading, [uploadedCount, totalChunks, percent]) + speedText);
                }
                updateProgressDisplayLocal();

                function worker() {
                    if (uploadCancelRequested) {
                        return Promise.resolve();
                    }
                    if (pointer >= missing.length) {
                        return Promise.resolve();
                    }
                    var idx = missing[pointer++];
                    return uploadOneChunk(sessionId, idx, chunkSize, totalChunks, 4, 0).then(function () {
                        uploadedCount++;
                        updateProgressDisplayLocal();
                        return worker();
                    });
                }

                var workers = [];
                for (var w = 0; w < concurrency; w++) {
                    workers.push(worker());
                }
                return Promise.all(workers).then(function () {
                    return { uploadedCount: uploadedCount };
                });
            }
            function finalizeSession(sessionId) {
                    updateUploadStatus(chunkStrings.merging);
                    var finalizeForm = prepareFormData('museder_restoreone_restore_chunk_finalize');
                    finalizeForm.append('session_id', sessionId);
                return ajaxRequest(finalizeForm);
            }

            // Hybrid + balanced: load saved tune, otherwise compute from server caps.
            var saved = loadAutotune();
            var initialTune = saved ? {
                chunkSize: clampInt(saved.chunkSize || DEFAULT_CHUNK_SIZE_BYTES, MIN_CHUNK_SIZE_BYTES, MAX_CHUNK_SIZE_BYTES),
                concurrency: clampInt(saved.concurrency || DEFAULT_CONCURRENCY, 1, MAX_CONCURRENCY)
            } : null;

            var tune = { chunkSize: DEFAULT_CHUNK_SIZE_BYTES, concurrency: DEFAULT_CONCURRENCY };
            var capsSnapshot = null;

            return fetchEnvCaps().then(function (caps) {
                capsSnapshot = caps;
                var fromCaps = computeInitialTuneFromCaps(caps);
                tune.chunkSize = initialTune ? initialTune.chunkSize : fromCaps.chunkSize;
                tune.concurrency = initialTune ? initialTune.concurrency : fromCaps.concurrency;
                // Large local uploads: start more aggressively, then fall back automatically on errors.
                if (file && file.size && file.size > (200 * 1024 * 1024)) { // > 200MB
                    tune.concurrency = clampInt(Math.max(tune.concurrency, 3), 1, MAX_CONCURRENCY);
                    // Prefer at least 4MB chunks if allowed.
                    if (tune.chunkSize < (4 * 1024 * 1024) && MAX_CHUNK_SIZE_BYTES >= (4 * 1024 * 1024)) {
                        tune.chunkSize = clampInt(4 * 1024 * 1024, MIN_CHUNK_SIZE_BYTES, MAX_CHUNK_SIZE_BYTES);
                    }
                }
                // Allow overriding max concurrency via localized config.
                if (autotuneConfig && autotuneConfig.maxConcurrency) {
                    MAX_CONCURRENCY = clampInt(autotuneConfig.maxConcurrency, 1, 6);
                    tune.concurrency = clampInt(tune.concurrency, 1, MAX_CONCURRENCY);
                }
                if (autotuneConfig && autotuneConfig.maxChunkBytes) {
                    MAX_CHUNK_SIZE_BYTES = clampInt(autotuneConfig.maxChunkBytes, MIN_CHUNK_SIZE_BYTES, 64 * 1024 * 1024);
                    tune.chunkSize = clampInt(tune.chunkSize, MIN_CHUNK_SIZE_BYTES, MAX_CHUNK_SIZE_BYTES);
                }
                return tune;
            }).then(function () {
                // Attempt upload; on 413 we restart with smaller chunk size.
                var attempt = 0;

                function runAttempt() {
                    attempt++;
                    return startChunkSession(tune.chunkSize).then(function (session) {
                        var sessionId = session.sessionId;
                        var totalChunks = session.totalChunks;
                        activeChunkSession = sessionId;
                        updateRestoreCancelState();

                        return queryChunkStatus(sessionId).then(function (status) {
                            var received = status && Array.isArray(status.received) ? status.received : [];
                            return uploadMissingConcurrent(sessionId, tune.chunkSize, totalChunks, received, tune.concurrency).then(function () {
                                return finalizeSession(sessionId).then(function (finalizeJson) {
                        activeChunkSession = null;
                        updateRestoreCancelState();
                                    // Save successful tune (balanced).
                                    saveAutotune({ chunkSize: tune.chunkSize, concurrency: tune.concurrency });
                        handleSummaryResponse(finalizeJson);
                                });
                    });
                });
            }).catch(function (error) {
                if (activeChunkSession) {
                    abortChunkSession(activeChunkSession);
                    activeChunkSession = null;
                    updateRestoreCancelState();
                }
                        abortAllControllers();

                        if (uploadCancelRequested) {
                            throw error;
                        }

                        // Too large: reduce chunk and retry (up to 3 attempts).
                        if (error && error.code === 'too_large' && attempt < 4) {
                            tune.chunkSize = Math.max(MIN_CHUNK_SIZE_BYTES, Math.floor(tune.chunkSize / 2));
                            console.warn('[Backup Lite] Chunk too large, reducing chunk size and retrying', { chunkSize: tune.chunkSize });
                            return runAttempt();
                        }

                        // Server/network errors: reduce concurrency and retry once if possible.
                        var kind = classifyUploadError(error);
                        if ((kind === 'server_error' || kind === 'network_error') && attempt < 3) {
                            tune.concurrency = Math.max(1, tune.concurrency - 1);
                            console.warn('[Backup Lite] Upload error, reducing concurrency and retrying', { concurrency: tune.concurrency, kind: kind });
                            return runAttempt();
                        }
                        throw error;
                    });
                }

                return runAttempt();
            }).catch(function (error) {
                if (activeChunkSession) {
                    abortChunkSession(activeChunkSession);
                    activeChunkSession = null;
                    updateRestoreCancelState();
                }
                abortAllControllers();
                throw error;
            });
        }

        function cancelRestoreProcess() {
            if (!restoreCancelBtn) {
                return;
            }

            restoreCancelBtn.disabled = true;
            uploadCancelRequested = true;
            if (activeUploadControllers && activeUploadControllers.length) {
                activeUploadControllers.forEach(function (controller) {
                    try {
                        if (controller && typeof controller.abort === 'function') {
                            controller.abort();
                        }
                    } catch (e) {}
                });
                activeUploadControllers = [];
            }

            if (activeChunkSession) {
                abortChunkSession(activeChunkSession);
                activeChunkSession = null;
            }

            var cancelForm = prepareFormData(activeRestoreJobId ? 'museder_restoreone_restore_job_cancel' : 'museder_restoreone_restore_cancel');
            if (activeRestoreJobId) {
                cancelForm.append('job_id', activeRestoreJobId);
            }

            ajaxRequest(cancelForm).then(function (json) {
                var payload = getJsonPayload(json) || {};
                restoreCancelBtn.disabled = false;
                stopRestoreJobMonitor();
                renderSummary(null);
                // Reset progress tracking when cancelling
                currentProgress = 0;
                setProgress(0, strings.awaitingRestore || 'Awaiting restore.', false);
                var progressContainer = document.getElementById('restore-progress-container');
                var waitingMessage = document.getElementById('restore-waiting-message');
                if (progressContainer) {
                    progressContainer.style.display = 'none';
                }
                if (waitingMessage) {
                    waitingMessage.style.display = 'block';
                }
                isAnalyzing = false;
                analysisError = false;
                hasAnalyzed = false;
                restoreCompleted = false;
                reviewCompleted = false;
                restoreInProgress = false;
                restoreCompletionShown = false;
                if (activeRestoreJobId) {
                    clearStep3StartedAt(activeRestoreJobId);
                }
                updateStep3TimerDisplay(activeRestoreJobId);
                if (startButton) {
                    startButton.disabled = false;
                }
                syncWizard();
                updateRestoreCancelState();
                var message = (payload && payload.message) ? payload.message : (strings.restoreCancelSuccess || 'Restore process cancelled.');
                showToast(message, 'warning');
            }).catch(function (error) {
                // Better error handling for cancel operation
                var errorMessage = strings.restoreCancelFailed || 'Unable to cancel restore.';
                if (error) {
                    if (error.message) {
                        errorMessage = error.message;
                    } else if (error.payload && error.payload.message) {
                        errorMessage = error.payload.message;
                    } else if (typeof error === 'string') {
                        errorMessage = error;
                    }
                }
                
                // Still reset the UI state even if cancel request failed
                restoreCancelBtn.disabled = false;
                stopRestoreJobMonitor();
                restoreInProgress = false;
                restoreCompleted = false;
                backupLiteRestoreFailureShown = false; // Reset failure flag when restarting
                if (startButton) {
                    startButton.disabled = false;
                }
                syncWizard();
                updateRestoreCancelState();
                
                // Show error message
                showToast(errorMessage, 'error');
                
                // Log error for debugging
                console.error('Cancel restore error:', error);
            });
        }
        var startButton = document.getElementById('startRestore');

        function setWizardNode(step, state) {
            if (wizardSteps[step]) {
                wizardSteps[step].dataset.blState = state;
            }
        }

        function toggleReviewLock(locked) {
            var card = stepCards.review;
            if (!card) {
                return;
            }
            card.classList.toggle('step-locked', locked);
            var inputs = card.querySelectorAll('input, select, textarea');
            inputs.forEach(function (input) {
                input.disabled = locked;
            });
        }

        function toggleExecuteLock(locked) {
            if (startButton) {
                startButton.disabled = locked;
                startButton.classList.toggle('button-disabled', locked);
            }
        }

        var restoreCancelBtn = document.getElementById('restore-cancel-btn');

        function toggleRestoreCancelButton(active) {
            if (!restoreCancelBtn) {
                return;
            }
            if (active) {
                restoreCancelBtn.style.display = '';
                restoreCancelBtn.disabled = false;
            } else {
                restoreCancelBtn.style.display = 'none';
                restoreCancelBtn.disabled = true;
            }
        }

        function updateRestoreCancelState() {
            var active = !!(restoreInProgress || isAnalyzing || activeChunkSession);
            toggleRestoreCancelButton(active);
        }

        function setStepStatus(step, state, text) {
            var node = stepStatusNodes[step];
            if (!node) {
                return;
            }
            node.dataset.status = state;
            if (typeof text === 'string') {
                var textNode = node.querySelector('.status-text');
                if (textNode) {
                    textNode.textContent = text;
                }
            }
        }

        function syncWizard() {
            var uploadState = 'idle';
            var uploadText = stepStrings.uploadIdle;
            if (analysisError) {
                uploadState = 'error';
                uploadText = stepStrings.uploadError;
            } else if (isAnalyzing) {
                uploadState = 'processing';
                uploadText = stepStrings.uploadProcessing;
            } else if (hasAnalyzed) {
                uploadState = 'done';
                uploadText = stepStrings.uploadDone;
            }
            setWizardNode('upload', hasAnalyzed ? 'done' : 'active');
            setStepStatus('upload', uploadState, uploadText);

            var reviewLocked = !hasAnalyzed;
            toggleReviewLock(reviewLocked);
            var reviewState;
            var reviewText;
            var reviewNodeState = 'active';
            if (reviewLocked) {
                reviewState = 'locked';
                reviewText = stepStrings.reviewLocked;
                reviewNodeState = 'locked';
            } else if (reviewCompleted) {
                reviewState = 'done';
                reviewText = stepStrings.reviewDone || stepStrings.reviewReady;
                reviewNodeState = 'done';
            } else {
                reviewState = 'ready';
                reviewText = stepStrings.reviewReady;
            }
            setWizardNode('review', reviewNodeState);
            setStepStatus('review', reviewState, reviewText);

            var executeState = 'locked';
            var executeText = stepStrings.executeLocked;
            var executeLocked = true;
            if (restoreInProgress) {
                executeState = 'processing';
                executeText = stepStrings.executeProcessing;
                executeLocked = true;
            } else if (restoreCompleted) {
                executeState = 'done';
                executeText = stepStrings.executeDone;
                executeLocked = false;
            } else if (reviewCompleted) {
                // Only unlock step 3 if step 2 (review) is completed
                executeState = 'ready';
                executeText = stepStrings.executeReady;
                executeLocked = false;
            }
            setWizardNode('execute', executeState === 'done' ? 'done' : (executeState === 'locked' ? 'locked' : 'active'));
            setStepStatus('execute', executeState, executeText);
            toggleExecuteLock(executeLocked);
        }

        function resetAnalysisState(options) {
            var opts = options || {};
            if (opts.processing) {
                isAnalyzing = true;
                analysisError = false;
            } else if (opts.error) {
                isAnalyzing = false;
                analysisError = true;
            } else {
                isAnalyzing = false;
                analysisError = false;
            }
            if (!opts.keepAnalysis) {
                hasAnalyzed = false;
                restoreCompleted = false;
            }
            if (!opts.keepReview) {
                reviewCompleted = false;
            }
            if (opts.restoreInProgress !== undefined) {
                restoreInProgress = opts.restoreInProgress;
            }
            syncWizard();
            updateRestoreCancelState();
        }

        function reportError(payload) {
            if (typeof handleError === 'function') {
                handleError(payload);
            } else {
                var message = payload && payload.message ? payload.message : (strings.errorGeneric || 'Something went wrong.');
                console.error(message, payload);
                showToast(message, 'error');
            }
        }

        syncWizard();
        updateRestoreCancelState();

        methodButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                methodButtons.forEach(function (button) {
                    button.classList.remove('active');
                });
                btn.classList.add('active');
                var method = btn.getAttribute('data-method');
                methodPanels.forEach(function (panel) {
                    panel.classList.remove('active');
                });
                var target = document.getElementById('restore-' + method);
                if (target) {
                    target.classList.add('active');
                }
                
                // Load backups list when switching to "existing" method
                if (method === 'existing' && existingSelect) {
                    loadBackupsList();
                }
            });
        });
        
        // Function to load backups list via AJAX
        function loadBackupsList() {
            if (!existingSelect) {
                return;
            }
            
            // Show loading state
            existingSelect.disabled = true;
            var originalHTML = existingSelect.innerHTML;
            existingSelect.innerHTML = '<option value="">' + (strings.loadingBackups || 'Loading backups...') + '</option>';
            
            var formData = prepareFormData('museder_restoreone_get_backups_list');
            
            ajaxRequest(formData).then(function (json) {
                existingSelect.disabled = false;
                if (json && json.success && json.data && json.data.backups) {
                    var backups = json.data.backups;
                    existingSelect.innerHTML = '<option value="">' + (strings.selectBackup || 'Select a backup…') + '</option>';
                    backups.forEach(function (backup) {
                        var option = document.createElement('option');
                        option.value = backup.name;
                        option.textContent = backup.name + ' (' + backup.size_human + ')';
                        existingSelect.appendChild(option);
                    });
                } else {
                    existingSelect.innerHTML = '<option value="">' + (strings.noBackups || 'No backups available.') + '</option>';
                }
            }).catch(function (error) {
                existingSelect.disabled = false;
                existingSelect.innerHTML = '<option value="">' + (strings.errorLoadingBackups || 'Error loading backups.') + '</option>';
                console.error('Failed to load backups list:', error);
                if (error && error.message) {
                    notifyError({ message: error.message });
                }
            });
        }

        if (uploadInput) {
            uploadInput.addEventListener('change', resetAnalysisState);
        }
        if (existingSelect) {
            existingSelect.addEventListener('change', resetAnalysisState);
            // Load backups once on page load so the dropdown is always current
            loadBackupsList();
        }

        function setProgress(percent, message, done) {
            var progressContainer = document.getElementById('restore-progress-container');
            var waitingMessage = document.getElementById('restore-waiting-message');
            var statusIcon = document.getElementById('restore-status-icon');
            var statusTitle = document.getElementById('restore-status-title');
            var statusMessage = document.getElementById('restore-status-message');
            var progressFill = document.getElementById('restore-progress-fill');
            var progressText = document.getElementById('restore-progress-text');
            
            var targetPercent = Math.max(0, Math.min(100, percent || 0));

            // Guard: while a restore is still running, avoid showing a "completed" message in the progress text
            // (can happen if a stale/optimistic message leaks into polling responses).
            var safeMessage = message || '';
            if (!done && restoreInProgress && safeMessage) {
                var lowered = String(safeMessage).toLowerCase();
                if (lowered.indexOf('completed') !== -1 || lowered.indexOf('successfully') !== -1) {
                    safeMessage = targetPercent >= 85
                        ? (strings.restoreFinalizingMessage || strings.restoreFinalizing || 'Finalizing restore…')
                        : (strings.restoreInProgress || 'Restore in Progress');
                }
            }
            
            // Cancel any existing animation
            if (progressAnimationId) {
                cancelAnimationFrame(progressAnimationId);
                progressAnimationId = null;
            }
            
            // Get progress bar elements if not already available
            if (!progressBar) {
                progressBar = document.querySelector('.restore-progress .progress-bar-fill');
            }
            
            // If done or target is 100%, update immediately (no animation delay for completion)
            // Also update immediately if target is less than current (backwards progress)
            if (done || targetPercent >= 100 || targetPercent <= currentProgress) {
                currentProgress = targetPercent;
                updateProgressDisplay(currentProgress, progressBar, progressFill, progressText);
            } else {
                // Animate progress smoothly from current to target
                // But use shorter duration for large jumps to avoid long waits
                var progressDiff = Math.abs(targetPercent - currentProgress);
                var duration = progressDiff > 30 ? 600 : 800; // Faster for large jumps
                animateProgress(currentProgress, targetPercent, progressBar, progressFill, progressText, duration);
            }
            if (progressStatus) {
                // Only show completion message if done is true AND progress is at 100%
                // This prevents showing "Restore completed successfully" when progress is still below 100%
                if (done && targetPercent >= 100) {
                    progressStatus.textContent = safeMessage || strings.restoreCompleted || 'Restore Completed.';
                } else if (done && targetPercent < 100) {
                    // done=true but progress < 100% - show the message but not completion text
                    progressStatus.textContent = safeMessage || strings.awaitingRestore || 'Awaiting restore.';
                } else if (safeMessage) {
                    // Add a non-intrusive hint if we're actively using browser-driven ticks.
                    if (restoreTickFallbackActive && restoreInProgress) {
                        var hint = strings.restoreTickFallbackActive || 'Cron appears unreliable. Using your browser to push restore progress…';
                        progressStatus.textContent = safeMessage + ' ' + hint;
                    } else {
                    progressStatus.textContent = safeMessage;
                    }
                } else {
                    progressStatus.textContent = strings.awaitingRestore || 'Awaiting restore.';
                }
            }
            if (statusMessage) {
                statusMessage.textContent = safeMessage || '';
            }
            
            // Show/hide progress container
            if (targetPercent > 0 || done) {
                if (progressContainer) {
                    progressContainer.style.display = 'block';
                }
                if (waitingMessage) {
                    waitingMessage.style.display = 'none';
                }
            } else {
                if (progressContainer) {
                    progressContainer.style.display = 'none';
                }
                if (waitingMessage) {
                    waitingMessage.style.display = 'block';
                }
            }
            
            // Update status based on progress
            var statusIconInline = document.getElementById('restore-status-icon-inline');
            var statusTitleText = document.getElementById('restore-status-title-text');
            
            // Only show "Restore Completed" if done is true AND progress is at 100% AND we're actually in a restore operation
            // Don't show completion status during step 1 (file analysis) or when progress is still below 100%
            if (done && targetPercent >= 100 && restoreInProgress) {
                // This is a real restore completion
                if (statusIcon) {
                    statusIcon.textContent = '✅';
                }
                if (statusIconInline) {
                    statusIconInline.style.display = 'inline-block';
                    statusIconInline.textContent = '✅';
                }
                if (statusTitle) {
                    statusTitle.style.color = 'var(--bl-success)';
                }
                if (statusTitleText) {
                    statusTitleText.textContent = strings.restoreCompleted || 'Restore Completed';
                } else if (statusTitle) {
                    statusTitle.textContent = strings.restoreCompleted || 'Restore Completed';
                }
                restoreCompleted = true;
                syncWizard();
            } else if (done && !restoreInProgress) {
                // done=true but not in restore - this shouldn't happen, but reset to avoid showing completion
                if (statusIcon) {
                    statusIcon.textContent = '';
                }
                if (statusIconInline) {
                    statusIconInline.style.display = 'none';
                }
                if (statusTitle) {
                    statusTitle.style.color = '';
                }
                if (statusTitleText) {
                    statusTitleText.textContent = '';
                } else if (statusTitle) {
                    statusTitle.textContent = '';
                }
            } else if (targetPercent > 0 && restoreInProgress) {
                // Only show "Restore in Progress" if we're actually in a restore operation
                if (statusIcon) {
                    statusIcon.textContent = '⚡';
                }
                if (statusIconInline) {
                    statusIconInline.style.display = 'none';
                }
                if (statusTitle) {
                    statusTitle.style.color = 'var(--bl-primary)';
                }
                if (statusTitleText) {
                    statusTitleText.textContent = strings.restoreInProgress || 'Restore in Progress';
                } else if (statusTitle) {
                    statusTitle.textContent = strings.restoreInProgress || 'Restore in Progress';
                }
            } else if (targetPercent > 0 && !restoreInProgress) {
                // Progress > 0 but not in restore - this is likely step 1 analysis
                // Don't show restore status, just clear it
                if (statusIcon) {
                    statusIcon.textContent = '';
                }
                if (statusIconInline) {
                    statusIconInline.style.display = 'none';
                }
                if (statusTitle) {
                    statusTitle.style.color = '';
                }
                if (statusTitleText) {
                    statusTitleText.textContent = '';
                } else if (statusTitle) {
                    statusTitle.textContent = '';
                }
            }
            
            // Update restoreCompleted flag - only set to true if done AND progress is at 100% AND in restore operation
            // Don't set restoreCompleted during step 1 (file analysis) or when progress is still below 100%
            if (done && targetPercent >= 100 && restoreInProgress) {
                // This is a real restore completion
                restoreInProgress = false;
                restoreCompleted = true;
                syncWizard();
            } else if (targetPercent > 0 && restoreInProgress) {
                // If we have an active restore job, keep restoreInProgress true
                // This ensures the state is maintained during restore operations
                if (activeRestoreJobId) {
                    restoreInProgress = true;
                    restoreCompleted = false;
                } else if (restoreInProgress) {
                    // If already in progress, maintain the state
                    restoreCompleted = false;
                }
                syncWizard();
            } else if (targetPercent === 0 && !done) {
                // If progress is reset to 0 and not done, only clear restoreInProgress if no active job
                if (!activeRestoreJobId) {
                    restoreInProgress = false;
                    restoreCompleted = false;
                }
                syncWizard();
            } else if (done && !restoreInProgress) {
                // done=true but not in restore - this shouldn't happen, but ensure we don't set restoreCompleted
                // This handles cases where step 1 might incorrectly pass done=true
                restoreCompleted = false;
                syncWizard();
            }
            updateRestoreCancelState();
        }

        /**
         * Update progress bar display immediately
         */
        function updateProgressDisplay(percent, progressBarEl, progressFillEl, progressTextEl) {
            var roundedPercent = Math.round(percent);
            if (progressBarEl) {
                progressBarEl.style.width = percent + '%';
                progressBarEl.style.transition = 'width 0.3s ease-out';
            }
            if (progressFillEl) {
                progressFillEl.style.width = percent + '%';
                progressFillEl.style.transition = 'width 0.3s ease-out';
            }
            if (progressTextEl) {
                progressTextEl.textContent = roundedPercent + '%';
            }
        }

        /**
         * Animate progress bar smoothly from current to target value
         */
        function animateProgress(from, to, progressBarEl, progressFillEl, progressTextEl, duration) {
            duration = duration || 800; // Default 800ms if not specified
            var startTime = null;
            var startValue = from;
            var endValue = to;
            
            // Use easing function for smooth animation (ease-out cubic)
            function easeOutCubic(t) {
                return 1 - Math.pow(1 - t, 3);
            }
            
            function animate(currentTime) {
                if (startTime === null) {
                    startTime = currentTime;
                }
                
                var elapsed = currentTime - startTime;
                var progress = Math.min(elapsed / duration, 1);
                var eased = easeOutCubic(progress);
                
                currentProgress = startValue + (endValue - startValue) * eased;
                updateProgressDisplay(currentProgress, progressBarEl, progressFillEl, progressTextEl);
                
                if (progress < 1) {
                    progressAnimationId = requestAnimationFrame(animate);
                } else {
                    currentProgress = endValue;
                    updateProgressDisplay(currentProgress, progressBarEl, progressFillEl, progressTextEl);
                    progressAnimationId = null;
                }
            }
            
            progressAnimationId = requestAnimationFrame(animate);
        }

        function renderSummary(summary) {
            if (!summaryContainer) {
                return;
            }
            if (!summary) {
                summaryContainer.innerHTML = '<p>' + (strings.noFileSelected || 'No file selected yet.') + '</p>';
                return;
            }
            var html = '';
            html += '<p><strong>' + (strings.fileLabel || 'File:') + '</strong> ' + summary.name + '</p>';
            html += '<p><strong>' + (strings.sizeLabel || 'Size:') + '</strong> ' + summary.size + '</p>';
            if (summary.sha1) {
                html += '<p><strong>SHA1:</strong> <code>' + summary.sha1 + '</code></p>';
            }
            if (summary.source) {
                html += '<p><strong>' + (strings.sourceLabel || 'Source:') + '</strong> ' + summary.source + '</p>';
            }
            if (summary.db_prefix_source && summary.db_prefix_target) {
                html += '<p><strong>' + (strings.dbPrefixLabel || 'DB Prefix (backup → target):') + '</strong> <code>' + summary.db_prefix_source + '</code> → <code>' + summary.db_prefix_target + '</code></p>';
            }
            summaryContainer.innerHTML = html;
        }

        // DISABLED: Restore History is now rendered server-side in PHP template (page-restore.php)
        // This function was causing "undefined" display issues by overwriting PHP-rendered content.
        // The table is now fully rendered in PHP with proper escaping and data structure.
        /*
        function renderHistory(history) {
            if (!historyTable) {
                return;
            }
            if (!history || !history.length) {
                historyTable.innerHTML = '<tr><td colspan="4">' + (strings.noHistory || 'No restore history recorded yet.') + '</td></tr>';
                return;
            }
            var downloadLabel = strings.downloadLog || 'Download';
            historyTable.innerHTML = history.map(function (item) {
                var result = item.result ? item.result.charAt(0).toUpperCase() + item.result.slice(1) : '';
                var logCell = item.log_url ? '<a class=\"button button-small\" href=\"' + item.log_url + '\" target=\"_blank\" rel=\"noopener noreferrer\">' + downloadLabel + '</a>' : '<em>N/A</em>';
                return '<tr><td>' + item.timestamp + '</td><td>' + item.file + '</td><td>' + result + '</td><td>' + logCell + '</td></tr>';
            }).join('');
        }
        */

        function getJsonPayload(response) {
            if (!response) {
                return null;
            }
            if (typeof response.success === 'boolean' && response.data !== undefined) {
                return response.data;
            }
            return response;
        }

        function prepareFormData(action) {
            var formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', nonce);
            return formData;
        }

        function handleSummaryResponse(json) {
            if (!json) {
                return;
            }
            var payload = getJsonPayload(json) || {};
            if (json.success === false) {
                isAnalyzing = false;
                analysisError = true;
                hasAnalyzed = false;
                restoreCompleted = false;
                // Only reset restoreInProgress if not currently restoring
                if (!restoreInProgress) {
                    restoreInProgress = false;
                }
                syncWizard();
                notifyError(payload);
                clearStep1Started();
                updateStep1TimerDisplay('');
                return;
            }
            if (payload.summary) {
                renderSummary(payload.summary);
                // Keep JS state in sync for same-page flow (prevents requiring hard reload to enable Step 3).
                restoreData.summary = payload.summary;
            }

            // If DB payload is missing, surface a clear UI hint and offer files-only restore.
            try {
                var s = payload.summary || restoreData.summary || {};
                var dbPresent = !!s.db_present;
                var dbType = String(s.db_type || '');
                if (filesOnlyWrap && filesOnlyToggle) {
                    if (!dbPresent) {
                        filesOnlyWrap.style.display = '';
                        filesOnlyToggle.checked = true; // default to safest option when DB is missing
                        showToast('⚠️ ' + (strings.dbMissingHint || 'Database file not found in this backup. Files-only restore is recommended.'), 'warning');
                    } else {
                        // Hide files-only toggle for normal backups (avoid confusion).
                        filesOnlyWrap.style.display = 'none';
                        filesOnlyToggle.checked = false;
                    }
                }
                // If SQL-only, show manual DB hint (still WP-compliant; no auto SQL execution).
                if (dbPresent && dbType === 'sql') {
                    if (filesOnlyWrap && filesOnlyToggle) {
                        filesOnlyWrap.style.display = '';
                        filesOnlyToggle.checked = true;
                    }
                    showToast('⚠️ ' + (strings.dbSqlManualHint || 'This backup contains database.sql. Automatic DB import is disabled; files will be restored and DB must be imported manually.'), 'warning');
                }
            } catch (e) {}
            if (payload.progress) {
                // When step 1 completes, we should NOT set done=true for the progress
                // This is just file analysis, not restore completion
                var progressDone = false; // Always false for step 1 completion
                setProgress(payload.progress.percent || 0, payload.progress.message || '', progressDone);
            }
            showToast('✅ ' + (strings.messageReady || 'Backup ready for restore.'), 'info');
            isAnalyzing = false;
            analysisError = false;
            hasAnalyzed = true;
            // Auto-complete Step 2 by default so Step 3 can start immediately.
            // Users can still adjust options before clicking Start Restore.
            reviewCompleted = true;
            // Step 1 completion should NEVER set restoreCompleted to true
            // restoreCompleted should only be true after Step 3 (restore execution) completes
            restoreCompleted = false;
            // Only reset restoreInProgress if not currently restoring
            // This prevents resetting the state during an active restore operation
            if (!restoreInProgress && !activeRestoreJobId) {
                restoreInProgress = false;
            }
            restoreCompletionShown = false;
            syncWizard();
            updateRestoreCancelState();
            // Step 1 timer: finalize and display duration.
            var step1Started = getStep1StartedAt();
            if (step1Started) {
                updateStep1TimerDisplay('⏱ ' + formatDurationMs(Date.now() - step1Started));
                clearStep1Started();
            }
        }

        // If a new Step 1 upload begins (chunk-upload-v2), clear stale summary and lock Step 3 until analysis completes.
        document.addEventListener('backup-lite-restore-upload-start', function () {
            try {
                renderSummary(null);
            } catch (e) {}
            try {
                restoreData.summary = null;
            } catch (e2) {}
            isAnalyzing = true;
            analysisError = false;
            hasAnalyzed = false;
            reviewCompleted = false;
            restoreCompleted = false;
            // Keep restoreInProgress false here; actual restore is Step 3.
            restoreInProgress = false;
            activeRestoreJobId = null;
            if (filesOnlyWrap && filesOnlyToggle) {
                filesOnlyWrap.style.display = 'none';
                filesOnlyToggle.checked = false;
            }
            syncWizard();
            updateRestoreCancelState();
        });

        // If upload failed/finalize failed, ensure Step 3 cannot proceed with old summary.
        document.addEventListener('backup-lite-restore-upload-failed', function () {
            try {
                renderSummary(null);
            } catch (e) {}
            try {
                restoreData.summary = null;
            } catch (e2) {}
            isAnalyzing = false;
            analysisError = true;
            hasAnalyzed = false;
            reviewCompleted = false;
            restoreCompleted = false;
            restoreInProgress = false;
            activeRestoreJobId = null;
            syncWizard();
            updateRestoreCancelState();
        });
        
        // Expose handleSummaryResponse to window.MusederRestoreOneUI for chunk-upload-v2.js
        if (typeof window.MusederRestoreOneUI === 'undefined') {
            window.MusederRestoreOneUI = {};
        }
        window.MusederRestoreOneUI.handleSummaryResponse = handleSummaryResponse;

        if (restoreData.summary) {
            renderSummary(restoreData.summary);
        }
        if (restoreData.history) {
            // DISABLED: Restore History is now rendered server-side in PHP template
            // renderHistory(restoreData.history);
        }
        
        // Check for active or completed restore job
        if (restoreData.job && restoreData.job.id) {
            var fileSize = (restoreData.summary && restoreData.summary.size) ? restoreData.summary.size : 0;
            var jobStatus = restoreData.job.status || '';
            
            // Check if job is already complete on page load (e.g., after re-login)
            if (jobStatus === 'success' || jobStatus === 'completed') {
                console.log('[Backup Lite] Job already complete on page load, marking as completed', { jobId: restoreData.job.id, status: jobStatus });
                if (startButton) {
                    startButton.disabled = false;
                }
                // Use setTimeout to ensure all functions are initialized
                setTimeout(function() {
                    markRestoreCompleted(restoreData.job.message || (strings.restoreCompleted || 'Restore Completed.'));
                }, 500);
            } else if (jobStatus === 'failed' || jobStatus === 'cancelled') {
                // Job failed or cancelled, reset progress and state - DO NOT auto-resume
                console.log('[Backup Lite] Job ' + jobStatus + ' on page load, resetting state (NOT auto-resuming)', { jobId: restoreData.job.id, status: jobStatus });
                stopRestoreJobMonitor();
                restoreInProgress = false;
                restoreCompleted = false;
                backupLiteRestoreFailureShown = false;
                restoreCompletionShown = false;
                activeRestoreJobId = null; // Clear active job ID to prevent auto-resume
                if (startButton) {
                    startButton.disabled = false;
                }
                setProgress(0, '', false);
                syncWizard();
                updateRestoreCancelState();
            } else if (jobStatus === 'running' || jobStatus === 'pending' || jobStatus === 'cancelling') {
                // Job is still running or pending, start monitoring
                console.log('[Backup Lite] Job ' + jobStatus + ' on page load, resuming monitoring', { jobId: restoreData.job.id, status: jobStatus });
                if (startButton) {
                    startButton.disabled = true;
                }
                startRestoreJobMonitor(restoreData.job, fileSize);
            } else {
                // Unknown status or empty status - treat as failed to prevent auto-resume
                console.warn('[Backup Lite] Job has unknown or empty status on page load, resetting state (NOT auto-resuming)', { jobId: restoreData.job.id, status: jobStatus });
                stopRestoreJobMonitor();
                restoreInProgress = false;
                restoreCompleted = false;
                backupLiteRestoreFailureShown = false;
                restoreCompletionShown = false;
                activeRestoreJobId = null; // Clear active job ID to prevent auto-resume
                if (startButton) {
                    startButton.disabled = false;
                }
                setProgress(0, '', false);
                syncWizard();
                updateRestoreCancelState();
            }
        } else {
            // No active job - check history to see if a restore just completed
            // This handles the case where restore completed but job was cleaned up
            if (restoreData.history && restoreData.history.length > 0) {
                var latestHistory = restoreData.history[0];
                var historyTime = latestHistory.timestamp_raw || 0;
                var now = Math.floor(Date.now() / 1000);
                
                // Check if the latest history entry shows a successful restore within the last 5 minutes
                if (latestHistory && latestHistory.result === 'success' && historyTime && (now - historyTime) < 300) {
                    // Use sessionStorage to track if we've already shown completion for this restore
                    var storageKey = 'museder_restoreone_restore_shown_' + (latestHistory.file || '') + '_' + historyTime;
                    var alreadyShown = sessionStorage.getItem(storageKey);
                    
                    if (!alreadyShown && !restoreCompletionShown) {
                        console.log('[Backup Lite] Found recent successful restore in history, showing completion window');
                        // Mark as shown in sessionStorage to prevent duplicate displays
                        sessionStorage.setItem(storageKey, '1');
                        setTimeout(function() {
                            markRestoreCompleted(strings.restoreCompleted || 'Restore Completed.');
                        }, 500);
                        return;
                    } else if (alreadyShown) {
                        console.log('[Backup Lite] Completion window already shown for this restore, skipping');
                    }
                } 
                // Check if the latest history entry shows a failed restore within the last 5 minutes
                else if (latestHistory && latestHistory.result === 'failed' && historyTime && (now - historyTime) < 300) {
                    // Use sessionStorage to track if we've already shown failure for this restore
                    var storageKey = 'museder_restoreone_restore_shown_' + (latestHistory.file || '') + '_' + historyTime;
                    var alreadyShown = sessionStorage.getItem(storageKey);
                    
                    if (!alreadyShown && !restoreCompletionShown) {
                        console.log('[Backup Lite] Found recent failed restore in history, showing failure window');
                        // Mark as shown in sessionStorage to prevent duplicate displays
                        sessionStorage.setItem(storageKey, '1');
                        setTimeout(function() {
                            stopRestoreJobMonitor();
                            restoreInProgress = false;
                            restoreCompleted = false;
                            if (startButton) {
                                startButton.disabled = false;
                            }
                            setProgress(100, latestHistory.message || (strings.errorGeneric || 'Restore failed.'), true);
                            syncWizard();
                            updateRestoreCancelState();
                            showToast('❌ ' + (strings.restoreFailed || 'Restore Failed'), 'error');
                            restoreCompletionShown = true;
                            showCompletionOverlay({
                                icon: '❌',
                                title: strings.restoreFailed || 'Restore Failed',
                                message: latestHistory.message || (strings.errorGeneric || 'Restore failed. Please review the error log and try again.'),
                                confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                type: 'error'
                            });
                        }, 500);
                        return;
                    }
                }
            }
            
            // No active job - reset progress to 0 if it's showing 100%
            // This handles the case where page was reloaded after restore completed
            if (restoreData.progress && restoreData.progress.done) {
                // Progress shows as done, but no active job - reset to initial state
                console.log('[Backup Lite] No active job but progress shows done, resetting to initial state');
                restoreInProgress = false;
                restoreCompleted = false;
                backupLiteRestoreFailureShown = false; // Reset failure flag when restarting
                restoreCompletionShown = false;
                setProgress(0, '', false);
            } else if (restoreData.progress) {
                // Progress exists but not done - only set if there's an active restore
                // Otherwise reset to 0
                setProgress(0, '', false);
            } else {
                // No progress data - ensure we're at 0%
                setProgress(0, '', false);
            }
        }

        syncWizard();

        var reviewCard = stepCards.review;

        function markReviewCompleted() {
            if (!hasAnalyzed) {
                return;
            }
            if (!reviewCompleted) {
                reviewCompleted = true;
                syncWizard();
            }
        }
        if (reviewCard) {
            reviewCard.addEventListener('change', function (event) {
                var target = event.target;
                if (target && (target.matches('input') || target.matches('select') || target.matches('textarea'))) {
                    markReviewCompleted();
                }
            });
        }

        if (uploadButton) {
            uploadButton.addEventListener('click', function () {
                if (!uploadInput || !uploadInput.files || !uploadInput.files.length) {
                    notifyError({ message: strings.noFileSelected || 'Please choose a backup file first.' });
                    return;
                }
                var file = uploadInput.files[0];
                uploadButton.disabled = true;
                isAnalyzing = true;
                analysisError = false;
                hasAnalyzed = false;
                restoreCompleted = false;
                restoreInProgress = false;
                syncWizard();
                updateRestoreCancelState();
                setStep1StartedNow();
                updateStep1TimerDisplay();
                runLocalUpload(file).then(function () {
                    uploadButton.disabled = false;
                }).catch(function (error) {
                    uploadButton.disabled = false;
                    isAnalyzing = false;
                    analysisError = true;
                    hasAnalyzed = false;
                    restoreCompleted = false;
                    restoreInProgress = false;
                    syncWizard();
                    updateRestoreCancelState();
                    var message = error && error.message ? error.message : (strings.errorGeneric || 'Upload failed. Please try again.');
                    notifyError({ message: message });
                    clearStep1Started();
                    updateStep1TimerDisplay('');
                });
            });
        }

        if (existingButton) {
            existingButton.addEventListener('click', function () {
                var value = existingSelect ? existingSelect.value : '';
                if (!value) {
                    notifyError({ message: strings.noFileSelected || 'Please select a backup file first.' });
                    return;
                }
                var formData = prepareFormData('museder_restoreone_restore_from_backup');
                formData.append('filename', value);

                existingButton.disabled = true;
                isAnalyzing = true;
                analysisError = false;
                hasAnalyzed = false;
                restoreCompleted = false;
                reviewCompleted = false;
                syncWizard();
                updateRestoreCancelState();
                setStep1StartedNow();
                updateStep1TimerDisplay();
                ajaxRequest(formData).then(function (json) {
                    existingButton.disabled = false;
                    handleSummaryResponse(json);
                }).catch(function (error) {
                    existingButton.disabled = false;
                    isAnalyzing = false;
                    analysisError = true;
                    hasAnalyzed = false;
                    restoreCompleted = false;
                    syncWizard();
                    updateRestoreCancelState();
                    notifyError({ message: (error && error.message) ? error.message : (strings.errorGeneric || 'Request failed. Please try again.') });
                    clearStep1Started();
                    updateStep1TimerDisplay('');
                });
            });
        }

        if (restoreCancelBtn) {
            restoreCancelBtn.addEventListener('click', function () {
                if (restoreCancelBtn.disabled) {
                    return;
                }
                var confirmMessage = strings.restoreCancelConfirm || 'Cancel the current restore process?';
                if (confirmMessage && !window.confirm(confirmMessage)) {
                    return;
                }
                cancelRestoreProcess();
            });
        }

        if (startButton) {
            startButton.addEventListener('click', function () {
                // CRITICAL: Prevent multiple simultaneous restore operations
                // Check if restore is already in progress before allowing new restore
                if (restoreInProgress || activeRestoreJobId) {
                    console.warn('[Backup Lite] Restore already in progress, ignoring click', {
                        restoreInProgress: restoreInProgress,
                        activeRestoreJobId: activeRestoreJobId
                    });
                    return;
                }
                
                // Check if button is already disabled (safety check)
                if (startButton.disabled) {
                    console.warn('[Backup Lite] Start button already disabled, ignoring click');
                    return;
                }
                
                // Guard: Step 1 must have produced a valid archive selection/summary before we can enqueue restore.
                // This prevents admin-ajax 400 errors and avoids starting the monitor with missing data.
                if (!restoreData || !restoreData.summary || !restoreData.summary.name) {
                    notifyError({ message: strings.noFileSelected || 'Please select a backup file to restore.' });
                    // Ensure UI stays in a retryable state.
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    activeRestoreJobId = null;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    syncWizard();
                    updateRestoreCancelState();
                    return;
                }
                
                if (!overwriteToggle.checked) {
                    var overwriteConfirm = getString('confirmOverwriteData', 'This will overwrite your site data. Continue?');
                    if (!window.confirm('⚠️ ' + overwriteConfirm)) {
                        return;
                    }
                }
                markReviewCompleted();

                var formData = prepareFormData('museder_restoreone_restore_enqueue');
                formData.append('overwrite', overwriteToggle.checked ? 'true' : 'false');
                formData.append('autoBackup', autoBackupToggle && autoBackupToggle.checked ? 'true' : 'false');
                formData.append('skipConfig', skipConfigToggle && skipConfigToggle.checked ? 'true' : 'false');
                formData.append('safeMode', safeModeToggle && safeModeToggle.checked ? 'true' : 'false');
                formData.append('filesOnly', filesOnlyToggle && filesOnlyToggle.checked ? 'true' : 'false');
                if (applyReplaceToggle && applyReplaceToggle.checked) {
                    formData.append('searchReplace', JSON.stringify([]));
                }

                // CRITICAL: Disable button and set state BEFORE making request to prevent double-clicks
                startButton.disabled = true;
                restoreInProgress = true;
                restoreCompleted = false;
                
                // Reset restore monitor state for new restore
                restoreMonitor.jobId = null;
                restoreMonitor.archive = null;
                restoreMonitor.hasFinalResult = false;
                restoreMonitor.lastStatus = null;
                restoreCompletionShown = false;
                backupLiteRestoreFailureShown = false;
                
                syncWizard();
                updateRestoreCancelState();
                
                // Immediately show progress container
                var progressContainer = document.getElementById('restore-progress-container');
                var waitingMessage = document.getElementById('restore-waiting-message');
                if (progressContainer) {
                    progressContainer.style.display = 'block';
                }
                if (waitingMessage) {
                    waitingMessage.style.display = 'none';
                }
                
                // Set initial progress state
                setProgress(5, strings.runningMessage || 'Starting restore…', false);

                ajaxRequest(formData).then(function (json) {
                    var payload = getJsonPayload(json) || {};
                    var job = payload.job || payload;
                    var fileSize = payload.file_size || 0;

                    if (payload.history) {
                        // DISABLED: Restore History is now rendered server-side in PHP template
                        // renderHistory(payload.history);
                    }

                    if (job && job.id) {
                        // Check if job is already complete before starting monitor
                        var jobStatus = job.status || '';
                        var isJobComplete = jobStatus === 'success' || jobStatus === 'completed';
                        var isJobFailed = jobStatus === 'failed';
                        
                        // If job is already complete, mark as completed immediately
                        if (isJobComplete) {
                            markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                            return;
                        }
                        
                        // If job failed, show error
                        if (isJobFailed) {
                            stopRestoreJobMonitor();
                            restoreInProgress = false;
                            restoreCompleted = false;
                            if (startButton) {
                                startButton.disabled = false;
                            }
                            syncWizard();
                            updateRestoreCancelState();
                            notifyError({ message: job.message || (strings.errorGeneric || 'Restore failed.') });
                            return;
                        }
                        
                        // Start monitoring the job
                        startRestoreJobMonitor(job, fileSize);
                    } else if (payload.progress) {
                        setProgress(
                            payload.progress.percent || 5,
                            payload.progress.message || (strings.runningMessage || 'Starting restore…'),
                            payload.progress.done
                        );
                    }
                }).catch(function (error) {
                    // CRITICAL: Reset all state on error to prevent stuck state and allow retry
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    activeRestoreJobId = null;
                    restoreMonitor.hasFinalResult = false;
                    restoreMonitor.lastStatus = null;
                    restoreCompletionShown = false;
                    backupLiteRestoreFailureShown = false;
                    if (startButton) {
                        startButton.disabled = false;
                    }
                    syncWizard();
                    updateRestoreCancelState();
                    console.error('Restore enqueue error:', error);
                    notifyError({ message: (error && error.message) ? error.message : (strings.errorGeneric || 'An error occurred during restore.') });
                });
            });
        }

        var forceUnlockBtn = document.getElementById('forceRestoreUnlock');
        if (forceUnlockBtn) {
            forceUnlockBtn.addEventListener('click', function () {
                var msg = strings && strings.forceUnlockConfirm
                    ? strings.forceUnlockConfirm
                    : 'Force unlock will clear a stuck restore lock. Use only if you are sure no restore is running. Continue?';
                if (!window.confirm('⚠️ ' + msg)) {
                    return;
                }

                forceUnlockBtn.disabled = true;
                var fd = prepareFormData('museder_restoreone_restore_force_unlock');
                ajaxRequest(fd).then(function (json) {
                    var payload = getJsonPayload(json) || {};
                    showToast('✅ ' + ((payload && payload.message) ? payload.message : 'Restore lock cleared.'), 'success');
                    setTimeout(function () {
                        window.location.reload();
                    }, 800);
                }).catch(function (error) {
                    // If backend says a job is running, offer a hard-force mode (3rd confirmation).
                    if (error && parseInt(error.status, 10) === 409 && error.payload && error.payload.job_id) {
                        var jobId = error.payload.job_id;
                        var stage = error.payload.stage || '';
                        var msg2 = 'A restore job is reported as running.\n\njob_id: ' + jobId + (stage ? ('\nstage: ' + stage) : '') + '\n\nIf you believe it is stuck, you can HARD FORCE unlock. Continue?';
                        if (!window.confirm('⚠️ ' + msg2)) {
                            forceUnlockBtn.disabled = false;
                            return;
                        }
                        var typed = window.prompt('Type FORCE to confirm hard unlock (this may break a truly running restore):', '');
                        if (typed !== 'FORCE') {
                            forceUnlockBtn.disabled = false;
                            showToast('⚠️ Hard unlock cancelled.', 'warning');
                            return;
                        }
                        var fd2 = prepareFormData('museder_restoreone_restore_force_unlock');
                        fd2.append('force', 'true');
                        ajaxRequest(fd2).then(function (json2) {
                            var payload2 = getJsonPayload(json2) || {};
                            showToast('✅ ' + ((payload2 && payload2.message) ? payload2.message : 'Restore lock cleared.'), 'success');
                            setTimeout(function () {
                                window.location.reload();
                            }, 800);
                        }).catch(function (error2) {
                            forceUnlockBtn.disabled = false;
                            var m2 = (error2 && error2.message) ? error2.message : (strings.errorGeneric || 'Request failed. Please try again.');
                            showToast('⚠️ ' + m2, 'warning');
                        });
                        return;
                    }

                    forceUnlockBtn.disabled = false;
                    var m = (error && error.message) ? error.message : (strings.errorGeneric || 'Request failed. Please try again.');
                    showToast('⚠️ ' + m, 'warning');
                });
            });
        }

        function tryAutoResumeRestoreMonitor() {
            if (!restoreMonitorPaused || !activeRestoreJobId) {
                return;
            }
            refreshAjaxNonce().then(function () {
                if (restoreAutoResumeInterval) {
                    clearInterval(restoreAutoResumeInterval);
                    restoreAutoResumeInterval = null;
                }
                resumeRestoreJobMonitor(activeRestoreJobId);
            }).catch(function () {
                // Ignore; interval-based auto-resume will keep trying.
            });
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                tryAutoResumeRestoreMonitor();
            }
        });
        window.addEventListener('focus', function () {
            tryAutoResumeRestoreMonitor();
        });
    }

    if (currentPage === 'museder-restoreone-restore') {
        initRestoreCenter();
        
        // Check for completed restore job on page load (e.g., after re-login)
        // This handles the case where restore completed while user was logged out
        var restoreData = window.MusederRestoreOneRestore || {};
        if (restoreData && restoreData.job && restoreData.job.id) {
            var jobId = restoreData.job.id;
            var jobStatus = restoreData.job.status || '';
            
            // If job is already complete, mark as completed immediately
            if (jobStatus === 'success' || jobStatus === 'completed') {
                console.log('[Backup Lite] Detected completed job on page load', { jobId, status: jobStatus });
                // Use setTimeout to ensure initRestoreCenter has finished
                setTimeout(function() {
                    if (typeof markRestoreCompleted === 'function') {
                        markRestoreCompleted(restoreData.job.message || 'Restore Completed.');
                    }
                }, 500);
            } else if (jobStatus === 'running' || jobStatus === 'pending') {
                // Job is still running, check history for completion
                console.log('[Backup Lite] Job still running on page load, checking history', { jobId, status: jobStatus });
                setTimeout(function() {
                    if (typeof checkRestoreCompletionFromHistory === 'function') {
                        checkRestoreCompletionFromHistory(jobId);
                    }
                }, 1000);
            }
        }
    }

    setupSearchReplaceToggle(document.getElementById('backup-lite-search-replace-toggle'));
    setupSearchReplaceToggle(document.getElementById('backup-lite-search-replace-toggle-v2'));

    var table = document.getElementById('backup-lite-table');
    var masterCheckbox = document.getElementById('bl-master-checkbox');
    var rowCheckboxes = [];
    var selectAllBtn = document.getElementById('bl-select-all');
    var clearSelectionBtn = document.getElementById('bl-clear-selection');
    var downloadSelectedBtn = document.getElementById('bl-download-selected');
    var deleteSelectedBtn = document.getElementById('bl-delete-selected');

    function refreshRowCheckboxes() {
        rowCheckboxes = table ? Array.prototype.slice.call(table.querySelectorAll('.bl-row-checkbox')) : [];
    }

    refreshRowCheckboxes();

    function updateMasterCheckbox() {
         if (!masterCheckbox || !rowCheckboxes.length) {
             if (masterCheckbox) {
                 masterCheckbox.indeterminate = false;
                 masterCheckbox.checked = false;
             }
             return;
         }
         var total = rowCheckboxes.length;
         var checked = 0;
         rowCheckboxes.forEach(function (checkbox) {
             if (checkbox.checked) {
                 checked++;
             }
         });
         masterCheckbox.indeterminate = checked > 0 && checked < total;
         masterCheckbox.checked = checked === total;
     }

    function setAllRowSelection(state) {
        refreshRowCheckboxes();
        rowCheckboxes.forEach(function (checkbox) {
            checkbox.checked = state;
        });
        updateMasterCheckbox();
    }

    if (masterCheckbox) {
        masterCheckbox.addEventListener('change', function () {
            setAllRowSelection(masterCheckbox.checked);
        });
    }

    if (table) {
        table.addEventListener('change', function (event) {
            if (event.target && event.target.classList && event.target.classList.contains('bl-row-checkbox')) {
                refreshRowCheckboxes();
                updateMasterCheckbox();
            }
        });
    }

    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function () {
            setAllRowSelection(true);
        });
    }

    if (clearSelectionBtn) {
        clearSelectionBtn.addEventListener('click', function () {
            setAllRowSelection(false);
        });
    }

    function getSelectedRows() {
        refreshRowCheckboxes();
        var selected = [];
        rowCheckboxes.forEach(function (checkbox) {
            if (checkbox.checked) {
                selected.push({ filename: checkbox.getAttribute('data-filename'), download: checkbox.getAttribute('data-download'), element: checkbox });
            }
        });
        return selected;
    }

    function replaceCountPlaceholder(template, count) {
        var output = String(template || '');
        output = output.replace(/%([0-9]+)\$s/g, String(count));
        return output.replace('%s', String(count));
    }

    if (downloadSelectedBtn) {
        downloadSelectedBtn.addEventListener('click', function () {
            var rows = getSelectedRows();
        if (!rows.length) {
            showToast('⚠️ ' + getString('selectAtLeastOneBackup', 'Please select at least one backup.'), 'warning');
                return;
            }
        rows.forEach(function (row) {
            if (row.download) {
                window.open(row.download, '_blank');
            }
        });
        var downloadingMessage = replaceCountPlaceholder(getString('downloadingBackups', 'Downloading %s backup(s)...'), rows.length);
        showToast('⬇️ ' + downloadingMessage, 'info');
        });
    }

    if (deleteSelectedBtn) {
        deleteSelectedBtn.addEventListener('click', function () {
            var rows = getSelectedRows();
        if (!rows.length) {
            showToast('⚠️ ' + getString('selectAtLeastOneBackup', 'Please select at least one backup.'), 'warning');
                return;
            }
        var confirmMessage = strings.confirmDeleteSelected || 'Are you sure you want to delete the selected backups? This action cannot be undone.';
            if (!window.confirm(confirmMessage)) {
                return;
            }
            var payload = new FormData();
            payload.append('action', 'museder_restoreone_delete_backups');
            payload.append('nonce', localizedSettings.nonce || '');
            payload.append('filenames', JSON.stringify(rows.map(function (row) { return row.filename; })));

            fetch(localizedSettings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                if (!json || json.success !== true) {
                    throw json && json.data ? json.data : json;
                }
                var data = json.data || {};
                var deleted = data.deleted || [];
                var errors = data.errors || [];

                if (deleted.length) {
                    deleted.forEach(function (filename) {
                        refreshRowCheckboxes();
                        rowCheckboxes.forEach(function (checkbox) {
                            if (checkbox.getAttribute('data-filename') === filename) {
                                var row = checkbox.closest('tr');
                                if (row) {
                                    row.parentNode.removeChild(row);
                                }
                            }
                        });
                    });
                    var deletedMessage = replaceCountPlaceholder(getString('deletedBackups', 'Deleted %s backup(s).'), deleted.length);
                    showToast('🗑️ ' + deletedMessage, 'error');
                }

                if (errors.length) {
                    var failedMessage = replaceCountPlaceholder(getString('failedDeleteBackups', 'Failed to delete %s backup(s). Check logs.'), errors.length);
                    showToast('⚠️ ' + failedMessage, 'warning');
                }

                // Reload page after successful deletion
                if (deleted.length > 0) {
                    window.location.reload();
                } else {
                    refreshRowCheckboxes();
                    updateMasterCheckbox();
                }
            }).catch(function (error) {
                var message = 'Unable to delete selected backups.';
                if (error && error.message) {
                    message = error.message;
                } else if (error && error.errors && error.errors.length && error.errors[0].message) {
                    message = error.errors[0].message;
                }
                showToast('⚠️ ' + message, 'warning');
            });
        });
    }
    var scheduleBody = document.getElementById('backup-lite-schedule-body');
    var newScheduleButtons = Array.prototype.slice.call(document.querySelectorAll('.bl-schedule-add-trigger'));
    var scheduleModal = document.getElementById('bl-schedule-modal');
    var scheduleModalTitle = document.getElementById('bl-modal-title');
    var localizedSettings = window.MusederRestoreOneAdmin || {};
    var strings = localizedSettings.strings || {};
    
    function getString(key, fallback) {
        if (strings && Object.prototype.hasOwnProperty.call(strings, key) && strings[key]) {
            return strings[key];
        }
        return fallback || '';
    }

    function buildScheduleFormContext(formElement) {
        if (!formElement) {
            return null;
        }
        return {
            form: formElement,
            id: formElement.querySelector('[data-field="id"]'),
            title: formElement.querySelector('[data-field="title"]'),
            type: formElement.querySelector('[data-field="type"]'),
            period: formElement.querySelector('[data-field="period"]'),
            time: formElement.querySelector('[data-field="time"]'),
            retain: formElement.querySelector('[data-field="retain"]'),
            maxAge: formElement.querySelector('[data-field="max_age"]'),
            notify: formElement.querySelector('[data-field="notify"]'),
            status: formElement.querySelector('[data-field="status"]')
        };
    }

    var modalFormContext = buildScheduleFormContext(document.getElementById('bl-schedule-form'));
    var inlineFormContext = buildScheduleFormContext(document.getElementById('bl-inline-schedule-form'));
    var scheduleForm = modalFormContext ? modalFormContext.form : null;
    var inlineForm = inlineFormContext ? inlineFormContext.form : null;
    var modalCloseElements = scheduleModal ? scheduleModal.querySelectorAll('[data-bl-modal-close]') : [];

    if (modalFormContext) {
        resetScheduleForm(modalFormContext);
    }

    if (inlineFormContext) {
        resetScheduleForm(inlineFormContext, { type: 'backup', period: 'weekly', time: '02:00', retain: 5, max_age: 30, status: 'enabled' });
    }

    var schedules = [];
    var activeInlineEditId = null;

    function loadSchedulesBootstrap() {
        var node = document.getElementById('museder-restoreone-schedules-data');
        if (!node || !node.textContent) {
            return;
        }
        try {
            var parsed = JSON.parse(node.textContent);
            if (Array.isArray(parsed) && parsed.length) {
                schedules = parsed;
            }
        } catch (bootstrapError) {
            schedules = [];
        }
    }

    loadSchedulesBootstrap();

    function ajaxRequest(action, payload) {
        if (!localizedSettings.ajaxUrl) {
            return Promise.reject({ message: 'AJAX URL missing.' });
        }

        var form = new FormData();
        form.append('action', action);
        form.append('nonce', localizedSettings.nonce || '');

        if (payload) {
            Object.keys(payload).forEach(function (key) {
                form.append(key, payload[key]);
            });
        }

        return fetch(localizedSettings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json || json.success !== true) {
                throw json && json.data ? json.data : json;
            }
            return json.data || {};
        });
    }

    function fetchSchedules() {
        ajaxRequest('museder_restoreone_fetch_schedules').then(function (data) {
            schedules = data.schedules || [];
            renderSchedules();
        }).catch(function () {
            schedules = [];
            renderSchedules();
        });
    }

    function renderSchedules() {
        if (!scheduleBody) {
            return;
        }
        scheduleBody.innerHTML = '';

        if (!schedules.length) {
            var emptyRow = document.createElement('tr');
            emptyRow.className = 'bl-schedules-empty-row';
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 8;
            var emptyPanel = document.createElement('div');
            emptyPanel.className = 'bl-schedules-empty';
            emptyPanel.id = 'bl-schedules-empty-state';

            var emptyTitle = document.createElement('p');
            emptyTitle.className = 'bl-schedules-empty__title';
            emptyTitle.textContent = strings.scheduleEmptyTitle || 'No schedules yet';
            emptyPanel.appendChild(emptyTitle);

            var emptyText = document.createElement('p');
            emptyText.className = 'bl-schedules-empty__text';
            emptyText.textContent = strings.scheduleEmptyText || 'Create your first automated backup job. You can set frequency, retention, and optional email alerts.';
            emptyPanel.appendChild(emptyText);

            var emptyButton = document.createElement('button');
            emptyButton.type = 'button';
            emptyButton.className = 'button button-primary bl-schedule-add-trigger';
            emptyButton.innerHTML = '<span aria-hidden="true">＋</span> ' + (strings.addFirstSchedule || 'Add your first schedule');
            emptyButton.addEventListener('click', function () {
                openScheduleModal(null);
            });
            emptyPanel.appendChild(emptyButton);

            emptyCell.appendChild(emptyPanel);
            emptyRow.appendChild(emptyCell);
            scheduleBody.appendChild(emptyRow);
            return;
        }

        schedules.forEach(function (schedule) {
            var row = document.createElement('tr');
            row.className = 'bl-schedule-row';
            row.setAttribute('data-schedule-id', String(schedule.id));

            var nameCell = document.createElement('td');
            nameCell.textContent = schedule.title || '(untitled)';
            row.appendChild(nameCell);

            var statusCell = document.createElement('td');
            if (schedule.status === 'disabled') {
                statusCell.className = 'bl-status-disabled';
                statusCell.textContent = strings.scheduleDisabled || 'Disabled';
            } else {
                statusCell.className = 'bl-status-enabled';
                statusCell.textContent = strings.scheduleEnabled || 'Enabled';
            }
            row.appendChild(statusCell);

            var periodCell = document.createElement('td');
            periodCell.textContent = (schedule.period || 'daily').charAt(0).toUpperCase() + (schedule.period || 'daily').slice(1);
            row.appendChild(periodCell);

            var timeCell = document.createElement('td');
            timeCell.textContent = schedule.time || '00:00';
            row.appendChild(timeCell);

            var nextRunCell = document.createElement('td');
            nextRunCell.textContent = formatDateTime(schedule.next_run);
            row.appendChild(nextRunCell);

            var lastResultCell = document.createElement('td');
            lastResultCell.appendChild(buildResultBadge(schedule.last_result));
            row.appendChild(lastResultCell);

            var lastRunCell = document.createElement('td');
            lastRunCell.textContent = formatDateTime(schedule.last_run);
            row.appendChild(lastRunCell);

            var actionCell = document.createElement('td');
            actionCell.className = 'bl-schedule-actions-cell';

            var menu = document.createElement('details');
            menu.className = 'bl-actions-menu';

            var trigger = document.createElement('summary');
            trigger.className = 'bl-actions-trigger';
            trigger.setAttribute('aria-label', getString('scheduleActionsAria', 'Schedule actions'));
            trigger.textContent = '⋮';
            menu.appendChild(trigger);

            var list = document.createElement('div');
            list.className = 'bl-actions-list';
            var startLabel = getString('scheduleActionStart', 'Start Now');
            var editLabel = getString('scheduleActionEditInline', 'Edit inline');
            var deleteLabel = getString('scheduleActionDelete', 'Delete');
            
            // Create action buttons with proper classes for event delegation
            var startBtn = createActionButton('▶️ ' + startLabel, function () {
                handleStartSchedule(schedule.id);
            });
            startBtn.className = 'button backup-lite-schedule-action-start bl-actions-list__item';
            startBtn.setAttribute('data-schedule-id', schedule.id);
            list.appendChild(startBtn);
            
            var editBtn = createActionButton('✏️ ' + editLabel, function () {
                handleEditScheduleClick(schedule.id);
            });
            editBtn.className = 'button backup-lite-schedule-action-edit bl-actions-list__item';
            editBtn.setAttribute('data-schedule-id', schedule.id);
            list.appendChild(editBtn);
            
            var deleteBtn = createActionButton('🗑️ ' + deleteLabel, function () {
                handleDeleteSchedule(schedule.id);
            });
            deleteBtn.className = 'button backup-lite-schedule-action-delete bl-actions-list__item';
            deleteBtn.setAttribute('data-schedule-id', schedule.id);
            list.appendChild(deleteBtn);

            menu.appendChild(list);
            actionCell.appendChild(menu);
            row.appendChild(actionCell);

            scheduleBody.appendChild(row);
        });
    }

    function createActionButton(label, onClick) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button';
        button.textContent = label;
        // Remove inline event handler - rely on jQuery event delegation instead
        // This ensures consistency between PHP-rendered and JS-rendered buttons
        // The onClick callback is kept for backward compatibility but won't be called
        // jQuery event delegation will handle all clicks
        return button;
    }

    function formatDateTime(value) {
        if (!value) {
            return '—';
        }
        var parsed = null;
        if (typeof value === 'number') {
            parsed = new Date(value > 1e12 ? value : value * 1000);
        } else if (typeof value === 'string') {
            if (/^\d+$/.test(value)) {
                var numeric = parseInt(value, 10);
                parsed = new Date(numeric > 1e12 ? numeric : numeric * 1000);
            } else {
                parsed = new Date(value.replace(' ', 'T'));
            }
        }

        if (parsed && !isNaN(parsed.getTime())) {
            return parsed.toLocaleString();
        }
        return value;
    }

    function buildResultBadge(statusKey) {
        var key = (statusKey || 'pending').toLowerCase();
        var labels = {
            success: { icon: '✅', text: strings.scheduleResultSuccess || 'Success', className: 'success' },
            failed: { icon: '❌', text: strings.scheduleResultFailed || 'Failed', className: 'error' },
            pending: { icon: '⏳', text: strings.scheduleResultPending || 'Pending', className: 'pending' }
        };
        var config = labels[key] || labels.pending;
        var badge = document.createElement('span');
        badge.className = 'bl-badge ' + config.className;
        badge.textContent = config.icon + ' ' + config.text;
        return badge;
    }

    function resetScheduleForm(context, defaults) {
        if (!context) {
            return;
        }
        var preset = defaults || {};
        if (context.id) {
            context.id.value = preset.id || '';
        }
        // Also clear schedule_id hidden field - check both form context and direct form selector
        if (context.form) {
            var $form = jQuery(context.form);
            $form.find('input[name="schedule_id"]').val('');
            $form.find('[data-field="id"]').val('');
            $form.find('#bl-schedule-id').val('');
        } else {
            // Fallback: try to find form by common selectors
            var $form = jQuery('#bl-schedule-form');
            if ($form.length) {
                $form.find('input[name="schedule_id"]').val('');
                $form.find('[data-field="id"]').val('');
                $form.find('#bl-schedule-id').val('');
            }
        }
        if (context.title) {
            context.title.value = preset.title || '';
        }
        if (context.type) {
            context.type.value = preset.type || 'backup';
        }
        if (context.period) {
            context.period.value = preset.period || 'daily';
        }
        if (context.time) {
            context.time.value = preset.time || '00:00';
        }
        if (context.retain) {
            context.retain.value = typeof preset.retain !== 'undefined' ? preset.retain : 5;
        }
        if (context.maxAge) {
            context.maxAge.value = typeof preset.max_age !== 'undefined' ? preset.max_age : 30;
        }
        if (context.notify) {
            context.notify.value = preset.notify || '';
        }
        if (context.status) {
            var statusValue = typeof preset.status !== 'undefined' ? preset.status : 'enabled';
            context.status.checked = statusValue !== 'disabled';
        }
        
        // Restore submit button text
        var $submitBtn = null;
        if (context.form) {
            $submitBtn = jQuery(context.form).find('button[type="submit"]');
        } else {
            $submitBtn = jQuery('#bl-schedule-form').find('button[type="submit"]');
        }
        
        if ($submitBtn.length) {
            if ($submitBtn.data('original-label')) {
                $submitBtn.text($submitBtn.data('original-label'));
                $submitBtn.removeData('original-label');
            } else {
                var saveLabel = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.saveSchedule) ||
                               (strings && strings.saveSchedule) ||
                               'Save Schedule';
                $submitBtn.text(saveLabel);
            }
        }
    }

    function openScheduleModal(schedule) {
        if (!scheduleModal) {
            return;
        }
        resetScheduleForm(modalFormContext);

        if (schedule) {
            scheduleModalTitle.textContent = strings.editScheduleTitle || 'Edit Schedule';
            populateScheduleFormContext(modalFormContext, schedule);
        } else {
            scheduleModalTitle.textContent = strings.newScheduleTitle || 'New Schedule';
        }

        scheduleModal.classList.add('is-visible');
        document.body.classList.add('bl-modal-open');
    }

    function closeScheduleModal() {
        if (!scheduleModal) {
            return;
        }
        scheduleModal.classList.remove('is-visible');
        document.body.classList.remove('bl-modal-open');
    }

    function populateScheduleFormContext(context, schedule) {
        if (!context || !schedule) {
            return;
        }
        if (context.id) {
            context.id.value = schedule.id || '';
        }
        if (context.title) {
            context.title.value = schedule.title || '';
        }
        if (context.type) {
            context.type.value = schedule.type || 'backup';
        }
        if (context.period) {
            context.period.value = schedule.period || 'daily';
        }
        if (context.time) {
            context.time.value = schedule.time || '00:00';
        }
        if (context.retain) {
            context.retain.value = schedule.retain || 5;
        }
        if (context.maxAge) {
            context.maxAge.value = schedule.max_age || 30;
        }
        if (context.notify) {
            context.notify.value = schedule.notify || '';
        }
        if (context.status) {
            context.status.checked = schedule.status !== 'disabled';
        }
    }

    function createInlineLabel(text, control) {
        var label = document.createElement('label');
        label.className = 'bl-form-control';
        var span = document.createElement('span');
        span.textContent = text;
        label.appendChild(span);
        label.appendChild(control);
        return label;
    }

    function createInlineInput(type, field, value, extra) {
        var input = document.createElement('input');
        input.type = type;
        input.setAttribute('data-field', field);
        if (typeof value !== 'undefined' && value !== null) {
            input.value = value;
        }
        if (extra) {
            Object.keys(extra).forEach(function (key) {
                input.setAttribute(key, extra[key]);
            });
        }
        return input;
    }

    function createInlineSelect(field, options, selected) {
        var select = document.createElement('select');
        select.setAttribute('data-field', field);
        options.forEach(function (option) {
            var opt = document.createElement('option');
            opt.value = option.value;
            opt.textContent = option.label;
            if (selected === option.value) {
                opt.selected = true;
            }
            select.appendChild(opt);
        });
        return select;
    }

    function buildInlineScheduleEditRow(schedule) {
        var tr = document.createElement('tr');
        tr.className = 'bl-schedule-inline-edit-row';
        tr.setAttribute('data-edit-for', String(schedule.id));

        var td = document.createElement('td');
        td.colSpan = 8;

        var panel = document.createElement('div');
        panel.className = 'bl-schedule-inline-editor';

        var heading = document.createElement('p');
        heading.className = 'bl-schedule-inline-editor__title';
        heading.textContent = getString('scheduleInlineEditTitle', 'Edit schedule') + ': ' + (schedule.title || '');
        panel.appendChild(heading);

        var form = document.createElement('form');
        form.className = 'bl-schedule-inline-form';
        form.setAttribute('data-schedule-id', String(schedule.id));

        var hiddenId = createInlineInput('hidden', 'id', schedule.id);
        form.appendChild(hiddenId);

        var grid = document.createElement('div');
        grid.className = 'bl-schedule-inline-grid';

        grid.appendChild(createInlineLabel(
            getString('scheduleFieldTitle', 'Title'),
            createInlineInput('text', 'title', schedule.title || '', { required: 'required' })
        ));
        grid.appendChild(createInlineLabel(
            getString('scheduleFieldType', 'Event type'),
            createInlineSelect('type', [
                { value: 'backup', label: getString('scheduleTypeBackup', 'Backup') },
                { value: 'restore', label: getString('scheduleTypeRestore', 'Restore') }
            ], schedule.type || 'backup')
        ));
        grid.appendChild(createInlineLabel(
            getString('scheduleFieldPeriod', 'Schedule interval'),
            createInlineSelect('period', [
                { value: 'daily', label: getString('schedulePeriodDaily', 'Daily') },
                { value: 'weekly', label: getString('schedulePeriodWeekly', 'Weekly') },
                { value: 'monthly', label: getString('schedulePeriodMonthly', 'Monthly') }
            ], schedule.period || 'weekly')
        ));
        grid.appendChild(createInlineLabel(
            getString('scheduleFieldTime', 'Start time'),
            createInlineInput('time', 'time', schedule.time || '02:00', { required: 'required' })
        ));
        grid.appendChild(createInlineLabel(
            getString('scheduleFieldRetain', 'Keep the most recent (N) backups'),
            createInlineInput('number', 'retain', schedule.retain || 5, { min: '1', step: '1' })
        ));
        grid.appendChild(createInlineLabel(
            getString('scheduleFieldMaxAge', 'Remove backups older than (days)'),
            createInlineInput('number', 'max_age', schedule.max_age || 30, { min: '0', step: '1' })
        ));
        grid.appendChild(createInlineLabel(
            getString('scheduleFieldNotify', 'Notification email (optional)'),
            createInlineInput('email', 'notify', schedule.notify || '', { placeholder: 'admin@example.com' })
        ));

        var statusLabel = document.createElement('label');
        statusLabel.className = 'bl-form-control bl-toggle';
        var statusSpan = document.createElement('span');
        statusSpan.textContent = getString('scheduleFieldStatus', 'Status');
        var statusInput = createInlineInput('checkbox', 'status', '');
        statusInput.checked = schedule.status !== 'disabled';
        statusLabel.appendChild(statusSpan);
        statusLabel.appendChild(statusInput);
        grid.appendChild(statusLabel);

        form.appendChild(grid);

        var actions = document.createElement('div');
        actions.className = 'bl-schedule-inline-actions';
        var saveBtn = document.createElement('button');
        saveBtn.type = 'submit';
        saveBtn.className = 'button button-primary';
        saveBtn.textContent = getString('scheduleInlineSave', 'Save changes');
        var cancelBtn = document.createElement('button');
        cancelBtn.type = 'button';
        cancelBtn.className = 'button button-secondary bl-schedule-inline-cancel';
        cancelBtn.textContent = getString('scheduleInlineCancel', 'Cancel');
        actions.appendChild(saveBtn);
        actions.appendChild(cancelBtn);
        form.appendChild(actions);

        panel.appendChild(form);
        td.appendChild(panel);
        tr.appendChild(td);

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var context = buildScheduleFormContext(form);
            var payload = gatherScheduleForm(context);
            if (!payload || !payload.title) {
                showToast('⚠️ ' + getString('provideScheduleTitle', 'Please provide a schedule title.'), 'warning');
                return;
            }
            ajaxRequest('museder_restoreone_update_schedule', {
                id: schedule.id,
                schedule: JSON.stringify(payload)
            }).then(function (data) {
                if (data.schedule) {
                    upsertSchedule(data.schedule);
                } else {
                    fetchSchedules();
                }
                closeScheduleInlineEdit();
                showToast('💾 ' + (strings.scheduleSaved || 'Schedule saved successfully.'), 'success');
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to save schedule.';
                showToast('⚠️ ' + message, 'warning');
            });
        });

        cancelBtn.addEventListener('click', function () {
            closeScheduleInlineEdit();
        });

        return tr;
    }

    function closeScheduleInlineEdit() {
        var editRow = document.querySelector('.bl-schedule-inline-edit-row');
        if (editRow) {
            editRow.remove();
        }
        if (scheduleBody) {
            var editingRows = scheduleBody.querySelectorAll('.bl-schedule-row--editing');
            editingRows.forEach(function (row) {
                row.classList.remove('bl-schedule-row--editing');
            });
        }
        activeInlineEditId = null;
    }

    function findScheduleRowById(scheduleId) {
        if (!scheduleBody) {
            return null;
        }
        var normalizedId = String(scheduleId);
        var rows = scheduleBody.querySelectorAll('.bl-schedule-row[data-schedule-id]');
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].getAttribute('data-schedule-id') === normalizedId) {
                return rows[i];
            }
        }
        return null;
    }

    function openScheduleInlineEdit(schedule) {
        if (!schedule || !schedule.id || !scheduleBody) {
            return;
        }
        closeScheduleModal();
        closeScheduleInlineEdit();

        var dataRow = findScheduleRowById(schedule.id);
        if (!dataRow) {
            showToast('⚠️ ' + getString('scheduleNotFound', 'Schedule not found.'), 'warning');
            return;
        }

        var editRow = buildInlineScheduleEditRow(schedule);
        if (dataRow.nextSibling) {
            scheduleBody.insertBefore(editRow, dataRow.nextSibling);
        } else {
            scheduleBody.appendChild(editRow);
        }

        dataRow.classList.add('bl-schedule-row--editing');
        activeInlineEditId = String(schedule.id);

        if (typeof editRow.scrollIntoView === 'function') {
            editRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function resolveScheduleForEdit(scheduleId, callback) {
        var normalizedId = String(scheduleId);
        var found = findScheduleById(normalizedId);
        if (!found) {
            schedules.some(function (item) {
                if (String(item.id) === normalizedId) {
                    found = item;
                    return true;
                }
                return false;
            });
        }
        if (found) {
            callback(found);
            return;
        }
        ajaxRequest('museder_restoreone_fetch_schedules').then(function (data) {
            schedules = data.schedules || [];
            var match = findScheduleById(normalizedId);
            if (!match) {
                schedules.some(function (item) {
                    if (String(item.id) === normalizedId) {
                        match = item;
                        return true;
                    }
                    return false;
                });
            }
            callback(match || null);
        }).catch(function () {
            callback(null);
        });
    }

    function handleEditScheduleClick(scheduleId) {
        if (!scheduleId) {
            return;
        }
        resolveScheduleForEdit(scheduleId, function (schedule) {
            if (schedule) {
                openScheduleInlineEdit(schedule);
            } else if (typeof showToast === 'function') {
                showToast('⚠️ ' + getString('scheduleNotFound', 'Schedule not found.'), 'warning');
            }
        });
    }

    window.musederRestoreoneScheduleUi = {
        open: openScheduleModal,
        openInline: openScheduleInlineEdit,
        closeInline: closeScheduleInlineEdit,
        edit: handleEditScheduleClick,
        find: findScheduleById,
        refresh: fetchSchedules
    };

    function gatherScheduleForm(context) {
        if (!context) {
            return null;
        }
        return {
            title: context.title ? context.title.value.trim() : '',
            type: context.type ? context.type.value : 'backup',
            period: context.period ? context.period.value : 'daily',
            time: context.time ? (context.time.value || '00:00') : '00:00',
            retain: context.retain ? (parseInt(context.retain.value, 10) || 0) : 0,
            max_age: context.maxAge ? (parseInt(context.maxAge.value, 10) || 0) : 0,
            notify: context.notify ? context.notify.value.trim() : '',
            status: context.status && context.status.checked ? 'enabled' : 'disabled'
        };
    }

    function upsertSchedule(schedule) {
        var found = false;
        schedules = schedules.map(function (item) {
            if (item.id === schedule.id) {
                found = true;
                return schedule;
            }
            return item;
        });
        if (!found) {
            schedules.push(schedule);
        }
        renderSchedules();
    }

    function findScheduleById(id) {
        var normalizedId = String(id);
        var match = null;
        schedules.forEach(function (schedule) {
            if (String(schedule.id) === normalizedId) {
                match = schedule;
            }
        });
        return match;
    }

    function removeScheduleLocally(id) {
        schedules = schedules.filter(function (schedule) {
            return schedule.id !== id;
        });
        renderSchedules();
    }

    function handleDeleteSchedule(id) {
        if (!id) {
            console.error('[Backup Lite] Schedule ID missing for Delete action');
            if (typeof showToast === 'function') {
                showToast('⚠️ ' + getString('scheduleNotFound', 'Schedule ID missing.'), 'warning');
            }
            return;
        }
        
        if (!window.confirm(getString('confirmDeleteSchedule', 'Delete this schedule?'))) {
            return;
        }
        
        ajaxRequest('museder_restoreone_delete_schedule', { id: id }).then(function () {
            removeScheduleLocally(id);
            if (typeof showToast === 'function') {
                showToast('🗑️ ' + getString('scheduleDeleted', 'Schedule deleted.'), 'error');
            }
        }).catch(function (error) {
            var message = (error && error.message) ? error.message : getString('unableDeleteSchedule', 'Unable to delete schedule.');
            console.error('[Backup Lite] Failed to delete schedule:', error);
            if (typeof showToast === 'function') {
                showToast('⚠️ ' + message, 'warning');
            }
        });
    }

    function handleStartSchedule(id) {
        if (!id) {
            console.error('[Backup Lite] Schedule ID missing for Start Now action');
            if (typeof showToast === 'function') {
                showToast('⚠️ ' + getString('scheduleNotFound', 'Schedule ID missing.'), 'warning');
            }
            return;
        }
        
        ajaxRequest('museder_restoreone_run_schedule_now', { id: id }).then(function (data) {
            if (data.schedule) {
                upsertSchedule(data.schedule);
            }
            if (typeof showToast === 'function') {
                showToast('✅ ' + getString('manualJobStarted', 'Schedule started. Backup job has been triggered.'), 'success');
            }
        }).catch(function (error) {
            var message = (error && error.message) ? error.message : 'Unable to start schedule.';
            console.error('[Backup Lite] Failed to start schedule:', error);
            if (typeof showToast === 'function') {
                showToast('⚠️ ' + message, 'warning');
            }
        });
    }

    if (newScheduleButtons.length) {
        newScheduleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                openScheduleModal(null);
            });
        });
    }

    if (modalCloseElements && modalCloseElements.length) {
        modalCloseElements.forEach(function (element) {
            element.addEventListener('click', closeScheduleModal);
        });
    }

    if (scheduleModal) {
        scheduleModal.addEventListener('click', function (event) {
            if (event.target && event.target.dataset && event.target.dataset.blModalClose !== undefined) {
                closeScheduleModal();
            }
        });
    }

    if (scheduleForm) {
        scheduleForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var payload = gatherScheduleForm(modalFormContext);
            if (!payload || !payload.title) {
                showToast('⚠️ ' + getString('provideScheduleTitle', 'Please provide a schedule title.'), 'warning');
                return;
            }
            var scheduleId = modalFormContext && modalFormContext.id ? modalFormContext.id.value : '';
            var action = scheduleId ? 'museder_restoreone_update_schedule' : 'museder_restoreone_add_schedule';
            var requestPayload = {
                schedule: JSON.stringify(payload)
            };
            if (scheduleId) {
                requestPayload.id = scheduleId;
            }
            ajaxRequest(action, requestPayload).then(function (data) {
                if (data.schedule) {
                    upsertSchedule(data.schedule);
                }
                closeScheduleModal();
                showToast('💾 ' + (strings.scheduleSaved || 'Schedule saved successfully.'), 'success');
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to save schedule.';
                showToast('⚠️ ' + message, 'warning');
            });
        });
    }

    if (inlineForm) {
        inlineForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var payload = gatherScheduleForm(inlineFormContext);
            if (!payload || !payload.title) {
                showToast('⚠️ ' + getString('provideScheduleTitle', 'Please provide a schedule title.'), 'warning');
                return;
            }
            
            // Check if editing (has schedule ID) - check multiple sources
            var scheduleId = '';
            if (inlineFormContext && inlineFormContext.id) {
                scheduleId = inlineFormContext.id.value || '';
            }
            if (!scheduleId && inlineForm) {
                var $scheduleIdInput = jQuery(inlineForm).find('input[name="schedule_id"]');
                if ($scheduleIdInput.length) {
                    scheduleId = $scheduleIdInput.val() || '';
                }
            }
            if (!scheduleId && inlineForm) {
                var $dataFieldId = jQuery(inlineForm).find('[data-field="id"]');
                if ($dataFieldId.length) {
                    scheduleId = $dataFieldId.val() || '';
                }
            }
            
            var isEdit = scheduleId && scheduleId.trim() !== '';
            
            // Use museder_restoreone_save_schedule which handles both create and update
            var requestPayload = {
                schedule: JSON.stringify(payload)
            };
            if (isEdit) {
                requestPayload.id = scheduleId;
                requestPayload.schedule_id = scheduleId; // Also send as schedule_id for compatibility
            }
            
            ajaxRequest('museder_restoreone_save_schedule', requestPayload).then(function (data) {
                if (data.schedule) {
                    upsertSchedule(data.schedule);
                } else {
                    fetchSchedules();
                }
                
                // Reset form and clear schedule ID
                resetScheduleForm(inlineFormContext, { type: 'backup', period: 'weekly', time: '02:00', retain: 5, max_age: 30, status: 'enabled' });
                
                // Ensure all schedule ID fields are cleared
                if (inlineFormContext && inlineFormContext.id) {
                    inlineFormContext.id.value = '';
                }
                if (inlineForm) {
                    var $form = jQuery(inlineForm);
                    $form.find('input[name="schedule_id"]').val('');
                    $form.find('[data-field="id"]').val('');
                    $form.find('#bl-schedule-id').val('');
                }
                
                showToast('✅ ' + (data.message || strings.scheduleSaved || 'Schedule saved successfully.'), 'success');
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to save schedule.';
                showToast('⚠️ ' + message, 'warning');
            });
        });

        inlineForm.addEventListener('reset', function () {
            resetScheduleForm(inlineFormContext, { type: 'backup', period: 'weekly', time: '02:00', retain: 5, max_age: 30, status: 'enabled' });
        });
    }

    var refreshLogsButton = document.getElementById('bl-refresh-logs');
    var logTableBody = document.getElementById('bl-log-table-body');
    var logPreviewTitle = document.getElementById('bl-log-preview-title');
    var logPreviewContent = document.getElementById('bl-log-preview-content');
    var logPreviewNote = document.getElementById('bl-log-preview-note');

    function updateLogPreview(title, content, truncated) {
        if (!logPreviewContent) {
            return;
        }
        if (logPreviewTitle) {
            logPreviewTitle.textContent = title ? '🪵 ' + title : '🪵 ' + (strings.logPreview || 'Preview');
        }
        var placeholder = strings.logPreviewPlaceholder || 'Select a log file to preview.';
        logPreviewContent.textContent = content || placeholder;
        if (logPreviewNote) {
            logPreviewNote.textContent = truncated ? (strings.logTruncated || 'Showing last 200KB (truncated).') : '';
        }
    }

    if (refreshLogsButton) {
        refreshLogsButton.addEventListener('click', function () {
            window.MusederRestoreOneUI.refreshLogs().then(function () {
                updateLogPreview('', '', false);
                showToast(strings.logsRefreshed || 'Logs refreshed.', 'success');
            });
        });
    }

    // Unified Schedule Actions Handler - Event delegation for all schedule actions
    (function ($) {
        'use strict';
        
        // Ensure jQuery is available
        if (typeof $ === 'undefined' || typeof jQuery === 'undefined') {
            console.error('[Backup Lite] jQuery is not available for schedule actions');
            return;
        }

        // Note: Menu positioning is handled by initFloatingActionsMenu() which creates a portal
        // We don't need to convert to fixed here as the portal already uses fixed positioning

        // Event delegation - works immediately, no need to wait for DOM ready
        // Start Now
        $(document).on('click', '.backup-lite-schedule-action-start', function (e) {
            console.log('[Backup Lite] Start Now button clicked (event delegation)', this);
            e.preventDefault();
            e.stopPropagation();

            // Use attr() instead of data() to avoid jQuery's automatic camelCase conversion
            var scheduleId = $(this).attr('data-schedule-id') || $(this).data('schedule-id');
            console.log('[Backup Lite] Start Now - scheduleId:', scheduleId, 'all attributes:', Array.prototype.slice.call(this.attributes).map(function(a) { return a.name + '=' + a.value; }).join(', '));
            
            if (!scheduleId) {
                console.warn('[Backup Lite] missing schedule-id for Start Now', this);
                console.warn('[Backup Lite] All data attributes:', $(this).data());
                return;
            }

            console.log('[Backup Lite] Start Now clicked, scheduleId:', scheduleId);

            // Close the details menu
            var $menu = $(this).closest('details');
            if ($menu.length) {
                $menu[0].removeAttribute('open');
            }

            console.log('[Backup Lite] Calling backupLiteStartSchedule, function available:', typeof backupLiteStartSchedule === 'function');
            if (typeof backupLiteStartSchedule === 'function') {
                backupLiteStartSchedule(scheduleId);
            } else {
                console.error('[Backup Lite] backupLiteStartSchedule not available in closure scope, trying global');
                if (typeof window.backupLiteStartSchedule === 'function') {
                    window.backupLiteStartSchedule(scheduleId);
                } else {
                    console.error('[Backup Lite] backupLiteStartSchedule not available anywhere!');
                }
            }
        });

        // Edit
        $(document).on('click', '.backup-lite-schedule-action-edit', function (e) {
            console.log('[Backup Lite] Edit button clicked (event delegation)', this);
            e.preventDefault();
            e.stopPropagation();

            // Use attr() instead of data() to avoid jQuery's automatic camelCase conversion
            var scheduleId = $(this).attr('data-schedule-id') || $(this).data('schedule-id');
            console.log('[Backup Lite] Edit - scheduleId:', scheduleId, 'all attributes:', Array.prototype.slice.call(this.attributes).map(function(a) { return a.name + '=' + a.value; }).join(', '));
            
            if (!scheduleId) {
                console.warn('[Backup Lite] missing schedule-id for Edit', this);
                console.warn('[Backup Lite] All data attributes:', $(this).data());
                return;
            }

            console.log('[Backup Lite] Edit clicked, scheduleId:', scheduleId);

            // Close the details menu
            var $menu = $(this).closest('details');
            if ($menu.length) {
                $menu[0].removeAttribute('open');
            }

            var scheduleUi = window.musederRestoreoneScheduleUi;
            if (scheduleUi && typeof scheduleUi.edit === 'function') {
                scheduleUi.edit(scheduleId);
                return;
            }

            console.log('[Backup Lite] Calling backupLitePopulateScheduleForm, function available:', typeof backupLitePopulateScheduleForm === 'function');
            if (typeof backupLitePopulateScheduleForm === 'function') {
                backupLitePopulateScheduleForm(scheduleId, $(this));
            } else {
                console.error('[Backup Lite] backupLitePopulateScheduleForm not available in closure scope, trying global');
                if (typeof window.backupLitePopulateScheduleForm === 'function') {
                    var $dummyTrigger = jQuery('<div>');
                    $dummyTrigger.data('processing', false);
                    window.backupLitePopulateScheduleForm(scheduleId, $dummyTrigger);
                } else {
                    console.error('[Backup Lite] backupLitePopulateScheduleForm not available anywhere!');
                }
            }
        });

        // Delete
        $(document).on('click', '.backup-lite-schedule-action-delete', function (e) {
            console.log('[Backup Lite] Delete button clicked (event delegation)', this);
            e.preventDefault();
            e.stopPropagation();

            // Use attr() instead of data() to avoid jQuery's automatic camelCase conversion
            var scheduleId = $(this).attr('data-schedule-id') || $(this).data('schedule-id');
            console.log('[Backup Lite] Delete - scheduleId:', scheduleId, 'all attributes:', Array.prototype.slice.call(this.attributes).map(function(a) { return a.name + '=' + a.value; }).join(', '));
            
            if (!scheduleId) {
                console.warn('[Backup Lite] missing schedule-id for Delete', this);
                console.warn('[Backup Lite] All data attributes:', $(this).data());
                return;
            }

            console.log('[Backup Lite] Delete clicked, scheduleId:', scheduleId);

            var confirmMessage = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.confirmDelete) || 
                                 (window.MusederRestoreOne && MusederRestoreOne.i18n_confirm_delete_schedule) || 
                                 'Are you sure you want to delete this schedule?';
            
            if (!window.confirm(confirmMessage)) {
                console.log('[Backup Lite] Delete cancelled by user');
                return;
            }

            // Close the details menu
            var $menu = $(this).closest('details');
            if ($menu.length) {
                $menu[0].removeAttribute('open');
            }

            console.log('[Backup Lite] Calling backupLiteDeleteSchedule, function available:', typeof backupLiteDeleteSchedule === 'function');
            if (typeof backupLiteDeleteSchedule === 'function') {
                backupLiteDeleteSchedule(scheduleId);
            } else {
                console.error('[Backup Lite] backupLiteDeleteSchedule not available in closure scope, trying global');
                if (typeof window.backupLiteDeleteSchedule === 'function') {
                    window.backupLiteDeleteSchedule(scheduleId);
                } else {
                    console.error('[Backup Lite] backupLiteDeleteSchedule not available anywhere!');
                }
            }
        });

        // Helper function: Start schedule immediately
        function backupLiteStartSchedule(scheduleId) {
            console.log('[Backup Lite] backupLiteStartSchedule called with scheduleId:', scheduleId);
            
            var ajaxUrl = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.ajaxUrl) ||
                         (window.musederRestoreoneAdmin && musederRestoreoneAdmin.ajax_url) ||
                         (window.MusederRestoreOne && MusederRestoreOne.ajax_url) ||
                         window.ajaxurl || '';
            
            var nonce = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.nonce) ||
                       (window.musederRestoreoneAdmin && musederRestoreoneAdmin.nonce) ||
                       (window.MusederRestoreOne && MusederRestoreOne.nonce) || '';

            console.log('[Backup Lite] AJAX config:', { ajaxUrl: ajaxUrl, hasNonce: !!nonce });

            if (!ajaxUrl) {
                console.error('[Backup Lite] AJAX URL not available');
                alert('AJAX URL not available. Please refresh the page.');
                return;
            }

            if (!nonce) {
                console.error('[Backup Lite] Nonce not available');
                alert('Security token not available. Please refresh the page.');
                return;
            }

            console.log('[Backup Lite] Sending AJAX request to start schedule');
            $.post(ajaxUrl, {
                action: 'museder_restoreone_schedule_action',
                schedule_action: 'start_now',
                schedule_id: scheduleId,
                nonce: nonce
            }).done(function (response) {
                console.log('[Backup Lite] Start schedule response:', response);
                if (response && response.success) {
                    window.location.reload();
                } else {
                    console.error('[Backup Lite] Start schedule failed:', response);
                    alert(response && response.data && response.data.message ? response.data.message : 'Failed to start schedule');
                }
            }).fail(function (xhr, status, error) {
                console.error('[Backup Lite] AJAX error:', { status: status, error: error, xhr: xhr });
                alert('Failed to start schedule. Please try again.');
            });
        }

        // Helper function: Populate schedule form for editing
        function backupLitePopulateScheduleForm(scheduleId, $trigger) {
            console.log('[Backup Lite] backupLitePopulateScheduleForm called with scheduleId:', scheduleId);
            
            var ajaxUrl = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.ajaxUrl) ||
                         (window.musederRestoreoneAdmin && musederRestoreoneAdmin.ajax_url) ||
                         (window.MusederRestoreOne && MusederRestoreOne.ajax_url) ||
                         window.ajaxurl || '';
            
            var fetchNonce = (window.MusederRestoreOneAdmin && MusederRestoreOneAdmin.nonce) ||
                            (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.nonce) ||
                            (window.musederRestoreoneAdmin && musederRestoreoneAdmin.nonce) ||
                            (window.MusederRestoreOne && MusederRestoreOne.nonce) || '';

            console.log('[Backup Lite] AJAX config for edit:', { ajaxUrl: ajaxUrl, hasNonce: !!fetchNonce });

            if (!ajaxUrl) {
                console.error('[Backup Lite] AJAX URL not available');
                alert('AJAX URL not available. Please refresh the page.');
                return;
            }

            if (!fetchNonce) {
                console.error('[Backup Lite] Nonce not available for fetch_schedules');
                alert('Security token not available. Please refresh the page.');
                return;
            }

            // Prevent duplicate requests
            if ($trigger && $trigger.data('processing')) {
                console.log('[Backup Lite] Edit action already processing, ignoring duplicate click');
                return;
            }
            if ($trigger) {
                $trigger.data('processing', true);
            }

            console.log('[Backup Lite] Sending AJAX request to fetch schedules');
            $.post(ajaxUrl, {
                action: 'museder_restoreone_fetch_schedules',
                nonce: fetchNonce
            }).done(function (response) {
                console.log('[Backup Lite] Fetch schedules response:', response);
                if (response && response.success && response.data && response.data.schedules) {
                    var schedules = response.data.schedules;
                    var scheduleIdStr = String(scheduleId);
                    console.log('[Backup Lite] Looking for schedule with ID:', scheduleIdStr, 'in', schedules.length, 'schedules');
                    var schedule = schedules.find(function (s) {
                        return String(s.id) === scheduleIdStr;
                    });

                    if (schedule) {
                        console.log('[Backup Lite] Found schedule:', schedule);
                        var scheduleUi = window.musederRestoreoneScheduleUi;
                        if (scheduleUi && typeof scheduleUi.openInline === 'function') {
                            scheduleUi.openInline(schedule);
                            return;
                        }
                        if (typeof handleEditScheduleClick === 'function') {
                            handleEditScheduleClick(scheduleId);
                        }
                    } else {
                        console.error('[Backup Lite] Schedule not found:', scheduleId);
                        alert('Schedule not found');
                    }
                } else {
                    console.error('[Backup Lite] Failed to fetch schedules:', response);
                    alert('Failed to load schedule data');
                }
            }).fail(function (xhr, status, error) {
                console.error('[Backup Lite] AJAX error:', error);
                alert('Failed to load schedule data. Please try again.');
            }).always(function() {
                if ($trigger) {
                    $trigger.removeData('processing');
                }
            });
        }

        // Helper function: Delete schedule
        function backupLiteDeleteSchedule(scheduleId) {
            console.log('[Backup Lite] backupLiteDeleteSchedule called with scheduleId:', scheduleId);
            
            var ajaxUrl = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.ajaxUrl) ||
                         (window.musederRestoreoneAdmin && musederRestoreoneAdmin.ajax_url) ||
                         (window.MusederRestoreOne && MusederRestoreOne.ajax_url) ||
                         window.ajaxurl || '';
            
            var nonce = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.nonce) ||
                       (window.musederRestoreoneAdmin && musederRestoreoneAdmin.nonce) ||
                       (window.MusederRestoreOne && MusederRestoreOne.nonce) || '';

            console.log('[Backup Lite] AJAX config for delete:', { ajaxUrl: ajaxUrl, hasNonce: !!nonce });

            if (!ajaxUrl) {
                console.error('[Backup Lite] AJAX URL not available');
                alert('AJAX URL not available. Please refresh the page.');
                return;
            }

            if (!nonce) {
                console.error('[Backup Lite] Nonce not available');
                alert('Security token not available. Please refresh the page.');
                return;
            }

            console.log('[Backup Lite] Sending AJAX request to delete schedule');
            $.post(ajaxUrl, {
                action: 'museder_restoreone_schedule_action',
                schedule_action: 'delete',
                schedule_id: scheduleId,
                nonce: nonce
            }).done(function (response) {
                console.log('[Backup Lite] Delete schedule response:', response);
                if (response && response.success) {
                    window.location.reload();
                } else {
                    console.error('[Backup Lite] Delete schedule failed:', response);
                    alert(response && response.data && response.data.message ? response.data.message : 'Failed to delete schedule');
                }
            }).fail(function (xhr, status, error) {
                console.error('[Backup Lite] AJAX error:', { status: status, error: error, xhr: xhr });
                alert('Failed to delete schedule. Please try again.');
            });
        }
        
        // Expose helper functions to global scope for portal access
        window.backupLiteStartSchedule = backupLiteStartSchedule;
        window.backupLitePopulateScheduleForm = backupLitePopulateScheduleForm;
        window.backupLiteDeleteSchedule = backupLiteDeleteSchedule;
    })(jQuery);

    // Legacy event handler for backward compatibility
    document.addEventListener('click', function (event) {
        var scheduleAction = event.target && event.target.dataset ? event.target.dataset.scheduleAction : null;
        if (scheduleAction) {
            var scheduleId = event.target.dataset.scheduleId || '';
            var menu = event.target.closest('details');
            if (menu) {
                menu.removeAttribute('open');
            }

            if ('start' === scheduleAction && scheduleId) {
                handleStartSchedule(scheduleId);
                return;
            }

            if ('delete' === scheduleAction && scheduleId) {
                handleDeleteSchedule(scheduleId);
                return;
            }

            if ('edit' === scheduleAction) {
                // Try to get schedule data from data attribute first
                var scheduleDataAttr = event.target.getAttribute('data-schedule-data');
                var scheduleData = null;
                if (scheduleDataAttr) {
                    try {
                        scheduleData = JSON.parse(scheduleDataAttr);
                    } catch (e) {
                        console.warn('[Backup Lite] Failed to parse schedule data from attribute', e);
                    }
                }
                
                // Fallback to JS array
                if (!scheduleData) {
                    scheduleData = scheduleId ? findScheduleById(scheduleId) : null;
                }
                
                // If still not found, fetch from server
                if (!scheduleData && scheduleId) {
                    ajaxRequest('museder_restoreone_fetch_schedules').then(function (data) {
                        schedules = data.schedules || [];
                        var found = findScheduleById(scheduleId);
                        if (found) {
                            openScheduleInlineEdit(found);
                        } else {
                            showToast('⚠️ ' + getString('scheduleNotFound', 'Schedule not found.'), 'warning');
                        }
                    }).catch(function (error) {
                        var message = (error && error.message) ? error.message : 'Unable to load schedule.';
                        showToast('⚠️ ' + message, 'warning');
                    });
                } else if (scheduleData) {
                    openScheduleInlineEdit(scheduleData);
                }
                return;
            }
        }

        // Only handle log actions when a log action button is clicked.
        // This prevents noise on other admin pages (e.g., Backups page).
        var logButton = null;
        if (event.target && event.target.closest) {
            logButton = event.target.closest('button[data-log-action]');
        }
        if (!logButton) {
            return;
        }

        // Optional hard-scope: only act when we're on Logs page.
        if (currentPage && currentPage !== 'museder-restoreone-logs') {
            return;
        }

        var action = logButton.dataset ? logButton.dataset.logAction : null;
        var logName = logButton.dataset ? logButton.dataset.log : null;

        // Fallback: try to get log name from row data-log attribute
        if (!logName && logButton.closest) {
            var row = logButton.closest('tr[data-log]');
            if (row && row.dataset && row.dataset.log) {
                logName = row.dataset.log;
            }
        }

        if (!action || !logName) {
            return;
        }

        if (action === 'view') {
            ajaxRequest('museder_restoreone_view_log', { log: logName }).then(function (data) {
                if (data.log) {
                    updateLogPreview(data.log.name, data.log.content, data.log.truncated);
                }
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to load log.';
                showToast('⚠️ ' + message, 'warning');
            });
        }

        if (action === 'download') {
            // Get fresh download URL with new nonce to prevent expiration
            // Use settings.ajaxUrl and settings.nonce (from BackupLite) instead of localizedSettings
            if (!settings.ajaxUrl) {
                settings.ajaxUrl = (typeof window.ajaxurl !== 'undefined') ? window.ajaxurl : '/wp-admin/admin-ajax.php';
            }
            
            var formData = new FormData();
            formData.append('action', 'museder_restoreone_get_log_download_url');
            formData.append('nonce', settings.nonce || '');
            formData.append('log', logName);
            
            fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            }).then(function (response) {
                return response.json();
            }).then(function (json) {
                if (!json || json.success !== true) {
                    throw json && json.data ? json.data : json;
                }
                var data = json.data || {};
                if (data.download_url) {
                    // Decode HTML entities in case the URL was escaped for HTML output (e.g., &amp; / &#038;).
                    // If we navigate to an entity-escaped URL, PHP receives "amp;log" instead of "log".
                    var downloadUrl = String(data.download_url);
                    downloadUrl = downloadUrl.replace(/&amp;/g, '&').replace(/&#038;/g, '&');
                    window.location.href = downloadUrl;
                } else {
                    showToast('⚠️ ' + 'Unable to get download URL.', 'warning');
                }
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to download log.';
                showToast('⚠️ ' + message, 'warning');
            });
        }

        if (action === 'delete') {
            if (!window.confirm(strings.confirmDeleteLog || 'Delete this log file?')) {
                return;
            }
            ajaxRequest('museder_restoreone_delete_log', { log: logName }).then(function () {
                showToast('🗑️ ' + (strings.logDeleted || 'Log deleted.'), 'error');
                window.MusederRestoreOneUI.refreshLogs();
                if (logPreviewTitle && logPreviewTitle.textContent && logPreviewTitle.textContent.indexOf(logName) !== -1) {
                    updateLogPreview('', '', false);
                }
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to delete log.';
                showToast('⚠️ ' + message, 'warning');
            });
        }
    });

    var settingsForm = document.getElementById('bl-settings-form');
    var settingsMessage = document.getElementById('bl-settings-message');
    var testEmailButton = document.getElementById('bl-test-email');

    function showSettingsMessage(message, type) {
        if (!settingsMessage) {
            if (message) {
                showToast(message, type === 'error' ? 'error' : 'success');
            }
            return;
        }
        settingsMessage.className = 'museder-restoreone-messages';
        settingsMessage.classList.add('is-visible');
        if (type === 'error') {
            settingsMessage.classList.add('is-error');
        } else {
            settingsMessage.classList.add('is-success');
        }
        settingsMessage.textContent = message;
    }
    function getCheckboxValue(id) {
        var el = document.getElementById(id);
        return !!(el && el.checked);
    }

    function getStorageSubdirValue() {
        var select = document.getElementById('bl-setting-storage-subdir');
        var newInput = document.getElementById('bl-setting-storage-new');
        if (!select) {
            return 'backups';
        }
        if (select.value === '__new__') {
            return newInput ? newInput.value.trim() : '';
        }
        return select.value || 'backups';
    }

    function updateStoragePathPreview() {
        var form = document.getElementById('bl-settings-form');
        var pathEl = document.getElementById('bl-setting-storage-path');
        if (!form || !pathEl) {
            return;
        }
        var root = form.getAttribute('data-storage-root') || '';
        var subdir = getStorageSubdirValue().replace(/\\/g, '/').replace(/^\/+|\/+$/g, '');
        if (!subdir) {
            subdir = 'backups';
        }
        pathEl.textContent = root.replace(/\/$/, '') + '/' + subdir;
    }

    function toggleStorageNewFolderField() {
        var select = document.getElementById('bl-setting-storage-subdir');
        var wrap = document.getElementById('bl-setting-storage-new-wrap');
        if (!select || !wrap) {
            return;
        }
        var showNew = select.value === '__new__';
        wrap.hidden = !showNew;
        updateStoragePathPreview();
    }

    function gatherSettings() {
        var smartThresholdEl = document.getElementById('bl-setting-smart-threshold');
        var smartThreshold = smartThresholdEl ? parseInt(smartThresholdEl.value, 10) : 50000;
        if (isNaN(smartThreshold)) {
            smartThreshold = 50000;
        }

        var settings = {
            backup_storage_subdir: getStorageSubdirValue(),
            notification_email: document.getElementById('bl-setting-notify-email') ? document.getElementById('bl-setting-notify-email').value.trim() : '',
            min_role: document.getElementById('bl-setting-role') ? document.getElementById('bl-setting-role').value : 'administrator',
            backup_mode_default: document.getElementById('bl-setting-backup-mode-default') ? document.getElementById('bl-setting-backup-mode-default').value : 'auto',
            backup_smart_exclude_default: document.getElementById('bl-setting-smart-exclude-default') ? document.getElementById('bl-setting-smart-exclude-default').value : 'auto',
            backup_smart_exclude_threshold: smartThreshold,
            backup_custom_excludes: document.getElementById('bl-setting-custom-excludes') ? document.getElementById('bl-setting-custom-excludes').value : '',
            feature_restore_center_v2: getCheckboxValue('bl-feature-restore-center'),
            feature_ui_animation: getCheckboxValue('bl-feature-ui-animation'),
            feature_extended_log: getCheckboxValue('bl-feature-extended-log'),
        };

        return settings;
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var payload = gatherSettings();
            ajaxRequest('museder_restoreone_save_settings', { settings: JSON.stringify(payload) }).then(function () {
                showSettingsMessage(strings.settingsSaved || 'Settings saved successfully.', 'success');
                showToast('⚙️ ' + (strings.settingsSaved || 'Settings saved successfully.'), 'success');
                window.setTimeout(function () {
                    window.location.reload();
                }, 600);
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to save settings.';
                showSettingsMessage(message, 'error');
            });
        });

        ajaxRequest('museder_restoreone_fetch_settings').then(function (data) {
            if (!data.settings) {
                return;
            }
            var s = data.settings;
            var storageSelect = document.getElementById('bl-setting-storage-subdir');
            var storageNew = document.getElementById('bl-setting-storage-new');
            var email = document.getElementById('bl-setting-notify-email');
            var role = document.getElementById('bl-setting-role');
            var backupMode = document.getElementById('bl-setting-backup-mode-default');
            var smartExclude = document.getElementById('bl-setting-smart-exclude-default');
            var smartThreshold = document.getElementById('bl-setting-smart-threshold');
            var customExcludes = document.getElementById('bl-setting-custom-excludes');

            if (storageSelect && s.backup_storage_subdir) {
                var subdir = s.backup_storage_subdir;
                var hasOption = Array.prototype.some.call(storageSelect.options, function (opt) {
                    return opt.value === subdir;
                });
                if (!hasOption && subdir !== '__new__') {
                    var customOption = document.createElement('option');
                    customOption.value = subdir;
                    customOption.textContent = subdir;
                    storageSelect.insertBefore(customOption, storageSelect.querySelector('option[value="__new__"]'));
                }
                storageSelect.value = subdir;
            }
            if (storageNew) {
                storageNew.value = '';
            }
            toggleStorageNewFolderField();
            if (email) email.value = s.notification_email || email.value;
            if (role) role.value = s.min_role || role.value;
            if (backupMode && s.backup_mode_default) backupMode.value = s.backup_mode_default;
            if (smartExclude && s.backup_smart_exclude_default) smartExclude.value = s.backup_smart_exclude_default;
            if (smartThreshold && s.backup_smart_exclude_threshold) smartThreshold.value = s.backup_smart_exclude_threshold;
            if (customExcludes && typeof s.backup_custom_excludes === 'string') customExcludes.value = s.backup_custom_excludes;

            var restoreToggle = document.getElementById('bl-feature-restore-center');
            if (restoreToggle) restoreToggle.checked = !!s.feature_restore_center_v2;
            var animationToggle = document.getElementById('bl-feature-ui-animation');
            if (animationToggle) animationToggle.checked = !!s.feature_ui_animation;
            var logToggle = document.getElementById('bl-feature-extended-log');
            if (logToggle) logToggle.checked = !!s.feature_extended_log;
        }).catch(function () {
            // ignore fetch errors
        });

        var storageSelect = document.getElementById('bl-setting-storage-subdir');
        var storageNewInput = document.getElementById('bl-setting-storage-new');
        if (storageSelect) {
            storageSelect.addEventListener('change', toggleStorageNewFolderField);
        }
        if (storageNewInput) {
            storageNewInput.addEventListener('input', updateStoragePathPreview);
        }
        toggleStorageNewFolderField();
    }

    // Feature Toggles UI removed per request; live preview wiring disabled.

    if (testEmailButton) {
        testEmailButton.addEventListener('click', function () {
            ajaxRequest('museder_restoreone_test_email').then(function () {
                showToast(strings.testEmailSuccess || '✅ Test email sent successfully', 'success');
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to send test email.';
                showToast('❌ ' + message, 'error');
            });
        });
    }

    // Only fetch schedules via AJAX if tbody is empty (PHP hasn't rendered schedules)
    // This prevents overwriting PHP-rendered HTML that already has proper structure and event bindings
    if (scheduleBody) {
        var hasExistingRows = scheduleBody.querySelectorAll('tr').length > 0;
        if (!hasExistingRows) {
            // Only fetch if no rows exist (PHP didn't render any schedules)
            fetchSchedules();
        }
    }

    if (currentPage === 'museder-restoreone-logs' && window.MusederRestoreOneUI && typeof window.MusederRestoreOneUI.refreshLogs === 'function') {
        window.MusederRestoreOneUI.refreshLogs();
    }

    // Floating Actions Menu to avoid clipping under rounded/scrollable containers
    (function initFloatingActionsMenu() {
        var activePortal = null;
        var sourceDetails = null;
        var summaryRef = null;
        var repositionHandler = null;

        function removePortal() {
            if (activePortal && activePortal.parentNode) {
                activePortal.parentNode.removeChild(activePortal);
            }
            activePortal = null;
            if (sourceDetails) {
                sourceDetails.open = false;
                sourceDetails = null;
            }
            if (repositionHandler) {
                window.removeEventListener('scroll', repositionHandler, true);
                window.removeEventListener('resize', repositionHandler, true);
                repositionHandler = null;
            }
            document.removeEventListener('click', onDocClick, true);
        }

        function onDocClick(e) {
            if (!activePortal) return;
            if (activePortal.contains(e.target)) return;
            if (summaryRef && summaryRef.contains(e.target)) return;
            removePortal();
        }

        function positionPortal() {
            if (!summaryRef || !activePortal) return;
            var rect = summaryRef.getBoundingClientRect();
            var width = activePortal.offsetWidth || 180;
            var top = Math.round(rect.bottom + 6);
            var left = Math.round(rect.right - width);
            // Clamp to viewport
            if (left < 8) left = 8;
            if (left + width > window.innerWidth - 8) {
                left = Math.max(8, Math.round(window.innerWidth - width - 8));
            }
            if (left < 8) left = 8;
            if (top + activePortal.offsetHeight > window.innerHeight - 8) {
                top = Math.max(8, Math.round(rect.top - activePortal.offsetHeight - 6));
            }
            activePortal.style.top = top + 'px';
            activePortal.style.left = left + 'px';
        }

        document.addEventListener('toggle', function (e) {
            var details = e.target;
            if (!details || !details.classList || !details.classList.contains('bl-actions-menu')) {
                return;
            }
            // Close any existing
            removePortal();
            if (!details.open) return;

            var summary = details.querySelector('.bl-actions-trigger');
            var list = details.querySelector('.bl-actions-list');
            if (!summary || !list) return;

            // Hide original list
            list.style.display = 'none';

            // Create portal clone
            var portal = list.cloneNode(true);
            portal.classList.add('bl-actions-list-floating');
            portal.style.position = 'fixed';
            portal.style.zIndex = '9999';
            portal.style.display = 'flex';
            // lock width close to original
            var measured = list.offsetWidth || 180;
            portal.style.minWidth = Math.max(160, Math.min(340, measured)) + 'px';
            document.body.appendChild(portal);
            
            // Ensure cloned buttons have all necessary attributes for jQuery event delegation
            var portalButtons = portal.querySelectorAll('a, button');
            var originalButtons = list.querySelectorAll('a, button');
            for (var i = 0; i < portalButtons.length && i < originalButtons.length; i++) {
                var portalBtn = portalButtons[i];
                var originalBtn = originalButtons[i];
                // Copy ALL attributes (not just data-*) to ensure complete match
                Array.prototype.forEach.call(originalBtn.attributes, function(attr) {
                    portalBtn.setAttribute(attr.name, attr.value);
                });
                // Ensure class names match exactly
                portalBtn.className = originalBtn.className;
                // Ensure type attribute for buttons
                if (originalBtn.tagName === 'BUTTON' && originalBtn.type) {
                    portalBtn.type = originalBtn.type;
                }
            }

            // Delegate click to handler functions directly
            portal.addEventListener('click', function (evt) {
                var btn = evt.target.closest('a, button');
                if (!btn) {
                    console.log('[Backup Lite] Portal click: No button found');
                    return;
                }
                
                // Prevent event from bubbling to avoid duplicate handling
                evt.stopPropagation();
                evt.preventDefault();
                
                console.log('[Backup Lite] Portal click event triggered on:', btn);
                
                // Get button attributes - support both data-id and data-schedule-id
                var btnId = btn.getAttribute('data-schedule-id') || btn.getAttribute('data-id');
                var btnClass = btn.className || '';
                var actionType = '';
                
                console.log('[Backup Lite] Button attributes:', {
                    btnId: btnId,
                    btnClass: btnClass,
                    allAttributes: Array.prototype.slice.call(btn.attributes).map(function(attr) {
                        return attr.name + '=' + attr.value;
                    }).join(', ')
                });
                
                // Determine action type from class - only handle schedule actions
                if (btnClass.indexOf('backup-lite-schedule-action-start') !== -1) {
                    actionType = 'start';
                } else if (btnClass.indexOf('backup-lite-schedule-action-edit') !== -1) {
                    actionType = 'edit';
                } else if (btnClass.indexOf('backup-lite-schedule-action-delete') !== -1) {
                    actionType = 'delete';
                } else {
                    // Not a schedule action button (e.g., log action buttons)
                    // Let the original button handle it via normal event delegation
                    console.log('[Backup Lite] Portal button is not a schedule action, triggering original button click');
                    removePortal();
                    // Find and click the original button
                    var originalBtn = list.querySelector(btn.tagName.toLowerCase() + '[data-log-action="' + btn.getAttribute('data-log-action') + '"]');
                    if (originalBtn) {
                        originalBtn.click();
                    } else if (btn.dataset.logAction === 'download' && btn.dataset.log) {
                        // For log download buttons, trigger the download action
                        btn.click();
                    } else if (btn.tagName === 'A' && btn.href) {
                        // For other download links, navigate directly
                        window.location.href = btn.href;
                    }
                    return;
                }
                
                if (!btnId) {
                    console.error('[Backup Lite] Portal button missing schedule ID. All attributes:', btn.attributes);
                    removePortal();
                    return;
                }
                
                if (!actionType) {
                    console.error('[Backup Lite] Portal button missing action type. Class:', btnClass);
                    removePortal();
                    return;
                }
                
                console.log('[Backup Lite] Portal button clicked:', {
                    id: btnId,
                    class: btnClass,
                    actionType: actionType,
                    tagName: btn.tagName
                });
                
                // Check if handler functions are available
                var handlersAvailable = {
                    startAvailable: typeof window.backupLiteStartSchedule === 'function',
                    editAvailable: typeof window.backupLitePopulateScheduleForm === 'function',
                    deleteAvailable: typeof window.backupLiteDeleteSchedule === 'function'
                };
                console.log('[Backup Lite] Handler functions available:', handlersAvailable);
                
                // Remove portal first
                removePortal();
                
                // Directly call the handler functions from global scope
                // These functions are exposed by the jQuery closure above
                try {
                    if (actionType === 'start') {
                        if (typeof window.backupLiteStartSchedule === 'function') {
                            console.log('[Backup Lite] Calling backupLiteStartSchedule with ID:', btnId);
                            window.backupLiteStartSchedule(btnId);
                        } else {
                            console.error('[Backup Lite] backupLiteStartSchedule not available or not a function');
                            throw new Error('backupLiteStartSchedule function not available');
                        }
                    } else if (actionType === 'edit') {
                        if (window.musederRestoreoneScheduleUi && typeof window.musederRestoreoneScheduleUi.edit === 'function') {
                            window.musederRestoreoneScheduleUi.edit(btnId);
                        } else if (typeof window.backupLitePopulateScheduleForm === 'function') {
                            var $dummyTrigger = jQuery('<div>');
                            $dummyTrigger.data('processing', false);
                            window.backupLitePopulateScheduleForm(btnId, $dummyTrigger);
                        } else {
                            console.error('[Backup Lite] Schedule edit handler not available');
                            throw new Error('Schedule edit handler not available');
                        }
                    } else if (actionType === 'delete') {
                        if (typeof window.backupLiteDeleteSchedule === 'function') {
                            var confirmMessage = (window.musederRestoreoneSchedulesL10n && musederRestoreoneSchedulesL10n.confirmDelete) || 
                                                 (window.MusederRestoreOne && MusederRestoreOne.i18n_confirm_delete_schedule) || 
                                                 'Are you sure you want to delete this schedule?';
                            
                            if (window.confirm(confirmMessage)) {
                                console.log('[Backup Lite] Calling backupLiteDeleteSchedule with ID:', btnId);
                                window.backupLiteDeleteSchedule(btnId);
                            } else {
                                console.log('[Backup Lite] Delete cancelled by user');
                            }
                        } else {
                            console.error('[Backup Lite] backupLiteDeleteSchedule not available or not a function');
                            throw new Error('backupLiteDeleteSchedule function not available');
                        }
                    } else {
                        throw new Error('Unknown action type: ' + actionType);
                    }
                } catch (error) {
                    console.error('[Backup Lite] Error calling handler function:', error);
                    console.error('[Backup Lite] Error message:', error.message);
                    console.error('[Backup Lite] Stack trace:', error.stack);
                    
                    // Fallback: create temporary button and trigger event via jQuery event delegation
                    console.log('[Backup Lite] Using fallback: creating temporary button');
                    var tempBtn = document.createElement('button');
                    tempBtn.type = 'button';
                    tempBtn.className = btnClass;
                    tempBtn.setAttribute('data-schedule-id', btnId);
                    tempBtn.style.position = 'absolute';
                    tempBtn.style.left = '-9999px';
                    tempBtn.style.visibility = 'hidden';
                    tempBtn.style.opacity = '0';
                    tempBtn.style.pointerEvents = 'none';
                    document.body.appendChild(tempBtn);
                    
                    setTimeout(function() {
                        var $tempBtn = jQuery(tempBtn);
                        if ($tempBtn.length) {
                            console.log('[Backup Lite] Triggering click on temporary button via jQuery');
                            $tempBtn.trigger('click');
                        } else {
                            console.error('[Backup Lite] jQuery not available for temporary button');
                        }
                        setTimeout(function() {
                            if (tempBtn.parentNode) {
                                tempBtn.parentNode.removeChild(tempBtn);
                            }
                        }, 200);
                    }, 100);
                }
            });

            activePortal = portal;
            sourceDetails = details;
            summaryRef = summary;
            positionPortal();
            repositionHandler = positionPortal;
            window.addEventListener('scroll', repositionHandler, true);
            window.addEventListener('resize', repositionHandler, true);
            document.addEventListener('click', onDocClick, true);
        }, true);
    })();

    document.querySelectorAll('.backup-lite-card').forEach(function (card) {
        card.addEventListener('mouseenter', function () {
            card.classList.add('active');
        });
        card.addEventListener('mouseleave', function () {
            card.classList.remove('active');
        });
    });

    // Initialize Backup Size Estimate functionality
    (function initBackupSizeEstimate() {
        var estimateCard = document.getElementById('backup-lite-estimate-card');
        if (!estimateCard) {
            return; // Not on backups page
        }

        var loadingEl = estimateCard.querySelector('.backup-lite-estimate-loading');
        var resultsEl = estimateCard.querySelector('.backup-lite-estimate-results');
        var scanningEl = estimateCard.querySelector('.backup-lite-estimate-scanning');
        var rescanBtn = document.getElementById('backup-lite-estimate-rescan');
        var progressFill = document.getElementById('backup-lite-estimate-progress-fill');
        var progressText = document.getElementById('backup-lite-estimate-progress-text');
        var scanStatus = document.getElementById('backup-lite-estimate-scan-status');
        var warningEl = document.getElementById('backup-lite-estimate-warning');

        var ajaxUrl = localizedSettings.ajaxUrl || '/wp-admin/admin-ajax.php';
        var nonce = localizedSettings.nonce || '';
        var progressInterval = null;

        // Load initial estimate
        function loadEstimate() {
            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'museder_restoreone_estimate_result',
                    nonce: nonce
                },
                success: function (response) {
                    if (response.success && response.data) {
                        displayResults(response.data);
                    } else {
                        // If no cached data, start scan
                        startScan(false);
                    }
                },
                error: function () {
                    loadingEl.style.display = 'none';
                    resultsEl.style.display = 'block';
                    estimateCard.querySelector('#backup-lite-estimate-db-size').textContent = '-';
                    estimateCard.querySelector('#backup-lite-estimate-files-size').textContent = '-';
                    estimateCard.querySelector('#backup-lite-estimate-total-size').textContent = '-';
                }
            });
        }

        // Display estimate results
        /**
         * Displays estimate results with safe type checking.
         * 
         * @test Checklist:
         * - 建立一次完整備份，確認 Backups 列表有新備份
         * - Dashboard → Recent Backups 有正確時間戳
         * - Logs 頁面有新增 log，時間戳合理
         * 
         * @param {Object} data - Response data from AJAX
         */
        function displayResults(data) {
            if (!data) {
                return;
            }

            loadingEl.style.display = 'none';
            scanningEl.style.display = 'none';
            resultsEl.style.display = 'block';

            // Safely get bytes values with type checking
            var totalBytes = typeof data.total === 'object' && typeof data.total.bytes === 'number'
                ? data.total.bytes
                : (data.total && data.total.bytes ? Number(data.total.bytes) : 0);

            if (!Number.isFinite(totalBytes)) {
                totalBytes = 0;
            }

            document.getElementById('backup-lite-estimate-db-size').textContent = (data.database && data.database.formatted) || '-';
            document.getElementById('backup-lite-estimate-files-size').textContent = (data.files && data.files.formatted) || '-';
            document.getElementById('backup-lite-estimate-total-size').textContent = (data.total && data.total.formatted) || '-';
            document.getElementById('backup-lite-estimate-last-scanned').textContent = data.last_scanned || '-';

            // Show warning if total > 1GB
            if (totalBytes > 1024 * 1024 * 1024) {
                warningEl.style.display = 'block';
            } else {
                warningEl.style.display = 'none';
            }
        }

        // Start scan
        function startScan(force) {
            loadingEl.style.display = 'none';
            resultsEl.style.display = 'none';
            scanningEl.style.display = 'block';
            if (rescanBtn) {
                rescanBtn.disabled = true;
            }

            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'museder_restoreone_estimate_start',
                    nonce: nonce,
                    force: force ? 'true' : 'false'
                },
                success: function (response) {
                    if (response.success) {
                        if (response.data.cached) {
                            // Use cached data
                            loadEstimate();
                        } else {
                            // Start polling progress
                            startProgressPolling();
                        }
                    } else {
                        showToast('❌ ' + (response.data && response.data.message ? response.data.message : 'Failed to start scan.'), 'error');
                        loadingEl.style.display = 'block';
                        scanningEl.style.display = 'none';
                        if (rescanBtn) {
                            rescanBtn.disabled = false;
                        }
                    }
                },
                error: function () {
                    showToast('❌ ' + (strings.errorGeneric || 'An error occurred. Please try again.'), 'error');
                    loadingEl.style.display = 'block';
                    scanningEl.style.display = 'none';
                    if (rescanBtn) {
                        rescanBtn.disabled = false;
                    }
                }
            });
        }

        // Poll scan progress
        function startProgressPolling() {
            if (progressInterval) {
                clearInterval(progressInterval);
            }

            progressInterval = setInterval(function () {
                jQuery.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'museder_restoreone_estimate_progress',
                        nonce: nonce
                    },
                    success: function (response) {
                        if (!response || !response.success || !response.data) {
                            console.error('[RestoreOne] Invalid estimate size response', response);
                            return;
                        }

                        var data = response.data;
                        
                        // Update progress bar with safe type checking
                        var percent = typeof data.progress_percent === 'number' && Number.isFinite(data.progress_percent)
                            ? Math.max(0, Math.min(100, data.progress_percent))
                            : 0;
                        
                        if (progressFill) {
                            progressFill.style.width = percent + '%';
                        }
                        if (progressText) {
                            progressText.textContent = percent.toFixed(1) + '%';
                        }

                        // Update status with safe type checking
                        if (scanStatus) {
                            // Safely handle scanned_count with type checking
                            var scannedCount = typeof data.scanned_count === 'number'
                                ? data.scanned_count
                                : (data.scanned_count ? Number(data.scanned_count) : 0);

                            if (!Number.isFinite(scannedCount) || scannedCount < 0) {
                                scannedCount = 0;
                            }

                            var scannedCountFormatted = scannedCount.toLocaleString(undefined, {
                                maximumFractionDigits: 0
                            });
                            var statusText = 'Scanned: ' + scannedCountFormatted + ' files, ' + (data.total_bytes_formatted || '0 B');
                            if (data.current_path) {
                                var pathParts = data.current_path.split('/');
                                var currentDir = pathParts[pathParts.length - 1] || data.current_path;
                                statusText += ' | Current: ' + currentDir;
                            }
                            scanStatus.textContent = statusText;
                        }

                        // Check if completed - ensure progress bar shows 100% when done
                        if (data.status === 'completed' || data.status === 'idle') {
                            // Force progress bar to 100% when completed
                            if (progressFill) {
                                progressFill.style.width = '100%';
                            }
                            if (progressText) {
                                progressText.textContent = '100%';
                            }
                            
                            clearInterval(progressInterval);
                            progressInterval = null;
                            loadEstimate();
                            if (rescanBtn) {
                                rescanBtn.disabled = false;
                            }
                        }
                    },
                    error: function () {
                        // Continue polling on error
                    }
                });
            }, 2000); // Poll every 2 seconds
        }

        // Handle rescan button
        if (rescanBtn) {
            rescanBtn.addEventListener('click', function () {
                startScan(true);
            });
        }

        // Initial load
        loadEstimate();
    })();

    // Handle exit safe mode button
    var exitSafeModeBtn = document.getElementById('museder-restoreone-exit-safe-mode-btn') ||
        document.getElementById('backup-lite-exit-safe-mode-btn');
    if (exitSafeModeBtn) {
        exitSafeModeBtn.addEventListener('click', function () {
            var button = this;
            var originalText = button.textContent;
            button.disabled = true;
            button.textContent = strings.restoreInProgress || 'Processing...';

            var ajaxUrl = localizedSettings.ajaxUrl || '/wp-admin/admin-ajax.php';
            var nonce = localizedSettings.nonce || '';

            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'museder_restoreone_exit_safe_mode',
                    nonce: nonce
                },
                success: function (response) {
                    if (response.success) {
                        showToast('✅ ' + (response.data.message || 'Safe mode exited successfully.'), 'success');
                        // Hide the notice
                        var notice = document.getElementById('museder-restoreone-safe-mode-notice');
                        if (notice) {
                            notice.style.transition = 'opacity 0.3s';
                            notice.style.opacity = '0';
                            setTimeout(function () {
                                notice.remove();
                            }, 300);
                        }
                        // Reload page after a short delay to reflect plugin changes
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showToast('❌ ' + (response.data && response.data.message ? response.data.message : 'Failed to exit safe mode.'), 'error');
                        button.disabled = false;
                        button.textContent = originalText;
                    }
                },
                error: function (xhr, status, error) {
                    showToast('❌ ' + (strings.errorGeneric || 'An error occurred. Please try again.'), 'error');
                    button.disabled = false;
                    button.textContent = originalText;
                }
            });
        });
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBackupLiteDomReady);
} else {
    initBackupLiteDomReady();
}

// Restore History checkbox and delete functionality
(function() {
    'use strict';
    
    var masterCheckbox = document.getElementById('bl-restore-history-master-checkbox');
    var deleteButton = document.getElementById('bl-delete-selected-restore-history');
    var rowCheckboxes = [];
    
    function initRestoreHistoryCheckboxes() {
        if (!masterCheckbox && !deleteButton) {
            return; // Not on restore page
        }
        
        rowCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.bl-restore-history-row-checkbox'));
        
        if (masterCheckbox) {
            masterCheckbox.addEventListener('change', function() {
                var checked = this.checked;
                rowCheckboxes.forEach(function(checkbox) {
                    checkbox.checked = checked;
                });
                updateDeleteButton();
            });
        }
        
        rowCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                updateMasterCheckbox();
                updateDeleteButton();
            });
        });
        
        if (deleteButton) {
            deleteButton.addEventListener('click', function() {
                var selected = rowCheckboxes.filter(function(cb) { return cb.checked; });
                if (selected.length === 0) {
                    var localizedSettings = window.MusederRestoreOneAdmin || {};
                    var strings = localizedSettings.strings || {};
                    var showToast = function(message, type) {
                        if (window.Toastify) {
                            window.Toastify({
                                text: message,
                                gravity: 'top',
                                position: 'right',
                                backgroundColor: type === 'warning' ? '#f59e0b' : '#3b82f6',
                                duration: 3000
                            }).showToast();
                        }
                    };
                    showToast('⚠️ ' + (strings.selectAtLeastOne || 'Please select at least one entry.'), 'warning');
                    return;
                }
                
                var localizedSettings = window.MusederRestoreOneAdmin || {};
                var strings = localizedSettings.strings || {};
                var confirmMessage = strings.confirmDeleteSelectedRestoreHistory || 'Are you sure you want to delete the selected restore history entries? This action cannot be undone.';
                if (!window.confirm(confirmMessage)) {
                    return;
                }
                
                var timestamps = selected.map(function(cb) {
                    return cb.getAttribute('data-timestamp');
                }).filter(function(ts) { return ts; });
                
                if (timestamps.length === 0) {
                    var showToast = function(message, type) {
                        if (window.Toastify) {
                            window.Toastify({
                                text: message,
                                gravity: 'top',
                                position: 'right',
                                backgroundColor: type === 'warning' ? '#f59e0b' : '#3b82f6',
                                duration: 3000
                            }).showToast();
                        }
                    };
                    showToast('⚠️ ' + (strings.noEntriesSelected || 'No valid entries selected.'), 'warning');
                    return;
                }
                
                deleteButton.disabled = true;
                
                var payload = new FormData();
                payload.append('action', 'museder_restoreone_delete_restore_history');
                payload.append('nonce', localizedSettings.nonce || '');
                payload.append('timestamps', JSON.stringify(timestamps));
                
                fetch(localizedSettings.ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: payload
                }).then(function(response) {
                    return response.json();
                }).then(function(json) {
                    deleteButton.disabled = false;
                    if (!json || json.success !== true) {
                        throw json && json.data ? json.data : json;
                    }
                    var data = json.data || {};
                    var deleted = data.deleted || [];
                    var errors = data.errors || [];
                    
                    var showToast = function(message, type) {
                        if (window.Toastify) {
                            window.Toastify({
                                text: message,
                                gravity: 'top',
                                position: 'right',
                                backgroundColor: type === 'success' ? '#10b981' : type === 'warning' ? '#f59e0b' : '#3b82f6',
                                duration: 3000
                            }).showToast();
                        }
                    };
                    
                    if (deleted.length) {
                        showToast('🗑️ ' + (strings.restoreHistoryDeleted || 'Restore history entries deleted.'), 'success');
                        window.location.reload();
                    }
                    
                    if (errors.length) {
                        var message = errors[0].message || 'Some entries could not be deleted.';
                        showToast('⚠️ ' + message, 'warning');
                    }
                }).catch(function(error) {
                    deleteButton.disabled = false;
                    var message = (error && error.message) ? error.message : 'Unable to delete restore history entries.';
                    var showToast = function(msg, type) {
                        if (window.Toastify) {
                            window.Toastify({
                                text: msg,
                                gravity: 'top',
                                position: 'right',
                                backgroundColor: type === 'warning' ? '#f59e0b' : '#3b82f6',
                                duration: 3000
                            }).showToast();
                        }
                    };
                    showToast('⚠️ ' + message, 'warning');
                });
            });
        }
    }
    
    function updateMasterCheckbox() {
        if (!masterCheckbox) {
            return;
        }
        var allChecked = rowCheckboxes.length > 0 && rowCheckboxes.every(function(cb) { return cb.checked; });
        var someChecked = rowCheckboxes.some(function(cb) { return cb.checked; });
        masterCheckbox.checked = allChecked;
        masterCheckbox.indeterminate = someChecked && !allChecked;
    }
    
    function updateDeleteButton() {
        if (!deleteButton) {
            return;
        }
        var hasSelection = rowCheckboxes.some(function(cb) { return cb.checked; });
        deleteButton.style.display = hasSelection ? '' : 'none';
    }
    
    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRestoreHistoryCheckboxes);
    } else {
        initRestoreHistoryCheckboxes();
    }
})();
})(jQuery);

