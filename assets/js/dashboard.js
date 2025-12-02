(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var config = window.BackupLiteDashboard || {};
        var strings = config.strings || {};

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

        // Use fixed colors: blue for success, red for failed
        var ctx = chartCanvas.getContext('2d');
        var successColor = '#2563eb'; // Blue
        var failedColor = '#ef4444';  // Red

        new window.Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: [
                    strings.successLabel || 'Success',
                    strings.failedLabel || 'Failed'
                ],
                datasets: [{
                    data: [successCount, failedCount],
                    backgroundColor: [successColor, failedColor],
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
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var value = context.raw;
                                var percent = total ? Math.round((value / total) * 100) : 0;
                                return context.label + ': ' + value + ' (' + percent + '%)';
                            }
                        }
                    }
                },
                cutout: '65%'
            }
        });
    });
})();

