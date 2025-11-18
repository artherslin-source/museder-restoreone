(function () {
    'use strict';

    var config = window.BackupLiteReports || {};
    var restUrl = config.restUrl || '';
    var nonce = config.nonce || '';
    var isPro = config.isPro || false;

    var trendsChart = null;
    var currentDays = 30;

    function init() {
        if ( ! isPro ) {
            return;
        }

        loadSystemCheck();
        loadTrends( 30 );
        loadAIAnalysis();

        // Trend period buttons
        document.querySelectorAll( '[data-trend-days]' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                var days = parseInt( btn.dataset.trendDays, 10 );
                loadTrends( days );
                
                // Update active button
                document.querySelectorAll( '[data-trend-days]' ).forEach( function ( b ) {
                    b.classList.remove( 'bl-button-primary' );
                } );
                btn.classList.add( 'bl-button-primary' );
            } );
        } );

        // Export buttons
        var exportJson = document.getElementById( 'bl-export-json' );
        if ( exportJson ) {
            exportJson.addEventListener( 'click', function () {
                generateJsonReport();
            } );
        }

        var exportPdf = document.getElementById( 'bl-export-pdf' );
        if ( exportPdf ) {
            exportPdf.addEventListener( 'click', function () {
                if ( window.BackupLiteUI ) {
                    window.BackupLiteUI.showProModal();
                }
            } );
        }
    }

    function loadSystemCheck() {
        // System check is already loaded in PHP
    }

    function loadTrends( days ) {
        currentDays = days;
        
        fetch( restUrl + 'pro/reports/trends?days=' + days, {
            method: 'GET',
            headers: {
                'X-WP-Nonce': nonce,
            },
        } )
        .then( function ( response ) {
            return response.json();
        } )
        .then( function ( json ) {
            if ( json.error ) {
                console.error( 'Failed to load trends:', json.message );
                return;
            }

            renderTrendsChart( json.data || [], json.summary || {} );
        } )
        .catch( function ( error ) {
            console.error( 'Error loading trends:', error );
        } );
    }

    function renderTrendsChart( data, summary ) {
        var ctx = document.getElementById( 'bl-trends-chart' );
        if ( ! ctx || ! window.Chart ) {
            return;
        }

        if ( trendsChart ) {
            trendsChart.destroy();
        }

        var labels = data.map( function ( item ) {
            return item.date || '';
        } );

        var counts = data.map( function ( item ) {
            return item.count || 0;
        } );

        var sizes = data.map( function ( item ) {
            return ( item.size || 0 ) / ( 1024 * 1024 ); // Convert to MB
        } );

        trendsChart = new Chart( ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Backup Count',
                        data: counts,
                        borderColor: '#3a7bff',
                        backgroundColor: 'rgba(58, 123, 255, 0.1)',
                        yAxisID: 'y',
                    },
                    {
                        label: 'Size (MB)',
                        data: sizes,
                        borderColor: '#3fbf70',
                        backgroundColor: 'rgba(63, 191, 112, 0.1)',
                        yAxisID: 'y1',
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Count',
                        },
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Size (MB)',
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    },
                },
            },
        } );
    }

    function loadAIAnalysis() {
        fetch( restUrl + 'pro/reports/ai-analysis', {
            method: 'GET',
            headers: {
                'X-WP-Nonce': nonce,
            },
        } )
        .then( function ( response ) {
            return response.json();
        } )
        .then( function ( json ) {
            if ( json.error ) {
                console.error( 'Failed to load AI analysis:', json.message );
                return;
            }

            renderAIAnalysis( json.events || [], json.insights || [] );
        } )
        .catch( function ( error ) {
            console.error( 'Error loading AI analysis:', error );
        } );
    }

    function renderAIAnalysis( events, insights ) {
        var container = document.getElementById( 'bl-ai-analysis-content' );
        if ( ! container ) {
            return;
        }

        var html = '';

        if ( insights.length > 0 ) {
            html += '<div style="margin-bottom: 16px;">';
            insights.forEach( function ( insight ) {
                var color = insight.type === 'warning' ? 'var(--bl-warning)' : 'var(--bl-primary)';
                html += '<div style="padding: 12px; background: ' + color + '20; border-left: 3px solid ' + color + '; border-radius: 4px; margin-bottom: 8px;">';
                html += '<strong>' + ( insight.type === 'warning' ? '⚠️ ' : 'ℹ️ ' ) + '</strong>';
                html += '<span>' + ( insight.message || '' ) + '</span>';
                html += '</div>';
            } );
            html += '</div>';
        }

        if ( events.length > 0 ) {
            html += '<div style="max-height: 300px; overflow-y: auto;">';
            html += '<table class="backup-lite-table" style="font-size: 13px;">';
            html += '<thead><tr>';
            html += '<th>Time</th><th>Status</th><th>Message</th>';
            html += '</tr></thead><tbody>';
            
            events.slice( 0, 20 ).forEach( function ( event ) {
                var statusColor = event.status === 'success' ? 'var(--bl-success)' : 'var(--bl-danger)';
                html += '<tr>';
                html += '<td>' + ( event.timestamp || '' ) + '</td>';
                html += '<td><span style="color: ' + statusColor + ';">' + ( event.status || '' ) + '</span></td>';
                html += '<td>' + ( event.message || '' ) + '</td>';
                html += '</tr>';
            } );
            
            html += '</tbody></table>';
            html += '</div>';
        } else {
            html += '<p style="color: var(--bl-text-muted);">' + ( 'No recent events to analyze.' ) + '</p>';
        }

        container.innerHTML = html;
    }

    function generateJsonReport() {
        var btn = document.getElementById( 'bl-export-json' );
        if ( btn ) {
            btn.disabled = true;
            btn.textContent = 'Generating...';
        }

        fetch( restUrl + 'pro/reports/generate-json', {
            method: 'POST',
            headers: {
                'X-WP-Nonce': nonce,
                'Content-Type': 'application/json',
            },
        } )
        .then( function ( response ) {
            return response.json();
        } )
        .then( function ( json ) {
            if ( json.error ) {
                alert( 'Failed to generate report: ' + ( json.message || 'Unknown error' ) );
                if ( btn ) {
                    btn.disabled = false;
                    btn.textContent = 'Download JSON Report';
                }
                return;
            }

            if ( json.url ) {
                window.location.href = json.url;
            }

            if ( btn ) {
                btn.disabled = false;
                btn.textContent = 'Download JSON Report';
            }
        } )
        .catch( function ( error ) {
            console.error( 'Error generating report:', error );
            if ( btn ) {
                btn.disabled = false;
                btn.textContent = 'Download JSON Report';
            }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
})();

