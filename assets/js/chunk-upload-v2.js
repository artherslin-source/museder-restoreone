(function () {
    'use strict';

    const ui = window.BackupLiteUI || {};
    const restConfig = window.BackupLiteV2 || {};
    const base = (restConfig.restUrl || '').replace(/\/?$/, '/');
    const restNonce = restConfig.nonce || '';

    if (!base) {
        console.error('Backup Lite V2: REST base URL missing.');
        return;
    }

    const form = document.getElementById('backup-lite-restore-form-v2');
    if (!form) {
        return;
    }

    const fileInput = document.getElementById('backup-lite-restore-file-v2');
    const confirmCheckbox = form.querySelector('input[name="backup_lite_confirm"]');
    const progressWrap = form.querySelector('.backup-lite-progress');
    const progressBar = progressWrap ? progressWrap.querySelector('.progress-bar-fill') : null;
    const statusEl = progressWrap ? progressWrap.querySelector('.backup-lite-progress-status') : null;
    const speedEl = progressWrap ? progressWrap.querySelector('.backup-lite-progress-speed') : null;
    const etaEl = progressWrap ? progressWrap.querySelector('.backup-lite-progress-eta') : null;
    const sha1El = progressWrap ? progressWrap.querySelector('.backup-lite-progress-sha1') : null;
    const searchToggle = document.getElementById('backup-lite-search-replace-toggle');
    const searchFields = form.querySelector('.backup-lite-search-replace-fields');
    const allowedExt = ((ui.allowedExt || (window.BackupLite && window.BackupLite.chunk && window.BackupLite.chunk.allowedExt)) || ['zip', 'wpress']).map((ext) => ext.toLowerCase());

    const showMessage = typeof ui.showMessage === 'function' ? ui.showMessage : function () {};
    const handleError = typeof ui.handleError === 'function' ? ui.handleError : function () {};
    const refreshLogs = typeof ui.refreshLogs === 'function' ? ui.refreshLogs : function () {};

    const CHUNK_SIZE = (window.BackupLite && window.BackupLite.chunk && window.BackupLite.chunk.chunkSize) || (2 * 1024 * 1024);
    const MAX_RETRIES = 5;

    let startTime = 0;
    let uploadedBytes = 0;
    let fileSha1Hex = '';
    let uploadId = '';
    let processedChunks = new Set();
    let useMultipartFallback = false;

    if (searchToggle && searchFields) {
        searchToggle.addEventListener('change', function () {
            if (searchToggle.checked) {
                searchFields.removeAttribute('hidden');
            } else {
                searchFields.setAttribute('hidden', 'hidden');
            }
        });
    }

    function buildHeaders(extra = {}, options = {}) {
        const headers = {};
        if (!options.omitNonce && restNonce) {
            headers['X-WP-Nonce'] = restNonce;
        }
        if (!options.omitContentType) {
            headers['Content-Type'] = options.contentType || 'application/octet-stream';
        }
        Object.keys(extra).forEach((key) => {
            if (extra[key] !== undefined && extra[key] !== null && extra[key] !== '') {
                headers[key] = extra[key];
            }
        });
        return headers;
    }

    async function restRequest(endpoint, options = {}) {
        const response = await fetch(base + endpoint.replace(/^\//, ''), Object.assign({ credentials: 'same-origin' }, options));
        const text = await response.text();
        let json = {};

        if (text) {
            try {
                json = JSON.parse(text);
            } catch (err) {
                console.error('REST parse failed', text);
                const parseError = new Error('REST_PARSE_ERROR');
                parseError.status = response.status;
                parseError.responseText = text;
                throw parseError;
            }
        }

        if (!response.ok || (json && json.ok === false) || (json && json.success === false)) {
            const error = new Error(json && json.message ? json.message : 'REST request failed');
            error.status = response.status;
            error.code = json && json.code ? json.code : 'rest_error';
            error.payload = json;
            throw error;
        }

        return json;
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
        const speed = uploadedBytes / elapsedSeconds;
        const remaining = Math.max(0, totalBytes - uploadedBytes);
        const eta = speed > 0 ? Math.ceil(remaining / speed) : 0;

        if (speedEl) {
            const mbSpeed = (speed / (1024 * 1024)).toFixed(2);
            speedEl.textContent = ui.strings && ui.strings.speed ? formatString(ui.strings.speed, [mbSpeed]) : '';
        }

        if (etaEl) {
            etaEl.textContent = ui.strings && ui.strings.eta ? formatString(ui.strings.eta, [eta]) : '';
        }
    }

    function updateSha1Display(serverSha1, clientSha1, mismatch) {
        if (!sha1El) {
            return;
        }
        let text = '';
        if (serverSha1) {
            text += ui.strings && ui.strings.serverSha1 ? formatString(ui.strings.serverSha1, [serverSha1]) : 'Server SHA1: ' + serverSha1;
        }
        if (clientSha1) {
            text += (text ? ' | ' : '') + (ui.strings && ui.strings.clientSha1 ? formatString(ui.strings.clientSha1, [clientSha1]) : 'Client SHA1: ' + clientSha1);
        }
        if (mismatch && ui.strings && ui.strings.sha1Mismatch) {
            text += ' — ' + ui.strings.sha1Mismatch;
        }
        sha1El.textContent = text;
    }

    function resetProgress() {
        if (progressWrap) progressWrap.classList.remove('is-visible');
        if (progressBar) progressBar.style.width = '0%';
        if (statusEl) statusEl.textContent = '';
        if (speedEl) speedEl.textContent = '';
        if (etaEl) etaEl.textContent = '';
        if (sha1El) sha1El.textContent = '';
    }

    function formatString(template, values) {
        if (!template) {
            return '';
        }
        const arr = Array.isArray(values) ? values : [values];
        let formatted = template.replace(/%([0-9]+)\$s/g, function (match, index) {
            const i = parseInt(index, 10) - 1;
            return typeof arr[i] !== 'undefined' ? arr[i] : match;
        });
        if (formatted.indexOf('%s') !== -1 && arr.length) {
            formatted = formatted.replace('%s', arr[0]);
        }
        return formatted;
    }

    function validateExtension(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        return allowedExt.indexOf(ext) !== -1;
    }

    async function calculateSha1(buffer) {
        if (crypto && crypto.subtle) {
            const hashBuffer = await crypto.subtle.digest('SHA-1', buffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            return hashArray.map((b) => b.toString(16).padStart(2, '0')).join('');
        }
        const sha1 = new Sha1();
        sha1.update(new Uint8Array(buffer));
        return sha1.hexDigest();
    }

    async function prepareUpload(file, totalChunks) {
        const payload = {
            filename: file.name,
            filesize: file.size,
            file_sha1: fileSha1Hex,
            chunk_size: CHUNK_SIZE,
            total_chunks: totalChunks
        };

        const headers = buildHeaders({
            'X-Backup-Lite-Action': 'prepare'
        }, { contentType: 'application/json' });

        const response = await restRequest('prepare', {
            method: 'POST',
            headers,
            body: JSON.stringify(payload)
        });

        return response && response.data ? response.data : response;
    }

    function createChunkFormData(buffer, chunkSha1, index, totalChunks, fileSize, fileName) {
        const formData = new FormData();
        formData.append('upload_id', uploadId);
        formData.append('chunk_index', String(index));
        formData.append('chunk_size', String(buffer.byteLength));
        formData.append('chunk_sha1', chunkSha1);
        formData.append('total_chunks', String(totalChunks));
        formData.append('file_size', String(fileSize));
        formData.append('file_sha1', fileSha1Hex);
        formData.append('filename', fileName);
        formData.append('chunk', new Blob([buffer]), `chunk-${index}.bin`);
        return formData;
    }

    async function sendChunkBinary(buffer, chunkSha1, index, totalChunks, fileSize, fileName) {
        const headers = buildHeaders({
            'X-Backup-Lite-Action': 'chunk',
            'X-Backup-Lite-Upload-Id': uploadId,
            'X-Chunk-Index': String(index),
            'X-Chunk-Total': String(totalChunks),
            'X-Chunk-Size': String(buffer.byteLength),
            'X-Chunk-Sha1': chunkSha1,
            'X-File-Size': String(fileSize),
            'X-File-Sha1': fileSha1Hex,
            'X-File-Name': encodeURIComponent(fileName)
        });

        const params = new URLSearchParams({
            upload_id: uploadId,
            chunk_index: String(index),
            chunk_size: String(buffer.byteLength),
            chunk_sha1: chunkSha1,
            total_chunks: String(totalChunks),
            file_size: String(fileSize),
            file_sha1: fileSha1Hex,
            filename: fileName
        });

        const response = await restRequest(`chunk?${params.toString()}`, {
            method: 'POST',
            headers,
            body: buffer
        });

        return response && response.data ? response.data : response;
    }

    async function sendChunkMultipart(buffer, chunkSha1, index, totalChunks, fileSize, fileName) {
        const formData = createChunkFormData(buffer, chunkSha1, index, totalChunks, fileSize, fileName);
        const headers = buildHeaders({}, { omitContentType: true });

        const response = await restRequest('chunk', {
            method: 'POST',
            headers,
            body: formData
        });

        return response && response.data ? response.data : response;
    }

    function shouldSwitchToMultipart(error) {
        if (!error) {
            return false;
        }
        const status = typeof error.status === 'number' ? error.status : 0;
        return status === 403 || status === 415;
    }

    async function sendChunk(buffer, chunkSha1, index, totalChunks, file) {
        if (useMultipartFallback) {
            return sendChunkMultipart(buffer, chunkSha1, index, totalChunks, file.size, file.name);
        }

        try {
            return await sendChunkBinary(buffer, chunkSha1, index, totalChunks, file.size, file.name);
        } catch (error) {
            if (shouldSwitchToMultipart(error)) {
                useMultipartFallback = true;
                return sendChunkMultipart(buffer, chunkSha1, index, totalChunks, file.size, file.name);
            }
            throw error;
        }
    }

    async function uploadChunkWithRetry(buffer, chunkSha1, index, totalChunks, file) {
        const start = index * CHUNK_SIZE;
        const end = Math.min(file.size, start + CHUNK_SIZE);
        console.log(`[Chunk ${index}] uploading bytes ${start}-${end} (${buffer.byteLength} bytes) via ${useMultipartFallback ? 'REST multipart' : 'REST binary'}`);

        for (let attempt = 0; attempt <= MAX_RETRIES; attempt++) {
            try {
                return await sendChunk(buffer, chunkSha1, index, totalChunks, file);
            } catch (error) {
                if (attempt === MAX_RETRIES) {
                    throw error;
                }
                if (statusEl && ui.strings && ui.strings.retryNotice) {
                    statusEl.textContent = formatString(ui.strings.retryNotice, [MAX_RETRIES - attempt]);
                }
                await delay(Math.pow(2, attempt) * 500);
            }
        }
        throw new Error(`Chunk ${index} upload failed.`);
    }

    async function finalizeUpload({ uploadId, fileSha1Hex }) {
        const response = await fetch(base + 'finalize', {
            method: 'POST',
            headers: buildHeaders({
                'X-Backup-Lite-Upload-Id': uploadId,
                'X-File-Sha1': fileSha1Hex,
            }),
            body: null,
            credentials: 'same-origin'
        });

        const text = await response.text();
        let json;
        try {
            json = text ? JSON.parse(text) : {};
        } catch (error) {
            console.error('REST parse failed', text);
            const parseError = new Error('REST_PARSE_ERROR');
            parseError.status = response.status;
            parseError.responseText = text;
            throw parseError;
        }

        if (!response.ok || (json && json.ok === false) || (json && json.success === false)) {
            const err = new Error(json && json.message ? json.message : 'REST finalize failed');
            err.status = response.status;
            err.code = json && json.code ? json.code : 'rest_error';
            err.payload = json;
            throw err;
        }

        return json;
    }

    async function abortUpload() {
        if (!uploadId) {
            return;
        }

        try {
            await restRequest('abort', {
                method: 'POST',
                headers: buildHeaders({ 'Content-Type': 'application/x-www-form-urlencoded' }, { contentType: 'application/x-www-form-urlencoded' }),
                body: new URLSearchParams({ upload_id: uploadId })
            });
        } catch (error) {
            // silent
        }
    }

    function delay(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
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
        return JSON.stringify([{ search: searchValue, replace: replaceValue || '' }]);
    }

    async function processChunks(file) {
        const searchReplacePayload = getSearchReplacePayload();
        processedChunks = new Set();
        uploadedBytes = 0;
        useMultipartFallback = false;

        updateStatus(ui.strings && ui.strings.calculating ? ui.strings.calculating : 'Calculating file SHA1...');
        const fileBuffer = await file.arrayBuffer();
        fileSha1Hex = await calculateSha1(fileBuffer);
        const totalChunks = Math.ceil(fileBuffer.byteLength / CHUNK_SIZE);

        updateStatus(ui.strings && ui.strings.preparing ? ui.strings.preparing : 'Preparing upload...');
        const prepareData = await prepareUpload(file, totalChunks);
        uploadId = prepareData.upload_id || prepareData.uploadId;
        if (!uploadId) {
            throw new Error('Upload ID missing from prepare response');
        }

        for (let index = 0; index < totalChunks; index++) {
            const start = index * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, fileBuffer.byteLength);
            const chunkBuffer = fileBuffer.slice(start, end);
            const chunkSha1 = await calculateSha1(chunkBuffer);

            await uploadChunkWithRetry(chunkBuffer, chunkSha1, index, totalChunks, file);
            processedChunks.add(index);

            uploadedBytes = start + chunkBuffer.byteLength;

            const percent = (processedChunks.size / totalChunks) * 100;
            updateProgress(percent);
            updateSpeedAndEta(file.size);
        }

        updateStatus(ui.strings && ui.strings.merging ? ui.strings.merging : 'Merging chunks...');
        console.log(`[Finalize] All ${totalChunks} chunks uploaded. Starting finalize...`);
        const finalizeData = await finalizeUpload({ uploadId, fileSha1Hex, totalChunks, fileName: file.name, fileSize: file.size, searchReplacePayload });

        updateSha1Display(finalizeData.sha1 || finalizeData.server_sha1 || '', fileSha1Hex, finalizeData.sha1_mismatch);

        // If summary and progress are returned, handle the analysis response
        if (finalizeData.summary && finalizeData.progress) {
            console.log('[Finalize] Analysis completed, updating UI with summary', { summary: finalizeData.summary, progress: finalizeData.progress });
            updateStatus(ui.strings && ui.strings.analysisComplete ? ui.strings.analysisComplete : 'Analysis complete!');
            
            // Try to call handleSummaryResponse from admin.js via window or ui object
            var handled = false;
            
            // Method 1: Check if it's available on window.BackupLiteUI
            if (typeof window.BackupLiteUI !== 'undefined' && typeof window.BackupLiteUI.handleSummaryResponse === 'function') {
                console.log('[Finalize] Calling handleSummaryResponse via window.BackupLiteUI');
                window.BackupLiteUI.handleSummaryResponse({
                    success: true,
                    data: {
                        summary: finalizeData.summary,
                        progress: finalizeData.progress
                    }
                });
                handled = true;
            }
            
            // Method 2: Check if it's available on ui object
            if (!handled && typeof ui.handleSummaryResponse === 'function') {
                console.log('[Finalize] Calling handleSummaryResponse via ui object');
                ui.handleSummaryResponse({
                    success: true,
                    data: {
                        summary: finalizeData.summary,
                        progress: finalizeData.progress
                    }
                });
                handled = true;
            }
            
            // Method 3: Dispatch custom event for admin.js to handle
            if (!handled) {
                console.log('[Finalize] Dispatching custom event for summary response');
                var event = new CustomEvent('backup-lite-summary-ready', {
                    detail: {
                        success: true,
                        data: {
                            summary: finalizeData.summary,
                            progress: finalizeData.progress
                        }
                    }
                });
                document.dispatchEvent(event);
                handled = true;
            }
            
            // Fallback: reload page to show analysis results
            if (!handled) {
                console.log('[Finalize] No handler found, reloading page to show analysis results');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else if (finalizeData.warning) {
            // Analysis failed but upload succeeded
            console.warn('[Finalize] Analysis warning:', finalizeData.warning);
            updateStatus(finalizeData.warning);
            showMessage('warning', ui.strings && ui.strings.warningTitle ? ui.strings.warningTitle : 'Warning', finalizeData.warning);
            setTimeout(() => {
                window.location.reload();
            }, 3000);
        } else {
            // No summary returned, just show success and reload
            console.log('[Finalize] No summary returned, reloading page');
            updateStatus(ui.strings && ui.strings.uploadComplete ? ui.strings.uploadComplete : 'Upload complete!');
            showMessage('success', ui.strings && ui.strings.successTitle ? ui.strings.successTitle : '', ui.strings && ui.strings.uploadComplete ? ui.strings.uploadComplete : 'Upload complete!');
            setTimeout(() => {
                window.location.reload();
            }, 2000);
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const file = fileInput && fileInput.files.length ? fileInput.files[0] : null;
        if (!file) {
            showMessage('error', ui.strings && ui.strings.errorTitle ? ui.strings.errorTitle : '', ui.strings && ui.strings.noFileSelected ? ui.strings.noFileSelected : '');
            return;
        }

        if (!validateExtension(file)) {
            const allowedList = allowedExt.join(', ');
            showMessage('error', ui.strings && ui.strings.errorTitle ? ui.strings.errorTitle : '', formatString(ui.strings && ui.strings.invalidFileType ? ui.strings.invalidFileType : 'Invalid file type: %s', [allowedList]));
            return;
        }

        if (!confirmCheckbox || !confirmCheckbox.checked) {
            showMessage('error', ui.strings && ui.strings.errorTitle ? ui.strings.errorTitle : '', ui.strings && ui.strings.noConfirm ? ui.strings.noConfirm : '');
            return;
        }

        try {
            resetProgress();
            startTime = Date.now();
            uploadedBytes = 0;
            fileSha1Hex = '';
            processedChunks = new Set();
            useMultipartFallback = false;

            await processChunks(file);

            if (typeof refreshLogs === 'function') {
                refreshLogs();
            }
        } catch (error) {
            console.error('Upload error:', error);
            await abortUpload();
            resetProgress();

            const errorMessage = error && error.message ? error.message : (ui.strings && ui.strings.errorGeneric ? ui.strings.errorGeneric : 'Upload failed');
            showMessage('error', ui.strings && ui.strings.errorTitle ? ui.strings.errorTitle : '', errorMessage);

            if (typeof handleError === 'function') {
                handleError(error);
            }
        }
    });

    function Sha1() {
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

    Sha1.prototype.update = function (chunk) {
        if (this.finished) {
            throw new Error('SHA1: cannot update after digest');
        }
        let i = 0;
        while (i < chunk.length) {
            this.buffer[this.bufferLength++] = chunk[i++];
            this.bytesHashed++;
            if (this.bufferLength === 64) {
                this.processBuffer();
                this.bufferLength = 0;
            }
        }
    };

    Sha1.prototype.processBuffer = function () {
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
                f = (b & c) | ((~b) & d);
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
            const temp = (((a << 5) | (a >>> 27)) + f + e + k + words[i]) | 0;
            e = d;
            d = c;
            c = (b << 30) | (b >>> 2);
            b = a;
            a = temp;
        }

        this.h0 = (this.h0 + a) | 0;
        this.h1 = (this.h1 + b) | 0;
        this.h2 = (this.h2 + c) | 0;
        this.h3 = (this.h3 + d) | 0;
        this.h4 = (this.h4 + e) | 0;
    };

    Sha1.prototype.digest = function () {
        if (!this.finished) {
            const bitLength = this.bytesHashed * 8;
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
            for (let i = 0; i < 8; i++) {
                this.buffer[56 + i] = (bitLength >>> ((7 - i) * 8)) & 0xff;
            }
            this.processBuffer();
            this.finished = true;
        }
        const result = new Uint8Array(20);
        const hashParts = [this.h0, this.h1, this.h2, this.h3, this.h4];
        for (let i = 0; i < 5; i++) {
            const h = hashParts[i];
            result[i * 4] = (h >>> 24) & 0xff;
            result[i * 4 + 1] = (h >>> 16) & 0xff;
            result[i * 4 + 2] = (h >>> 8) & 0xff;
            result[i * 4 + 3] = h & 0xff;
        }
        return result;
    };

    Sha1.prototype.hexDigest = function () {
        const bytes = this.digest();
        let hex = '';
        for (let i = 0; i < bytes.length; i++) {
            hex += bytes[i].toString(16).padStart(2, '0');
        }
        return hex;
    };
})();
