// ── Real-time notification polling ──
// Updates bell badge, dropdown content, and sidebar request badge
(function () {
    const POLL_INTERVAL = 5000; // 5 seconds
    const POLL_API_URL = resolveNotifApiUrl();

    function resolveNotifApiUrl() {
        var script = document.currentScript || document.querySelector('script[src*="superadmin/includes/notif_poll.js"]');
        if (script && script.src) {
            return new URL('notif_count_api.php', script.src).toString();
        }

        var roleMatch = location.pathname.match(/\/(admin|employee|superadmin)\//);
        var roleBase = roleMatch ? roleMatch[0].replace(/\/$/, '') : '/superadmin';
        return roleBase + '/includes/notif_count_api.php';
    }

    function updateNotifications() {
        fetch(POLL_API_URL, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (r) {
            if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
            }
            return r.json();
        })
        .then(function (data) {
            // Validate response structure
            if (!data || typeof data !== 'object') {
                console.warn('Invalid notification response:', data);
                return;
            }

            var total = data.total || 0;

            // ── Bell badge count ──
            var badge = document.getElementById('notifBadgeCount');
            if (badge) {
                badge.textContent = total;
                if (total > 0) {
                    badge.classList.remove('d-none');
                } else {
                    badge.classList.add('d-none');
                }
            }

            // ── Dropdown content (full HTML refresh) ──
            var menu = document.querySelector('.notif-dropdown-menu');
            if (menu && data.dropdownHtml) {
                menu.innerHTML = data.dropdownHtml;
            }

            // ── Sidebar "Requests" badge ──
            var sidebarBadges = document.querySelectorAll('.notification-badge');
            sidebarBadges.forEach(function (el) {
                var sidebarCount = data.sidebarBadge || 0;
                el.textContent = sidebarCount;
                if (sidebarCount > 0) {
                    el.classList.add('pulse');
                } else {
                    el.classList.remove('pulse');
                }
            });
        })
        .catch(function (err) {
            console.error('Notification poll failed:', err);
        });
    }

    // Run immediately on load, then poll
    updateNotifications();
    setInterval(updateNotifications, POLL_INTERVAL);
})();
