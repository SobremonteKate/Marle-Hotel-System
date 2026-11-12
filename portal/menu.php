<?php
require_once __DIR__ . '/includes/session_guard.php';
// Fetch this guest's food orders. A single order can contain several
// items (food_order_items rows all sharing one requests.id), so group
// them into one card per order rather than one row per item.
$foodOrders = [];
if ($customerId > 0) {
    $ordStmt = $conn->prepare(
        "SELECT id, status, denial_reason, created_at
         FROM requests
         WHERE customer_id = ? AND type = 'food'
         ORDER BY created_at DESC"
    );
    $ordStmt->bind_param('i', $customerId);
    $ordStmt->execute();
    $ordResult = $ordStmt->get_result();
    while ($row = $ordResult->fetch_assoc()) {
        $row['items'] = [];
        $row['total'] = 0.0;
        $foodOrders[(int)$row['id']] = $row;
    }
    $ordStmt->close();

    if (!empty($foodOrders)) {
        $requestIds   = array_keys($foodOrders);
        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $types        = str_repeat('i', count($requestIds));

        $itemStmt = $conn->prepare(
            "SELECT request_id, item_name, item_category, quantity, unit_price, total_amount, special_request
             FROM food_order_items
             WHERE request_id IN ($placeholders)
             ORDER BY id ASC"
        );
        $itemStmt->bind_param($types, ...$requestIds);
        $itemStmt->execute();
        $itemResult = $itemStmt->get_result();
        while ($item = $itemResult->fetch_assoc()) {
            $rid = (int)$item['request_id'];
            if (isset($foodOrders[$rid])) {
                $foodOrders[$rid]['items'][] = $item;
                $foodOrders[$rid]['total']  += (float)$item['total_amount'];
            }
        }
        $itemStmt->close();
    }

    $foodOrders = array_values($foodOrders);
}

require_once __DIR__ . '/includes/menu_data.php';

$pageTitle = 'Restaurant Menu';
$activePage = 'menu';
$showBackButton = true;
$breadcrumb = 'Menu';
$pageCss = 'css/menu.css';
include __DIR__ . '/includes/header.php';
?>

<main class="portal-main menu-page" data-menu-default="<?= htmlspecialchars($menuDefaultCategory, ENT_QUOTES, 'UTF-8') ?>">

    <header class="menu-hero">
        <div class="landing-text">
            <p>Marle Restaurant</p>
            <h1>Curated dining, <span>delivered to you</span></h1>
            <div class="line"></div>
            <p>Order in-room or reserve a table at the restaurant.</p>
        </div>
    </header>

    <div class="search-wrapper">
        <label class="visually-hidden" for="menuSearch">Search dishes</label>
        <input id="menuSearch" type="search" class="search-input" placeholder="Search dishes&hellip;" autocomplete="off">
        <button class="search-icon-btn" type="button" aria-label="Search menu">
            <i class="bi bi-search" aria-hidden="true"></i>
        </button>
    </div>

    <div class="menu-category-area">
        <div class="scroll-wrapper" role="group" aria-label="Menu categories">
            <?php foreach ($menuCategories as $slug => [$english, $tagalog]): ?>
                <button type="button"
                        class="btn category-btn<?= $slug === $menuDefaultCategory ? ' active' : '' ?>"
                        data-filter="<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                        aria-pressed="<?= $slug === $menuDefaultCategory ? 'true' : 'false' ?>"><?= ui_t($english, $tagalog) ?></button>
            <?php endforeach; ?>
        </div>
    </div>

    <p class="menu-count" id="menuCount" aria-live="polite"></p>

    <div class="menu-grid" id="menuGrid">
        <?php foreach ($menuItems as $item):
            $primary = $item['cats'][0] ?? '';
            $label   = $menuCategories[$primary][0] ?? ucfirst($primary);
        ?>
            <div class="menu-card"
                 data-categories="<?= htmlspecialchars(implode(' ', $item['cats']), ENT_QUOTES, 'UTF-8') ?>"
                 data-price="<?= (int)$item['price'] ?>"
                 data-category-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                <div class="card h-100">
                    <div class="card-body">
                        <div class="menu-icon-container"><i class="fa-solid <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></div>
                        <div>
                            <h3 class="card-title mb-0"><?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                            <p class="card-text small text-muted mb-0"><?= htmlspecialchars($item['desc'], ENT_QUOTES, 'UTF-8') ?></p>
                            <div class="menu-tags">
                                <span class="price">&#8369;<?= number_format((int)$item['price']) ?></span>
                                <?php foreach ($item['cats'] as $cat): ?>
                                    <span class="category"><?= htmlspecialchars($menuCategories[$cat][0] ?? ucfirst($cat), ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <button class="add-menu-btn" type="button" aria-label="Add <?= htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') ?> to your order">
                            <i class="bi bi-plus-lg" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <nav class="menu-pagination" id="menuPagination" aria-label="Menu pages"></nav>

    <div class="estimated-total-area" id="orderBar" hidden>
        <div>
            <div class="fw-bold" id="orderBarTotal">&#8369;0.00</div>
            <div class="small" id="orderBarMeta">0 items</div>
        </div>
        <button class="btn btn-request" type="button">Review order</button>
    </div>

    <section class="mt-4" aria-labelledby="orderHistoryTitle">
        <div class="d-flex justify-content-between align-items-end mb-2">
            <h2 class="section-title" id="orderHistoryTitle">Your orders</h2>
        </div>

        <div class="scroll-wrapper mb-2" id="orderStatusFilterArea" role="group" aria-label="Filter orders by status">
            <button class="btn order-status-filter-btn active" data-filter="all" aria-pressed="true">All</button>
            <button class="btn order-status-filter-btn" data-filter="approved" aria-pressed="false">Approved</button>
            <button class="btn order-status-filter-btn" data-filter="denied" aria-pressed="false">Denied</button>
        </div>

        <div id="foodOrderHistoryContainer">
            <?php if (empty($foodOrders)): ?>
                <p class="text-muted">No food orders yet. Tap <strong>+</strong> on any dish to order it.</p>
            <?php else: ?>
                <?php foreach ($foodOrders as $ord): ?>
                    <div class="card order-summary-card mb-2" data-order-status="<?= htmlspecialchars($ord['status'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div>
                                    <strong>Order #<?= (int)$ord['id'] ?></strong>
                                    <div class="text-muted small"><?= date('M j, g:ia', strtotime($ord['created_at'])) ?></div>
                                </div>
                                <span class="badge <?= match ($ord['status']) {
                                    'pending'  => 'bg-warning text-dark',
                                    'approved' => 'bg-success',
                                    'denied'   => 'bg-danger',
                                    default    => 'bg-secondary',
                                } ?>"><?= ucfirst(htmlspecialchars($ord['status'], ENT_QUOTES, 'UTF-8')) ?></span>
                            </div>

                            <ul class="list-unstyled mb-2" style="font-size:.82rem;">
                                <?php foreach ($ord['items'] as $item): ?>
                                    <li class="d-flex justify-content-between gap-2 py-1" style="border-bottom:1px solid var(--m-line-soft);">
                                        <span><?= (int)$item['quantity'] ?>&times; <?= htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span style="font-family:var(--m-mono);">&#8369;<?= number_format((float)$item['total_amount'], 2) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>

                            <?php if (!empty($ord['denial_reason'])): ?>
                                <p class="mb-2" style="font-size:.8rem;color:var(--m-rust);"><i class="bi bi-info-circle me-1"></i><?= htmlspecialchars($ord['denial_reason'], ENT_QUOTES, 'UTF-8') ?></p>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between fw-bold" style="font-size:.9rem;">
                                <span>Total</span>
                                <span style="font-family:var(--m-mono);">&#8369;<?= number_format((float)$ord['total'], 2) ?></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</main>

<div id="orderOverlay" class="order-overlay"></div>

<div id="orderPopup" class="order-popup" role="dialog" aria-modal="true" aria-labelledby="orderPopupTitle">
    <div class="popup-header d-flex justify-content-between align-items-center">
        <h2 class="mb-0" id="orderPopupTitle" style="font-family:var(--m-display);font-weight:500;font-size:1.2rem;">Review your order</h2>
        <button class="btn-close" id="closeOrder" type="button" aria-label="Close order"></button>
    </div>

    <div class="popup-body p-3"></div>

    <div class="p-3">
        <div id="orderSummaryBlock">
            <div class="d-flex justify-content-between mb-1"><span>Subtotal</span><span id="popupSubtotal">&#8369;0.00</span></div>
            <div class="d-flex justify-content-between mb-1"><span>Service fee (16%)</span><span id="popupFee">&#8369;0.00</span></div>
            <hr>
            <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span id="popupTotal" style="color:var(--m-brass-deep);">&#8369;0.00</span></div>
        </div>
    </div>

    <div class="popup-footer p-3 border-top">
        <button class="btn confirm-btn w-100 fw-bold py-3" id="confirmOrderBtn" type="button">Confirm request</button>
    </div>
</div>

<div class="modal fade" id="addItemModal" tabindex="-1" aria-labelledby="addItemName" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title fw-bold" id="addItemName" style="font-size:1.15rem;">Add item</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Unit price: <span id="addItemPrice" class="fw-semibold"></span></p>
                <div class="mb-3">
                    <label class="form-label fw-semibold small" for="addItemQty">Quantity</label>
                    <input type="number" id="addItemQty" class="form-control" value="1" min="1" max="20">
                </div>
                <div>
                    <label class="form-label fw-semibold small" for="addItemSpecial">Special request <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea id="addItemSpecial" class="form-control" rows="2" placeholder="E.g. no onions, extra sauce, well done&hellip;"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmAddItem" class="btn btn-primary fw-bold"><i class="bi bi-plus-lg me-1"></i>Add to order</button>
            </div>
        </div>
    </div>
</div>

<div class="position-fixed bottom-0 end-0 p-3" style="z-index:2000;">
    <div id="menuToast" class="toast align-items-center border-0 text-bg-success" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="menuToastMsg"></div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Dismiss"></button>
        </div>
    </div>
</div>

<div class="modal fade" id="foodOrderConfirmation" tabindex="-1" aria-labelledby="foodOrderConfirmationTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <div class="modal-body text-center p-4">
                <div class="text-success mb-3" style="font-size:2.5rem;"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
                <h2 class="fw-bold" id="foodOrderConfirmationTitle" style="font-size:1.15rem;">Order request sent</h2>
                <p class="text-muted mb-4" id="foodOrderConfirmationMessage">Your order has been sent to the front desk.</p>
                <button type="button" class="btn btn-dark px-4" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        // ── Category filter ────────────────────────────────────────────────
        // No "All": a category is always chosen, and Main is the default. The
        // card list and the chips both come from includes/menu_data.php.
        var grid          = document.getElementById('menuGrid');
        var cards         = Array.prototype.slice.call(document.querySelectorAll('.menu-card'));
        var chips         = Array.prototype.slice.call(document.querySelectorAll('.category-btn'));
        var searchInput   = document.querySelector('.search-input');
        var paginationEl  = document.getElementById('menuPagination');
        var countEl       = document.getElementById('menuCount');
        var page          = document.querySelector('.menu-page');
        var CARDS_PER_PAGE = 9;
        var activeFilter  = (page && page.dataset.menuDefault) || 'main';
        var currentPage   = 1;

        function chipLabel() {
            var chip = chips.filter(function (b) { return b.dataset.filter === activeFilter; })[0];
            return chip ? chip.textContent.trim() : '';
        }

        function matchedCards() {
            var query = (searchInput.value || '').trim().toLowerCase();
            return cards.filter(function (card) {
                var cats = (card.dataset.categories || '').split(' ');
                var title = card.querySelector('.card-title');
                var desc  = card.querySelector('.card-text');
                var text  = ((title ? title.textContent : '') + ' ' + (desc ? desc.textContent : '')).toLowerCase();
                return cats.indexOf(activeFilter) !== -1 && (!query || text.indexOf(query) !== -1);
            });
        }

        function renderPagination(totalPages) {
            paginationEl.innerHTML = '';
            if (totalPages <= 1) { return; }

            function pageButton(label, target, options) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pagination-btn' + (options && options.active ? ' active' : '');
                btn.innerHTML = label;
                if (options && options.disabled) { btn.disabled = true; }
                if (options && options.label) { btn.setAttribute('aria-label', options.label); }
                if (options && options.active) { btn.setAttribute('aria-current', 'page'); }
                btn.addEventListener('click', function () {
                    currentPage = target;
                    applyFilters(false);
                });
                return btn;
            }

            paginationEl.appendChild(pageButton('<i class="bi bi-chevron-left" aria-hidden="true"></i>', currentPage - 1, {
                disabled: currentPage === 1, label: 'Previous page'
            }));
            for (var p = 1; p <= totalPages; p++) {
                paginationEl.appendChild(pageButton(String(p), p, { active: p === currentPage }));
            }
            paginationEl.appendChild(pageButton('<i class="bi bi-chevron-right" aria-hidden="true"></i>', currentPage + 1, {
                disabled: currentPage === totalPages, label: 'Next page'
            }));
        }

        function applyFilters(resetPage) {
            if (resetPage !== false) { currentPage = 1; }
            var matched = matchedCards();
            var totalPages = Math.max(1, Math.ceil(matched.length / CARDS_PER_PAGE));
            if (currentPage > totalPages) { currentPage = totalPages; }
            var start = (currentPage - 1) * CARDS_PER_PAGE;

            cards.forEach(function (card) {
                card.style.display = 'none';
                card.classList.remove('fade-in');
            });
            matched.slice(start, start + CARDS_PER_PAGE).forEach(function (card) {
                card.style.display = 'flex';
                card.classList.add('fade-in');
            });

            countEl.textContent = matched.length + (matched.length === 1 ? ' dish' : ' dishes') + ' \u00b7 ' + chipLabel();
            renderPagination(totalPages);
        }

        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                chips.forEach(function (btn) {
                    btn.classList.remove('active');
                    btn.setAttribute('aria-pressed', 'false');
                });
                chip.classList.add('active');
                chip.setAttribute('aria-pressed', 'true');
                activeFilter = chip.dataset.filter;
                applyFilters();
            });
        });

        if (searchInput) {
            searchInput.addEventListener('input', function () { applyFilters(); });
        }
        var searchBtn = document.querySelector('.search-icon-btn');
        if (searchBtn && searchInput) {
            searchBtn.addEventListener('click', function () { searchInput.focus(); });
        }

        applyFilters();

        // ── Order state ────────────────────────────────────────────────────
        var SERVICE_FEE_RATE = 0.16;
        var order = [];
        var pendingAdd = null;
        var addItemModal = null;

        function escHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function peso(amount) {
            return '\u20b1' + Number(amount).toLocaleString('en-PH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }

        function updateOrderBar() {
            var totalItems = order.reduce(function (s, i) { return s + i.qty; }, 0);
            var subtotal = order.reduce(function (s, i) { return s + i.price * i.qty; }, 0);
            var bar = document.getElementById('orderBar');
            if (!bar) { return; }
            bar.hidden = totalItems === 0;
            document.getElementById('orderBarTotal').textContent = peso(subtotal + subtotal * SERVICE_FEE_RATE);
            document.getElementById('orderBarMeta').textContent =
                totalItems + ' item' + (totalItems !== 1 ? 's' : '') + ' \u00b7 Room <?= htmlspecialchars($_SESSION['room_number'] ?? '—', ENT_QUOTES, 'UTF-8') ?>';
        }

        function setPopupTotals(subtotal, fee) {
            document.getElementById('popupSubtotal').textContent = peso(subtotal);
            document.getElementById('popupFee').textContent = peso(fee);
            document.getElementById('popupTotal').textContent = peso(subtotal + fee);
        }

        function renderOrderPopup() {
            var body = document.querySelector('.popup-body');
            body.innerHTML = '';

            if (order.length === 0) {
                body.innerHTML = '<p class="text-muted text-center py-4">Your order is empty.</p>';
                setPopupTotals(0, 0);
                return;
            }

            order.forEach(function (item, idx) {
                var row = document.createElement('div');
                row.className = 'd-flex justify-content-between align-items-start mb-3 pb-3 gap-2';
                row.style.borderBottom = '1px solid var(--m-line-soft)';
                row.innerHTML =
                    '<div class="flex-grow-1">' +
                        '<h3 class="mb-0 fw-bold" style="font-size:.92rem;">' + escHtml(item.name) + '</h3>' +
                        '<span class="text-muted small">' + (item.price > 0 ? peso(item.price * item.qty) : 'Free') + '</span>' +
                        (item.specialRequest ? '<p class="mb-0 text-muted" style="font-size:.75rem;"><i class="bi bi-chat-quote me-1"></i>' + escHtml(item.specialRequest) + '</p>' : '') +
                    '</div>' +
                    '<div class="d-flex align-items-center gap-2">' +
                        '<div class="d-flex align-items-center gap-2 rounded-pill px-2 py-1" style="background:var(--m-cream);">' +
                            '<button class="btn btn-sm border-0 qty-minus" data-idx="' + idx + '" aria-label="Fewer"><i class="bi bi-dash"></i></button>' +
                            '<span class="fw-bold qty-number">' + item.qty + '</span>' +
                            '<button class="btn btn-sm border-0 qty-plus" data-idx="' + idx + '" aria-label="More"><i class="bi bi-plus"></i></button>' +
                        '</div>' +
                        '<button class="btn btn-sm border-0 text-danger remove-item" data-idx="' + idx + '" title="Remove"><i class="bi bi-trash"></i></button>' +
                    '</div>';
                body.appendChild(row);
            });

            var subtotal = order.reduce(function (s, i) { return s + i.price * i.qty; }, 0);
            setPopupTotals(subtotal, subtotal * SERVICE_FEE_RATE);
        }

        function openOrderPopup() {
            renderOrderPopup();
            document.getElementById('orderPopup').classList.add('active');
            document.getElementById('orderOverlay').style.display = 'block';
        }

        function closeOrderPopup() {
            document.getElementById('orderPopup').classList.remove('active');
            document.getElementById('orderOverlay').style.display = 'none';
        }

        addItemModal = new bootstrap.Modal(document.getElementById('addItemModal'));

        document.querySelectorAll('.add-menu-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var card = btn.closest('.menu-card');
                if (!card) { return; }
                var name = card.querySelector('.card-title').textContent.trim();
                var price = parseFloat(card.dataset.price) || 0;
                var category = card.dataset.categoryLabel || '';

                pendingAdd = { name: name, category: category, price: price };
                document.getElementById('addItemName').textContent = name;
                document.getElementById('addItemPrice').textContent = price > 0 ? peso(price) : 'Free';
                document.getElementById('addItemSpecial').value = '';
                document.getElementById('addItemQty').value = 1;
                addItemModal.show();
            });
        });

        document.getElementById('confirmAddItem').addEventListener('click', function () {
            if (!pendingAdd) { return; }
            var qty = Math.max(1, Math.min(20, parseInt(document.getElementById('addItemQty').value, 10) || 1));
            var specialRequest = document.getElementById('addItemSpecial').value.trim();
            var existing = order.filter(function (i) { return i.name === pendingAdd.name; })[0];

            if (existing) {
                existing.qty = Math.min(20, existing.qty + qty);
                if (specialRequest) { existing.specialRequest = specialRequest; }
            } else {
                order.push({
                    name: pendingAdd.name,
                    category: pendingAdd.category,
                    price: pendingAdd.price,
                    qty: qty,
                    specialRequest: specialRequest
                });
            }
            pendingAdd = null;
            addItemModal.hide();
            updateOrderBar();
            window.setTimeout(openOrderPopup, 280);
        });

        document.querySelector('.btn-request').addEventListener('click', openOrderPopup);
        document.getElementById('closeOrder').addEventListener('click', closeOrderPopup);
        document.getElementById('orderOverlay').addEventListener('click', closeOrderPopup);
        document.getElementById('confirmOrderBtn').addEventListener('click', submitFoodOrder);

        document.addEventListener('click', function (e) {
            var plus = e.target.closest('.qty-plus[data-idx]');
            if (plus) {
                order[parseInt(plus.dataset.idx, 10)].qty = Math.min(20, order[parseInt(plus.dataset.idx, 10)].qty + 1);
                renderOrderPopup(); updateOrderBar(); return;
            }
            var minus = e.target.closest('.qty-minus[data-idx]');
            if (minus) {
                var mi = parseInt(minus.dataset.idx, 10);
                if (order[mi].qty > 1) { order[mi].qty--; } else { order.splice(mi, 1); }
                renderOrderPopup(); updateOrderBar(); return;
            }
            var remove = e.target.closest('.remove-item[data-idx]');
            if (remove) {
                order.splice(parseInt(remove.dataset.idx, 10), 1);
                renderOrderPopup(); updateOrderBar();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && document.getElementById('orderPopup').classList.contains('active')) {
                closeOrderPopup();
            }
        });

        function submitFoodOrder() {
            if (order.length === 0) {
                showMenuToast('Your order is empty.', 'warning');
                return;
            }

            var btn = document.getElementById('confirmOrderBtn');
            btn.disabled = true;
            btn.textContent = 'Submitting\u2026';

            var body = new URLSearchParams();
            body.append('items', JSON.stringify(order));

            fetch('submit_food_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data.success) {
                        order = [];
                        updateOrderBar();
                        closeOrderPopup();
                        document.getElementById('foodOrderConfirmationMessage').textContent =
                            data.message || 'Your order has been sent to the front desk.';
                        bootstrap.Modal.getOrCreateInstance(
                            document.getElementById('foodOrderConfirmation')
                        ).show();
                        showMenuToast(data.message, 'success');
                    } else {
                        showMenuToast(data.message || 'Could not submit order.', 'danger');
                    }
                })
                .catch(function () { showMenuToast('Network error. Please try again.', 'danger'); })
                .finally(function () {
                    btn.disabled = false;
                    btn.textContent = 'Confirm request';
                });
        }

        function showMenuToast(msg, type) {
            var toast = document.getElementById('menuToast');
            toast.className = 'toast align-items-center border-0 text-bg-' + type;
            document.getElementById('menuToastMsg').textContent = msg;
            new bootstrap.Toast(toast, { delay: 4000 }).show();
        }
    });

    // ── Past-order status filter ───────────────────────────────────────────
    var currentOrderFilter = 'all';
    document.querySelectorAll('.order-status-filter-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.order-status-filter-btn').forEach(function (b) {
                b.classList.remove('active');
                b.setAttribute('aria-pressed', 'false');
            });
            btn.classList.add('active');
            btn.setAttribute('aria-pressed', 'true');
            currentOrderFilter = btn.getAttribute('data-filter');
            filterOrderSummary();
        });
    });

    function filterOrderSummary() {
        var cards = document.querySelectorAll('#foodOrderHistoryContainer .order-summary-card');
        var visibleCount = 0;

        cards.forEach(function (card) {
            var status = card.getAttribute('data-order-status');
            var isVisible = (currentOrderFilter === 'all') || (status === currentOrderFilter);
            card.style.display = isVisible ? '' : 'none';
            if (isVisible) { visibleCount++; }
        });

        var container = document.getElementById('foodOrderHistoryContainer');
        if (!container) { return; }
        var noResultsMsg = container.querySelector('.no-results-msg');
        if (visibleCount === 0 && cards.length > 0) {
            if (!noResultsMsg) {
                noResultsMsg = document.createElement('p');
                noResultsMsg.className = 'text-muted no-results-msg';
                noResultsMsg.textContent = 'No orders match the selected filter.';
                container.appendChild(noResultsMsg);
            }
        } else if (noResultsMsg) {
            noResultsMsg.remove();
        }
    }
})();
</script>

<?php include __DIR__ . '/includes/review_prompt.php'; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
