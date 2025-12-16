(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var config = window.BackupLiteDashboard || {};
        var strings = config.strings || {};
        var aiConfig = config.ai || {};

        var countdownEl = document.getElementById('bl-dashboard-countdown');
        var nextRunTimestamp = parseInt(config.nextRunTimestamp || 0, 10);

        if (countdownEl && nextRunTimestamp > 0) {
            var updateCountdown = function () {
                var now = Math.floor(Date.now() / 1000);
                var diff = Math.max(0, nextRunTimestamp - now);

                if (diff <= 0) {
                    countdownEl.textContent = strings.dueNow || 'Due now';
                    return;
                }

                var hours = Math.floor(diff / 3600);
                diff %= 3600;
                var minutes = Math.floor(diff / 60);
                var seconds = diff % 60;

                var parts = [];
                if (hours > 0) {
                    parts.push(hours + 'h');
                }
                if (minutes > 0 || hours > 0) {
                    parts.push(minutes + 'm');
                }
                parts.push(seconds + 's');

                countdownEl.textContent = parts.join(' ');
            };

            updateCountdown();
            setInterval(updateCountdown, 1000);
        }

        var chartCanvas = document.getElementById('backup-lite-activity-chart');
        var emptyState = document.getElementById('bl-dashboard-chart-empty');

        if (!chartCanvas || !window.Chart) {
            if (chartCanvas) {
                chartCanvas.style.display = 'none';
            }
            if (emptyState) {
                emptyState.hidden = false;
            }
            return;
        }

        var successCount = parseInt(config.chart && config.chart.success ? config.chart.success : 0, 10);
        var failedCount = parseInt(config.chart && config.chart.failed ? config.chart.failed : 0, 10);
        var total = successCount + failedCount;

        if (total <= 0) {
            chartCanvas.style.display = 'none';
            if (emptyState) {
                emptyState.hidden = false;
            }
            return;
        }

        if (emptyState) {
            emptyState.hidden = true;
        }

        var ctx = chartCanvas.getContext('2d');
        var gradient = ctx.createLinearGradient(0, 0, 0, chartCanvas.height || 240);
        gradient.addColorStop(0, '#34d399');
        gradient.addColorStop(1, '#2563eb');

        new window.Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    strings.successLabel || 'Success',
                    strings.failedLabel || 'Failed'
                ],
                datasets: [{
                    data: [successCount, failedCount],
                    backgroundColor: [gradient, 'rgba(239, 68, 68, 0.85)'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: strings.legendColor || '#1f2937'
                        }
                    }
                },
                cutout: '65%'
            }
        });

        // AI Site Scan (Preview)
        var runBtn = document.getElementById('backup-lite-ai-run-scan');
        var statusEl = document.getElementById('backup-lite-ai-status');
        var reportsList = document.getElementById('backup-lite-ai-reports');
        var emptyEl = document.getElementById('backup-lite-ai-empty');
        var reportsContainer = document.getElementById('backup-lite-ai-reports-container');
        var lastScanEl = document.getElementById('backup-lite-ai-last-scan');
        var latestItemsWrap = document.getElementById('backup-lite-ai-latest-items-wrap');
        var latestItemsList = document.getElementById('backup-lite-ai-latest-items');

        var setStatus = function (msg) {
            if (!statusEl) return;
            statusEl.textContent = msg || '';
        };

        var normalizeSeverity = function (severity) {
            var s = String(severity || '').toLowerCase();
            if (s === 'high' || s === 'medium' || s === 'info') return s;
            return 'info';
        };

        var severityBadge = function (severity) {
            var s = normalizeSeverity(severity);
            var label = 'Info';
            var klass = 'success';
            if (s === 'high') {
                label = 'High';
                klass = 'error';
            } else if (s === 'medium') {
                label = 'Medium';
                klass = 'pending';
            }

            var badge = document.createElement('span');
            badge.className = 'badge ' + klass;
            badge.textContent = label;
            return badge;
        };

        var formatCreatedAt = function (createdAtGmt) {
            var raw = String(createdAtGmt || '').trim();
            if (!raw) return '';
            // created_at_gmt uses MySQL datetime "YYYY-MM-DD HH:MM:SS" in UTC.
            var iso = raw.replace(' ', 'T') + 'Z';
            var date = new Date(iso);
            if (isNaN(date.getTime())) return raw;
            return date.toLocaleString();
        };

        var updateLastScan = function (createdAtGmt) {
            if (!lastScanEl) return;
            var display = formatCreatedAt(createdAtGmt);
            lastScanEl.textContent = display || '—';
        };

        var updateLatestItems = function (items) {
            if (!latestItemsList || !latestItemsWrap) return;
            while (latestItemsList.firstChild) {
                latestItemsList.removeChild(latestItemsList.firstChild);
            }

            var safeItems = Array.isArray(items) ? items : [];
            if (!safeItems.length) {
                latestItemsWrap.hidden = true;
                return;
            }
            latestItemsWrap.hidden = false;

            safeItems.slice(0, 5).forEach(function (item) {
                if (!item) return;
                var li = document.createElement('li');

                li.appendChild(severityBadge(item.severity));

                var title = String(item.title || '').trim();
                if (title) {
                    var strong = document.createElement('strong');
                    strong.textContent = title;
                    li.appendChild(strong);
                }

                var rec = String(item.recommendation || '').trim();
                if (rec) {
                    var span = document.createElement('span');
                    span.textContent = rec;
                    li.appendChild(span);
                }

                latestItemsList.appendChild(li);
            });
        };

        var appendReport = function (entry) {
            if (!entry) return;
            if (emptyEl) {
                emptyEl.style.display = 'none';
            }
            if (!reportsList) {
                // Create list if it doesn't exist yet.
                var card = document.getElementById('backup-lite-ai-card');
                if (!card) return;
                reportsList = document.createElement('ul');
                reportsList.className = 'backup-lite-list';
                reportsList.id = 'backup-lite-ai-reports';
                if (reportsContainer) {
                    reportsContainer.appendChild(reportsList);
                } else {
                    card.appendChild(reportsList);
                }
            }

            var li = document.createElement('li');

            var strong = document.createElement('strong');
            strong.textContent = formatCreatedAt(entry.created_at_gmt || '');
            li.appendChild(strong);

            var report = entry.report || {};
            var summary = report.summary || '';
            if (summary) {
                var span = document.createElement('span');
                span.textContent = summary;
                li.appendChild(span);
            }

            var items = Array.isArray(report.items) ? report.items : [];
            if (items.length) {
                var details = document.createElement('details');
                details.style.marginTop = '6px';

                var summaryEl = document.createElement('summary');
                summaryEl.textContent = 'View details';
                details.appendChild(summaryEl);

                var ul = document.createElement('ul');
                ul.style.margin = '8px 0 0 18px';

                items.slice(0, 5).forEach(function (item) {
                    if (!item) return;
                    var itemLi = document.createElement('li');
                    itemLi.appendChild(severityBadge(item.severity));

                    var title = String(item.title || '').trim();
                    if (title) {
                        var itemStrong = document.createElement('strong');
                        itemStrong.textContent = title;
                        itemLi.appendChild(itemStrong);
                    }

                    var rec = String(item.recommendation || '').trim();
                    if (rec) {
                        var itemSpan = document.createElement('span');
                        itemSpan.textContent = ' — ' + rec;
                        itemLi.appendChild(itemSpan);
                    }
                    ul.appendChild(itemLi);
                });

                details.appendChild(ul);
                li.appendChild(details);
            }

            reportsList.insertBefore(li, reportsList.firstChild);
        };

        if (runBtn && aiConfig && aiConfig.restUrl && aiConfig.nonce) {
            runBtn.addEventListener('click', function () {
                if (runBtn.disabled) return;

                runBtn.disabled = true;
                setStatus((aiConfig.strings && aiConfig.strings.running) ? aiConfig.strings.running : 'Running scan…');

                fetch(aiConfig.restUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': aiConfig.nonce
                    },
                    body: JSON.stringify({})
                })
                    .then(function (res) {
                        return res.json().then(function (json) {
                            return { ok: res.ok, status: res.status, json: json };
                        });
                    })
                    .then(function (result) {
                        if (!result.ok) {
                            var message = (result.json && (result.json.message || result.json.data)) ? (result.json.message || result.json.data) : null;
                            setStatus((aiConfig.strings && aiConfig.strings.failed) ? aiConfig.strings.failed : 'Scan failed.');
                            if (message && statusEl) {
                                // Append a short error detail.
                                statusEl.textContent += ' ' + String(message);
                            }
                            return;
                        }

                        setStatus((aiConfig.strings && aiConfig.strings.done) ? aiConfig.strings.done : 'Scan completed.');
                        if (result.json && result.json.report) {
                            appendReport(result.json.report);
                            updateLastScan(result.json.report.created_at_gmt);
                            if (result.json.report.report && Array.isArray(result.json.report.report.items)) {
                                updateLatestItems(result.json.report.report.items);
                            } else {
                                updateLatestItems([]);
                            }
                        }
                    })
                    .catch(function () {
                        setStatus((aiConfig.strings && aiConfig.strings.failed) ? aiConfig.strings.failed : 'Scan failed.');
                    })
                    .finally(function () {
                        runBtn.disabled = false;
                    });
            });
        }
    });
})();

