<?php
/**
 * requests_pagination.php — 15-per-page pagination for the Requests lists
 * (Extensions, Services, Food, Bookings), shared by all three staff roles.
 *
 * Why client-side: these tabs already filter themselves in the browser (the
 * status dropdowns and the sidebar search toggle a card's inline `display`).
 * Paginating on the server would leave those filters operating on one page at a
 * time — "Denied" would show only the denials that happened to be in the loaded
 * page. So the whole list still renders, and this layer only decides which of
 * the *currently visible* cards are on screen: filtering first, then paging.
 *
 * The two concerns are kept apart on purpose:
 *   · filters keep writing `element.style.display` ('' or 'none')
 *   · paging writes only the `req-page-hidden` class
 * so a re-filter never has to reason about which page is showing, and a page
 * change never has to re-run a filter.
 *
 * Usage, once per page, after every other script:
 *   <?php marle_requests_pagination_assets(); ?>
 */

if (!function_exists('marle_requests_pagination_assets')) {
    function marle_requests_pagination_assets(): void
    {
        ?>
    <style>
    .marle-req-pager {
        margin-top: 1rem;
        padding-top: 0.85rem;
        border-top: 1px solid rgba(147, 51, 16, 0.12);
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.6rem;
    }

    .marle-req-pager .pagination { margin: 0; flex-wrap: wrap; gap: 2px; }

    .marle-req-pager .page-link {
        color: #7a4a1d;
        border-radius: 8px;
        border-color: #eadccf;
        font-weight: 600;
    }

    .marle-req-pager .page-item.active .page-link {
        background: #ffb60a;
        border-color: #ffb60a;
        color: #3a2413;
    }

    .marle-req-pager .page-item.disabled .page-link { color: #b3a79c; }

    .marle-req-pager .marle-req-count {
        font-size: 0.85rem;
        color: #6f5c50;
        font-weight: 600;
    }

    .marle-req-pager .marle-req-perpage {
        font-size: 0.78rem;
        color: #8a7a6d;
    }

    /* Paging wins over the inline `display` the status filters set, so a card
       kept out of the current page stays hidden even after a re-filter. */
    .req-page-hidden { display: none !important; }

    /* Hide the pager itself while a search or filter leaves nothing to page. */
    .marle-req-pager[hidden] { display: none !important; }
    </style>
    <script>
    (function () {
        var PER_PAGE = 15;

        // Each group is one tab pane plus the card class it renders.
        var GROUPS = [
            { pane: 'tabExtensions', card: 'ext-card', filters: ['extStatusFilter'] },
            { pane: 'tabServices',   card: 'svc-card', filters: ['svcStatusFilter'] },
            { pane: 'tabFood',       card: 'svc-card', filters: ['foodStatusFilter'] },
            { pane: 'tabBookings',   card: 'svc-card', filters: ['bookStatusFilter'] }
        ];

        var pageByPane = {};
        var refreshTimer = null;

        function paneGroup(el) {
            for (var i = 0; i < GROUPS.length; i++) {
                if (el && el.closest && el.closest('#' + GROUPS[i].pane)) return GROUPS[i];
            }
            return null;
        }

        function directCards(pane, cardClass) {
            var out = [];
            for (var i = 0; i < pane.children.length; i++) {
                if (pane.children[i].classList.contains(cardClass)) out.push(pane.children[i]);
            }
            return out;
        }

        function buildPager(pane) {
            var bar = document.createElement('div');
            bar.className = 'marle-req-pager';
            bar.dataset.pagerFor = pane.id;

            var count = document.createElement('span');
            count.className = 'marle-req-count';

            var nav = document.createElement('nav');
            var ul = document.createElement('ul');
            ul.className = 'pagination pagination-sm';
            nav.appendChild(ul);

            var perPage = document.createElement('span');
            perPage.className = 'marle-req-perpage';
            perPage.textContent = PER_PAGE + ' per page';

            bar.appendChild(count);
            bar.appendChild(nav);
            bar.appendChild(perPage);
            pane.appendChild(bar);
            return { bar: bar, count: count, ul: ul };
        }

        function makeItem(label, page, disabled, active, onClick) {
            var li = document.createElement('li');
            li.className = 'page-item' + (disabled ? ' disabled' : '') + (active ? ' active' : '');

            var a = document.createElement('a');
            a.className = 'page-link';
            a.href = '#';
            a.innerHTML = label;
            if (disabled) {
                a.setAttribute('aria-disabled', 'true');
                a.tabIndex = -1;
            } else {
                a.addEventListener('click', function (e) {
                    e.preventDefault();
                    onClick(page);
                });
            }

            li.appendChild(a);
            return li;
        }

        /* A short window of page numbers with ellipses, so a 200-card list does
           not turn the footer into a wall of buttons. */
        function pageNumbers(current, total) {
            var pages = [];
            var i;
            for (i = 1; i <= total; i++) {
                if (i === 1 || i === total || Math.abs(i - current) <= 2) pages.push(i);
            }
            var withGaps = [];
            var prev = 0;
            pages.forEach(function (p) {
                if (prev && p - prev > 1) withGaps.push('…');
                withGaps.push(p);
                prev = p;
            });
            return withGaps;
        }

        function effectivePage(pane, cardClass) {
            var cards = directCards(pane, cardClass);
            var eligible = cards.filter(function (c) { return c.style.display !== 'none'; });
            return { cards: cards, eligible: eligible };
        }

        function refresh(paneId) {
            var group = null;
            for (var i = 0; i < GROUPS.length; i++) {
                if (GROUPS[i].pane === paneId) group = GROUPS[i];
            }
            if (!group) return;

            var pane = document.getElementById(paneId);
            if (!pane) return;

            var state = effectivePage(pane, group.card);
            var total = state.eligible.length;
            var pages = Math.max(1, Math.ceil(total / PER_PAGE));
            var page  = Math.min(Math.max(1, pageByPane[paneId] || 1), pages);
            pageByPane[paneId] = page;

            // Hide everything, then reveal only the current page's slice. Cards
            // the filter hid are untouched — the class simply sits unused on them.
            state.cards.forEach(function (c) { c.classList.add('req-page-hidden'); });
            state.eligible.slice((page - 1) * PER_PAGE, page * PER_PAGE).forEach(function (c) {
                c.classList.remove('req-page-hidden');
            });

            var pager = pane.querySelector('.marle-req-pager');
            if (!pager) return;

            var count = pager.querySelector('.marle-req-count');
            var ul    = pager.querySelector('.pagination');

            var first = total === 0 ? 0 : (page - 1) * PER_PAGE + 1;
            var last  = Math.min(page * PER_PAGE, total);
            count.textContent = total === 0
                ? 'No records match the current filter.'
                : 'Showing ' + first + '–' + last + ' of ' + total;

            ul.innerHTML = '';

            if (pages > 1) {
                ul.appendChild(makeItem('&lsaquo;', page - 1, page <= 1, false, function (p) {
                    pageByPane[paneId] = p; refresh(paneId);
                }));
                pageNumbers(page, pages).forEach(function (p) {
                    if (p === '…') {
                        var li = document.createElement('li');
                        li.className = 'page-item disabled';
                        li.innerHTML = '<span class="page-link">…</span>';
                        ul.appendChild(li);
                        return;
                    }
                    ul.appendChild(makeItem(String(p), p, false, p === page, function (np) {
                        pageByPane[paneId] = np; refresh(paneId);
                    }));
                });
                ul.appendChild(makeItem('&rsaquo;', page + 1, page >= pages, false, function (p) {
                    pageByPane[paneId] = p; refresh(paneId);
                }));
            }
        }

        function refreshAll() {
            GROUPS.forEach(function (g) {
                if (document.getElementById(g.pane)) refresh(g.pane);
            });
        }

        function refreshSoon() {
            clearTimeout(refreshTimer);
            refreshTimer = setTimeout(refreshAll, 0);
        }

        document.addEventListener('DOMContentLoaded', function () {
            GROUPS.forEach(function (g) {
                var pane = document.getElementById(g.pane);
                if (!pane || pane.querySelector('.marle-req-pager')) return;
                buildPager(pane);
            });

            refreshAll();

            // Filter dropdowns set display inline; refresh after their own
            // handler has run (element handlers run before this document-level
            // listener), so paging always sees the freshly filtered set.
            document.addEventListener('change', function (e) {
                if (paneGroup(e.target)) refreshSoon();
            });

            document.addEventListener('input', function (e) {
                if (e.target && (e.target.id === 'myInput' || paneGroup(e.target))) refreshSoon();
            });

            // Bootstrap tab switches re-show a pane; re-apply that pane's page.
            document.addEventListener('shown.bs.tab', function (e) {
                var target = e.target && e.target.getAttribute('data-bs-target');
                if (target) refresh(target.replace('#', ''));
            });

            // Approve/deny/archive handlers rewrite cards without a page reload.
            window.marleRequestsPaginationRefresh = refreshSoon;
        });
    })();
    </script>
        <?php
    }
}
