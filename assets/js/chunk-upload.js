(function () {
    'use strict';

    const ui = window.BackupLiteUI || {};
    const settings = window.MusederRestoreOneAdmin || {};
    const chunkSettings = settings.chunk || {};
    const strings = ui.strings || {};

    const form = document.getElementById('backup-lite-restore-form');
    if (!form) {
        return;
    }

    const fileInput = document.getElementById('backup-lite-restore-file');
    const confirmCheckbox = form.querySelector('input[name="backup_lite_confirm"]');
    const progressWrap = form.querySelector('.backup-lite-progress');
    const progressBar = progressWrap ? progressWrap.querySelector('.backup-lite-progress-bar span') : null;
    const statusEl = progressWrap ? progressWrap.querySelector('.backup-lite-progress-status') : null;
    const speedEl = progressWrap ? progressWrap.querySelector('.backup-lite-progress-speed') : null;
    const etaEl = progressWrap ? progressWrap.querySelector('.backup-lite-progress-eta') : null;
    const sha1El = progressWrap ? progressWrap.querySelector('.backup-lite-progress-sha1') : null;
    const searchToggle = document.getElementById('backup-lite-search-replace-toggle');
    const searchFields = form.querySelector('.backup-lite-search-replace-fields');

    const allowedExt = (chunkSettings.allowedExt || ['zip', 'wpress']).map(function (ext) {
        return ext.toLowerCase();
    });

    let startTime = 0;
    let uploadedBytes = 0;
    let uploadId = '';
    let uploadToken = '';
    let clientSha1Hex = '';
    let processedChunks = new Set();

    const showMessage = ui.showMessage || function () {};
    const handleError = ui.handleError || function () {};
    const refreshLogs = ui.refreshLogs || function () {};
    const spinner = ui.spinner || '';

    if (searchToggle && searchFields) {
        searchToggle.addEventListener('change', function () {
            if (searchToggle.checked) {
                searchFields.removeAttribute('hidden');
            } else {
                searchFields.setAttribute('hidden', 'hidden');
            }
        });
    }

    function updateProgress(percent) {
        if (!progressWrap) {
            return;
        }
        if (!progressWrap.classList.contains('is-visible')) {
            progressWrap.classList.add('is-visible');
        }
        if (progressBar) {
            progressBar.style.width = Math.min(100, Math.max(0, percent)) + '%';
        }
    }

    function updateStatus(text) {
        if (statusEl) {
            statusEl.textContent = text || '';
        }
    }

    function updateSpeedAndEta(totalBytes) {
        if (!speedEl && !etaEl) {
            return;
        }

        const elapsedSeconds = Math.max(1, (Date.now() - startTime) / 1000);
        const speed = uploadedBytes / elapsedSeconds; // bytes per second
        const remaining = Math.max(0, totalBytes - uploadedBytes);
        const eta = speed > 0 ? Math.ceil(remaining / speed) : 0;

        if (speedEl) {
            const mbSpeed = (speed / (1024 * 1024)).toFixed(2);
            speedEl.textContent = strings.speed ? formatString(strings.speed, [mbSpeed]) : '';
        }

        if (etaEl) {
            etaEl.textContent = strings.eta ? formatString(strings.eta, [eta]) : '';
        }
    }

    function updateSha1Display(serverSha1, clientSha1, mismatch) {
        if (!sha1El) {
            return;
        }
        let text = '';
        if (serverSha1) {
            text += strings.serverSha1 ? formatString(strings.serverSha1, [serverSha1]) : 'Server SHA1: ' + serverSha1;
        }
        if (clientSha1) {
            text += (text ? ' | ' : '') + (strings.clientSha1 ? formatString(strings.clientSha1, [clientSha1]) : 'Client SHA1: ' + clientSha1);
        }
        if (mismatch && strings.sha1Mismatch) {
            text += ' — ' + strings.sha1Mismatch;
        }
        sha1El.textContent = text;
    }

    function resetProgress() {
        if (progressWrap) {
            progressWrap.classList.remove('is-visible');
        }
        if (progressBar) {
            progressBar.style.width = '0%';
        }
        if (statusEl) {
            statusEl.textContent = '';
        }
        if (speedEl) {
            speedEl.textContent = '';
        }
        if (etaEl) {
            etaEl.textContent = '';
        }
        if (sha1El) {
            sha1El.textContent = '';
        }
    }

    function formatString(template, values) {
        if (!template) {
            return '';
        }

        const arr = Array.isArray(values) ? values : [values];

        let formatted = template.replace(/%([0-9]+)\$s/g, function (match, index) {
            const i = parseInt(index, 10) - 1;
            return (typeof arr[i] !== 'undefined') ? arr[i] : match;
        });

        if (formatted.indexOf('%s') !== -1 && arr.length) {
            formatted = formatted.replace('%s', arr[0]);
        }

        return formatted;
    }

    function mapStageErrorMessage(error) {
        if (!error || !error.stage) {
            return error;
        }

        if (error.error === 'sha1_mismatch') {
            error.message = strings.errorSha1Mismatch || 'SHA1 不相符，請重新上傳備份檔案。';
        } else if (error.error === 'zip_verification_failed') {
            error.message = strings.errorZipVerificationFailed || '合併後的備份檔案驗證失敗，請重新上傳。';
        } else if (error.error === 'zip_open_failed') {
            error.message = strings.errorZipOpenFailed || '解壓縮備份檔案失敗，請檢查 restore.log 與 debug.log。';
        }

        if (!error.message) {
            error.message = strings.errorGeneric || '發生未知錯誤，請查閱系統紀錄。';
        }

        return error;
    }

    async function prepareUpload(file, totalChunks) {
        const formData = new FormData();
        formData.append('action', chunkSettings.prepareAction || 'backup_lite_prepare_upload');
        formData.append('nonce', settings.nonce);
        formData.append('file_name', file.name);
        formData.append('file_size', file.size);
        formData.append('total_chunks', totalChunks);

        const response = await fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });

        const json = await response.json();
        if (!json || !json.success) {
            throw (json && json.data) ? json.data : { message: strings.prepareFailed || 'Unable to prepare upload.' };
        }

        return json.data || {};
    }

    async function calculateChunkSha1(arrayBuffer) {
        if (window.crypto && window.crypto.subtle) {
            const hashBuffer = await crypto.subtle.digest('SHA-1', arrayBuffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        }
        return '';
    }

    async function sendChunk(uploadId, token, chunkBlob, chunkSha1, index, totalChunks, file) {
        const formData = new FormData();
        formData.append('action', chunkSettings.uploadAction);
        formData.append('nonce', settings.nonce);
        formData.append('upload_id', uploadId);
        formData.append('upload_token', token);
        formData.append('chunk_index', index);
        formData.append('total_chunks', totalChunks);
        formData.append('chunk_size', chunkBlob.size);
        formData.append('chunk_sha1', chunkSha1);
        formData.append('total_size', file.size);
        formData.append('original_name', file.name);
        formData.append('file', chunkBlob, `chunk_${index}.part`);

        const response = await fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: formData
        });

        const json = await response.json();
        if (!json || !json.success) {
            throw (json && json.data) ? json.data : { message: strings.errorGeneric };
        }

        return json.data || {};
    }

    async function uploadChunkWithRetry(uploadId, token, chunkBlob, chunkSha1, index, totalChunks, file, retries) {
        try {
            return await sendChunk(uploadId, token, chunkBlob, chunkSha1, index, totalChunks, file);
        } catch (error) {
            if (retries > 0) {
                if (statusEl && strings.retryNotice) {
                    statusEl.textContent = formatString(strings.retryNotice, [retries]);
                }
                await new Promise(resolve => setTimeout(resolve, Math.pow(2, 3 - retries) * 1000));
                return uploadChunkWithRetry(uploadId, token, chunkBlob, chunkSha1, index, totalChunks, file, retries - 1);
            }
            throw error;
        }
    }

    async function finalizeUpload(uploadId, token, file, clientSha1, searchReplacePayload) {
        const payload = new FormData();
        payload.append('action', chunkSettings.finalizeAction);
        payload.append('nonce', settings.nonce);
        payload.append('upload_id', uploadId);
        payload.append('upload_token', token);
        payload.append('original_name', file.name);
        payload.append('client_sha1', clientSha1);
        if (searchReplacePayload) {
            payload.append('search_replace', searchReplacePayload);
        }

        const response = await fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: payload
        });

        const json = await response.json();
        if (!json || !json.success) {
            throw (json && json.data) ? json.data : { message: strings.errorGeneric };
        }

        return json.data || {};
    }

    async function abortUpload(uploadId, token) {
        if (!uploadId) {
            return;
        }

        const payload = new FormData();
        payload.append('action', chunkSettings.abortAction);
        payload.append('nonce', settings.nonce);
        payload.append('upload_id', uploadId);
        payload.append('upload_token', token);

        try {
            await fetch(settings.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            });
        } catch (e) {
            // ignore cleanup failure
        }
    }

    function getSearchReplacePayload() {
        if (!searchToggle || !searchToggle.checked || !searchFields) {
            return '';
        }

        const searchValue = searchFields.querySelector('input[name="backup_lite_search"]').value;
        const replaceValue = searchFields.querySelector('input[name="backup_lite_replace"]').value;

        if (!searchValue) {
            return '';
        }

        const payload = [ { search: searchValue, replace: replaceValue || '' } ];
        return JSON.stringify(payload);
    }

    function validateExtension(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        return allowedExt.indexOf(ext) !== -1;
    }

    class Sha1 {
        constructor() {
            this.h0 = 0x67452301;
            this.h1 = 0xefcdab89;
            this.h2 = 0x98badcfe;
            this.h3 = 0x10325476;
            this.h4 = 0xc3d2e1f0;
            this.buffer = new Uint8Array(64);
            this.bufferLength = 0;
            this.bytesHashed = 0;
            this.finished = false;
        }

        update(chunk) {
            if (this.finished) {
                throw new Error('SHA1: cannot update after digest');
            }

            const length = chunk.length;
            this.bytesHashed += length;
            for (let i = 0; i < length; i++) {
                this.buffer[this.bufferLength++] = chunk[i];
                if (this.bufferLength === 64) {
                    this.processBuffer();
                    this.bufferLength = 0;
                }
            }
        }

        processBuffer() {
            const words = new Uint32Array(80);
            for (let i = 0; i < 16; i++) {
                words[i] = (this.buffer[i * 4] << 24) | (this.buffer[i * 4 + 1] << 16) | (this.buffer[i * 4 + 2] << 8) | (this.buffer[i * 4 + 3]);
            }
            for (let i = 16; i < 80; i++) {
                const n = words[i - 3] ^ words[i - 8] ^ words[i - 14] ^ words[i - 16];
                words[i] = (n << 1) | (n >>> 31);
            }

            let a = this.h0;
            let b = this.h1;
            let c = this.h2;
            let d = this.h3;
            let e = this.h4;

            for (let i = 0; i < 80; i++) {
                let f, k;
                if (i < 20) {
                    f = (b & c) | (~b & d);
                    k = 0x5a827999;
                } else if (i < 40) {
                    f = b ^ c ^ d;
                    k = 0x6ed9eba1;
                } else if (i < 60) {
                    f = (b & c) | (b & d) | (c & d);
                    k = 0x8f1bbcdc;
                } else {
                    f = b ^ c ^ d;
                    k = 0xca62c1d6;
                }

                const temp = (((a << 5) | (a >>> 27)) + f + e + k + words[i]) >>> 0;
                e = d;
                d = c;
                c = (b << 30) | (b >>> 2);
                b = a;
                a = temp;
            }

            this.h0 = (this.h0 + a) >>> 0;
            this.h1 = (this.h1 + b) >>> 0;
            this.h2 = (this.h2 + c) >>> 0;
            this.h3 = (this.h3 + d) >>> 0;
            this.h4 = (this.h4 + e) >>> 0;
        }

        digest() {
            if (!this.finished) {
                const bytes = this.bytesHashed;
                this.buffer[this.bufferLength++] = 0x80;

                if (this.bufferLength > 56) {
                    while (this.bufferLength < 64) {
                        this.buffer[this.bufferLength++] = 0;
                    }
                    this.processBuffer();
                    this.bufferLength = 0;
                }

                while (this.bufferLength < 56) {
                    this.buffer[this.bufferLength++] = 0;
                }

                const bits = bytes * 8;
                for (let i = 7; i >= 0; i--) {
                    this.buffer[this.bufferLength++] = (bits >>> (i * 8)) & 0xff;
                }

                this.processBuffer();
                this.finished = true;
            }

            const digest = new Uint8Array(20);
            const words = [this.h0, this.h1, this.h2, this.h3, this.h4];
            for (let i = 0; i < words.length; i++) {
                digest[i * 4] = (words[i] >>> 24) & 0xff;
                digest[i * 4 + 1] = (words[i] >>> 16) & 0xff;
                digest[i * 4 + 2] = (words[i] >>> 8) & 0xff;
                digest[i * 4 + 3] = words[i] & 0xff;
            }
            return digest;
        }

        hexDigest() {
            const bytes = this.digest();
            let hex = '';
            for (let i = 0; i < bytes.length; i++) {
                const byteHex = bytes[i].toString(16).padStart(2, '0');
                hex += byteHex;
            }
            return hex;
        }
    }

    async function processChunks(file, totalChunks, chunkSizeServer) {
        const chunkSize = chunkSizeServer || chunkSettings.chunkSize || (5 * 1024 * 1024);
        const searchReplacePayload = getSearchReplacePayload();
        const sha1 = new Sha1();
        processedChunks = new Set();

        uploadedBytes = 0;

        for (let index = 0; index < totalChunks; index++) {
            const start = index * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunkBlob = file.slice(start, end);

            const buffer = await chunkBlob.arrayBuffer();
            sha1.update(new Uint8Array(buffer));
            
            const chunkSha1 = await calculateChunkSha1(buffer);
            
            processedChunks.add(index);

            const response = await uploadChunkWithRetry(uploadId, uploadToken, chunkBlob, chunkSha1, index, totalChunks, file, 3);
            uploadedBytes = response.uploaded_bytes || uploadedBytes;
            const percent = response.progress || ((processedChunks.size / totalChunks) * 100);
            updateProgress(percent);
            updateSpeedAndEta(file.size);
        }

        clientSha1Hex = sha1.hexDigest();

        while (true) {
            try {
                updateStatus(strings.merging || '');
                const finalizeData = await finalizeUpload(uploadId, uploadToken, file, clientSha1Hex, searchReplacePayload);
                updateStatus(strings.successRestore || '');
                updateSha1Display(finalizeData.sha1 || '', finalizeData.client_sha1 || clientSha1Hex || '', finalizeData.sha1_mismatch);

                if (finalizeData.message) {
                    showMessage('success', strings.successTitle || '', finalizeData.message);
                } else {
                    showMessage('success', strings.successTitle || '', strings.successRestore || '');
                }

                return;
            } catch (error) {
                if (error && error.code === 'missing_chunks' && Array.isArray(error.missing_chunks)) {
                    if (statusEl && strings.missingChunks) {
                        statusEl.textContent = formatString(strings.missingChunks, [error.missing_chunks.join(', ')]);
                    }
                    if (strings.resumeUpload) {
                        updateStatus(strings.resumeUpload);
                    }
                    await reuploadMissingChunks(error.missing_chunks, file, chunkSize);
                    continue;
                }
                if (error && error.stage) {
                    mapStageErrorMessage(error);
                }
                throw error;
            }
        }
    }

    async function reuploadMissingChunks(missing, file, chunkSize) {
        const totalChunks = Math.ceil(file.size / chunkSize);
        for (let i = 0; i < missing.length; i++) {
            const index = missing[i];
            const start = index * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const chunkBlob = file.slice(start, end);
            
            const buffer = await chunkBlob.arrayBuffer();
            const chunkSha1 = await calculateChunkSha1(buffer);
            
            const response = await uploadChunkWithRetry(uploadId, uploadToken, chunkBlob, chunkSha1, index, totalChunks, file, 3);
            uploadedBytes = response.uploaded_bytes || uploadedBytes;
            updateProgress(response.progress || ((processedChunks.size / totalChunks) * 100));
            updateSpeedAndEta(file.size);
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const file = fileInput && fileInput.files.length ? fileInput.files[0] : null;
        if (!file) {
            showMessage('error', strings.errorTitle || '', strings.noFileSelected || '');
            return;
        }

        if (!validateExtension(file)) {
            const allowedList = allowedExt.join(', ');
            const message = strings.invalidExtension ? formatString(strings.invalidExtension, [allowedList]) : 'Unsupported file extension.';
            showMessage('error', strings.errorTitle || '', message);
            return;
        }

        if (confirmCheckbox && !confirmCheckbox.checked) {
            showMessage('error', strings.errorTitle || '', strings.noConfirm || '');
            return;
        }

        if (file.size > (chunkSettings.maxFileSize || (4 * 1024 * 1024 * 1024))) {
            const limitHuman = ((chunkSettings.maxFileSize || (4 * 1024 * 1024 * 1024)) / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
            const sizeMsg = strings.fileTooLarge ? formatString(strings.fileTooLarge, [limitHuman]) : 'File exceeds maximum allowed size.';
            showMessage('error', strings.errorTitle || '', sizeMsg);
            return;
        }

        if (settings.confirmRestore && !window.confirm(settings.confirmRestore)) {
            return;
        }

        const totalChunks = Math.ceil(file.size / (chunkSettings.chunkSize || (5 * 1024 * 1024)));

        startTime = Date.now();
        uploadedBytes = 0;
        clientSha1Hex = '';
        uploadId = '';
        uploadToken = '';
        resetProgress();
        updateProgress(0);
        updateStatus(strings.uploadStarting || '');
        updateSpeedAndEta(file.size);

        showMessage('', strings.runningTitle || '', (strings.runningMessage || '') + spinner);

        try {
            const session = await prepareUpload(file, totalChunks);
            uploadId = session.upload_id;
            uploadToken = session.upload_token;

            if (!uploadId || !uploadToken) {
                throw { message: strings.prepareFailed || 'Unable to prepare upload session.' };
            }

            const chunkSizeServer = session.chunk_size || chunkSettings.chunkSize;
            await processChunks(file, totalChunks, chunkSizeServer);

            if (typeof refreshLogs === 'function') {
                refreshLogs();
            }

            form.reset();
        } catch (error) {
            if (error && error.stage) {
                mapStageErrorMessage(error);
            }
            const message = error && error.message ? error.message : (strings.errorGeneric || '');
            showMessage('error', strings.errorTitle || '', message);
            updateSha1Display('', clientSha1Hex || '', false);
            await abortUpload(uploadId, uploadToken);
        } finally {
            resetProgress();
        }
    });
})();

