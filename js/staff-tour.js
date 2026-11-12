/* ==========================================================================
   Marle staff console — the tour
   --------------------------------------------------------------------------
   A spotlight walkthrough over the real screen: the rail, the desk strip, the
   live request queue, the language switch, the notification/profile pair and
   the footer. Six steps on a desktop, fewer on a phone, because a step whose
   target is inside the collapsed menu is dropped rather than pointed at an
   off-screen element.

   It is a dialog: focus moves into the card, Tab cycles inside it, Escape and
   the dimmed area close it, and arrow keys step. The dim is a shadow cast by
   the hole, so the lit target keeps its real colours.

   Runs itself once per browser and role on the dashboard; the Tour button in
   the rail replays it on any screen, and ?tour=1 forces it for a demo.
   ========================================================================== */
(function () {
    'use strict';

    var config = window.MARLE_STAFF_TOUR;
    if (!config || !Array.isArray(config.steps) || !config.steps.length) return;

    var words = config.i18n || {};
    function t(key, fallback) { return words[key] || fallback; }
    function fill(template, a, b) {
        return String(template).replace('%d', a).replace('%d', b);
    }

    var narrow = window.matchMedia('(max-width: 767.98px)');
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var root = document.documentElement;
    var storageKey = 'marle.staffTour.v1.' + (config.role || 'staff');

    var steps = [];
    var index = 0;
    var lastFocus = null;
    var lastSpot = null;
    var overlay, spot, card, titleEl, textEl, countEl, dotsEl, nextBtn, backBtn, liveEl;

    /* ------------------------------------------------------------------
       Which steps this screen and this viewport can actually show
       ------------------------------------------------------------------ */
    function selectorFor(step) {
        return (!narrow.matches && step.selDesktop) ? step.selDesktop : step.sel;
    }

    function targetFor(step) {
        var sel = selectorFor(step);
        return sel ? document.querySelector(sel) : null;
    }

    function collect() {
        steps = config.steps.filter(function (step) { return !!targetFor(step); });
    }

    function seen() {
        try { return window.localStorage.getItem(storageKey) !== null; } catch (err) { return true; }
    }

    function remember() {
        try { window.localStorage.setItem(storageKey, String(config.version || 1)); } catch (err) { /* private mode */ }
    }

    /* ------------------------------------------------------------------
       Markup
       ------------------------------------------------------------------ */
    function build() {
        overlay = document.createElement('div');
        overlay.className = 'staff-tour';
        overlay.hidden = true;
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'staffTourTitle');

        spot = document.createElement('div');
        spot.className = 'staff-tour__spot';
        spot.setAttribute('aria-hidden', 'true');

        card = document.createElement('div');
        card.className = 'staff-tour__card';
        card.innerHTML =
            '<button type="button" class="staff-tour__close" data-tour-close aria-label="' + t('close', 'Close the tour') + '">&times;</button>' +
            '<p class="staff-tour__eyebrow">' + t('eyebrow', 'Quick tour') + '</p>' +
            '<h2 class="staff-tour__title" id="staffTourTitle"></h2>' +
            '<p class="staff-tour__text"></p>' +
            '<div class="staff-tour__foot">' +
                '<div class="staff-tour__progress">' +
                    '<span class="staff-tour__dots" aria-hidden="true"></span>' +
                    '<span class="staff-tour__count" aria-hidden="true"></span>' +
                '</div>' +
                '<div class="staff-tour__actions">' +
                    '<button type="button" class="staff-tour__skip" data-tour-skip>' + t('skip', 'Skip tour') + '</button>' +
                    '<div class="staff-tour__nav">' +
                        '<button type="button" class="staff-tour__back" data-tour-back>' + t('back', 'Back') + '</button>' +
                        '<button type="button" class="staff-tour__next" data-tour-next>' + t('next', 'Next') + '</button>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<p class="staff-tour__live" aria-live="polite"></p>';

        overlay.appendChild(spot);
        overlay.appendChild(card);
        document.body.appendChild(overlay);

        titleEl = card.querySelector('.staff-tour__title');
        textEl = card.querySelector('.staff-tour__text');
        countEl = card.querySelector('.staff-tour__count');
        dotsEl = card.querySelector('.staff-tour__dots');
        nextBtn = card.querySelector('[data-tour-next]');
        backBtn = card.querySelector('[data-tour-back]');
        liveEl = card.querySelector('.staff-tour__live');

        overlay.addEventListener('click', function (event) {
            var target = event.target;
            if (!target || !target.closest) return;
            if (target.closest('[data-tour-close]') || target.closest('[data-tour-skip]')) return finish();
            if (target.closest('[data-tour-back]')) return go(index - 1);
            if (target.closest('[data-tour-next]')) return go(index + 1);
            if (event.target === overlay) return finish();
        });

        // The page must not slide around underneath a modal spotlight.
        overlay.addEventListener('wheel', function (event) { event.preventDefault(); }, { passive: false });
        overlay.addEventListener('touchmove', function (event) { event.preventDefault(); }, { passive: false });

        document.addEventListener('keydown', function (event) {
            if (overlay.hidden) return;
            if (event.key === 'Escape') { event.preventDefault(); finish(); }
            else if (event.key === 'ArrowRight') { event.preventDefault(); go(index + 1); }
            else if (event.key === 'ArrowLeft') { event.preventDefault(); go(index - 1); }
            else if (event.key === 'Tab') trapFocus(event);
        }, true);

        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', onScroll, { passive: true });
    }

    function trapFocus(event) {
        var focusables = card.querySelectorAll('button:not([disabled])');
        if (!focusables.length) return;
        var first = focusables[0];
        var last = focusables[focusables.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    /* ------------------------------------------------------------------
       Placement
       ------------------------------------------------------------------ */
    var frame = 0;
    function onScroll() {
        if (!overlay || overlay.hidden) return;
        if (frame) return;
        frame = window.requestAnimationFrame(function () {
            frame = 0;
            reposition();
        });
    }

    function reposition() {
        if (!overlay || overlay.hidden) return;
        var step = steps[index];
        var target = step && targetFor(step);
        if (!target) return;

        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var pad = narrow.matches ? 8 : 10;
        var rect = target.getBoundingClientRect();
        var box = {
            top: rect.top - pad,
            left: rect.left - pad,
            width: Math.max(rect.width + pad * 2, 12),
            height: Math.max(rect.height + pad * 2, 12)
        };

        // Keep the hole on screen: the rail sits flush to the left edge and
        // the footer to the right, and a border hanging outside the viewport
        // reads as a mistake.
        if (box.left < 0) { box.width += box.left; box.left = 0; }
        if (box.top < 0) { box.height += box.top; box.top = 0; }
        if (box.left + box.width > vw) box.width = vw - box.left;
        if (box.top + box.height > vh) box.height = vh - box.top;

        // A 220ms slide from the rail to the footer looks like a glitch; move
        // instantly when the new hole is nowhere near the old one.
        if (!reduced.matches && lastSpot &&
            (Math.abs(lastSpot.top - box.top) > 420 || Math.abs(lastSpot.left - box.left) > 420)) {
            spot.style.transition = 'none';
            window.setTimeout(function () { spot.style.transition = ''; }, 60);
        }
        lastSpot = { top: box.top, left: box.left };

        spot.style.top = box.top + 'px';
        spot.style.left = box.left + 'px';
        spot.style.width = box.width + 'px';
        spot.style.height = box.height + 'px';

        var margin = narrow.matches ? 12 : 16;
        var gap = narrow.matches ? 12 : 16;
        var cardW = narrow.matches ? vw - margin * 2 : Math.min(376, vw - margin * 2);
        card.style.width = cardW + 'px';
        var cardH = card.offsetHeight || 220;
        var left;
        var top;
        var inRail = !narrow.matches && box.left < 280 && box.left + box.width < 320;

        if (inRail) {
            // A target in the rail: sit beside it, level with the target — not
            // on top of the navigation the step is explaining.
            left = box.left + box.width + gap;
            top = Math.min(Math.max(box.top + box.height / 2 - cardH / 2, margin), vh - cardH - margin);
        } else if (narrow.matches) {
            left = margin;
            var below = box.top + box.height + gap;
            var above = box.top - gap - cardH;
            if (below + cardH <= vh - margin) top = below;
            else if (above >= margin) top = above;
            else top = Math.max(margin, vh - cardH - margin);
        } else {
            left = Math.min(Math.max(box.left, margin), vw - cardW - margin);
            var roomBelow = vh - (box.top + box.height) - gap - margin;
            var roomAbove = box.top - gap - margin;
            if (roomBelow >= cardH) {
                top = box.top + box.height + gap;
            } else if (roomAbove >= cardH) {
                top = box.top - cardH - gap;
            } else {
                left = box.left + box.width + gap + cardW <= vw - margin
                    ? box.left + box.width + gap
                    : Math.max(margin, box.left - cardW - gap);
                top = Math.min(Math.max(box.top, margin), vh - cardH - margin);
            }
        }

        card.style.left = Math.round(left) + 'px';
        card.style.top = Math.round(top) + 'px';
    }

    /* ------------------------------------------------------------------
       Flow
       ------------------------------------------------------------------ */
    function renderDots() {
        dotsEl.textContent = '';
        for (var i = 0; i < steps.length; i++) {
            var dot = document.createElement('span');
            dot.className = 'staff-tour__dot' + (i === index ? ' is-on' : '');
            dotsEl.appendChild(dot);
        }
    }

    function go(next) {
        if (next < 0) next = 0;
        if (next >= steps.length) return finish();

        var step = steps[next];
        var target = targetFor(step);
        if (!target) {
            // A breakpoint change can remove the current target; drop the step
            // rather than spotlighting nothing.
            steps.splice(next, 1);
            if (!steps.length) return finish();
            return go(Math.min(next, steps.length - 1));
        }

        var first = (next === 0);
        index = next;

        if (first && overlay.hidden) {
            lastFocus = document.activeElement;
            overlay.hidden = false;
        }

        titleEl.textContent = step.title;
        textEl.textContent = step.text;
        countEl.textContent = fill(t('counter', '%d of %d'), index + 1, steps.length);
        backBtn.disabled = index === 0;
        nextBtn.textContent = index === steps.length - 1 ? t('done', 'Done') : t('next', 'Next');
        renderDots();
        liveEl.textContent = fill(t('step', 'Step %d of %d'), index + 1, steps.length) + ' — ' + step.title;

        var rect = target.getBoundingClientRect();
        var offscreen = rect.top < 80 || rect.bottom > window.innerHeight - 100;
        if (offscreen) {
            target.scrollIntoView({
                block: 'center',
                behavior: reduced.matches ? 'auto' : 'smooth'
            });
            window.setTimeout(reposition, reduced.matches ? 0 : 340);
        }

        reposition();
        // Position first, then focus: focusing an off-screen node can scroll
        // the page behind the spotlight.
        nextBtn.focus({ preventScroll: true });
    }

    function finish() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        lastSpot = null;
        if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
        lastFocus = null;
    }

    function start() {
        if (!overlay) build();
        collect();
        if (!steps.length) return;
        remember();
        go(0);
    }

    /* ------------------------------------------------------------------
       Boot
       ------------------------------------------------------------------ */
    function boot() {
        root.classList.add('staff-tour-ready');

        document.addEventListener('click', function (event) {
            var trigger = event.target && event.target.closest ? event.target.closest('[data-staff-tour]') : null;
            if (!trigger) return;
            event.preventDefault();
            // Starting from the replay button inside a phone drawer: close the
            // drawer first or the spotlight lands on a menu covering the page.
            var openMenu = document.getElementById('sidebarMenu');
            if (openMenu && openMenu.classList.contains('show')) {
                if (window.bootstrap && window.bootstrap.Collapse) {
                    window.bootstrap.Collapse.getOrCreateInstance(openMenu, { toggle: false }).hide();
                } else {
                    openMenu.classList.remove('show');
                }
                window.setTimeout(start, 320);
            } else {
                start();
            }
        });

        // The tour follows the viewport across the breakpoint, but only while
        // it is open — a resize on a normal screen changes nothing.
        if (narrow.addEventListener) {
            narrow.addEventListener('change', function () {
                if (!overlay || overlay.hidden) return;
                collect();
                if (!steps.length) return finish();
                if (index >= steps.length) index = steps.length - 1;
                go(index);
            });
        }

        var forced = /(?:^|[?&])tour=1(?:&|$)/.test(window.location.search);
        if (forced) {
            window.setTimeout(start, 350);
        } else if (config.auto && !seen()) {
            window.setTimeout(function () {
                if (document.visibilityState !== 'visible') return;
                if (document.querySelector('.modal.show')) return; // never interrupt a dialog
                start();
            }, 1100);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
