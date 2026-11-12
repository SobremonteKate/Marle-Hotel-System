/* ==========================================================================
   Marle Guest Portal — the tour
   --------------------------------------------------------------------------
   Five spotlight steps over the real dashboard. Mobile is the primary canvas:
   the card is a bottom sheet and the target is scrolled into view, so a thumb
   can still reach the buttons.

   Runs once per login (the server clears the flag), and can be replayed from
   any [data-portal-tour] button.
   ========================================================================== */
(function () {
    'use strict';

    var config = window.MARLE_PORTAL_TOUR;
    if (!config || !Array.isArray(config.steps) || !config.steps.length) return;

    var words = config.i18n || {};
    function t(key, fallback) { return words[key] || fallback; }
    // The page's own wording is a sprintf pair ("%d of %d"), so the same string
    // serves both languages with the numbers filled in here.
    function counter(step, total) {
        return t('counter', '%d of %d').replace('%d', step).replace('%d', total);
    }

    var narrow = window.matchMedia('(max-width: 767.98px)');
    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var root = document.documentElement;

    var steps = [];
    var index = 0;
    var lastFocus = null;
    var overlay, spot, card, titleEl, textEl, countEl, nextBtn, backBtn, liveEl;

    /* --------------------------------------------------------------------
       Markup
       -------------------------------------------------------------------- */
    function build() {
        overlay = document.createElement('div');
        overlay.className = 'tour';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'tourTitle');
        overlay.hidden = true;

        spot = document.createElement('div');
        spot.className = 'tour__spot';

        card = document.createElement('div');
        card.className = 'tour__card';
        card.innerHTML =
            '<p class="tour__eyebrow"></p>' +
            '<p class="tour__count" aria-live="polite"></p>' +
            '<h2 class="tour__title" id="tourTitle"></h2>' +
            '<p class="tour__text"></p>' +
            '<div class="tour__actions">' +
                '<button type="button" class="tour__skip" data-tour-skip></button>' +
                '<div class="tour__nav">' +
                    '<button type="button" class="tour__back" data-tour-back></button>' +
                    '<button type="button" class="tour__next" data-tour-next></button>' +
                '</div>' +
            '</div>' +
            '<p class="tour__live" data-tour-live aria-live="assertive"></p>';

        // The stylesheet anchors the card to the bottom on a phone; placement is
        // computed here instead, so the two must not both apply (top + bottom
        // would stretch the card to fill the gap between them).
        card.style.bottom = 'auto';
        card.style.right = 'auto';

        overlay.appendChild(spot);
        overlay.appendChild(card);
        document.body.appendChild(overlay);

        titleEl = card.querySelector('.tour__title');
        textEl = card.querySelector('.tour__text');
        countEl = card.querySelector('.tour__count');
        backBtn = card.querySelector('[data-tour-back]');
        nextBtn = card.querySelector('[data-tour-next]');
        liveEl = card.querySelector('[data-tour-live]');

        card.querySelector('.tour__eyebrow').textContent = t('eyebrow', 'Quick tour');
        card.querySelector('[data-tour-skip]').textContent = t('skip', 'Skip');
        backBtn.textContent = t('back', 'Back');

        overlay.addEventListener('click', function (event) {
            var target = event.target;
            if (target.closest('[data-tour-skip]')) return finish();
            if (target.closest('[data-tour-back]')) return go(index - 1);
            if (target.closest('[data-tour-next]')) return go(index + 1);
            if (target === overlay) return finish();
        });

        document.addEventListener('keydown', function (event) {
            if (overlay.hidden) return;
            if (event.key === 'Escape') { event.preventDefault(); finish(); }
            else if (event.key === 'ArrowRight') { event.preventDefault(); go(index + 1); }
            else if (event.key === 'ArrowLeft') { event.preventDefault(); go(index - 1); }
            else if (event.key === 'Tab') trapFocus(event);
        }, true);

        window.addEventListener('resize', reposition);
        window.addEventListener('scroll', reposition, { passive: true });
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

    /* --------------------------------------------------------------------
       Which steps actually have a target on this screen
       -------------------------------------------------------------------- */
    function collectSteps() {
        steps = config.steps.filter(function (step) {
            var sel = (!narrow.matches && step.selDesktop) ? step.selDesktop : step.sel;
            return document.querySelector(sel);
        });
    }

    /* --------------------------------------------------------------------
       Placement
       -------------------------------------------------------------------- */
    function reposition() {
        if (overlay.hidden) return;
        var step = steps[index];
        if (!step) return;
        var sel = (!narrow.matches && step.selDesktop) ? step.selDesktop : step.sel;
        var target = document.querySelector(sel);
        if (!target) return;

        var pad = narrow.matches ? 8 : 10;
        var rect = target.getBoundingClientRect();
        var box = {
            top: rect.top - pad,
            left: rect.left - pad,
            width: rect.width + pad * 2,
            height: rect.height + pad * 2
        };

        spot.style.top = box.top + 'px';
        spot.style.left = box.left + 'px';
        spot.style.width = box.width + 'px';
        spot.style.height = box.height + 'px';

        var vw = window.innerWidth;
        var vh = window.innerHeight;
        var margin = narrow.matches ? 12 : 16;
        var gap = 14;
        var cardW = narrow.matches ? vw - margin * 2 : Math.min(360, vw - margin * 2);
        card.style.width = cardW + 'px';
        var cardH = card.offsetHeight || 200;

        var left;
        var top;

        if (narrow.matches) {
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
            if (roomBelow >= cardH) top = box.top + box.height + gap;
            else if (roomAbove >= cardH) top = box.top - cardH - gap;
            else {
                left = box.left + box.width + gap + cardW <= vw - margin
                    ? box.left + box.width + gap
                    : Math.max(margin, box.left - cardW - gap);
                top = Math.min(Math.max(box.top, margin), vh - cardH - margin);
            }
        }

        card.style.left = Math.round(left) + 'px';
        card.style.top = Math.round(top) + 'px';
    }

    /* --------------------------------------------------------------------
       Flow
       -------------------------------------------------------------------- */
    function go(next) {
        if (next < 0) next = 0;
        if (next >= steps.length) return finish();

        index = next;
        var step = steps[index];
        var sel = (!narrow.matches && step.selDesktop) ? step.selDesktop : step.sel;
        var target = document.querySelector(sel);
        if (!target) { return go(next + 1); }

        if (index === 0) {
            lastFocus = document.activeElement;
            overlay.hidden = false;
            root.classList.add('tour-open');
            // The review prompt yields to the tour the same way it yields to chat.
            document.dispatchEvent(new CustomEvent('marle:tour-open'));
        }

        titleEl.textContent = step.title;
        textEl.textContent = step.text;
        countEl.textContent = counter(index + 1, steps.length);
        backBtn.disabled = index === 0;
        nextBtn.textContent = index === steps.length - 1 ? t('done', 'Done') : t('next', 'Next');
        if (liveEl) liveEl.textContent = step.title + ' — ' + counter(index + 1, steps.length);

        var rect = target.getBoundingClientRect();
        var offscreen = rect.top < 70 || rect.bottom > window.innerHeight - 90;
        if (offscreen) {
            target.scrollIntoView({
                block: 'center',
                behavior: reduced.matches ? 'auto' : 'smooth'
            });
            window.setTimeout(reposition, reduced.matches ? 0 : 320);
        }

        reposition();
        nextBtn.focus({ preventScroll: true });
    }

    function finish() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        root.classList.remove('tour-open');
        document.dispatchEvent(new CustomEvent('marle:tour-end'));
        steps.length = 0;
        if (lastFocus && lastFocus.focus) lastFocus.focus({ preventScroll: true });
        lastFocus = null;
    }

    function start() {
        collectSteps();
        if (!steps.length) return;
        if (!overlay) build();
        go(0);
    }

    /* --------------------------------------------------------------------
       Boot
       -------------------------------------------------------------------- */
    function boot() {
        var triggers = document.querySelectorAll('[data-portal-tour]');
        Array.prototype.forEach.call(triggers, function (button) {
            button.addEventListener('click', function (event) {
                event.preventDefault();
                start();
            });
        });

        if (config.pending) {
            window.setTimeout(start, 900);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}());
