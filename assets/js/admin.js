var backupJobContext = {
    current: null,
    timer: null,
    lastNudge: 0
};

(function ($) {
    'use strict';

    const settings = window.BackupLite || {};
    const strings = settings.strings || {};
    function getString(key, fallback) {
        if (strings && Object.prototype.hasOwnProperty.call(strings, key) && strings[key]) {
            return strings[key];
        }
        return fallback || '';
    }
    const messages = $('#backup-lite-messages');
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
            backgroundColor: background,
            duration: 3000
        }).showToast();
    }

    function showCompletionOverlay(options) {
        var config = options || {};
        var icon = config.icon || '✅';
        var title = config.title || strings.successTitle || 'Operation completed';
        var message = config.message || '';
        var actionHref = config.actionHref || '';
        var actionText = config.actionText || strings.downloadLabel || 'Download';
        var confirmText = config.confirmText || '';
        var autoClose = typeof config.autoClose === 'number' ? config.autoClose : 0;
        var type = config.type || 'success'; // 'success' or 'error'

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
            messageEl.textContent = message;
            dialog.appendChild(messageEl);
        }

        if (actionHref || confirmText) {
            var actionsEl = document.createElement('div');
            actionsEl.className = 'bl-completion-actions';

            if (actionHref && actionHref !== '') {
                var actionBtn = document.createElement('a');
                actionBtn.className = 'button button-primary';
                actionBtn.href = actionHref;
                actionBtn.textContent = actionText;
                actionBtn.setAttribute('target', '_blank');
                actionBtn.setAttribute('rel', 'noopener noreferrer');
                
                // Check if download link is expired before allowing download
                actionBtn.addEventListener('click', function(e) {
                    if (!actionHref || actionHref === '') {
                        e.preventDefault();
                        e.stopPropagation();
                        alert(strings.downloadUnavailable || 'Download link is not available. Please download from the backup library.');
                        // Optionally redirect to backups page
                        if (window.location.href.indexOf('page=backup-lite-backups') === -1) {
                            var backupsUrl = window.location.href.replace(/page=[^&]*/, 'page=backup-lite-backups');
                            if (backupsUrl === window.location.href) {
                                backupsUrl += (window.location.href.indexOf('?') === -1 ? '?' : '&') + 'page=backup-lite-backups';
                            }
                            window.location.href = backupsUrl;
                        }
                        return false;
                    }
                    try {
                        // Use window.location.origin as base for relative URLs
                        var url = new URL(actionHref, window.location.origin);
                        var expires = parseInt(url.searchParams.get('expires'), 10);
                        if (expires && expires < Math.floor(Date.now() / 1000)) {
                            e.preventDefault();
                            e.stopPropagation();
                            alert(strings.downloadExpired || 'Your download link has expired. Please download from the backup library.');
                            // Optionally redirect to backups page
                            if (window.location.href.indexOf('page=backup-lite-backups') === -1) {
                                var backupsUrl = window.location.href.replace(/page=[^&]*/, 'page=backup-lite-backups');
                                if (backupsUrl === window.location.href) {
                                    backupsUrl += (window.location.href.indexOf('?') === -1 ? '?' : '&') + 'page=backup-lite-backups';
                                }
                                window.location.href = backupsUrl;
                            }
                            return false;
                        }
                    } catch (err) {
                        // If URL parsing fails, allow the download to proceed
                        // This handles nonce-based download URLs or relative URLs
                        console.warn('[Backup Lite] Could not parse download URL, allowing download to proceed', err);
                    }
                });
                
                actionsEl.appendChild(actionBtn);
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
            if (typeof window.BackupLiteUI !== 'undefined' && typeof window.BackupLiteUI.resetRestoreProgress === 'function') {
                window.BackupLiteUI.resetRestoreProgress();
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

            var downloadLink = document.createElement('a');
            downloadLink.className = 'button';
            downloadLink.href = log.download_url;
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
            action: 'backup_lite_fetch_logs',
            nonce: settings.nonce
        }).done(function (resp) {
            if (!resp.success) {
                return;
            }

            const logs = resp.data.logs || [];

            if (renderLogTable(logs)) {
                return;
            }

            const list = $('.backup-lite-logs ul');
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

    window.BackupLiteUI = {
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

    backupJobContext.pollDelay = Math.max(2500, (settings.jobPollingInterval || 3) * 1000);

    var backupFormEl = null;
    var backupProgressEl = document.getElementById('backup-progress-fill');
    var backupProgressText = document.getElementById('backup-progress-text');
    var backupSubmitBtn = null;
    var backupCancelBtn = document.getElementById('bl-backup-cancel-btn');

    function setBackupFormElements(formEl) {
        backupFormEl = formEl;
        backupSubmitBtn = backupFormEl ? backupFormEl.querySelector('button[type="submit"]') : null;
        resetBackupProgress();
        setBackupCancelable(false);
    }

    function resetBackupProgress() {
        if (backupProgressEl) {
            backupProgressEl.style.width = '0%';
        }
        if (backupProgressText) {
            backupProgressText.textContent = '0%';
        }
    }

    function updateBackupProgress(percent) {
        if (!backupProgressEl) {
            return;
        }
        var value = Math.max(0, Math.min(100, percent || 0));
        backupProgressEl.style.width = value + '%';
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
        payload.append('action', 'backup_lite_cancel_backup_job');
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
            resetBackupProgress();
            showToast(strings.jobCancelSuccess || 'Backup cancelled.', 'warning');
        }).catch(function (error) {
            var message = (error && error.message) ? error.message : (strings.jobCancelFailed || 'Unable to cancel backup.');
            showToast(message, 'error');
            setBackupCancelable(!!(backupJobContext.current && backupJobContext.current.id));
        });
    }

    function appendBackupOptions(payload) {
        if (!window.BackupLitePro || !window.BackupLitePro.isPro || !backupFormEl) {
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
        updateBackupProgress(job.percentage || 0);
        setBackupCancelable(true);

        if ('completed' === job.status) {
            setBackupCancelable(false);
            finishBackupJob(job);
            return;
        }

        if ('failed' === job.status) {
            stopBackupJobPolling();
            setBackupBusy(false);
            handleError({ message: job.message || strings.jobFailed || strings.errorGeneric });
            backupJobContext.current = null;
            setBackupCancelable(false);
            return;
        }

        if ('cancelled' === job.status) {
            stopBackupJobPolling();
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
            stageMessage = strings.jobProcessing || stageMessage;
        } else if ('finalizing' === job.stage || 'completed' === job.stage) {
            stageMessage = strings.jobFinalizing || stageMessage;
        }
        setBackupStatusMessage(stageMessage, 'loading');

        if (!job.processing && job.id) {
            maybeNudgeBackupJob(job.id);
        }
    }

    function maybeNudgeBackupJob(jobId) {
        var now = Date.now();
        if (now - backupJobContext.lastNudge < backupJobContext.pollDelay) {
            return;
        }
        backupJobContext.lastNudge = now;

        var payload = new FormData();
        payload.append('action', 'backup_lite_continue_backup_job');
        payload.append('nonce', settings.nonce);
        payload.append('job_id', jobId);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (json && json.success && json.data && json.data.job) {
                handleJobResponse(json.data.job);
            }
        }).catch(function () {
            // Silent fallback – manual nudge is best effort.
        });
    }

    function pollBackupJobStatus() {
        if (!backupJobContext.current || !backupJobContext.current.id) {
            stopBackupJobPolling();
            return;
        }

        var payload = new FormData();
        payload.append('action', 'backup_lite_get_job_status');
        payload.append('nonce', settings.nonce);
        payload.append('job_id', backupJobContext.current.id);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json || !json.success || !json.data || !json.data.job) {
                throw json && json.data ? json.data : json;
            }
            handleJobResponse(json.data.job);
        }).catch(function (error) {
            stopBackupJobPolling();
            setBackupBusy(false);
            handleError(error && error.message ? error : null);
            backupJobContext.current = null;
        });
    }

    function scheduleBackupJobPolling(immediate) {
        stopBackupJobPolling();
        backupJobContext.timer = window.setInterval(pollBackupJobStatus, backupJobContext.pollDelay);
        if (immediate) {
            pollBackupJobStatus();
        }
    }

    function stopBackupJobPolling() {
        if (backupJobContext.timer) {
            window.clearInterval(backupJobContext.timer);
            backupJobContext.timer = null;
        }
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
        payload.append('action', 'backup_lite_start_backup_job');
        payload.append('nonce', settings.nonce);
        appendBackupOptions(payload);

        setBackupBusy(true);
        resetBackupProgress();
        setBackupStatusMessage(strings.jobPreparing || strings.runningMessage || '', 'loading');

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        }).then(function (response) {
            return response.json();
        }).then(function (json) {
            if (!json || !json.success || !json.data || !json.data.job) {
                throw json && json.data ? json.data : json;
            }
            backupJobContext.current = json.data.job;
            scheduleBackupJobPolling(true);
        }).catch(function (error) {
            setBackupBusy(false);
            backupJobContext.current = null;
            resetBackupProgress();
            setBackupCancelable(false);
            handleError(error && error.message ? error : null);
        });

        return false;
    }

    function finishBackupJob(job) {
        stopBackupJobPolling();
        setBackupBusy(false);
        backupJobContext.current = null;
        setBackupCancelable(false);
        updateBackupProgress(100);

        var overlayTitle = strings.backupOverlayTitle || strings.successTitle || 'Backup completed';
        var overlayMessage = strings.jobComplete || strings.successBackup || 'Backup completed successfully.';

        showMessage('success', strings.successTitle || '', strings.jobComplete || strings.successBackup || '');
        refreshLogs();

        var overlayFunc = window.BackupLiteUI && window.BackupLiteUI.showCompletionOverlay
            ? window.BackupLiteUI.showCompletionOverlay
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
        payload.append('action', 'backup_lite_restore_existing');
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
        payload.append('action', 'backup_lite_delete_backup');
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
            
            button.closest('tr').fadeOut(300, function () {
                $(this).remove();
            });
        }).catch(function () {
            button.prop('disabled', false);
            handleError();
        });
});

function initBackupLiteDomReady() {
    var localizedSettings = window.BackupLite || {};
    var strings = localizedSettings.strings || {};
    var currentPage = localizedSettings.page || '';
    var backupForm = document.getElementById('backup-lite-backup-form');
    var backupProgress = document.getElementById('backup-progress-fill');
    var restoreFormV2 = document.getElementById('backup-lite-restore-form-v2');
    var uploadProgress = document.getElementById('upload-progress');
    var uploadInterval = null;

    var showToast = function () {
        if (window.BackupLiteUI && typeof window.BackupLiteUI.showToast === 'function') {
            return window.BackupLiteUI.showToast.apply(window.BackupLiteUI, arguments);
        }
        return undefined;
    };

    var showCompletionOverlay = function () {
        if (window.BackupLiteUI && typeof window.BackupLiteUI.showCompletionOverlay === 'function') {
            return window.BackupLiteUI.showCompletionOverlay.apply(window.BackupLiteUI, arguments);
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
        updateBackupProgress(localizedSettings.activeJob.percentage || 0);
        setBackupStatusMessage(strings.jobResuming || strings.runningMessage || '', 'loading');
        setBackupCancelable(true);
        scheduleBackupJobPolling(true);
    }

    if (restoreFormV2 && uploadProgress) {
        restoreFormV2.addEventListener('submit', function () {
            if (uploadInterval) {
                window.clearInterval(uploadInterval);
            }
            var strings = (window.BackupLite && window.BackupLite.strings) || {};
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
        var handleSummaryResponse = window.BackupLiteUI && window.BackupLiteUI.handleSummaryResponse;
        if (typeof handleSummaryResponse === 'function') {
            handleSummaryResponse(event.detail);
        } else {
            // If handleSummaryResponse is not yet available, wait a bit and try again
            setTimeout(function() {
                handleSummaryResponse = window.BackupLiteUI && window.BackupLiteUI.handleSummaryResponse;
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
        var restoreData = window.BackupLiteRestore || {};
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
        var remoteInput = document.getElementById('remoteUrl');
        var remoteButton = document.getElementById('downloadRestore');
        var progressBar = document.querySelector('.restore-progress .progress-bar-fill');
        var currentProgress = 0; // Track current displayed progress for smooth transitions
        var progressAnimationId = null; // Track animation frame ID
        var progressStatus = document.querySelector('.restore-progress .progress-status');
        var startButton = document.getElementById('startRestore');
        var overwriteToggle = document.getElementById('overwriteData');
        var applyReplaceToggle = document.getElementById('applyReplace');
        var skipConfigToggle = document.getElementById('skipConfig');
        var autoBackupToggle = document.getElementById('autoBackup');
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
        var reviewCompleted = false;
        var restoreCompletionShown = false;
        var hasAnalyzed = !!(restoreData.summary && (restoreData.summary.name || restoreData.summary.size));
        var restoreInProgress = false;
        var restoreCompleted = !!(restoreData.progress && restoreData.progress.done);
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
        var CHUNK_SIZE_BYTES = 2 * 1024 * 1024;
        var activeChunkSession = null;
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
        var RESTORE_JOB_POLL_TIMEOUT = 300000; // 5 minutes timeout
        var RESTORE_JOB_100_POLL_LIMIT = 60000; // 1 minute after reaching 100%

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
        function ajaxRequest(formData) {
            return fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            }).then(function (res) {
                return res.text().then(function (text) {
                    var json = {};
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
            fd.append('action', 'backup_lite_refresh_nonce');
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
            
            console.log('[Backup Lite] Checking completion from history as fallback', { jobId });
            
            // Try to get history directly via a simple fetch (without nonce if possible)
            // Or use the job status endpoint with fresh nonce
            var formData = prepareFormData('backup_lite_restore_job_status');
            formData.append('job_id', jobId);
            
            ajaxRequest(formData).then(function (json) {
                var payload = getJsonPayload(json) || {};
                var job = payload.job || payload;
                
                // Check if job is complete
                if (job && (job.status === 'success' || job.status === 'completed')) {
                    console.log('[Backup Lite] Detected completion from history fallback check', { jobId, status: job.status, restoreCompletionShown });
                    // Force show overlay even if restoreCompletionShown is true (in case overlay was removed)
                    if (restoreCompletionShown) {
                        var existingOverlay = document.querySelector('.bl-completion-overlay.is-visible');
                        if (!existingOverlay) {
                            console.log('[Backup Lite] Completion flag set but overlay not visible, resetting flag');
                            restoreCompletionShown = false;
                        }
                    }
                    markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                    return;
                }
                
                // Check if job failed
                if (job && job.status === 'failed') {
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
                    if (!restoreCompletionShown) {
                        restoreCompletionShown = true;
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
                
                // Check history for success entry
                if (payload.history && Array.isArray(payload.history) && payload.history.length > 0) {
                    var latestHistory = payload.history[0];
                    if (latestHistory && latestHistory.result === 'success') {
                        var historyTimestamp = latestHistory.timestamp_raw || 0;
                        var jobStartRaw = job && (job.started_at_raw || job.created_at_raw) || 0;
                        if (!jobStartRaw || (historyTimestamp && historyTimestamp >= jobStartRaw)) {
                            console.log('[Backup Lite] Detected completion from history entry', { jobId, historyTimestamp, jobStartRaw, restoreCompletionShown });
                            // Force show overlay even if restoreCompletionShown is true (in case overlay was removed)
                            if (restoreCompletionShown) {
                                var existingOverlay = document.querySelector('.bl-completion-overlay.is-visible');
                                if (!existingOverlay) {
                                    console.log('[Backup Lite] Completion flag set but overlay not visible, resetting flag');
                                    restoreCompletionShown = false;
                                }
                            }
                            markRestoreCompleted(job && job.message || (strings.restoreCompleted || 'Restore Completed.'));
                            return;
                        }
                    } else if (latestHistory && latestHistory.result === 'failed') {
                        // History shows failure
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
                        return;
                    }
                }
            }).catch(function (error) {
                console.warn('[Backup Lite] History fallback check also failed:', error);
                // If history check also fails, and we've been polling for a while, assume we should reset
                // This prevents stuck progress when all requests fail
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
                }
            });
        }
        
        function markRestoreCompleted(message) {
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
                            var storageKey = 'backup_lite_restore_shown_' + (latestHistory.file || '') + '_' + historyTime;
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
                showCompletionOverlay({
                    icon: '✅',
                    title: strings.restoreCompleted || 'Restore Completed',
                    message: strings.restoreOverlayMessage || strings.successRestore || 'Your site has been restored successfully.',
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
                                message: strings.restoreOverlayMessage || strings.successRestore || 'Your site has been restored successfully.',
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
            activeRestoreJobId = job.id;
            restoreInProgress = true;
            restoreJobPollStartTime = Date.now();
            restoreJobReached100Time = null;
            restoreJobReached85Time = null;
            restoreCompletionShown = false;
            restoreJobEstimatedProgress = 5; // Start at 5%
            restoreJobFileSize = fileSize || 0;
            
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
            if (job.status === 'pending') {
                // Trigger cron execution immediately via AJAX
                var triggerFormData = prepareFormData('backup_lite_trigger_restore_job');
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
                                    jQuery.heartbeat.enqueue('backup_lite_keep_alive', heartbeatData);
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
                if (!window.backupLiteHeartbeatInterval) {
                    window.backupLiteHeartbeatInterval = heartbeatInterval;
                }
            }
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
            if (window.backupLiteHeartbeatInterval) {
                clearInterval(window.backupLiteHeartbeatInterval);
                window.backupLiteHeartbeatInterval = null;
            }
            activeRestoreJobId = null;
            restoreJobPollStartTime = null;
            restoreJobReached100Time = null;
            restoreJobReached85Time = null;
            restoreJobEstimatedProgress = null;
            restoreJobFileSize = 0;
            restoreJobEstimatedDuration = 0;
        }
        function pollRestoreJob(jobId, silent) {
            if (!jobId) {
                return;
            }
            
            // Check for timeout
            if (restoreJobPollStartTime && (Date.now() - restoreJobPollStartTime) > RESTORE_JOB_POLL_TIMEOUT) {
                stopRestoreJobMonitor();
                restoreInProgress = false;
                restoreCompleted = false;
                if (startButton) {
                    startButton.disabled = false;
                }
                syncWizard();
                updateRestoreCancelState();
                notifyError({ message: strings.errorGeneric || 'Restore operation timed out. Please refresh the page to check the status.' });
                return;
            }
            
            var formData = prepareFormData('backup_lite_restore_job_status');
            formData.append('job_id', jobId);
            ajaxRequest(formData).then(function (json) {
                var payload = getJsonPayload(json) || {};
                var job = payload.job || payload;
                
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
                
                if (!job) {
                    return;
                }
                
                var progress = job.progress || 0;
                var status = job.status || '';
                
                // If job is still pending after 10 seconds, try to trigger it again
                if (status === 'pending' && restoreJobPollStartTime) {
                    var timeSinceStart = Date.now() - restoreJobPollStartTime;
                    if (timeSinceStart > 10000) {
                        // Job has been pending for more than 10 seconds, try to trigger it
                        var triggerFormData = prepareFormData('backup_lite_trigger_restore_job');
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
                
                if (payload.history) {
                    renderHistory(payload.history);
                    
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
                            markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                            return; // Stop polling
                        }
                    }
                }
                
                // Check for completion conditions
                // Priority 1: Explicit success/completed status
                if (status === 'success' || status === 'completed') {
                    console.log('[Backup Lite] Job status is success/completed, marking as completed', { status, progress, jobId, restoreCompletionShown });
                    // Force show overlay even if restoreCompletionShown is true (in case overlay was removed)
                    if (restoreCompletionShown) {
                        var existingOverlay = document.querySelector('.bl-completion-overlay.is-visible');
                        if (!existingOverlay) {
                            console.log('[Backup Lite] Completion flag set but overlay not visible, resetting flag');
                            restoreCompletionShown = false;
                        }
                    }
                    markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
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
                                    
                                    // Show failure overlay
                                    if (!restoreCompletionShown) {
                                        restoreCompletionShown = true;
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
                                    console.log('[Backup Lite] Progress at 100%, history shows success, marking as completed immediately', { 
                                        latestHistoryAt100 
                                    });
                                    // Force show overlay even if restoreCompletionShown is true (in case overlay was removed)
                                    if (restoreCompletionShown) {
                                        var existingOverlay = document.querySelector('.bl-completion-overlay.is-visible');
                                        if (!existingOverlay) {
                                            console.log('[Backup Lite] Completion flag set but overlay not visible, resetting flag');
                                            restoreCompletionShown = false;
                                        }
                                    }
                                    markRestoreCompleted(job.message || (strings.restoreCompleted || 'Restore Completed.'));
                                    return; // Stop polling immediately
                                }
                            }
                        }
                        
                        if (restoreJobReached100Time) {
                            var timeAt100 = Date.now() - restoreJobReached100Time;
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
                                    // Only assume completion if we haven't already shown failure overlay
                                    var failureOverlay = document.querySelector('.bl-completion-overlay.is-visible[data-type="error"]');
                                    if (!failureOverlay && activeRestoreJobId === jobId) {
                                        // Force show overlay even if restoreCompletionShown is true (in case overlay was removed)
                                        if (restoreCompletionShown) {
                                            var existingOverlay = document.querySelector('.bl-completion-overlay.is-visible');
                                            if (!existingOverlay) {
                                                console.log('[Backup Lite] Completion flag set but overlay not visible, resetting flag');
                                                restoreCompletionShown = false;
                                            }
                                        }
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
                    // Continue polling to catch status update
                    return;
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
                        var historyCheckFormData = prepareFormData('backup_lite_restore_job_status');
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
                
                // For other errors (including 404), still try to check completion from history as fallback
                if (activeRestoreJobId === jobId) {
                    if (!silent) {
                        console.warn('[Backup Lite] Restore job status failed, checking history as fallback:', error);
                    }
                    // Always try to check history, even if silent
                    // This is important because errors might prevent status updates, but restore might have completed
                    checkRestoreCompletionFromHistory(jobId);
                    
                    // If we've been polling for a while and getting consistent errors, check if restore completed
                    // Reduce timeout to 60 seconds for faster detection
                    if (restoreJobPollStartTime && (Date.now() - restoreJobPollStartTime) > 60000) {
                        // If we've been polling for more than 60 seconds with errors, check history one more time
                        // and if restore completed, show success; otherwise show failure
                        console.log('[Backup Lite] Polling timeout reached, checking history for final status');
                        setTimeout(function() {
                            if (activeRestoreJobId === jobId) {
                                var historyCheckFormData = prepareFormData('backup_lite_restore_job_status');
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
                                    }
                                    // If no matching history found, assume failure after timeout
                                    console.warn('[Backup Lite] Timeout check: No matching history found, assuming failure');
                                    stopRestoreJobMonitor();
                                    restoreInProgress = false;
                                    restoreCompleted = false;
                                    if (startButton) {
                                        startButton.disabled = false;
                                    }
                                    setProgress(100, strings.errorGeneric || 'Restore failed. Please review the error log and try again.', true);
                                    syncWizard();
                                    updateRestoreCancelState();
                                    showToast('❌ ' + (strings.restoreFailed || 'Restore Failed'), 'error');
                                    if (!restoreCompletionShown) {
                                        restoreCompletionShown = true;
                                        showCompletionOverlay({
                                            icon: '❌',
                                            title: strings.restoreFailed || 'Restore Failed',
                                            message: strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                            confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                            type: 'error'
                                        });
                                    }
                                }).catch(function(historyError) {
                                    // If history check also fails, assume failure after timeout
                                    console.error('[Backup Lite] History check failed after timeout, assuming failure:', historyError);
                                    stopRestoreJobMonitor();
                                    restoreInProgress = false;
                                    restoreCompleted = false;
                                    if (startButton) {
                                        startButton.disabled = false;
                                    }
                                    setProgress(100, strings.errorGeneric || 'Restore failed. Please review the error log and try again.', true);
                                    syncWizard();
                                    updateRestoreCancelState();
                                    showToast('❌ ' + (strings.restoreFailed || 'Restore Failed'), 'error');
                                    if (!restoreCompletionShown) {
                                        restoreCompletionShown = true;
                                        showCompletionOverlay({
                                            icon: '❌',
                                            title: strings.restoreFailed || 'Restore Failed',
                                            message: strings.errorGeneric || 'Restore failed. Please review the error log and try again.',
                                            confirmText: strings.restoreOverlayConfirm || strings.close || 'Got it',
                                            type: 'error'
                                        });
                                    }
                                });
                            }
                        }, 1000);
                    }
                } else if (error && error.status === 400 && error.payload && error.payload.message && error.payload.message.indexOf('Job identifier') !== -1) {
                    // Job ID was missing - try to check history to see if restore completed
                    // This handles the case where job_id was lost but restore might have completed
                    console.log('[Backup Lite] Job ID missing in request, checking history for completion');
                    // Try to get history without job_id
                    var historyFormData = prepareFormData('backup_lite_restore_job_status');
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
                    // Reset state to prevent stuck progress
                    if (!silent) {
                        console.warn('[Backup Lite] Restore job status failed and no active job, resetting state:', error);
                    }
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
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
            var abortForm = prepareFormData('backup_lite_restore_chunk_abort');
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
            var formData = prepareFormData('backup_lite_restore_upload');
            formData.append('file', file);
            return ajaxRequest(formData).then(function (json) {
                handleSummaryResponse(json);
            });
        }
        function runChunkUpload(file) {
            var totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE_BYTES));
            updateUploadStatus(chunkStrings.preparing);
            var prepareForm = prepareFormData('backup_lite_restore_chunk_prepare');
            prepareForm.append('filename', file.name);
            prepareForm.append('filesize', file.size);
            prepareForm.append('chunk_size', CHUNK_SIZE_BYTES);
            prepareForm.append('total_chunks', totalChunks);
            return ajaxRequest(prepareForm).then(function (json) {
                var payload = getJsonPayload(json) || {};
                var sessionId = payload.session_id;
                if (!sessionId) {
                    throw new Error(strings.errorGeneric || 'Unable to start chunk upload.');
                }
                activeChunkSession = sessionId;
                updateRestoreCancelState();
                var sequence = Promise.resolve();
                for (var index = 0; index < totalChunks; index++) {
                    (function (chunkIndex) {
                        sequence = sequence.then(function () {
                            var start = chunkIndex * CHUNK_SIZE_BYTES;
                            var end = Math.min(start + CHUNK_SIZE_BYTES, file.size);
                            var chunkBlob = file.slice(start, end);
                            var percent = Math.min(100, Math.round(((chunkIndex + 1) / totalChunks) * 100));
                            updateUploadStatus(formatString(chunkStrings.uploading, [chunkIndex + 1, totalChunks, percent]));
                            var uploadForm = prepareFormData('backup_lite_restore_chunk_upload');
                            uploadForm.append('session_id', sessionId);
                            uploadForm.append('chunk_index', chunkIndex);
                            uploadForm.append('chunk', chunkBlob, file.name + '.part');
                            return ajaxRequest(uploadForm);
                        });
                    })(index);
                }
                return sequence.then(function () {
                    updateUploadStatus(chunkStrings.merging);
                    var finalizeForm = prepareFormData('backup_lite_restore_chunk_finalize');
                    finalizeForm.append('session_id', sessionId);
                    return ajaxRequest(finalizeForm).then(function (finalizeJson) {
                        activeChunkSession = null;
                        updateRestoreCancelState();
                        handleSummaryResponse(finalizeJson);
                    });
                });
            }).catch(function (error) {
                if (activeChunkSession) {
                    abortChunkSession(activeChunkSession);
                    activeChunkSession = null;
                    updateRestoreCancelState();
                }
                throw error;
            });
        }

        function cancelRestoreProcess() {
            if (!restoreCancelBtn) {
                return;
            }

            restoreCancelBtn.disabled = true;

            if (activeChunkSession) {
                abortChunkSession(activeChunkSession);
                activeChunkSession = null;
            }

            var cancelForm = prepareFormData(activeRestoreJobId ? 'backup_lite_restore_job_cancel' : 'backup_lite_restore_cancel');
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
            
            var formData = prepareFormData('backup_lite_get_backups_list');
            
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
        if (remoteInput) {
            remoteInput.addEventListener('input', resetAnalysisState);
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
                if (done) {
                    progressStatus.textContent = message || strings.restoreCompleted || 'Restore Completed.';
                } else if (message) {
                    progressStatus.textContent = message;
                } else {
                    progressStatus.textContent = strings.awaitingRestore || 'Awaiting restore.';
                }
            }
            if (statusMessage) {
                statusMessage.textContent = message || '';
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
            
            // Only show "Restore Completed" if done is true AND we're actually in a restore operation
            // Don't show completion status during step 1 (file analysis)
            if (done && restoreInProgress) {
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
            
            // Update restoreCompleted flag - only set to true if done AND in restore operation
            // Don't set restoreCompleted during step 1 (file analysis)
            if (done && restoreInProgress) {
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
            summaryContainer.innerHTML = html;
        }

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
                return;
            }
            if (payload.summary) {
                renderSummary(payload.summary);
            }
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
            reviewCompleted = false;
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
        }
        
        // Expose handleSummaryResponse to window.BackupLiteUI for chunk-upload-v2.js
        if (typeof window.BackupLiteUI === 'undefined') {
            window.BackupLiteUI = {};
        }
        window.BackupLiteUI.handleSummaryResponse = handleSummaryResponse;

        if (restoreData.summary) {
            renderSummary(restoreData.summary);
        }
        if (restoreData.history) {
            renderHistory(restoreData.history);
        }
        
        // Check for active or completed restore job
        if (restoreData.job && restoreData.job.id) {
            if (startButton) {
                startButton.disabled = true;
            }
            var fileSize = (restoreData.summary && restoreData.summary.size) ? restoreData.summary.size : 0;
            var jobStatus = restoreData.job.status || '';
            
            // Check if job is already complete on page load (e.g., after re-login)
            if (jobStatus === 'success' || jobStatus === 'completed') {
                console.log('[Backup Lite] Job already complete on page load, marking as completed', { jobId: restoreData.job.id, status: jobStatus });
                // Use setTimeout to ensure all functions are initialized
                setTimeout(function() {
                    markRestoreCompleted(restoreData.job.message || (strings.restoreCompleted || 'Restore Completed.'));
                }, 500);
            } else if (jobStatus === 'failed') {
                // Job failed, reset progress and state
                console.log('[Backup Lite] Job failed on page load, resetting state', { jobId: restoreData.job.id, status: jobStatus });
                stopRestoreJobMonitor();
                restoreInProgress = false;
                restoreCompleted = false;
                if (startButton) {
                    startButton.disabled = false;
                }
                setProgress(0, '', false);
                syncWizard();
                updateRestoreCancelState();
            } else {
                // Job is still running or pending, start monitoring
                startRestoreJobMonitor(restoreData.job, fileSize);
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
                    var storageKey = 'backup_lite_restore_shown_' + (latestHistory.file || '') + '_' + historyTime;
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
                    var storageKey = 'backup_lite_restore_shown_' + (latestHistory.file || '') + '_' + historyTime;
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
                var formData = prepareFormData('backup_lite_restore_from_backup');
                formData.append('filename', value);

                existingButton.disabled = true;
                isAnalyzing = true;
                analysisError = false;
                hasAnalyzed = false;
                restoreCompleted = false;
                reviewCompleted = false;
                syncWizard();
                updateRestoreCancelState();
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
                });
            });
        }

        if (remoteButton) {
            remoteButton.addEventListener('click', function () {
                var value = remoteInput ? remoteInput.value.trim() : '';
                if (!value) {
                    notifyError({ message: strings.noRemoteUrl || 'Please enter a valid URL.' });
                    return;
                }
                var formData = prepareFormData('backup_lite_restore_remote_url');
                formData.append('url', value);

                remoteButton.disabled = true;
                isAnalyzing = true;
                analysisError = false;
                hasAnalyzed = false;
                restoreCompleted = false;
                reviewCompleted = false;
                syncWizard();
                updateRestoreCancelState();
                ajaxRequest(formData).then(function (json) {
                    remoteButton.disabled = false;
                    handleSummaryResponse(json);
                }).catch(function (error) {
                    remoteButton.disabled = false;
                    isAnalyzing = false;
                    analysisError = true;
                    hasAnalyzed = false;
                    restoreCompleted = false;
                    syncWizard();
                    updateRestoreCancelState();
                    notifyError({ message: (error && error.message) ? error.message : (strings.errorGeneric || 'Request failed. Please try again.') });
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
                if (!overwriteToggle.checked) {
                    var overwriteConfirm = getString('confirmOverwriteData', 'This will overwrite your site data. Continue?');
                    if (!window.confirm('⚠️ ' + overwriteConfirm)) {
                        return;
                    }
                }
                markReviewCompleted();

                var formData = prepareFormData('backup_lite_restore_enqueue');
                formData.append('overwrite', overwriteToggle.checked ? 'true' : 'false');
                formData.append('autoBackup', autoBackupToggle && autoBackupToggle.checked ? 'true' : 'false');
                formData.append('skipConfig', skipConfigToggle && skipConfigToggle.checked ? 'true' : 'false');
                if (applyReplaceToggle && applyReplaceToggle.checked) {
                    formData.append('searchReplace', JSON.stringify([]));
                }

                startButton.disabled = true;
                restoreInProgress = true;
                restoreCompleted = false;
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
                        renderHistory(payload.history);
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
                    stopRestoreJobMonitor();
                    restoreInProgress = false;
                    restoreCompleted = false;
                    startButton.disabled = false;
                    syncWizard();
                    updateRestoreCancelState();
                    console.error('Restore enqueue error:', error);
                    notifyError({ message: (error && error.message) ? error.message : (strings.errorGeneric || 'An error occurred during restore.') });
                });
            });
        }
    }

    if (currentPage === 'backup-lite-restore') {
        initRestoreCenter();
        
        // Check for completed restore job on page load (e.g., after re-login)
        // This handles the case where restore completed while user was logged out
        var restoreData = window.BackupLiteRestore || {};
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
        var downloadingMessage = formatString(
            getString('downloadingBackups', 'Downloading %s backup(s)...'),
            rows.length
        );
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
            payload.append('action', 'backup_lite_delete_backups');
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
                    var deletedMessage = formatString(
                        getString('deletedBackups', 'Deleted %s backup(s).'),
                        deleted.length
                    );
                    showToast('🗑️ ' + deletedMessage, 'error');
                }

                if (errors.length) {
                    var failedMessage = formatString(
                        getString('failedDeleteBackups', 'Failed to delete %s backup(s). Check logs.'),
                        errors.length
                    );
                    showToast('⚠️ ' + failedMessage, 'warning');
                }

                refreshRowCheckboxes();
                updateMasterCheckbox();
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
    var newScheduleButtons = Array.prototype.slice.call(document.querySelectorAll('#bl-new-schedule, [data-bl-action="new-schedule"]'));
    var scheduleModal = document.getElementById('bl-schedule-modal');
    var scheduleModalTitle = document.getElementById('bl-modal-title');

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
        ajaxRequest('backup_lite_fetch_schedules').then(function (data) {
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
            emptyRow.className = 'bl-empty-row';
            var emptyCell = document.createElement('td');
            emptyCell.colSpan = 8;
            emptyCell.textContent = strings.noSchedules || 'No schedules configured yet.';
            emptyRow.appendChild(emptyCell);
            scheduleBody.appendChild(emptyRow);
            return;
        }

        schedules.forEach(function (schedule) {
            var row = document.createElement('tr');

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
            var editLabel = getString('scheduleActionEdit', 'Edit');
            var deleteLabel = getString('scheduleActionDelete', 'Delete');
            list.appendChild(createActionButton('▶️ ' + startLabel, function () {
                handleStartSchedule(schedule.id);
            }));
            list.appendChild(createActionButton('✏️ ' + editLabel, function () {
                openScheduleModal(schedule);
            }));
            list.appendChild(createActionButton('🗑️ ' + deleteLabel, function () {
                handleDeleteSchedule(schedule.id);
            }));

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
        button.addEventListener('click', function (event) {
            if (typeof onClick === 'function') {
                onClick(event);
            }
            var menu = button.closest('details');
            if (menu) {
                menu.removeAttribute('open');
            }
        });
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
    }

    function openScheduleModal(schedule) {
        if (!scheduleModal) {
            return;
        }
        resetScheduleForm(modalFormContext);

        if (schedule) {
            scheduleModalTitle.textContent = strings.editScheduleTitle || 'Edit Schedule';
            if (modalFormContext.id) {
                modalFormContext.id.value = schedule.id || '';
            }
            if (modalFormContext.title) {
                modalFormContext.title.value = schedule.title || '';
            }
            if (modalFormContext.type) {
                modalFormContext.type.value = schedule.type || 'backup';
            }
            if (modalFormContext.period) {
                modalFormContext.period.value = schedule.period || 'daily';
            }
            if (modalFormContext.time) {
                modalFormContext.time.value = schedule.time || '00:00';
            }
            if (modalFormContext.retain) {
                modalFormContext.retain.value = schedule.retain || 5;
            }
            if (modalFormContext.maxAge) {
                modalFormContext.maxAge.value = schedule.max_age || 30;
            }
            if (modalFormContext.notify) {
                modalFormContext.notify.value = schedule.notify || '';
            }
            if (modalFormContext.status) {
                modalFormContext.status.checked = schedule.status !== 'disabled';
            }
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
        var match = null;
        schedules.forEach(function (schedule) {
            if (schedule.id === id) {
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
        if (!window.confirm(getString('confirmDeleteSchedule', 'Delete this schedule?'))) {
            return;
        }
        ajaxRequest('backup_lite_delete_schedule', { id: id }).then(function () {
            removeScheduleLocally(id);
            showToast('🗑️ ' + getString('scheduleDeleted', 'Schedule deleted.'), 'error');
        }).catch(function (error) {
            var message = (error && error.message) ? error.message : getString('unableDeleteSchedule', 'Unable to delete schedule.');
            showToast('⚠️ ' + message, 'warning');
        });
    }

    function handleStartSchedule(id) {
        ajaxRequest('backup_lite_start_schedule', { id: id }).then(function (data) {
            if (data.schedule) {
                upsertSchedule(data.schedule);
            }
            showToast('✅ ' + getString('manualJobStarted', 'Backup job started manually.'), 'success');
        }).catch(function (error) {
            var message = (error && error.message) ? error.message : 'Unable to start schedule.';
            showToast('⚠️ ' + message, 'warning');
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
            var action = scheduleId ? 'backup_lite_update_schedule' : 'backup_lite_add_schedule';
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
            ajaxRequest('backup_lite_add_schedule', { schedule: JSON.stringify(payload) }).then(function (data) {
                if (data.schedule) {
                    upsertSchedule(data.schedule);
                } else {
                    fetchSchedules();
                }
                resetScheduleForm(inlineFormContext, { type: 'backup', period: 'weekly', time: '02:00', retain: 5, max_age: 30, status: 'enabled' });
                showToast('✅ ' + (strings.scheduleSaved || 'Schedule saved successfully.'), 'success');
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
            window.BackupLiteUI.refreshLogs().then(function () {
                updateLogPreview('', '', false);
                showToast(strings.logsRefreshed || 'Logs refreshed.', 'success');
            });
        });
    }

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
                var existing = scheduleId ? findScheduleById(scheduleId) : null;
                if (existing) {
                    openScheduleModal(existing);
                }
                return;
            }
        }

        var action = event.target && event.target.dataset ? event.target.dataset.logAction : null;
        var logName = event.target && event.target.dataset ? event.target.dataset.log : null;
        if (!action || !logName) {
            return;
        }

        if (action === 'view') {
            ajaxRequest('backup_lite_view_log', { log: logName }).then(function (data) {
                if (data.log) {
                    updateLogPreview(data.log.name, data.log.content, data.log.truncated);
                }
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to load log.';
                showToast('⚠️ ' + message, 'warning');
            });
        }

        if (action === 'delete') {
            if (!window.confirm(strings.confirmDeleteLog || 'Delete this log file?')) {
                return;
            }
            ajaxRequest('backup_lite_delete_log', { log: logName }).then(function () {
                showToast('🗑️ ' + (strings.logDeleted || 'Log deleted.'), 'error');
                window.BackupLiteUI.refreshLogs();
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
    var verifyLicenseBtn = document.getElementById('bl-verify-license');

    // License verification
    if (verifyLicenseBtn) {
        verifyLicenseBtn.addEventListener('click', function () {
            var licenseKey = document.getElementById('bl-setting-license-key');
            if (!licenseKey || !licenseKey.value) {
                alert(strings.errorGeneric || 'License key is required.');
                return;
            }

            verifyLicenseBtn.disabled = true;
            verifyLicenseBtn.textContent = strings.runningMessage || 'Verifying...';

            fetch(ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'backup_lite_verify_license',
                    nonce: settings.nonce,
                    license_key: licenseKey.value,
                }),
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (json.success) {
                    showToast(json.data.message || 'License key saved successfully.', 'success');
                } else {
                    showToast(json.data.message || 'Failed to verify license key.', 'error');
                }
            })
            .catch(function (error) {
                console.error('Error verifying license:', error);
            showToast(getString('licenseError', 'An error occurred while verifying the license.'), 'error');
            })
            .finally(function () {
                verifyLicenseBtn.disabled = false;
                verifyLicenseBtn.textContent = strings.verifyLicense || 'Verify License';
            });
        });
    }
    var settingsMessage = document.getElementById('bl-settings-message');
    var testEmailButton = document.getElementById('bl-test-email');

    function showSettingsMessage(message, type) {
        if (!settingsMessage) {
            if (message) {
                showToast(message, type === 'error' ? 'error' : 'success');
            }
            return;
        }
        settingsMessage.className = 'backup-lite-messages';
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

    function gatherSettings() {
        var settings = {
            backup_directory: document.getElementById('bl-setting-backup-dir') ? document.getElementById('bl-setting-backup-dir').value.trim() : '',
            notification_email: document.getElementById('bl-setting-notify-email') ? document.getElementById('bl-setting-notify-email').value.trim() : '',
            min_role: document.getElementById('bl-setting-role') ? document.getElementById('bl-setting-role').value : 'administrator',
            ui_theme: document.getElementById('bl-setting-theme-mode') ? document.getElementById('bl-setting-theme-mode').value : 'auto',
            feature_restore_center_v2: getCheckboxValue('bl-feature-restore-center'),
            feature_ui_animation: getCheckboxValue('bl-feature-ui-animation'),
            feature_extended_log: getCheckboxValue('bl-feature-extended-log'),
        };

        var debugToggle = document.getElementById('bl-setting-debug-mode');
        if (debugToggle) {
            settings.debug_mode = debugToggle.checked;
        }

        var cloudToggle = document.getElementById('bl-feature-cloud');
        if (cloudToggle) {
            settings.feature_cloud_destinations = cloudToggle.checked;
        }

        var advancedToggle = document.getElementById('bl-feature-advanced');
        if (advancedToggle) {
            settings.feature_advanced_filters = advancedToggle.checked;
        }

        var aiKey = document.getElementById('bl-setting-ai-key');
        if (aiKey) {
            settings.ai_openai_key = aiKey.value.trim();
        }
        var aiModel = document.getElementById('bl-setting-ai-model');
        if (aiModel) {
            settings.ai_model = aiModel.value;
        }
        var aiTemp = document.getElementById('bl-setting-ai-temperature');
        if (aiTemp) {
            settings.ai_temperature = parseFloat(aiTemp.value) || 0.7;
        }
        var aiEnabled = document.getElementById('bl-setting-ai-enabled');
        if (aiEnabled) {
            settings.ai_enabled = aiEnabled.checked;
        }
        var aiLog = document.getElementById('bl-setting-ai-log');
        if (aiLog) {
            settings.ai_log_activity = aiLog.checked;
        }

        return settings;
    }

    if (settingsForm) {
        settingsForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var payload = gatherSettings();
            ajaxRequest('backup_lite_save_settings', { settings: JSON.stringify(payload) }).then(function () {
                showSettingsMessage(strings.settingsSaved || 'Settings saved successfully.', 'success');
                showToast('⚙️ ' + (strings.settingsSaved || 'Settings saved successfully.'), 'success');
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to save settings.';
                showSettingsMessage(message, 'error');
            });
        });

        ajaxRequest('backup_lite_fetch_settings').then(function (data) {
            if (!data.settings) {
                return;
            }
            var s = data.settings;
            var backupDir = document.getElementById('bl-setting-backup-dir');
            var email = document.getElementById('bl-setting-notify-email');
            var role = document.getElementById('bl-setting-role');
            var theme = document.getElementById('bl-setting-theme-mode');
            if (backupDir) backupDir.value = s.backup_directory || backupDir.value;
            if (email) email.value = s.notification_email || email.value;
            if (role) role.value = s.min_role || role.value;
            if (theme && s.ui_theme) theme.value = s.ui_theme;

            var debugToggle = document.getElementById('bl-setting-debug-mode');
            if (debugToggle) debugToggle.checked = !!s.debug_mode;

            var restoreToggle = document.getElementById('bl-feature-restore-center');
            if (restoreToggle) restoreToggle.checked = !!s.feature_restore_center_v2;
            var animationToggle = document.getElementById('bl-feature-ui-animation');
            if (animationToggle) animationToggle.checked = !!s.feature_ui_animation;
            var logToggle = document.getElementById('bl-feature-extended-log');
            if (logToggle) logToggle.checked = !!s.feature_extended_log;
            var cloudToggle = document.getElementById('bl-feature-cloud');
            if (cloudToggle) cloudToggle.checked = !!s.feature_cloud_destinations;
            var advancedToggle = document.getElementById('bl-feature-advanced');
            if (advancedToggle) advancedToggle.checked = !!s.feature_advanced_filters;

            // PRO Settings
            var aiKey = document.getElementById('bl-setting-ai-key');
            if (aiKey && s.ai_openai_key !== undefined) {
                aiKey.value = s.ai_openai_key || '';
            }
            var aiModel = document.getElementById('bl-setting-ai-model');
            if (aiModel && s.ai_model !== undefined) {
                aiModel.value = s.ai_model || 'gpt-4o-mini';
            }
            var aiTemp = document.getElementById('bl-setting-ai-temperature');
            if (aiTemp && s.ai_temperature !== undefined) {
                aiTemp.value = s.ai_temperature || 0.7;
            }
            var aiEnabled = document.getElementById('bl-setting-ai-enabled');
            if (aiEnabled) {
                aiEnabled.checked = !!s.ai_enabled;
            }
            var aiLog = document.getElementById('bl-setting-ai-log');
            if (aiLog) {
                aiLog.checked = !!s.ai_log_activity;
            }

            var licenseKey = document.getElementById('bl-setting-license-key');
            if (licenseKey && s.pro_license_key !== undefined) {
                licenseKey.value = s.pro_license_key || '';
            }
        }).catch(function () {
            // ignore fetch errors
        });
    }

    // Feature Toggles UI removed per request; live preview wiring disabled.

    if (testEmailButton) {
        testEmailButton.addEventListener('click', function () {
            ajaxRequest('backup_lite_test_email').then(function () {
                showToast(strings.testEmailSuccess || '✅ Test email sent successfully', 'success');
            }).catch(function (error) {
                var message = (error && error.message) ? error.message : 'Unable to send test email.';
                showToast('❌ ' + message, 'error');
            });
        });
    }

    if (scheduleBody) {
        fetchSchedules();
    }

    if (currentPage === 'backup-lite-logs' && window.BackupLiteUI && typeof window.BackupLiteUI.refreshLogs === 'function') {
        window.BackupLiteUI.refreshLogs();
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

            // Delegate click to original buttons via shared data attributes
            portal.addEventListener('click', function (evt) {
                var btn = evt.target.closest('button');
                if (!btn) return;
                var action = btn.getAttribute('data-schedule-action') || btn.getAttribute('data-log-action') || '';
                if (!action) return;
                var original = list.querySelector('button[data-schedule-action=\"' + action + '\"]') ||
                               list.querySelector('button[data-log-action=\"' + action + '\"]');
                if (original) original.click();
                removePortal();
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
                    action: 'backup_lite_estimate_result',
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
        function displayResults(data) {
            loadingEl.style.display = 'none';
            scanningEl.style.display = 'none';
            resultsEl.style.display = 'block';

            document.getElementById('backup-lite-estimate-db-size').textContent = data.database.formatted || '-';
            document.getElementById('backup-lite-estimate-files-size').textContent = data.files.formatted || '-';
            document.getElementById('backup-lite-estimate-total-size').textContent = data.total.formatted || '-';
            document.getElementById('backup-lite-estimate-last-scanned').textContent = data.last_scanned || '-';

            // Show warning if total > 1GB
            if (data.total.bytes > 1024 * 1024 * 1024) {
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
                    action: 'backup_lite_estimate_start',
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
                        action: 'backup_lite_estimate_progress',
                        nonce: nonce
                    },
                    success: function (response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            
                            // Update progress bar
                            var percent = data.progress_percent || 0;
                            if (progressFill) {
                                progressFill.style.width = percent + '%';
                            }
                            if (progressText) {
                                progressText.textContent = percent.toFixed(1) + '%';
                            }

                            // Update status
                            if (scanStatus) {
                                var scannedCount = (data.scanned_count !== undefined && data.scanned_count !== null) ? data.scanned_count : 0;
                                var totalBytesFormatted = data.total_bytes_formatted || '0 B';
                                var statusText = 'Scanned: ' + scannedCount.toLocaleString() + ' files, ' + totalBytesFormatted;
                                if (data.current_path) {
                                    statusText += ' | Current: ' + data.current_path.substring(data.current_path.lastIndexOf('/') + 1);
                                }
                                scanStatus.textContent = statusText;
                            }

                            // Check if completed
                            if (data.status === 'completed' || data.status === 'idle') {
                                clearInterval(progressInterval);
                                progressInterval = null;
                                loadEstimate();
                                if (rescanBtn) {
                                    rescanBtn.disabled = false;
                                }
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

    // Initialize AI Site Scan (Demo)
    (function initAISiteScan() {
        var scanBtn = document.getElementById('museder-ai-scan-btn');
        var scanCard = document.getElementById('museder-ai-scan-card');
        if (!scanBtn || !scanCard) {
            return; // Not on dashboard page
        }

        var loadingEl = document.getElementById('museder-ai-scan-loading');
        var resultsEl = document.getElementById('museder-ai-scan-results');
        var errorEl = document.getElementById('museder-ai-scan-error');
        var modeEl = document.getElementById('museder-ai-scan-mode');
        var summaryText = document.getElementById('museder-ai-scan-summary-text');
        var riskBadge = document.getElementById('museder-ai-scan-risk-badge');
        var recommendationsList = document.getElementById('museder-ai-scan-recommendations-list');
        var errorMessage = document.getElementById('museder-ai-scan-error-message');

        scanBtn.addEventListener('click', function() {
            // Reset UI
            scanBtn.disabled = true;
            loadingEl.style.display = 'block';
            resultsEl.style.display = 'none';
            errorEl.style.display = 'none';

            var ajaxUrl = localizedSettings.ajaxUrl || '/wp-admin/admin-ajax.php';
            var nonce = localizedSettings.nonce || '';

            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'museder_ai_demo_site_scan',
                    nonce: nonce
                },
                success: function(response) {
                    scanBtn.disabled = false;
                    loadingEl.style.display = 'none';

                    if (response.success && response.data) {
                        var data = response.data;
                        
                        // Display mode indicator
                        if (modeEl) {
                            if (data.mode === 'demo') {
                                modeEl.textContent = 'Demo mode (no external AI call).';
                            } else if (data.mode === 'live') {
                                modeEl.textContent = 'Powered by Museder AI (OpenAI).';
                            } else {
                                modeEl.textContent = '';
                            }
                        }
                        
                        // Display summary
                        if (summaryText && data.summary) {
                            summaryText.textContent = data.summary;
                        }

                        // Display risk badge
                        if (riskBadge && data.risk) {
                            var risk = data.risk.toLowerCase();
                            var riskColors = {
                                'low': { bg: '#d4edda', color: '#155724', text: 'Low' },
                                'medium': { bg: '#fff3cd', color: '#856404', text: 'Medium' },
                                'high': { bg: '#f8d7da', color: '#721c24', text: 'High' },
                                'critical': { bg: '#f5c6cb', color: '#721c24', text: 'Critical' }
                            };
                            var riskStyle = riskColors[risk] || riskColors['medium'];
                            riskBadge.textContent = riskStyle.text;
                            riskBadge.style.backgroundColor = riskStyle.bg;
                            riskBadge.style.color = riskStyle.color;
                        }

                        // Display recommendations
                        if (recommendationsList && data.recommendations && Array.isArray(data.recommendations)) {
                            recommendationsList.innerHTML = '';
                            data.recommendations.forEach(function(rec) {
                                var li = document.createElement('li');
                                li.textContent = rec;
                                recommendationsList.appendChild(li);
                            });
                        }

                        resultsEl.style.display = 'block';
                    } else {
                        // Handle limit reached error
                        if (response.data && response.data.code === 'limit_reached') {
                            errorMessage.textContent = response.data.message || 'You have used your free AI Site Scan for this month. Upgrade to Pro for unlimited scans.';
                            // Optionally disable button or change text
                            // scanBtn.disabled = true;
                            // scanBtn.textContent = 'Upgrade to Pro';
                        } else {
                            errorMessage.textContent = response.data && response.data.message 
                                ? response.data.message 
                                : (strings.errorGeneric || 'An error occurred.');
                        }
                        errorEl.style.display = 'block';
                    }
                },
                error: function(xhr, status, error) {
                    scanBtn.disabled = false;
                    loadingEl.style.display = 'none';
                    errorMessage.textContent = strings.errorGeneric || 'An error occurred. Please try again.';
                    errorEl.style.display = 'block';
                }
            });
        });
    })();

    // Initialize Backup AI Report
    (function initAIBackupReport() {
        var reportBtn = document.getElementById('museder-ai-backup-report-btn');
        var reportCard = document.getElementById('museder-ai-backup-report');
        if (!reportBtn || !reportCard) {
            return; // Not on dashboard page
        }

        var loadingEl = document.getElementById('museder-ai-backup-report-loading');
        var resultsEl = document.getElementById('museder-ai-backup-report-results');
        var errorEl = document.getElementById('museder-ai-backup-report-error');
        var modeEl = document.getElementById('museder-ai-backup-report-mode');
        var summaryText = document.getElementById('museder-ai-backup-report-summary-text');
        var scoreBadge = document.getElementById('museder-ai-backup-report-score-badge');
        var riskBadge = document.getElementById('museder-ai-backup-report-risk-badge');
        var riskFactorsList = document.getElementById('museder-ai-backup-report-risk-factors-list');
        var recommendationsList = document.getElementById('museder-ai-backup-report-recommendations-list');
        var errorMessage = document.getElementById('museder-ai-backup-report-error-message');

        reportBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            console.log('AI Backup Report clicked');
            
            // Reset UI
            reportBtn.disabled = true;
            loadingEl.style.display = 'block';
            resultsEl.style.display = 'none';
            errorEl.style.display = 'none';

            var ajaxUrl = localizedSettings.ajaxUrl || '/wp-admin/admin-ajax.php';
            var nonce = localizedSettings.nonce || '';

            jQuery.ajax({
                url: ajaxUrl,
                type: 'POST',
                data: {
                    action: 'museder_ai_backup_report',
                    nonce: nonce
                },
                success: function(response) {
                    reportBtn.disabled = false;
                    loadingEl.style.display = 'none';

                    if (response.success && response.data) {
                        var data = response.data;
                        
                        // Display mode indicator
                        if (modeEl) {
                            if (data.mode === 'demo') {
                                modeEl.textContent = 'Demo mode (no external AI call).';
                            } else if (data.mode === 'live') {
                                modeEl.textContent = 'Powered by Museder AI (OpenAI).';
                            } else {
                                modeEl.textContent = '';
                            }
                        }
                        
                        // Display summary
                        if (summaryText && data.summary) {
                            summaryText.textContent = data.summary;
                        }

                        // Display overall score
                        if (scoreBadge && data.overall_score !== undefined) {
                            var score = parseInt(data.overall_score, 10);
                            scoreBadge.textContent = score + '/100';
                            
                            // Color code based on score
                            if (score >= 80) {
                                scoreBadge.style.backgroundColor = '#d4edda';
                                scoreBadge.style.color = '#155724';
                            } else if (score >= 60) {
                                scoreBadge.style.backgroundColor = '#fff3cd';
                                scoreBadge.style.color = '#856404';
                            } else {
                                scoreBadge.style.backgroundColor = '#f8d7da';
                                scoreBadge.style.color = '#721c24';
                            }
                        }

                        // Display risk badge
                        if (riskBadge && data.risk_level) {
                            var risk = data.risk_level.toLowerCase();
                            var riskColors = {
                                'low': { bg: '#d4edda', color: '#155724', text: 'Low' },
                                'medium': { bg: '#fff3cd', color: '#856404', text: 'Medium' },
                                'high': { bg: '#f8d7da', color: '#721c24', text: 'High' }
                            };
                            var riskStyle = riskColors[risk] || riskColors['medium'];
                            riskBadge.textContent = riskStyle.text;
                            riskBadge.style.backgroundColor = riskStyle.bg;
                            riskBadge.style.color = riskStyle.color;
                        }

                        // Display risk factors
                        if (riskFactorsList && data.risk_factors && Array.isArray(data.risk_factors)) {
                            riskFactorsList.innerHTML = '';
                            data.risk_factors.forEach(function(factor) {
                                var li = document.createElement('li');
                                var severity = factor.severity || 'medium';
                                var severityColors = {
                                    'low': '#28a745',
                                    'medium': '#ffc107',
                                    'high': '#dc3545'
                                };
                                var severityColor = severityColors[severity] || '#666';
                                
                                var text = '<strong style="color: ' + severityColor + ';">' + 
                                    (factor.name || 'Unknown') + '</strong>';
                                if (factor.details) {
                                    text += ': ' + factor.details;
                                }
                                li.innerHTML = text;
                                riskFactorsList.appendChild(li);
                            });
                        }

                        // Display recommendations
                        if (recommendationsList && data.recommendations && Array.isArray(data.recommendations)) {
                            recommendationsList.innerHTML = '';
                            data.recommendations.forEach(function(rec) {
                                var li = document.createElement('li');
                                li.textContent = rec;
                                recommendationsList.appendChild(li);
                            });
                        }

                        resultsEl.style.display = 'block';

                        // Show success message (optional)
                        if (showToast) {
                            showToast('✅ ' + (strings.settingsSaved || 'Report generated successfully.'), 'success');
                        }
                    } else {
                        // Handle limit reached error
                        if (response.data && response.data.code === 'limit_reached') {
                            errorMessage.textContent = response.data.message || 'You have used your free Backup AI Report for this month. Upgrade to Pro for unlimited reports.';
                        } else {
                            errorMessage.textContent = response.data && response.data.message 
                                ? response.data.message 
                                : (strings.errorGeneric || 'An error occurred.');
                        }
                        errorEl.style.display = 'block';
                        // Do not reload on error
                    }
                },
                error: function(xhr, status, error) {
                    reportBtn.disabled = false;
                    loadingEl.style.display = 'none';
                    errorMessage.textContent = strings.errorGeneric || 'An error occurred. Please try again.';
                    errorEl.style.display = 'block';
                }
            });
        });
    })();

    // Handle scroll-to anchor links (for Health Score card and other AI features)
    (function initAIScrollLinks() {
        var links = document.querySelectorAll('[data-museder-scroll]');
        if (!links || !links.length) {
            return;
        }

        links.forEach(function(link) {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                var targetSelector = link.getAttribute('data-museder-scroll');
                if (!targetSelector) {
                    return;
                }
                var target = document.querySelector(targetSelector);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    // Fallback to default anchor behavior if target not found
                    var href = link.getAttribute('href');
                    if (href && href.indexOf('#') === 0) {
                        window.location.hash = href;
                    }
                }
            });
        });
    })();

    // Handle exit safe mode button
    var exitSafeModeBtn = document.getElementById('backup-lite-exit-safe-mode-btn');
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
                    action: 'backup_lite_exit_safe_mode',
                    nonce: nonce
                },
                success: function (response) {
                    if (response.success) {
                        showToast('✅ ' + (response.data.message || 'Safe mode exited and plugins restored successfully.'), 'success');
                        // Hide the notice
                        var notice = document.getElementById('backup-lite-safe-mode-notice');
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
})(jQuery);
