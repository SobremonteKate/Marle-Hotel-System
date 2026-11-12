<?php
/**
 * Shared dashboard trends panel.
 *
 * Every chart now carries its own heading so a reader never has to guess what
 * a canvas is showing, and the four charts answer four different questions
 * instead of repeating the same monthly trend twice.
 *
 * The including dashboard is expected to provide the extracted dashboard
 * metrics plus $months (the selected range) and $dashboardLinks.
 */

$dashTrendRequestsHref = $dashboardLinks['requests'] ?? 'requests.php';
$dashTrendInventoryHref = $dashboardLinks['inventory'] ?? 'inventory.php';
$dashTrendTransactionsHref = $dashboardLinks['transactions'] ?? null;
?>
<!-- Trend analytics -->
<div class="content p-4">
    <section class="dashboard-panel">
        <div class="dashboard-action-shell">
            <div>
                <span class="dashboard-section-kicker"><i class="bi bi-bar-chart-line"></i>Performance trends</span>
                <h3 class="dashboard-section-title">How the hotel is moving</h3>
                <p class="dashboard-section-copy">Four focused views: demand over time, where collections come from,
                    what guests are asking for, and how the rooms are split right now.</p>
            </div>
            <form method="get" class="dashboard-filter-form mb-0">
                <label for="monthsSelect">Range</label>
                <select id="monthsSelect" name="months" class="form-select form-select-sm">
                    <option value="6" <?php echo $months === 6 ? 'selected' : ''; ?>>Last 6 months</option>
                    <option value="12" <?php echo $months === 12 ? 'selected' : ''; ?>>Last 12 months</option>
                    <option value="24" <?php echo $months === 24 ? 'selected' : ''; ?>>Last 24 months</option>
                    <option value="36" <?php echo $months === 36 ? 'selected' : ''; ?>>Last 36 months</option>
                </select>
                <button type="submit" class="btn btn-sm btn-warning">Apply</button>
            </form>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-6">
                <div class="dashboard-chart-card has-head">
                    <div class="dashboard-chart-head">
                        <div>
                            <h5>Demand over time</h5>
                            <span class="chart-sub">Check-ins and total guests registered each month</span>
                        </div>
                        <span class="chart-tag"><?php echo (int) $months; ?> mo</span>
                    </div>
                    <canvas id="lineChart"></canvas>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="dashboard-chart-card has-head">
                    <div class="dashboard-chart-head">
                        <div>
                            <h5>Where collections come from</h5>
                            <span class="chart-sub">Paid amounts grouped by transaction source</span>
                        </div>
                        <span class="chart-tag">All time</span>
                    </div>
                    <canvas id="barChart"></canvas>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="dashboard-chart-card has-head">
                    <div class="dashboard-chart-head">
                        <div>
                            <h5>What guests ask for</h5>
                            <span class="chart-sub">Open (non-archived) requests grouped by type</span>
                        </div>
                        <span class="chart-tag">Open</span>
                    </div>
                    <canvas id="requestTypeChart"></canvas>
                </div>
            </div>

            <div class="col-12 col-xl-6">
                <div class="dashboard-chart-card has-head">
                    <div class="dashboard-chart-head">
                        <div>
                            <h5>Room status mix</h5>
                            <span class="chart-sub">Live split of every room right now</span>
                        </div>
                        <span class="chart-tag">Live</span>
                    </div>
                    <canvas id="roomStatusChart"></canvas>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="dashboard-mini-info-card">
                    <h5 class="dashboard-mini-title">Awaiting action</h5>
                    <p class="dashboard-mini-stat">
                        <strong><?php echo number_format($pendingBookings); ?></strong> booking requests waiting for
                        review.
                    </p>
                    <div class="dashboard-mini-list">
                        <div class="dashboard-mini-row"><span>Room service requests</span>
                            <strong><?php echo number_format($pendingServiceRequests); ?></strong>
                        </div>
                        <div class="dashboard-mini-row"><span>Food orders</span>
                            <strong><?php echo number_format($pendingFoodRequests); ?></strong>
                        </div>
                        <div class="dashboard-mini-row"><span>Stay extensions</span>
                            <strong><?php echo number_format($pendingStayRequests); ?></strong>
                        </div>
                    </div>
                    <a class="dashboard-mini-link" href="<?php echo htmlspecialchars($dashTrendRequestsHref); ?>">Open the
                        request queue <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="dashboard-mini-info-card">
                    <h5 class="dashboard-mini-title">Collections</h5>
                    <p class="dashboard-mini-stat">
                        <strong><?php echo dashboard_money($unpaidReceivables); ?></strong> in unpaid balances remain
                        open.
                    </p>
                    <div class="dashboard-mini-list">
                        <div class="dashboard-mini-row"><span>Billed today</span>
                            <strong><?php echo dashboard_money($unpaidToday); ?></strong>
                        </div>
                        <div class="dashboard-mini-row"><span>Overdue (before today)</span>
                            <strong><?php echo dashboard_money($unpaidOverdue); ?></strong>
                        </div>
                        <div class="dashboard-mini-row"><span>Paid today</span>
                            <strong><?php echo dashboard_money($revenueToday); ?></strong>
                        </div>
                    </div>
                    <?php if ($dashTrendTransactionsHref !== null): ?>
                        <a class="dashboard-mini-link"
                            href="<?php echo htmlspecialchars($dashTrendTransactionsHref); ?>">Review transactions
                            <i class="bi bi-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-12 col-xl-4">
                <div class="dashboard-mini-info-card">
                    <h5 class="dashboard-mini-title">Inventory alerts</h5>
                    <?php if (empty($lowStockItems)): ?>
                        <p class="dashboard-mini-stat">No low-stock items right now.</p>
                    <?php else: ?>
                        <div class="dashboard-mini-list">
                            <?php foreach ($lowStockItems as $item): ?>
                                <div class="dashboard-mini-row">
                                    <span><?php echo htmlspecialchars($item['item']); ?></span>
                                    <strong><?php echo number_format((int) $item['quantity']); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <a class="dashboard-mini-link" href="<?php echo htmlspecialchars($dashTrendInventoryHref); ?>">Go to
                        inventory <i class="bi bi-arrow-right"></i></a>
                </div>
            </div>
        </div>
    </section>
</div>
