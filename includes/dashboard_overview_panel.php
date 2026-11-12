<?php
/**
 * Shared dashboard overview panel.
 *
 * Renders the "what needs attention today" strip followed by grouped KPI cards
 * so admins, employees and the owner all read the same operational picture.
 *
 * The including dashboard is expected to provide:
 *   - $dashboardMetrics extracted into local variables (dashboard_metrics())
 *   - $dashboardLinks: ['requests' => ..., 'inventory' => ..., 'transactions' => ?string]
 *   - $dashboardSectionTailor: short string naming who the summary is for
 */

$dashRequestsHref = $dashboardLinks['requests'] ?? 'requests.php';
$dashInventoryHref = $dashboardLinks['inventory'] ?? 'inventory.php';
$dashTransactionsHref = $dashboardLinks['transactions'] ?? null;
$dashSectionTailor = $dashboardSectionTitle ?? 'Operations summary';
?>
<!-- Business analytics -->
<div class="content p-4">
    <div class="container">
        <section class="dashboard-panel">
            <div class="d-flex flex-column flex-lg-row align-items-start justify-content-between gap-3 mb-4">
                <div>
                    <span class="dashboard-section-kicker"><i class="bi bi-clipboard2-pulse"></i>Today at a
                        glance</span>
                    <h3 class="dashboard-section-title"><?php echo htmlspecialchars($dashSectionTailor); ?></h3>
                    <p class="dashboard-section-copy">Start with the action queue on the left of each tile, then scan the
                        grouped figures below for the front desk, collections and inventory in one pass.</p>
                </div>
                <div class="dashboard-section-pill">
                    <i class="bi bi-lightning-charge-fill"></i>
                    Live management snapshot
                </div>
            </div>

            <div class="dashboard-attention-strip">
                <a class="dashboard-attention-tile <?php echo $pendingBookings > 0 ? 'is-active' : 'is-clear'; ?>"
                    href="<?php echo htmlspecialchars($dashRequestsHref); ?>">
                    <span class="attention-icon" aria-hidden="true"><i class="bi bi-calendar2-check"></i></span>
                    <span class="attention-body">
                        <span class="attention-count"><?php echo number_format($pendingBookings); ?></span>
                        <span class="attention-label">Booking approvals</span>
                        <span
                            class="attention-hint"><?php echo $pendingBookings > 0 ? 'Waiting for a decision' : 'Nothing waiting'; ?></span>
                    </span>
                    <i class="bi bi-chevron-right attention-chevron" aria-hidden="true"></i>
                </a>

                <a class="dashboard-attention-tile <?php echo $pendingRequests > 0 ? 'is-active' : 'is-clear'; ?>"
                    href="<?php echo htmlspecialchars($dashRequestsHref); ?>">
                    <span class="attention-icon" aria-hidden="true"><i class="bi bi-chat-dots"></i></span>
                    <span class="attention-body">
                        <span class="attention-count"><?php echo number_format($pendingRequests); ?></span>
                        <span class="attention-label">Guest requests</span>
                        <span class="attention-hint">
                            <?php echo number_format($pendingServiceRequests); ?> service &middot;
                            <?php echo number_format($pendingFoodRequests); ?> food
                        </span>
                    </span>
                    <i class="bi bi-chevron-right attention-chevron" aria-hidden="true"></i>
                </a>

                <?php if ($dashTransactionsHref !== null): ?>
                    <a class="dashboard-attention-tile <?php echo $unpaidReceivables > 0 ? 'is-active' : 'is-clear'; ?>"
                        href="<?php echo htmlspecialchars($dashTransactionsHref); ?>">
                        <span class="attention-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                        <span class="attention-body">
                            <span
                                class="attention-count"><?php echo dashboard_money($unpaidReceivables); ?></span>
                            <span class="attention-label">Unpaid balances</span>
                            <span class="attention-hint"><?php echo number_format($unpaidCount); ?> open &middot;
                                <?php echo dashboard_money($unpaidOverdue); ?> overdue</span>
                        </span>
                        <i class="bi bi-chevron-right attention-chevron" aria-hidden="true"></i>
                    </a>
                <?php else: ?>
                    <div class="dashboard-attention-tile <?php echo $unpaidReceivables > 0 ? 'is-active' : 'is-clear'; ?>">
                        <span class="attention-icon" aria-hidden="true"><i class="bi bi-cash-coin"></i></span>
                        <span class="attention-body">
                            <span
                                class="attention-count"><?php echo dashboard_money($unpaidReceivables); ?></span>
                            <span class="attention-label">Unpaid balances</span>
                            <span class="attention-hint">Collect at the front desk &middot;
                                <?php echo dashboard_money($unpaidOverdue); ?> overdue</span>
                        </span>
                    </div>
                <?php endif; ?>

                <a class="dashboard-attention-tile <?php echo $lowStockCount > 0 ? 'is-active' : 'is-clear'; ?>"
                    href="<?php echo htmlspecialchars($dashInventoryHref); ?>">
                    <span class="attention-icon" aria-hidden="true"><i class="bi bi-box-seam"></i></span>
                    <span class="attention-body">
                        <span class="attention-count"><?php echo number_format($lowStockCount); ?></span>
                        <span class="attention-label">Low stock items</span>
                        <span
                            class="attention-hint"><?php echo $lowStockCount > 0 ? 'At or below threshold' : 'Stock levels healthy'; ?></span>
                    </span>
                    <i class="bi bi-chevron-right attention-chevron" aria-hidden="true"></i>
                </a>
            </div>

            <?php
            $dashRevenueDelta = dashboard_delta($revenueToday, $revenueYesterday, true);
            $dashUnpaidDelta = dashboard_delta($unpaidToday, $unpaidYesterday, true);
            $dashCheckInDelta = dashboard_delta($checkInsToday, $checkInsYesterday);
            ?>

            <div class="dashboard-kpi-group">
                <div class="dashboard-kpi-group-head"><span>Front desk &mdash; today</span></div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-checkins">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">Arrivals</div>
                                    <h4 class="dashboard-metric-title">Expected today</h4>
                                </div>
                                <div class="dashboard-stat-icon checkins" aria-hidden="true"><i
                                        class="bi bi-door-open"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo number_format($arrivalsToday); ?></p>
                            <div class="dashboard-metric-foot">
                                <span class="dashboard-metric-chip"><?php echo number_format($pendingBookings); ?>
                                    awaiting approval</span>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-rooms">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">In-house</div>
                                    <h4 class="dashboard-metric-title">Guests right now</h4>
                                </div>
                                <div class="dashboard-stat-icon guests" aria-hidden="true"><i
                                        class="bi bi-people"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo number_format($totalCheckIns); ?></p>
                            <div class="dashboard-metric-foot">
                                <span class="dashboard-metric-chip"><?php echo number_format($occupiedRooms); ?> rooms
                                    &middot; <?php echo number_format($occupancyRate, 1); ?>%</span>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-checkins">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">Check-ins</div>
                                    <h4 class="dashboard-metric-title">Registered today</h4>
                                </div>
                                <div class="dashboard-stat-icon checkins" aria-hidden="true"><i
                                        class="bi bi-journal-check"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo number_format($checkInsToday); ?></p>
                            <div class="dashboard-metric-foot">
                                <?php echo dashboard_delta_chip($dashCheckInDelta); ?>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-rooms">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">Departures</div>
                                    <h4 class="dashboard-metric-title">Check-outs today</h4>
                                </div>
                                <div class="dashboard-stat-icon guests" aria-hidden="true"><i
                                        class="bi bi-calendar-check"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo number_format($todayCheckouts); ?></p>
                            <div class="dashboard-metric-foot">
                                <span class="dashboard-metric-chip"><?php echo number_format($availableRooms); ?>
                                    rooms free</span>
                            </div>
                        </article>
                    </div>
                </div>
            </div>

            <div class="dashboard-kpi-group">
                <div class="dashboard-kpi-group-head"><span>Money &mdash; collections</span></div>
                <div class="row g-3">
                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-finance">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">Collected</div>
                                    <h4 class="dashboard-metric-title">Today</h4>
                                </div>
                                <div class="dashboard-stat-icon revenue" aria-hidden="true"><i
                                        class="bi bi-cash-stack"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo dashboard_money($revenueToday); ?></p>
                            <div class="dashboard-metric-foot">
                                <?php echo dashboard_delta_chip($dashRevenueDelta); ?>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-finance">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">Collected</div>
                                    <h4 class="dashboard-metric-title"><?php echo htmlspecialchars($revenueMonthLabel); ?>
                                    </h4>
                                </div>
                                <div class="dashboard-stat-icon revenue" aria-hidden="true"><i
                                        class="bi bi-graph-up-arrow"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo dashboard_money($revenueMonth); ?></p>
                            <div class="dashboard-metric-foot">
                                <span class="dashboard-metric-chip">Month to date</span>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-requests">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">New unpaid</div>
                                    <h4 class="dashboard-metric-title">Billed today</h4>
                                </div>
                                <div class="dashboard-stat-icon revenue" aria-hidden="true"><i
                                        class="bi bi-hourglass-split"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo dashboard_money($unpaidToday); ?></p>
                            <div class="dashboard-metric-foot">
                                <?php echo dashboard_delta_chip($dashUnpaidDelta, 'down'); ?>
                            </div>
                        </article>
                    </div>

                    <div class="col-12 col-sm-6 col-xl-3">
                        <article class="dashboard-metric-card metric-stock">
                            <div class="dashboard-metric-top">
                                <div>
                                    <div class="dashboard-metric-label">Overdue</div>
                                    <h4 class="dashboard-metric-title">Older unpaid balances</h4>
                                </div>
                                <div class="dashboard-stat-icon guests" aria-hidden="true"><i
                                        class="bi bi-exclamation-circle"></i></div>
                            </div>
                            <p class="dashboard-metric-value"><?php echo dashboard_money($unpaidOverdue); ?></p>
                            <div class="dashboard-metric-foot">
                                <span class="dashboard-metric-chip">Before today</span>
                            </div>
                        </article>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
