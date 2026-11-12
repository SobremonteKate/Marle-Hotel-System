<?php
/**
 * Shared dashboard chart script.
 *
 * One implementation for all three staff dashboards so the charts cannot drift
 * apart. Chart headings live in the panel markup, so the in-canvas titles are
 * gone and the space is used for data instead.
 *
 * Expects the dashboard to have defined the json_encode'd series variables
 * ($jsLabels, $jsCheckIns, $jsGuests, $jsRevenueTypeLabels, $jsRevenueTypeData,
 * $jsRequestTypeLabels, $jsRequestTypeData, $jsRoomStatusLabels,
 * $jsRoomStatusData).
 */
?>
<!-- Graph JS -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const labels = <?php echo $jsLabels; ?>;
        const checkIns = <?php echo $jsCheckIns; ?>;
        const guests = <?php echo $jsGuests; ?>;
        const revenueTypeLabels = <?php echo $jsRevenueTypeLabels; ?>;
        const revenueTypeData = <?php echo $jsRevenueTypeData; ?>;
        const requestTypeLabels = <?php echo $jsRequestTypeLabels; ?>;
        const requestTypeData = <?php echo $jsRequestTypeData; ?>;
        const roomStatusLabels = <?php echo $jsRoomStatusLabels; ?>;
        const roomStatusData = <?php echo $jsRoomStatusData; ?>;

        const palette = ['#b97836', '#355d54', '#d7785d', '#6b8f86', '#9a6a3a', '#8a9b96', '#c8d1cd'];
        const chartText = '#53605d';
        const chartGrid = 'rgba(83, 96, 93, 0.12)';
        // Locale pinned on purpose: an undefined/device locale groups by the viewer's own
        // conventions, so a ₱3,500 total rendered as ₱3.500 on a European device.
        const peso = (value) => '\u20B1' + Number(value || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        const chartBaseOptions = {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 650, easing: 'easeOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    position: 'top',
                    align: 'end',
                    labels: {
                        color: chartText,
                        boxWidth: 12,
                        boxHeight: 12,
                        padding: 18,
                        usePointStyle: true,
                        font: { family: 'DM Sans', size: 14, weight: '600' }
                    }
                },
                tooltip: {
                    backgroundColor: '#253634',
                    titleColor: '#fffdf9',
                    bodyColor: '#fffdf9',
                    padding: 14,
                    cornerRadius: 8,
                    displayColors: true,
                    titleFont: { family: 'DM Sans', size: 14, weight: '700' },
                    bodyFont: { family: 'DM Sans', size: 14 }
                }
            },
            // Axis labels are read at a glance from across a desk, so they are
            // larger than Chart.js' 12px default rather than smaller.
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: chartText, font: { family: 'DM Sans', size: 13.5 }, maxRotation: 0, autoSkipPadding: 12 }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: chartGrid, drawBorder: false },
                    border: { display: false },
                    ticks: { color: chartText, precision: 0, font: { family: 'DM Sans', size: 13.5 }, padding: 8 }
                }
            }
        };

        // Doughnut and pie charts have no axes, so the cartesian defaults must
        // not leak into them (they would draw stray tick labels behind the ring).
        const cartesianTypes = ['line', 'bar'];

        function makeChart(id, config) {
            const el = document.getElementById(id);
            if (!el || typeof Chart === 'undefined') return;
            const options = config.options || {};
            const merged = {
                ...chartBaseOptions,
                ...options,
                plugins: { ...chartBaseOptions.plugins, ...options.plugins }
            };
            if (cartesianTypes.includes(config.type)) {
                merged.scales = { ...chartBaseOptions.scales, ...options.scales };
            } else {
                delete merged.scales;
                delete merged.interaction;
            }
            new Chart(el, { ...config, options: merged });
        }

        // Demand over time: check-ins and total guests on one shared timeline.
        makeChart('lineChart', {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Check-ins',
                        data: checkIns,
                        backgroundColor: 'rgba(185, 120, 54, 0.14)',
                        borderColor: '#b97836',
                        borderWidth: 2.5,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#b97836',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    },
                    {
                        label: 'Guests',
                        data: guests,
                        borderColor: '#355d54',
                        borderWidth: 2,
                        borderDash: [5, 4],
                        fill: false,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: '#355d54',
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2
                    }
                ]
            }
        });

        // Where collections come from: paid amounts split by transaction source.
        makeChart('barChart', {
            type: 'bar',
            data: {
                labels: revenueTypeLabels,
                datasets: [{
                    label: 'Paid collections',
                    data: revenueTypeData,
                    backgroundColor: palette,
                    borderRadius: 8,
                    borderSkipped: false,
                    maxBarThickness: 46
                }]
            },
            options: {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: { label: (ctx) => ' ' + peso(ctx.parsed.y) }
                    }
                },
                // This replaces the base cartesian scales wholesale (a shallow
                // merge), so the shared font/colour must be repeated here or the
                // ticks would silently fall back to Chart.js' small default.
                scales: { y: {
                    beginAtZero: true,
                    grid: { color: chartGrid, drawBorder: false },
                    border: { display: false },
                    ticks: {
                        color: chartText,
                        padding: 8,
                        font: { family: 'DM Sans', size: 13.5 },
                        callback: (value) => '\u20B1' + Number(value).toLocaleString('en-US')
                    }
                } }
            }
        });

        // What guests ask for: volume by request type.
        makeChart('requestTypeChart', {
            type: 'bar',
            data: {
                labels: requestTypeLabels,
                datasets: [{
                    label: 'Requests',
                    data: requestTypeData,
                    backgroundColor: '#b97836',
                    borderRadius: 7,
                    borderSkipped: false,
                    barPercentage: 0.62,
                    categoryPercentage: 0.78,
                    maxBarThickness: 26
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: { legend: { display: false } },
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: { color: chartGrid },
                        ticks: {
                            precision: 0,
                            color: chartText,
                            font: { family: 'DM Sans', size: 13.5 }
                        }
                    },
                    y: {
                        grid: { display: false },
                        ticks: {
                            color: chartText,
                            padding: 8,
                            font: { family: 'DM Sans', size: 13.5 }
                        }
                    }
                }
            }
        });

        // Room status mix: live distribution, with counts inside the slices.
        makeChart('roomStatusChart', {
            type: 'doughnut',
            data: {
                labels: roomStatusLabels,
                datasets: [{
                    data: roomStatusData,
                    backgroundColor: ['#355d54', '#d7785d', '#b97836', '#8a9b96', '#c8d1cd'],
                    borderColor: '#fff',
                    borderWidth: 2
                }]
            },
            options: {
                cutout: '58%',
                plugins: {
                    legend: { position: 'bottom', align: 'center' },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => {
                                const total = ctx.dataset.data.reduce((a, b) => a + Number(b || 0), 0);
                                const share = total > 0 ? Math.round((Number(ctx.parsed || 0) / total) * 100) : 0;
                                return ' ' + ctx.parsed + ' room(s) \u00B7 ' + share + '%';
                            }
                        }
                    }
                }
            }
        });
    });
</script>
