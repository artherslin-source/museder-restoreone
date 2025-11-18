(function () {
    'use strict';

    var config = window.BackupLiteRestore || {};
    var restURL = config.restURL || '';
    var nonce = config.nonce || '';
    var toast = window.Toastify || null;

    if (!restURL) {
        return;
    }

    var RestoreCenter = {
        state: {
            selectedBackup: null,
            jobId: null,
            status: null,
            validation: null,
            dryRun: null,
            rollback: null,
            autoScroll: true,
            polling: null,
            lastStage: null,
            lastMessage: null
        },
        refs: {},
        init: function () {
            this.cacheDOM();
            this.bindEvents();
            this.renderBackupList();
            this.setAutoScroll(true);
            this.log('尚未開始還原作業，請先選擇備份檔並建立還原作業。', 'info');
            this.updateHeader();
            this.updateStepIndicator();
            this.updateSafetyPanel();
            this.updateDryRunPanel();
            this.updateExecutePanel();
            this.updateRollbackPanel();
            this.updateProgress({ progress: 0, message: '等待流程開始…' });
        },
        cacheDOM: function () {
            this.refs.backupRows = document.querySelector('#bl-restore-backup-list');
            this.refs.prepareBtn = document.querySelector('#bl-restore-prepare');
            this.refs.searchInput = document.querySelector('#bl-backup-search');
            this.refs.validationList = document.querySelector('#bl-restore-validation-list');
            this.refs.validationBadge = document.querySelector('#bl-validation-status');
            this.refs.dryRunBtn = document.querySelector('#bl-restore-dryrun');
            this.refs.dryRunSummary = document.querySelector('#bl-dryrun-summary');
            this.refs.dryRunDownloads = document.querySelector('#bl-dryrun-downloads');
            this.refs.dryRunReportTxt = document.querySelector('#bl-dryrun-report-txt');
            this.refs.dryRunReportJson = document.querySelector('#bl-dryrun-report-json');
            this.refs.executeBtn = document.querySelector('#bl-restore-execute');
            this.refs.executeCheckbox = document.querySelector('#bl-restore-confirm');
            this.refs.executeStatus = document.querySelector('#bl-execute-status');
            this.refs.rollbackCard = document.querySelector('#bl-restore-card-rollback');
            this.refs.rollbackBtn = document.querySelector('#bl-restore-rollback');
            this.refs.rollbackCheckbox = document.querySelector('#bl-restore-rollback-confirm');
            this.refs.rollbackMeta = document.querySelector('#bl-rollback-meta');
            this.refs.rollbackStatus = document.querySelector('#bl-rollback-status');
            this.refs.jobStatus = document.querySelector('#bl-restore-job-status');
            this.refs.jobId = document.querySelector('#bl-restore-job-id');
            this.refs.stepIndicator = document.querySelector('#bl-restore-step-indicator');
            this.refs.progressText = document.querySelector('#bl-restore-progress .progress-status');
            this.refs.progressBar = document.querySelector('#bl-restore-progress .progress-bar-fill');
            this.refs.progressLog = document.querySelector('#bl-restore-log');
            this.refs.autoScrollToggle = document.querySelector('#bl-log-autoscroll');
        },
        bindEvents: function () {
            var self = this;
            if (this.refs.prepareBtn) {
                this.refs.prepareBtn.addEventListener('click', function () {
                    self.startPrepareAndValidate();
                });
            }

            if (this.refs.searchInput) {
                this.refs.searchInput.addEventListener('input', function (event) {
                    self.filterBackupList(event.target.value);
                });
            }

            if (this.refs.backupRows) {
                this.refs.backupRows.addEventListener('change', function (event) {
                    var radio = event.target.closest('input[type="radio"]');
                    if (!radio) {
                        return;
                    }
                    self.selectBackup(radio.value, radio.closest('tr'));
                });
                this.refs.backupRows.addEventListener('click', function (event) {
                    var row = event.target.closest('tr[data-backup-name]');
                    if (!row) {
                        return;
                    }
                    var radio = row.querySelector('input[type="radio"]');
                    if (radio) {
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            }

            if (this.refs.dryRunBtn) {
                this.refs.dryRunBtn.addEventListener('click', function () {
                    self.runDryRun();
                });
            }

            if (this.refs.executeBtn) {
                this.refs.executeBtn.addEventListener('click', function () {
                    self.runExecute();
                });
            }

            if (this.refs.rollbackBtn) {
                this.refs.rollbackBtn.addEventListener('click', function () {
                    self.runRollback();
                });
            }

            if (this.refs.executeCheckbox) {
                this.refs.executeCheckbox.addEventListener('change', function () {
                    self.updateExecutePanel();
                });
            }

            if (this.refs.rollbackCheckbox) {
                this.refs.rollbackCheckbox.addEventListener('change', function () {
                    self.updateRollbackPanel();
                });
            }

            if (this.refs.autoScrollToggle) {
                this.refs.autoScrollToggle.addEventListener('change', function (event) {
                    self.setAutoScroll(!!event.target.checked);
                });
            }
        },
        renderBackupList: function () {
            if (!this.refs.backupRows) {
                return;
            }
            this.refs.backupRows.innerHTML = '';
            if (!Array.isArray(config.backups) || !config.backups.length) {
                var empty = document.createElement('tr');
                empty.innerHTML = '<td colspan="4">' + (config.labels && config.labels.noBackups ? config.labels.noBackups : 'No backups available.') + '</td>';
                this.refs.backupRows.appendChild(empty);
                return;
            }

            config.backups.forEach(function (backup) {
                var row = document.createElement('tr');
                row.dataset.backupName = backup.name;
                var size = backup.size_human || backup.size || '';
                var created = backup.created || '';
                row.innerHTML = '<td class="bl-restore-backup-radio"><input type="radio" name="restore_backup" value="' + backup.name + '" /></td>' +
                    '<td><strong>' + backup.name + '</strong></td>' +
                    '<td>' + created + '</td>' +
                    '<td>' + size + '</td>';
                this.refs.backupRows.appendChild(row);
            }, this);
        },
        filterBackupList: function (term) {
            if (!this.refs.backupRows) {
                return;
            }
            var rows = this.refs.backupRows.querySelectorAll('tr');
            rows.forEach(function (row) {
                var name = row.dataset.backupName;
                if (!name) {
                    row.style.display = '';
                    return;
                }
                row.style.display = !term || name.toLowerCase().indexOf(term.toLowerCase()) !== -1 ? '' : 'none';
            });
        },
        selectBackup: function (name, row) {
            this.state.selectedBackup = name;
            if (this.refs.backupRows) {
                this.refs.backupRows.querySelectorAll('tr').forEach(function (tr) {
                    tr.classList.remove('is-selected');
                });
            }
            if (row) {
                row.classList.add('is-selected');
            }
        },
        buildRequest: function (path, options) {
            var opts = options || {};
            opts.headers = opts.headers || {};
            opts.headers['Content-Type'] = opts.headers['Content-Type'] || 'application/json';
            if (nonce) {
                opts.headers['X-WP-Nonce'] = nonce;
            }
            opts.credentials = 'same-origin';
            return fetch(restURL.replace(/\/?$/, '/') + path.replace(/^\//, ''), opts).then(function (res) {
                if (!res.ok) {
                    return res.json().catch(function () {
                        return { ok: false, message: res.statusText };
                    });
                }
                return res.json();
            });
        },
        startPrepareAndValidate: function () {
            var _this = this;
            if (!this.state.selectedBackup) {
                this.toast('請先選擇要還原的備份檔案。', 'warning');
                return;
            }
            if (this.refs.prepareBtn) {
                this.refs.prepareBtn.disabled = true;
            }
            if (this.refs.dryRunBtn) {
                this.refs.dryRunBtn.disabled = true;
            }
            if (this.refs.executeCheckbox) {
                this.refs.executeCheckbox.checked = false;
            }
            if (this.refs.rollbackCheckbox) {
                this.refs.rollbackCheckbox.checked = false;
            }
            this.state.validation = null;
            this.state.dryRun = null;
            this.state.status = null;
            this.state.lastStage = null;
            this.state.lastMessage = null;
            this.setJobStatus('建立還原作業中…');
            this.log('Creating restore job for ' + this.state.selectedBackup + '...', 'info', 'source');
            this.buildRequest('restore/prepare', {
                method: 'POST',
                body: JSON.stringify({
                    source: 'existing',
                    file: this.state.selectedBackup
                })
            }).then(function (data) {
                if (!data || data.ok === false) {
                    throw new Error(data && data.message ? data.message : 'Failed to prepare restore job.');
                }
                _this.state.jobId = data.job_id;
                _this.state.status = { stage: 'prepared', progress: 10, message: 'Restore job prepared.' };
                _this.toast('Restore job prepared.', 'success');
                _this.log('Restore job prepared. Job ID: ' + data.job_id, 'success', 'source');
                _this.updateHeader();
                _this.updateStepIndicator('prepared');
                _this.updateExecutePanel();
                _this.pollStatus(true);
                return _this.buildRequest('restore/validate/' + data.job_id, { method: 'GET' });
            }).then(function (result) {
                if (!result) {
                    return;
                }
                if (result.ok === false) {
                    throw new Error(result.message || 'Validation failed.');
                }
                _this.state.validation = result.result;
                _this.updateSafetyPanel();
                _this.toast('Validation complete.', 'success');
                _this.log('Validation completed successfully.', 'success', 'safety');
                _this.updateStepIndicator();
                _this.updateExecutePanel();
                _this.enableDryRun();
            }).catch(function (error) {
                _this.toast(error.message || 'Unable to prepare restore job.', 'error');
                _this.log('Error: ' + error.message, 'error', 'source');
            }).finally(function () {
                if (_this.refs.prepareBtn) {
                    _this.refs.prepareBtn.disabled = false;
                }
            });
        },
        runDryRun: function () {
            var _this2 = this;
            if (!this.state.jobId) {
                this.toast('請先建立還原作業。', 'warning');
                return;
            }
            if (this.refs.dryRunBtn) {
                this.refs.dryRunBtn.disabled = true;
            }
            this.log('Dry-run started…', 'info', 'dryrun');
            this.updateStepIndicator('dry-run');
            this.pollStatus();
            this.buildRequest('restore/dry-run/' + this.state.jobId, {
                method: 'POST',
                body: JSON.stringify({})
            }).then(function (data) {
                if (!data || data.ok === false) {
                    throw new Error(data && data.message ? data.message : 'Dry-run failed.');
                }
                _this2.state.dryRun = data.summary || {};
                _this2.updateDryRunPanel();
                _this2.toast('Dry-run completed.', 'success');
                _this2.log('Dry-run completed successfully.', 'success', 'dryrun');
                if (_this2.refs.dryRunDownloads) {
                    _this2.refs.dryRunDownloads.hidden = false;
                }
                if (_this2.refs.dryRunReportTxt) {
                    _this2.refs.dryRunReportTxt.href = data.report_url_txt;
                }
                if (_this2.refs.dryRunReportJson) {
                    _this2.refs.dryRunReportJson.href = data.report_url_json;
                }
                _this2.updateExecutePanel();
                _this2.updateStepIndicator();
            }).catch(function (error) {
                _this2.toast(error.message || 'Unable to run dry-run.', 'error');
                _this2.log('Dry-run error: ' + error.message, 'error', 'dryrun');
            }).finally(function () {
                if (_this2.refs.dryRunBtn) {
                    _this2.refs.dryRunBtn.disabled = false;
                }
            });
        },
        runExecute: function () {
            var _this3 = this;
            if (!this.state.jobId) {
                this.toast('請先建立還原作業。', 'warning');
                return;
            }
            if (!this.refs.executeCheckbox.checked) {
                this.toast('請勾選確認，表示已了解還原將覆蓋現有站台。', 'warning');
                return;
            }
            if (this.refs.executeBtn) {
                this.refs.executeBtn.disabled = true;
            }
            this.log('Restore execution started…', 'info', 'restore');
            this.updateStepIndicator('restore');
            this.setJobStatus('執行還原中…');
            this.pollStatus();
            this.buildRequest('restore/execute/' + this.state.jobId, {
                method: 'POST',
                body: JSON.stringify({ autoBackup: true })
            }).then(function (data) {
                if (!data || data.ok === false) {
                    throw new Error(data && data.message ? data.message : 'Restore failed.');
                }
                _this3.toast(data.message || 'Restore completed successfully.', 'success');
                _this3.log(data.message || 'Restore completed successfully.', 'success', 'restore');
                _this3.state.status = Object.assign({}, _this3.state.status || {}, { rollback_available: !!data.rollback_available, completed: true, stage: 'done', message: data.message || 'Restore completed successfully.' });
                if (_this3.refs.executeCheckbox) {
                    _this3.refs.executeCheckbox.checked = false;
                }
                _this3.updateExecutePanel();
                _this3.updateRollbackPanel();
                _this3.updateStepIndicator('activity');
                _this3.setJobStatus(data.message || 'Restore completed successfully.');
            }).catch(function (error) {
                _this3.toast(error.message || 'Unable to execute restore.', 'error');
                _this3.log('Restore error: ' + error.message, 'error', 'restore');
            }).finally(function () {
                if (_this3.refs.executeBtn) {
                    _this3.refs.executeBtn.disabled = false;
                }
            });
        },
        runRollback: function () {
            var _this4 = this;
            if (!this.state.jobId) {
                this.toast('目前沒有可用的還原作業。', 'warning');
                return;
            }
            if (!this.refs.rollbackCheckbox.checked) {
                this.toast('請勾選確認後再執行 Rollback。', 'warning');
                return;
            }
            if (this.refs.rollbackBtn) {
                this.refs.rollbackBtn.disabled = true;
            }
            this.log('Rollback started…', 'info', 'rollback');
            this.updateStepIndicator('rollback');
            this.pollStatus();
            this.buildRequest('restore/rollback/' + this.state.jobId, {
                method: 'POST',
                body: JSON.stringify({})
            }).then(function (data) {
                if (!data || data.ok === false) {
                    throw new Error(data && data.message ? data.message : 'Rollback failed.');
                }
                _this4.toast(data.message || 'Rollback completed successfully.', 'success');
                _this4.log(data.message || 'Rollback completed successfully.', 'success', 'rollback');
                _this4.state.status = Object.assign({}, _this4.state.status || {}, { stage: 'rollback-done', message: data.message || 'Rollback completed successfully.' });
                if (_this4.refs.rollbackCheckbox) {
                    _this4.refs.rollbackCheckbox.checked = false;
                }
                _this4.updateRollbackPanel();
                _this4.updateStepIndicator('activity');
                _this4.setJobStatus(data.message || 'Rollback completed successfully.');
            }).catch(function (error) {
                _this4.toast(error.message || 'Unable to rollback.', 'error');
                _this4.log('Rollback error: ' + error.message, 'error', 'rollback');
            }).finally(function () {
                if (_this4.refs.rollbackBtn) {
                    _this4.refs.rollbackBtn.disabled = false;
                }
            });
        },
        pollStatus: function (force) {
            var _this5 = this;
            if (!this.state.jobId) {
                return;
            }
            if (force) {
                this.stopPolling();
            }
            if (this.state.polling) {
                return;
            }
            this.state.polling = window.setInterval(function () {
                _this5.buildRequest('restore/status/' + _this5.state.jobId, { method: 'GET' }).then(function (data) {
                    if (!data || data.ok === false) {
                        _this5.stopPolling();
                        if (data && data.message) {
                            _this5.log('Status error: ' + data.message, 'error');
                        }
                        return;
                    }
                    _this5.state.status = data;
                    if (data.stage && data.stage !== _this5.state.lastStage) {
                        _this5.log('進入階段：' + _this5.formatStageLabel(data.stage), 'info', _this5.detectStepFromStage(data.stage));
                        _this5.state.lastStage = data.stage;
                    }
                    if (data.message && data.message !== _this5.state.lastMessage) {
                        _this5.log(data.message, 'info', _this5.detectStepFromStage(data.stage));
                        _this5.state.lastMessage = data.message;
                    }
                    _this5.updateProgress(data);
                    _this5.updateStepIndicator();
                    _this5.updateExecutePanel();
                    _this5.updateRollbackPanel();
                    _this5.updateHeader();
                    if (data.completed) {
                        _this5.stopPolling();
                    }
                }).catch(function () {
                    _this5.stopPolling();
                });
            }, 2000);
        },
        stopPolling: function () {
            if (this.state.polling) {
                window.clearInterval(this.state.polling);
                this.state.polling = null;
            }
        },
        updateProgress: function (status) {
            if (!this.refs.progressBar || !this.refs.progressText) {
                return;
            }
            var progress = status.progress || 0;
            this.refs.progressBar.style.width = Math.min(100, Math.max(0, progress)) + '%';
            this.refs.progressText.textContent = status.message || this.formatStageLabel(status.stage || '') || '等待流程開始…';
        },
        updateHeader: function () {
            if (!this.refs.jobStatus) {
                return;
            }
            if (!this.state.jobId) {
                this.refs.jobStatus.textContent = '尚未開始還原作業';
                if (this.refs.jobId) {
                    this.refs.jobId.textContent = '';
                }
                return;
            }
            var stage = this.state.status && this.state.status.stage ? this.formatStageLabel(this.state.status.stage) : '待命中';
            this.refs.jobStatus.textContent = stage;
            if (this.refs.jobId) {
                this.refs.jobId.textContent = 'Job ID: ' + this.state.jobId;
            }
        },
        updateStepIndicator: function (overrideStage) {
            var stage = overrideStage || (this.state.status && this.state.status.stage) || 'idle';
            var steps = [
                { key: 'source', element: this.stepElement(1) },
                { key: 'safety', element: this.stepElement(2) },
                { key: 'dryrun', element: this.stepElement(3) },
                { key: 'restore', element: this.stepElement(4) },
                { key: 'rollback', element: this.stepElement(5) },
                { key: 'activity', element: this.stepElement(6) }
            ];
            var stageIndex = this.stageIndex(stage);
            steps.forEach(function (step, idx) {
                if (!step.element) {
                    return;
                }
                step.element.classList.remove('is-active', 'is-completed', 'is-disabled');
                if (idx < stageIndex) {
                    step.element.classList.add('is-completed');
                } else if (idx === stageIndex) {
                    step.element.classList.add('is-active');
                } else {
                    step.element.classList.add('is-disabled');
                }
            });
        },
        stageIndex: function (stage) {
            var order = ['idle', 'prepared', 'validated', 'dry-run', 'restore-files', 'restore-db', 'restore-files-final', 'search-replace', 'cleanup', 'done', 'rollback', 'rollback-done'];
            var index = order.indexOf(stage);
            if (index === -1) {
                index = 0;
            }
            if (stage === 'done') {
                return 3;
            }
            if (stage === 'rollback-done') {
                return 5;
            }
            if (stage.indexOf('rollback') !== -1) {
                return 4;
            }
            if (stage.indexOf('restore') !== -1 || stage === 'cleanup') {
                return 3;
            }
            if (stage === 'dry-run') {
                return 2;
            }
            if (stage === 'validated') {
                return 1;
            }
            if (stage === 'prepared') {
                return 0;
            }
            return 0;
        },
        detectStepFromStage: function (stage) {
            if (!stage) {
                return 'source';
            }
            if (stage.indexOf('rollback') !== -1) {
                return 'rollback';
            }
            if (stage.indexOf('restore') !== -1 || stage === 'cleanup') {
                return 'restore';
            }
            if (stage === 'dry-run') {
                return 'dryrun';
            }
            if (stage === 'validated') {
                return 'safety';
            }
            if (stage === 'prepared') {
                return 'source';
            }
            return 'activity';
        },
        stepElement: function (index) {
            if (!this.refs.stepIndicator) {
                return null;
            }
            return this.refs.stepIndicator.querySelector('[data-step="' + index + '"]');
        },
        updateSafetyPanel: function () {
            if (!this.refs.validationList) {
                return;
            }
            this.refs.validationList.innerHTML = '';
            if (!this.state.validation) {
                this.refs.validationList.innerHTML = '<div class="bl-empty-state">尚未開始驗證。請先完成 Step 1。</div>';
                if (this.refs.validationBadge) {
                    this.refs.validationBadge.innerHTML = '<span class="bl-badge bl-badge-warning">PENDING</span>';
                }
                return;
            }

            var result = this.state.validation;
            var compat = result.compat || {};
            var domain = result.domain || { current: '', backup: '', migrateMode: false };
            compat.wp = compat.wp || { current: '', backup: '', ok: true };
            compat.php = compat.php || { current: '', backup: '', ok: true };
            compat.db = compat.db || { current: '', backup: '', ok: true };

            var list = document.createElement('div');
            list.className = 'bl-restore-validation';

            var self = this;
            function addItem(label, current, backup, status) {
                var item = document.createElement('div');
                item.className = 'bl-restore-validation-item ' + status;
                var valueHTML = '<div class="bl-restore-validation-values"><span>' + self.escape(current || '—') + '</span> → <span>' + self.escape(backup || '—') + '</span></div>';
                item.innerHTML = '<strong>' + label + '</strong>' + valueHTML + '<span class="bl-badge ' + self.validationBadgeClass(status) + '">' + self.validationBadgeLabel(status) + '</span>';
                list.appendChild(item);
            }

            addItem('WordPress 版本', compat.wp.current, compat.wp.backup, compat.wp.ok ? 'success' : 'warning');
            addItem('PHP 版本', compat.php.current, compat.php.backup, compat.php.ok ? 'success' : 'warning');
            addItem('Database 版本', compat.db.current, compat.db.backup, compat.db.ok ? 'success' : 'warning');

            var domainStatus = domain.migrateMode ? 'warning' : 'success';
            addItem('Site URL / Domain', domain.current, domain.backup, domainStatus);

            var dbScan = result.dbScan || { add: 0, update: 0, conflict: 0 };
            var dbItem = document.createElement('div');
            dbItem.className = 'bl-restore-validation-item info';
            dbItem.innerHTML = '<strong>Database Tables</strong><div class="bl-restore-validation-values">新增 ' + this.escape(dbScan.add) + '、覆蓋 ' + this.escape(dbScan.update) + '、可能衝突 ' + this.escape(dbScan.conflict) + '</div><span class="bl-badge bl-badge-info">INFO</span>';
            list.appendChild(dbItem);

            var fileSummary = this.state.dryRun && this.state.dryRun.files ? this.state.dryRun.files : (result.files || null);
            if (fileSummary) {
                var filesItem = document.createElement('div');
                filesItem.className = 'bl-restore-validation-item info';
                var label = 'Files / wp-content';
                var text = '新增 ' + this.escape(fileSummary.create || 0) + '、更新 ' + this.escape(fileSummary.update || 0) + '、刪除 ' + this.escape(fileSummary.delete || 0);
                if (fileSummary.size) {
                    text += ' · ' + this.escape(fileSummary.size);
                }
                filesItem.innerHTML = '<strong>' + label + '</strong><div class="bl-restore-validation-values">' + text + '</div><span class="bl-badge bl-badge-info">INFO</span>';
                list.appendChild(filesItem);
            }

            this.refs.validationList.appendChild(list);
            if (this.refs.validationBadge) {
                this.refs.validationBadge.innerHTML = '<span class="bl-badge bl-badge-success">READY</span>';
            }
        },
        validationBadgeClass: function (status) {
            if (status === 'warning') {
                return 'bl-badge-warning';
            }
            if (status === 'danger') {
                return 'bl-badge-danger';
            }
            return 'bl-badge-success';
        },
        validationBadgeLabel: function (status) {
            if (status === 'warning') {
                return 'WARNING';
            }
            if (status === 'danger') {
                return 'ERROR';
            }
            return 'OK';
        },
        updateDryRunPanel: function () {
            if (!this.refs.dryRunSummary) {
                return;
            }
            if (!this.state.dryRun) {
                this.refs.dryRunSummary.innerHTML = '<div class="bl-empty-state">尚未執行 Dry-Run。完成驗證後即可啟動模擬。</div>';
                return;
            }

            var summary = this.state.dryRun;
            var container = document.createElement('div');
            container.className = 'bl-restore-summary-grid';

            function createCard(title, content) {
                var card = document.createElement('div');
                card.className = 'bl-restore-summary-card';
                card.innerHTML = '<strong>' + title + '</strong><span>' + content + '</span>';
                return card;
            }

            var files = summary.files || { create: 0, update: 0, delete: 0, size: '' };
            container.appendChild(createCard('檔案變動', '新增 ' + this.escape(files.create) + '、更新 ' + this.escape(files.update) + '、刪除 ' + this.escape(files.delete) + (files.size ? ' · ' + this.escape(files.size) : '')));

            var tables = summary.tables || { add: 0, update: 0, drop: 0 };
            container.appendChild(createCard('資料表變動', '新增 ' + this.escape(tables.add) + '、更新 ' + this.escape(tables.update) + '、刪除 ' + this.escape(tables.drop)));

            var warnings = summary.warnings && summary.warnings.length ? summary.warnings.join('；') : '無額外警告';
            container.appendChild(createCard('警告 / 注意事項', warnings));

            this.refs.dryRunSummary.innerHTML = '';
            this.refs.dryRunSummary.appendChild(container);
        },
        enableDryRun: function () {
            if (this.refs.dryRunBtn) {
                this.refs.dryRunBtn.disabled = false;
            }
        },
        updateExecutePanel: function () {
            var ready = !!(this.state.validation && (this.state.dryRun || this.state.status && (this.state.status.stage === 'dry-run' || this.state.status.stage === 'done')));
            if (this.refs.executeBtn) {
                var checked = this.refs.executeCheckbox ? this.refs.executeCheckbox.checked : false;
                this.refs.executeBtn.disabled = !ready || !checked;
            }
            if (this.refs.executeStatus) {
                this.refs.executeStatus.innerHTML = ready ? '<span class="bl-badge bl-badge-success">READY</span>' : '<span class="bl-badge bl-badge-warning">PENDING</span>';
            }
        },
        updateRollbackPanel: function () {
            if (!this.refs.rollbackCard) {
                return;
            }
            var available = !!(this.state.status && this.state.status.rollback_available);
            this.refs.rollbackCard.dataset.state = available ? 'ready' : 'disabled';
            if (this.refs.rollbackBtn) {
                var checked = this.refs.rollbackCheckbox ? this.refs.rollbackCheckbox.checked : false;
                this.refs.rollbackBtn.disabled = !available || !checked;
            }
            if (available && this.refs.rollbackMeta) {
                this.refs.rollbackMeta.innerHTML = '<strong>Restore-Pre-Backup Snapshot</strong><span>已在正式還原前建立快照，可立即回復。</span>';
            } else if (this.refs.rollbackMeta) {
                this.refs.rollbackMeta.innerHTML = '<div class="bl-empty-state">目前沒有可用的預先快照。完成正式還原後才會建立。</div>';
            }
            if (this.refs.rollbackStatus) {
                this.refs.rollbackStatus.innerHTML = available ? '<span class="bl-badge bl-badge-success">AVAILABLE</span>' : '<span class="bl-badge bl-badge-warning">UNAVAILABLE</span>';
            }
        },
        setJobStatus: function (text) {
            if (this.refs.jobStatus) {
                this.refs.jobStatus.textContent = text;
            }
        },
        log: function (message, type, stage) {
            if (!message || !this.refs.progressLog) {
                return;
            }
            var entry = document.createElement('div');
            entry.className = 'bl-log-entry' + (type ? ' is-' + type : '');
            var time = document.createElement('span');
            time.className = 'bl-log-entry__time';
            time.textContent = new Date().toLocaleTimeString();
            var msg = document.createElement('span');
            msg.className = 'bl-log-entry__message';
            msg.textContent = message;
            entry.appendChild(time);
            entry.appendChild(msg);
            this.refs.progressLog.appendChild(entry);
            if (this.state.autoScroll && window.BackupLiteUI && typeof window.BackupLiteUI.autoScroll === 'function') {
                window.BackupLiteUI.autoScroll('#bl-restore-log');
            } else if (this.state.autoScroll) {
                this.refs.progressLog.scrollTop = this.refs.progressLog.scrollHeight;
            }
        },
        setAutoScroll: function (enabled) {
            this.state.autoScroll = !!enabled;
            if (this.refs.autoScrollToggle) {
                this.refs.autoScrollToggle.checked = this.state.autoScroll;
            }
        },
        toast: function (message, type) {
            if (!toast) {
                return;
            }
            var color = '#2563eb';
            if (type === 'success') {
                color = '#10b981';
            } else if (type === 'warning') {
                color = '#f59e0b';
            } else if (type === 'error') {
                color = '#ef4444';
            }
            toast({ text: message, backgroundColor: color, duration: 3500, gravity: 'top', position: 'right' }).showToast();
        },
        escape: function (value) {
            if (value === null || value === undefined) {
                return '';
            }
            return String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        },
        formatStageLabel: function (stage) {
            switch (stage) {
                case 'prepared':
                    return '待驗證';
                case 'validated':
                    return '安全驗證完成';
                case 'dry-run':
                    return 'Dry-Run 完成';
                case 'restore-files':
                case 'restore-db':
                case 'restore-files-final':
                case 'search-replace':
                case 'cleanup':
                    return '還原進行中…';
                case 'done':
                    return '還原完成';
                case 'rollback':
                    return 'Rollback 進行中…';
                case 'rollback-done':
                    return 'Rollback 完成';
                default:
                    return '尚未開始';
            }
        }
    };

    window.BackupLiteRestoreCenter = RestoreCenter;
})();
document.addEventListener('DOMContentLoaded', function () {
    if (window.BackupLiteRestoreLoaded) {
        return;
    }
    window.BackupLiteRestoreLoaded = true;
    if (window.BackupLiteRestoreCenter && typeof window.BackupLiteRestoreCenter.init === 'function') {
        window.BackupLiteRestoreCenter.init();
    }
});
