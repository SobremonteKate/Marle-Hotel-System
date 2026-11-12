/* ==========================================================================
   Marle Hotel — arrival, tour and help
   --------------------------------------------------------------------------
   Three small pieces, one file, no dependencies:

     1. the entry screen (once per session, 2.2s, skippable)
     2. the five-step spotlight tour over the real homepage
     3. the FAQ concierge

   Everything is progressive. Each element ships `hidden` or is built here, so
   a script failure leaves the ordinary page. No Date(), no toLocaleString():
   clock_hygiene_checks.php scans this file.
   ========================================================================== */

(function () {
    'use strict';

    var reduceMotion = false;
    try {
        reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (err) { /* very old browser: keep motion */ }

    function each(list, fn) {
        Array.prototype.forEach.call(list || [], fn);
    }

    function store(kind, key, value) {
        try {
            var s = kind === 'local' ? window.localStorage : window.sessionStorage;
            if (value === undefined) { return s.getItem(key); }
            s.setItem(key, value);
        } catch (err) { /* private mode: treat as empty */ }
        return null;
    }

    function focusQuietly(el) {
        if (!el || typeof el.focus !== 'function') { return; }
        try { el.focus({ preventScroll: true }); } catch (err) { el.focus(); }
    }

    function now() {
        return (window.performance && typeof window.performance.now === 'function')
            ? window.performance.now()
            : 0;
    }

    /* ======================================================================
       1. Entry screen
       ====================================================================== */

    var entry = document.querySelector('[data-entry]');
    var entryPanel = entry ? entry.querySelector('.entry__panel') : null;
    var entryEnter = entry ? entry.querySelector('[data-entry-enter]') : null;
    var entryTour = entry ? entry.querySelector('[data-entry-tour]') : null;
    var entryRoot = document.documentElement;
    var ENTRY_MS = 2200;
    var entryLeft = ENTRY_MS;
    var entryBegan = 0;
    var entryTimer = null;
    var entryDone = false;

    function entryVisible() {
        return !!entry && !entry.hidden;
    }

    function startEntryClock(ms) {
        entryLeft = ms;
        entryBegan = now();
        if (entryTimer) { window.clearTimeout(entryTimer); }
        entryTimer = window.setTimeout(function () { dismissEntry('timer'); }, ms);
    }

    function pauseEntry() {
        if (!entryVisible() || !entryTimer) { return; }
        window.clearTimeout(entryTimer);
        entryTimer = null;
        if (entryBegan) {
            entryLeft = Math.max(600, entryLeft - (now() - entryBegan));
        }
        if (entryPanel) { entryPanel.classList.add('is-paused'); }
    }

    function resumeEntry() {
        if (!entryVisible() || entryTimer) { return; }
        if (entryPanel) { entryPanel.classList.remove('is-paused'); }
        startEntryClock(entryLeft);
    }

    function dismissEntry(action) {
        if (!entryVisible() || entryDone) { return; }
        entryDone = true;
        if (entryTimer) { window.clearTimeout(entryTimer); entryTimer = null; }
        entry.hidden = true;
        entryRoot.classList.remove('entry-open');
        store('session', 'marleEntry', '1');

        if (action === 'tour') {
            startTour(true);
            return;
        }

        focusQuietly(document.getElementById('main'));

        // A first-ever visitor who has just come through the door gets shown
        // around once. Skipping (Escape, a click outside) is a decision, so it
        // is respected.
        if ((action === 'enter' || action === 'timer')
            && !reduceMotion
            && !window.location.hash
            && store('local', 'marleTour') !== '1') {
            startTour(true);
        }
    }

    function shouldShowEntry() {
        if (!entry) { return false; }
        if (reduceMotion) { return false; }
        if (store('session', 'marleEntry') === '1') { return false; }
        // Any deep link means the visitor asked for a place, not the door —
        // including the footer's home.php#tour, which opens the tour itself.
        if (window.location.hash) { return false; }
        if (/[?&]nointro=1\b/.test(window.location.search)) { return false; }
        return true;
    }

    if (entry) {
        if (shouldShowEntry()) {
            entry.hidden = false;
            entryRoot.classList.add('entry-open');
            startEntryClock(ENTRY_MS);
            if (entryPanel) { focusQuietly(entryPanel); }

            entryEnter.addEventListener('click', function () { dismissEntry('enter'); });
            entryTour.addEventListener('click', function () { dismissEntry('tour'); });

            entry.addEventListener('click', function (event) {
                if (event.target === entry) { dismissEntry('skip'); }
            });

            // Reading pauses the clock. The listeners sit on the panel, not on
            // the full-screen overlay: a pointer resting anywhere on the page
            // must never be able to freeze the door shut.
            if (entryPanel) {
                entryPanel.addEventListener('mouseenter', pauseEntry);
                entryPanel.addEventListener('mouseleave', resumeEntry);
            }
            entry.addEventListener('focusin', function (event) {
                if (event.target.closest && event.target.closest('button, a')) { pauseEntry(); }
            });
            entry.addEventListener('focusout', function (event) {
                if (!entry.contains(event.relatedTarget)) { resumeEntry(); }
            });

            document.addEventListener('keydown', function (event) {
                if (!entryVisible()) { return; }
                var onControl = event.target.closest && event.target.closest('button, a');
                if (event.key === 'Escape') {
                    event.preventDefault();
                    dismissEntry('skip');
                } else if (event.key === 'Enter' && !onControl) {
                    event.preventDefault();
                    // The tour listens on the same document and would treat this
                    // very keypress as "Next", skipping step one.
                    event.stopImmediatePropagation();
                    dismissEntry('enter');
                } else if (event.key === 'Tab' && entryPanel) {
                    var focusable = entryPanel.querySelectorAll('button, a[href]');
                    if (!focusable.length) { return; }
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) {
                        event.preventDefault();
                        last.focus();
                    } else if (!event.shiftKey && document.activeElement === last) {
                        event.preventDefault();
                        first.focus();
                    }
                }
            });

            window.addEventListener('scroll', function () { dismissEntry('skip'); }, { passive: true, once: true });
            window.addEventListener('wheel', function () { dismissEntry('skip'); }, { passive: true, once: true });
            window.addEventListener('touchmove', function () { dismissEntry('skip'); }, { passive: true, once: true });
        } else {
            entry.hidden = true;
        }
    }

    /* ======================================================================
       2. Tour
       ====================================================================== */

    var tourData = Array.isArray(window.MARLE_TOUR) ? window.MARLE_TOUR : [];
    var tour = null;
    var tourSpot = null;
    var tourCard = null;
    var tourTitle = null;
    var tourBody = null;
    var tourCount = null;
    var tourBack = null;
    var tourNext = null;
    var tourLive = null;
    var tourIndex = 0;
    var tourTrigger = null;
    var tourSteps = [];
    var tourStarted = false;

    function buildTour() {
        tour = document.createElement('div');
        tour.className = 'tour';
        tour.setAttribute('role', 'dialog');
        tour.setAttribute('aria-modal', 'true');
        tour.setAttribute('aria-labelledby', 'tourTitle');
        tour.innerHTML = ''
            + '<div class="tour__spot" aria-hidden="true"></div>'
            + '<div class="tour__card">'
            + '  <p class="tour__eyebrow"><span class="tour__count"></span> &middot; <span data-tour-label>Website tour</span></p>'
            + '  <h2 class="tour__title" id="tourTitle"></h2>'
            + '  <p class="tour__text"></p>'
            + '  <div class="tour__actions">'
            + '    <button type="button" class="tour__skip" data-tour-skip>Skip tour</button>'
            + '    <div class="tour__nav">'
            + '      <button type="button" class="tour__back" data-tour-back>Back</button>'
            + '      <button type="button" class="tour__next" data-tour-next>Next</button>'
            + '    </div>'
            + '  </div>'
            + '  <p class="tour__live" aria-live="polite"></p>'
            + '</div>';
        document.body.appendChild(tour);

        tourSpot = tour.querySelector('.tour__spot');
        tourCard = tour.querySelector('.tour__card');
        tourTitle = tour.querySelector('.tour__title');
        tourBody = tour.querySelector('.tour__text');
        tourCount = tour.querySelector('.tour__count');
        tourBack = tour.querySelector('[data-tour-back]');
        tourNext = tour.querySelector('[data-tour-next]');
        tourLive = tour.querySelector('.tour__live');

        tour.querySelector('[data-tour-skip]').addEventListener('click', function () { endTour(); });
        tourBack.addEventListener('click', function () { goTour(-1); });
        tourNext.addEventListener('click', function () {
            if (tourIndex >= tourSteps.length - 1) { endTour(); } else { goTour(1); }
        });

        window.addEventListener('resize', placeTour);
        window.addEventListener('scroll', placeTour, { passive: true });
    }

    function collectTourSteps() {
        tourSteps = [];
        each(tourData, function (step) {
            var el = document.querySelector(step.sel || '');
            if (el) {
                tourSteps.push({ el: el, title: step.title || '', text: step.text || '' });
            }
        });
    }

    function startTour(manual) {
        if (tourStarted || !tourData.length) { return; }
        if (entryVisible()) { entry.hidden = true; entryRoot.classList.remove('entry-open'); entryDone = true; }
        collectTourSteps();
        if (!tourSteps.length) { return; }
        if (!tour) { buildTour(); }
        tourStarted = true;
        tourTrigger = manual ? document.getElementById('main') : document.activeElement;
        tour.hidden = false;
        document.documentElement.classList.add('tour-open');
        store('local', 'marleTour', '1');
        tourIndex = 0;
        showTourStep(0);
    }

    function showTourStep(index) {
        var step = tourSteps[index];
        if (!step) { endTour(); return; }
        tourIndex = index;

        if (reduceMotion) {
            step.el.scrollIntoView({ block: 'center' });
        } else {
            step.el.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }

        tourTitle.textContent = step.title;
        tourBody.textContent = step.text;
        tourCount.textContent = (index + 1) + ' / ' + tourSteps.length;
        tourLive.textContent = 'Step ' + (index + 1) + ' of ' + tourSteps.length + ': ' + step.title;
        tourBack.disabled = index === 0;
        tourNext.textContent = index === tourSteps.length - 1 ? 'Done' : 'Next';

        window.requestAnimationFrame(placeTour);
        window.setTimeout(placeTour, 380);
        focusQuietly(tourNext);
    }

    function placeTour() {
        if (!tourStarted || !tour || tour.hidden || !tourSteps[tourIndex]) { return; }
        var el = tourSteps[tourIndex].el;
        var r = el.getBoundingClientRect();
        var pad = 8;
        var top = Math.max(6, r.top - pad);
        var left = Math.max(6, r.left - pad);
        var width = Math.min(window.innerWidth - 12, Math.max(60, r.width + pad * 2));
        var height = Math.max(24, r.height + pad * 2);

        tourSpot.style.top = top + 'px';
        tourSpot.style.left = left + 'px';
        tourSpot.style.width = width + 'px';
        tourSpot.style.height = height + 'px';

        var narrow = window.innerWidth < 768;
        var cardW = narrow ? window.innerWidth - 32 : Math.min(360, window.innerWidth - 48);
        tourCard.style.width = cardW + 'px';

        var cardH = tourCard.offsetHeight || 210;

        if (narrow) {
            // A sheet at whichever edge has more free space. Tall targets (the
            // booking desk) exceed the screen, so "more space" is what keeps
            // the form rather than the card on screen.
            var belowRoom = window.innerHeight - (top + height) - 16;
            var aboveRoom = top - 16;
            var toTop = belowRoom < cardH + 16 && aboveRoom > belowRoom;
            tourCard.style.left = '16px';
            tourCard.style.right = '';
            if (toTop) {
                tourCard.style.top = '16px';
                tourCard.style.bottom = '';
            } else {
                tourCard.style.top = '';
                tourCard.style.bottom = '16px';
            }
            return;
        }

        tourCard.style.bottom = '';
        tourCard.style.right = '';
        var below = top + height + 18;
        var above = top - cardH - 18;
        var sideTop = Math.max(16, Math.min(top + height / 2 - cardH / 2, window.innerHeight - cardH - 16));
        var spaceRight = window.innerWidth - (left + width + 18);
        var spaceLeft = left - 18;

        if (below + cardH < window.innerHeight - 16) {
            tourCard.style.top = below + 'px';
            tourCard.style.left = Math.max(16, Math.min(left, window.innerWidth - cardW - 16)) + 'px';
        } else if (above > 16) {
            tourCard.style.top = above + 'px';
            tourCard.style.left = Math.max(16, Math.min(left, window.innerWidth - cardW - 16)) + 'px';
        } else if (spaceRight >= cardW) {
            tourCard.style.top = sideTop + 'px';
            tourCard.style.left = (left + width + 18) + 'px';
        } else if (spaceLeft >= cardW) {
            tourCard.style.top = sideTop + 'px';
            tourCard.style.left = (left - cardW - 18) + 'px';
        } else {
            tourCard.style.top = Math.max(16, Math.min(below, window.innerHeight - cardH - 16)) + 'px';
            tourCard.style.left = '16px';
        }
    }

    function goTour(delta) {
        var next = tourIndex + delta;
        if (next < 0) { return; }
        if (next >= tourSteps.length) { endTour(); return; }
        showTourStep(next);
    }

    function endTour() {
        if (!tourStarted) { return; }
        tourStarted = false;
        document.documentElement.classList.remove('tour-open');
        if (tour) { tour.hidden = true; }
        focusQuietly(tourTrigger);
    }

    if (tourData.length) {
        document.addEventListener('keydown', function (event) {
            if (!tourStarted) { return; }
            var onControl = event.target.closest && event.target.closest('button, a[href]');
            if (event.key === 'Escape') { event.preventDefault(); endTour(); }
            else if (event.key === 'ArrowRight') { event.preventDefault(); goTour(1); }
            else if (event.key === 'ArrowLeft') { event.preventDefault(); goTour(-1); }
            else if (event.key === 'Enter' && !onControl) { event.preventDefault(); goTour(1); }
            else if (event.key === 'Tab') {
                // The dialog dims the page, so Tab must not wander into it.
                var keys = tourCard.querySelectorAll('button:not(:disabled)');
                if (!keys.length) { return; }
                var edge = event.shiftKey ? keys[0] : keys[keys.length - 1];
                if (document.activeElement === edge) {
                    event.preventDefault();
                    (event.shiftKey ? keys[keys.length - 1] : keys[0]).focus();
                }
            }
        });

        window.addEventListener('load', function () {
            if (window.location.hash === '#tour' || /[?&]tour=1\b/.test(window.location.search)) {
                if (window.history && window.history.replaceState) {
                    window.history.replaceState(null, '', window.location.pathname);
                }
                startTour(true);
                return;
            }
            // The tour follows the entry screen, never a later pageview: a
            // visitor who skipped the door, deep-linked, or asked for
            // ?nointro=1 should not be toured on their next reload.
        });
    }

    each(document.querySelectorAll('[data-tour-replay]'), function (link) {
        link.addEventListener('click', function (event) {
            if (!tourData.length) { return; }
            event.preventDefault();
            startTour(true);
        });
    });

    /* ======================================================================
       3. FAQ concierge
       ====================================================================== */

    var helpData = window.MARLE_HELP || null;
    var helpPanel = document.querySelector('[data-help]');
    var helpLauncher = document.querySelector('[data-help-launcher]');
    var helpBody = helpPanel ? helpPanel.querySelector('[data-help-body]') : null;
    var helpChips = helpPanel ? helpPanel.querySelector('[data-help-chips]') : null;
    var helpSearch = helpPanel ? helpPanel.querySelector('[data-help-search]') : null;
    var helpClose = helpPanel ? helpPanel.querySelector('[data-help-close]') : null;
    var helpTrigger = null;
    var helpOpen = false;

    function helpMatch(topic, query) {
        if (!query) { return true; }
        var hay = [topic.title, topic.chip].concat(topic.keys || []).join(' ').toLowerCase();
        return hay.indexOf(query) !== -1;
    }

    function renderHelpAnswer(topic) {
        if (!topic || !helpBody) { return; }
        helpBody.innerHTML = '<h3 class="help__answer-title">' + topic.title + '</h3>' + topic.html;
        each(helpChips.querySelectorAll('button'), function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-topic') === topic.id ? 'true' : 'false');
        });
    }

    function topicById(id) {
        var found = null;
        each(helpData.topics, function (t) { if (t.id === id) { found = t; } });
        return found;
    }

    function renderHelpChips(query) {
        if (!helpChips || !helpData) { return; }
        var q = (query || '').trim().toLowerCase();
        helpChips.innerHTML = '';
        each(helpData.topics, function (topic) {
            if (!helpMatch(topic, q)) { return; }
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'help__chip';
            b.setAttribute('data-topic', topic.id);
            b.setAttribute('aria-pressed', 'false');
            b.textContent = topic.chip;
            b.addEventListener('click', function () { renderHelpAnswer(topic); });
            helpChips.appendChild(b);
        });

        if (!helpChips.children.length) {
            var none = document.createElement('p');
            none.className = 'help__noresult';
            none.textContent = helpData.noResult;
            helpChips.appendChild(none);
        }
    }

    function openHelp() {
        if (!helpPanel || !helpData || helpOpen) { return; }
        helpOpen = true;
        helpTrigger = document.activeElement;
        helpPanel.hidden = false;
        document.documentElement.classList.add('help-open');
        helpLauncher.setAttribute('aria-expanded', 'true');
        if (!helpBody.innerHTML) {
            renderHelpChips('');
            renderHelpAnswer(helpData.topics[0]);
        }
        focusQuietly(helpSearch);
    }

    function closeHelp() {
        if (!helpPanel || !helpOpen) { return; }
        helpOpen = false;
        helpPanel.hidden = true;
        document.documentElement.classList.remove('help-open');
        helpLauncher.setAttribute('aria-expanded', 'false');
        focusQuietly(helpTrigger || helpLauncher);
    }

    if (helpPanel && helpData && helpLauncher) {
        helpLauncher.hidden = false;
        helpLauncher.addEventListener('click', function () {
            if (helpOpen) { closeHelp(); } else { openHelp(); }
        });
        helpClose.addEventListener('click', closeHelp);

        helpSearch.addEventListener('input', function () {
            renderHelpChips(helpSearch.value);
            var first = helpChips.querySelector('button');
            if (first) {
                var topic = topicById(first.getAttribute('data-topic'));
                if (topic) { renderHelpAnswer(topic); }
            }
        });

        helpSearch.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') { return; }
            event.preventDefault();
            var first = helpChips.querySelector('button');
            if (first) { first.click(); }
        });

        document.addEventListener('keydown', function (event) {
            if (!helpOpen) { return; }
            if (event.key === 'Escape') {
                event.preventDefault();
                closeHelp();
                return;
            }
            if (event.key !== 'Tab') { return; }
            var focusable = helpPanel.querySelectorAll('button, a[href], input');
            if (!focusable.length) { return; }
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    }
})();
