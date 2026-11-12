/* ==========================================================================
   Marle Hotel — public site behaviour
   --------------------------------------------------------------------------
   Dependency-free on purpose: the old homepage loaded jQuery solely to make
   two GET requests, and Bootstrap's collapse is what let the mobile menu spill
   over the hero without a background. Everything here is progressive — with no
   JavaScript the page still reads, the folio still shows its first room, and
   the drawer button simply does nothing.

   No Date(), no toLocaleString(), no device timezone: the hotel's clock lives
   in includes/server_clock.php, and clock_hygiene_checks.php scans this file.
   ========================================================================== */

(function () {
    'use strict';

    var reduceMotion = false;
    try {
        reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    } catch (err) { /* very old browser: keep motion */ }

    // Native smooth scrolling for the in-page anchors, only when the visitor
    // has not asked for reduced motion.
    if (!reduceMotion) {
        document.documentElement.classList.add('site-scroll');
    }

    function each(list, fn) {
        Array.prototype.forEach.call(list || [], fn);
    }

    /* ----------------------------------------------------------------------
       Header: hairline deepens once the page moves under the fixed bar.
       ---------------------------------------------------------------------- */
    var header = document.querySelector('[data-site-header]');
    var bookBar = document.querySelector('[data-book-bar]');
    var bookingDesk = document.getElementById('bookingArea');
    var siteFooter = document.querySelector('.site-footer');
    var scrollQueued = false;

    function paintScrollState() {
        scrollQueued = false;
        var y = window.pageYOffset || document.documentElement.scrollTop || 0;

        if (header) {
            header.classList.toggle('is-scrolled', y > 8);
        }

        if (bookBar) {
            var show = y > 420;
            if (bookingDesk) {
                var d = bookingDesk.getBoundingClientRect();
                if (d.top < window.innerHeight * 0.9 && d.bottom > window.innerHeight * 0.15) {
                    show = false;
                }
            }
            if (siteFooter) {
                var f = siteFooter.getBoundingClientRect();
                if (f.top < window.innerHeight) {
                    show = false;
                }
            }
            if (document.documentElement.classList.contains('menu-open')) {
                show = false;
            }
            bookBar.hidden = !show;
            // The help launcher sits above the bar when it is out, and the CSS
            // needs to know without a second copy of this calculation.
            document.documentElement.classList.toggle('has-book-bar', !!show);
        }
    }

    function onScroll() {
        if (!scrollQueued) {
            scrollQueued = true;
            window.requestAnimationFrame(paintScrollState);
        }
    }

    if (header || bookBar) {
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        paintScrollState();
    }

    /* ----------------------------------------------------------------------
       Mobile drawer. A native <dialog> gives us the top layer, focus
       containment and Esc for free; the fallback below covers browsers
       without showModal (the panel still opens, just not as a modal).
       ---------------------------------------------------------------------- */
    var menu = document.getElementById('siteMenu');
    var openers = document.querySelectorAll('[data-menu-open]');
    var closers = document.querySelectorAll('[data-menu-close]');
    var lastFocus = null;

    function setExpanded(value) {
        each(openers, function (btn) {
            btn.setAttribute('aria-expanded', value ? 'true' : 'false');
        });
    }

    /* Every path out of the drawer — the close button, a link, Esc, a resize to
       desktop — runs through this, and it runs synchronously. Relying on the
       dialog's own `close` event alone left the page body locked and the button
       still saying aria-expanded="true" after Esc in Chromium. */
    function setMenuState(isOpen) {
        document.documentElement.classList.toggle('menu-open', isOpen);
        setExpanded(isOpen);
        if (!isOpen && lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
            lastFocus = null;
        }
        paintScrollState();
    }

    function openMenu() {
        if (!menu) { return; }
        lastFocus = document.activeElement;
        setMenuState(true);
        if (typeof menu.showModal === 'function') {
            try { menu.showModal(); } catch (err) { menu.setAttribute('open', ''); }
        } else {
            menu.setAttribute('open', '');
        }
        var close = menu.querySelector('[data-menu-close]');
        if (close) { close.focus(); }
    }

    function closeMenu() {
        if (!menu) { return; }
        if (typeof menu.close === 'function' && menu.open) {
            menu.close();
        } else {
            menu.removeAttribute('open');
        }
        setMenuState(false);
    }

    if (menu) {
        each(openers, function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                openMenu();
            });
        });

        each(closers, function (btn) {
            btn.addEventListener('click', function (event) {
                event.preventDefault();
                closeMenu();
            });
        });

        // Any link closes the drawer before the browser navigates.
        each(menu.querySelectorAll('a'), function (link) {
            link.addEventListener('click', function () {
                closeMenu();
            });
        });

        // Esc (dialog cancel) and a backdrop click both close it.
        menu.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeMenu();
        });
        menu.addEventListener('click', function (event) {
            if (event.target === menu) { closeMenu(); }
        });
        // Safety net for a native close we did not initiate (browser UI, a
        // future caller of dialog.close()).
        menu.addEventListener('close', function () {
            setMenuState(false);
        });
    }

    try {
        var desktop = window.matchMedia('(min-width: 992px)');
        var onDesktop = function (event) {
            if (event.matches && menu && menu.hasAttribute('open')) {
                closeMenu();
            }
        };
        if (typeof desktop.addEventListener === 'function') {
            desktop.addEventListener('change', onDesktop);
        } else if (typeof desktop.addListener === 'function') {
            desktop.addListener(onDesktop);
        }
    } catch (err) { /* matchMedia unsupported: drawer still works */ }

    /* ----------------------------------------------------------------------
       Room viewer: the four photographs behind "Check this room".
       The names, rates and photo paths are rendered by includes/room_viewer.php
       into #roomViewerData, so this file never holds a second copy of them. A
       photo whose file is missing arrives with an empty src and is drawn as a
       captioned plate instead of a broken image.
       ---------------------------------------------------------------------- */
    var roomView = (function () {
        var dialog = document.getElementById('roomViewer');
        var dataEl = document.getElementById('roomViewerData');
        if (!dialog || !dataEl || typeof dialog.showModal !== 'function') {
            return null;
        }

        var data;
        try {
            data = JSON.parse(dataEl.textContent || '{}');
        } catch (err) {
            return null;
        }

        var root = document.documentElement;
        var sheet = dialog.querySelector('.roomview__sheet');
        var stage = dialog.querySelector('.roomview__stage');
        var img = dialog.querySelector('[data-rv-img]');
        var counter = dialog.querySelector('[data-rv-count]');
        var credit = dialog.querySelector('[data-rv-credit]');
        var thumbs = dialog.querySelector('[data-rv-thumbs]');
        var nameEl = dialog.querySelector('[data-rv-name]');
        var roomEl = dialog.querySelector('[data-rv-room]');
        var metaEl = dialog.querySelector('[data-rv-meta]');
        var blurbEl = dialog.querySelector('[data-rv-blurb]');
        var threeEl = dialog.querySelector('[data-rv-three]');
        var extraEl = dialog.querySelector('[data-rv-extra]');
        var nightEl = dialog.querySelector('[data-rv-night]');
        var amenitiesEl = dialog.querySelector('[data-rv-amenities]');
        var photoButtons = [];
        var current = null;
        var currentType = '';
        var index = 0;

        function plate(label) {
            var el = document.createElement('span');
            el.className = 'roomview__plate';
            el.textContent = label;
            return el;
        }

        function buildThumbs(photos) {
            thumbs.textContent = '';
            photoButtons = [];
            each(photos, function (photo, i) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'roomview__thumb';
                btn.setAttribute('aria-pressed', i === 0 ? 'true' : 'false');
                btn.setAttribute('aria-label', photo.label);

                if (photo.src) {
                    var im = document.createElement('img');
                    im.src = photo.src;
                    im.alt = '';
                    im.width = 1024;
                    im.height = 768;
                    im.loading = 'lazy';
                    btn.appendChild(im);
                } else {
                    btn.appendChild(plate(photo.label));
                }

                var caption = document.createElement('span');
                caption.className = 'roomview__thumb-caption';
                caption.textContent = photo.label;
                btn.appendChild(caption);

                btn.addEventListener('click', function () { select(i); });
                thumbs.appendChild(btn);
                photoButtons.push(btn);
            });
        }

        function paintCredit(photo) {
            credit.textContent = '';
            if (!photo.credit) {
                credit.hidden = true;
                return;
            }
            credit.hidden = false;
            credit.appendChild(document.createTextNode('Photo: ' + photo.credit.by + ' · '));
            var link = document.createElement('a');
            link.href = photo.credit.href;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = photo.credit.lic;
            credit.appendChild(link);
        }

        function select(i) {
            if (!current || !current.photos.length) {
                return;
            }
            index = (i + current.photos.length) % current.photos.length;
            var photo = current.photos[index];

            each(photoButtons, function (btn, n) {
                btn.setAttribute('aria-pressed', n === index ? 'true' : 'false');
            });

            var old = stage.querySelector('.roomview__plate');
            if (old) { old.parentNode.removeChild(old); }

            if (photo.src) {
                stage.classList.remove('is-empty');
                img.src = photo.src;
                img.alt = photo.alt;
            } else {
                stage.classList.add('is-empty');
                img.removeAttribute('src');
                img.alt = '';
                stage.appendChild(plate(photo.label));
            }

            if (counter) {
                counter.textContent = (index + 1) + ' / ' + current.photos.length;
            }
            if (credit) {
                paintCredit(photo);
            }
        }

        function close() {
            dialog.close();
        }

        each(dialog.querySelectorAll('[data-rv-close]'), function (btn) {
            btn.addEventListener('click', close);
        });

        dialog.addEventListener('click', function (event) {
            // A click that lands on the dialog itself is a click on the backdrop.
            if (event.target === dialog) { close(); }
        });

        dialog.addEventListener('keydown', function (event) {
            if (!current) { return; }
            if (event.key === 'ArrowRight') {
                event.preventDefault();
                select(index + 1);
            } else if (event.key === 'ArrowLeft') {
                event.preventDefault();
                select(index - 1);
            }
        });

        dialog.addEventListener('close', function () {
            root.classList.remove('roomview-open');
        });

        var book = dialog.querySelector('[data-rv-book]');
        if (book) {
            book.addEventListener('click', function () {
                var type = currentType;
                close();
                if (type && typeof window.marleSelectRoom === 'function') {
                    window.marleSelectRoom(type);
                }
            });
        }

        return {
            open: function (type) {
                if (!data[type]) { return false; }
                current = data[type];
                currentType = type;

                if (nameEl) { nameEl.textContent = current.name; }
                if (roomEl) { roomEl.textContent = current.room ? current.room : ''; }
                if (metaEl) { metaEl.textContent = current.meta; }
                if (blurbEl) { blurbEl.textContent = current.blurb; }
                if (threeEl) { threeEl.textContent = current.three; }
                if (nightEl) { nightEl.textContent = current.night; }
                if (extraEl) {
                    extraEl.textContent = current.extra || '';
                    extraEl.hidden = !current.extra;
                }

                // What is in this room, read from the same card the tariff uses.
                if (amenitiesEl) {
                    amenitiesEl.textContent = '';
                    each(current.amenities || [], function (label) {
                        var item = document.createElement('li');
                        item.textContent = label;
                        amenitiesEl.appendChild(item);
                    });
                }

                buildThumbs(current.photos);
                select(0);
                if (sheet) { sheet.scrollTop = 0; }

                dialog.showModal();
                root.classList.add('roomview-open');
                return true;
            },
            close: close
        };
    })();

    /* ----------------------------------------------------------------------
       Rooms folio: tapping a tariff row swaps the photograph and caption.
       Rows are buttons with aria-pressed, so this is usable by keyboard and
       announced correctly. "Check this room" opens the viewer, then feeds the
       desk from the viewer's own button.
       ---------------------------------------------------------------------- */
    var folio = document.querySelector('[data-folio]');

    if (folio) {
        var folioImage = folio.querySelector('[data-folio-img]');
        var folioName = folio.querySelector('[data-folio-name]');
        var folioMeta = folio.querySelector('[data-folio-meta]');
        var folioRoom = folio.querySelector('[data-folio-room]');
        var folioAction = folio.querySelector('[data-folio-book]');
        var folioRows = folio.querySelectorAll('[data-folio-row]');
        // The first row ships pressed, so the button under the photograph must
        // already know which room it means — before, it did nothing until a row
        // had been tapped.
        var pressed = folio.querySelector('[data-folio-row][aria-pressed="true"]');
        var selectedType = pressed ? (pressed.getAttribute('data-room-type') || '') : '';

        function selectRow(row) {
            each(folioRows, function (other) {
                other.setAttribute('aria-pressed', other === row ? 'true' : 'false');
            });

            selectedType = row.getAttribute('data-room-type') || '';
            var src = row.getAttribute('data-src');
            var name = row.getAttribute('data-name') || '';
            var meta = row.getAttribute('data-meta') || '';
            var room = row.getAttribute('data-room') || '';

            if (folioImage && src) {
                folioImage.setAttribute('src', src);
                folioImage.setAttribute('alt', row.getAttribute('data-alt') || name);
                var media = folioImage.parentNode;
                if (media && !reduceMotion) {
                    media.classList.add('is-swapping');
                    window.setTimeout(function () { media.classList.remove('is-swapping'); }, 180);
                }
            }
            if (folioName) { folioName.textContent = name; }
            if (folioMeta) { folioMeta.textContent = meta; }
            if (folioRoom) { folioRoom.textContent = room; }
        }

        each(folioRows, function (row) {
            row.addEventListener('click', function () { selectRow(row); });
        });

        if (folioAction) {
            folioAction.addEventListener('click', function () {
                if (!selectedType) { return; }
                if (roomView && roomView.open(selectedType)) { return; }
                if (typeof window.marleSelectRoom === 'function') {
                    window.marleSelectRoom(selectedType);
                }
            });
        }
    }

    /* Pick a room type in the desk and take the guest to it. */
    window.marleSelectRoom = function (roomType) {
        var select = document.getElementById('bookRoomType');
        if (select) {
            select.value = roomType;
        }
        var desk = document.getElementById('bookingArea');
        if (desk) {
            if (reduceMotion) {
                desk.scrollIntoView();
            } else {
                desk.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
        var checkIn = document.getElementById('bookCheckIn');
        if (checkIn) {
            window.setTimeout(function () {
                try { checkIn.focus({ preventScroll: true }); } catch (err) { checkIn.focus(); }
            }, reduceMotion ? 0 : 420);
        }
    };

    /* Add-on cards are divs (they live inside a <form>, where a nested submit
       button would book the room). Give them the keyboard behaviour their
       role="button" promises. */
    document.addEventListener('keydown', function (event) {
        var target = event.target;
        if (!target || !target.classList || !target.classList.contains('addon-card')) {
            return;
        }
        if (event.key === 'Enter' || event.key === ' ' || event.key === 'Spacebar') {
            event.preventDefault();
            target.click();
        }
    });

    /* ----------------------------------------------------------------------
       The menu: the chips above the price list filter it by section.
       Every dish is printed exactly once, under one heading, so filtering is
       only ever show/hide of whole sections — no dish is duplicated and the
       headings never disagree with the rows beneath them. With JavaScript
       absent every section is already visible, so nothing is lost.
       ---------------------------------------------------------------------- */
    var menuTabs = document.querySelectorAll('[data-menu-tab]');
    var menuSections = document.querySelectorAll('[data-menu-sec]');

    if (menuTabs.length && menuSections.length) {
        each(menuTabs, function (tab) {
            tab.addEventListener('click', function () {
                var wanted = tab.getAttribute('data-menu-tab') || 'all';

                each(menuTabs, function (other) {
                    var on = other === tab;
                    other.classList.toggle('is-active', on);
                    other.setAttribute('aria-pressed', on ? 'true' : 'false');
                });

                each(menuSections, function (section) {
                    var show = wanted === 'all'
                        || section.getAttribute('data-menu-sec') === wanted;
                    if (show) {
                        section.removeAttribute('hidden');
                    } else {
                        section.setAttribute('hidden', '');
                    }
                });
            });
        });
    }

    /* ----------------------------------------------------------------------
       "Read the deposit and cancellation policy".

       That link sits inside the booking form, in the terms <label>. It used to
       close the form and scroll to the policy block — which threw away a
       half-filled booking for the sake of reading four paragraphs. It now opens
       the policy in a second dialog stacked over the form: close it and every
       field is still exactly where the guest left it.

       The reader's body is copied out of the real #policies section the first
       time it is opened, so the page carries one copy of those words and their
       figures still come from includes/booking_policies.php. Ids are stripped in
       the copy (a document may not hold two of one id), and the section's own
       heading and foot are dropped — the dialog has its own heading, and that
       foot's links would navigate away from the form.

       It is a native <dialog> on purpose. A second Bootstrap modal over the
       booking form loses every keystroke to Bootstrap's focus trap, which listens
       on the document and pulls focus back into its own modal; a modal dialog
       makes the rest of the page inert instead, so nothing below can take focus
       away from it.

       No dialog support, or no section on this page: the link is left to the
       browser and its href still goes to the policy block.
       ---------------------------------------------------------------------- */
    var policyReader = document.getElementById('policyDialog');

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('.policy-link') : null;
        if (!link) { return; }

        var source = document.getElementById('policies');
        var reader = policyReader;
        if (!source || !reader || typeof reader.showModal !== 'function') { return; }

        event.preventDefault();
        fillPolicyReader(source, reader);

        if (reader.open) { return; }

        var pageY = window.scrollY;
        reader.showModal();

        // Focus lands on the scrollable sheet by default. The close button is a
        // better landmark: it is what the dialog is asking the guest to do next,
        // and it is labelled for a screen reader.
        var closer = reader.querySelector('.policyreader__close');
        if (closer) {
            try { closer.focus({ preventScroll: true }); } catch (err) { closer.focus(); }
        }

        // A modal dialog must not move the page underneath it. Chrome scrolls the
        // document by the height of the sheet's first row (~56px on a phone) when
        // the dialog takes focus, and the shift is still there when it closes.
        var pin = function () {
            if (window.scrollY !== pageY) { window.scrollTo(0, pageY); }
        };
        pin();
        window.addEventListener('scroll', pin, { passive: true });

        // The booking form's Bootstrap focus trap listens on the document and pulls
        // focus back into its own — already inert — modal, which drops it on <body>.
        // Keeping these focus events from reaching that listener leaves the trap
        // armed but blind while the reader is open, and the dialog's own focus
        // containment does the work instead.
        var guardFocus = function (event) {
            if (reader.contains(event.target)) {
                event.stopPropagation();
                return;
            }
            var landing = reader.querySelector('.policyreader__close');
            if (landing) {
                try { landing.focus({ preventScroll: true }); } catch (err) { landing.focus(); }
            }
        };
        document.addEventListener('focusin', guardFocus, true);

        reader.addEventListener('close', function () {
            window.removeEventListener('scroll', pin);
            document.removeEventListener('focusin', guardFocus, true);
        }, { once: true });
    });

    if (policyReader) {
        // Escape is handled here, not left to the browser: with the booking modal
        // still open underneath, Chrome's own close request never reaches this
        // dialog (measured — the first Escape after the click does nothing), and a
        // guest who cannot dismiss the policy is a guest who cannot book.
        policyReader.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' && event.key !== 'Esc') { return; }
            event.preventDefault();
            policyReader.close();
        });
    }

    // Closing the reader leaves the booking form exactly as it was — that is the
    // whole point of it being a separate layer rather than a page jump.
    document.addEventListener('click', function (event) {
        var closer = event.target.closest ? event.target.closest('[data-policy-close]') : null;
        if (!closer) { return; }
        var reader = closer.closest('dialog');
        if (reader) { reader.close(); }
    });

    /** Build the policy reader's contents from the page's own policy section. */
    function fillPolicyReader(source, reader) {
        var body = reader.querySelector('[data-policy-body]');
        var shell = source.querySelector('.shell');
        if (!body || !shell || body.getAttribute('data-filled') === '1') { return; }

        var copy = shell.cloneNode(true);

        var head = copy.querySelector('.section-head');
        var lead = head ? head.querySelector('.section-lead') : null;
        if (head) { head.parentNode.removeChild(head); }

        var foot = copy.querySelector('.policies__foot');
        if (foot) { foot.parentNode.removeChild(foot); }

        var i;
        var withIds = copy.querySelectorAll('[id]');

        for (i = 0; i < withIds.length; i++) { withIds[i].removeAttribute('id'); }

        // Nothing may carry the scroll-reveal classes: a copy taken before the
        // guest scrolled that far would inherit `.reveal` without `.is-in`,
        // which is invisible inside the dialog.
        var revealing = copy.querySelectorAll('[data-reveal], .reveal');
        for (i = 0; i < revealing.length; i++) {
            revealing[i].removeAttribute('data-reveal');
            revealing[i].classList.remove('reveal');
            revealing[i].classList.remove('is-in');
        }

        body.innerHTML = '';
        if (lead) {
            var intro = document.createElement('p');
            intro.className = 'policyreader__lead';
            intro.innerHTML = lead.innerHTML;
            body.appendChild(intro);
        }
        while (copy.firstChild) { body.appendChild(copy.firstChild); }
        body.setAttribute('data-filled', '1');
    }

    /* ----------------------------------------------------------------------
       Reveal on scroll. Classes are added by JS, so a failed script leaves
       every section visible rather than blank.
       ---------------------------------------------------------------------- */
    var revealables = document.querySelectorAll('[data-reveal]');

    if (revealables.length && !reduceMotion && 'IntersectionObserver' in window) {
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-in');
                    observer.unobserve(entry.target);
                }
            });
        }, { rootMargin: '0px 0px -8% 0px', threshold: 0.06 });

        each(revealables, function (element) {
            element.classList.add('reveal');
            observer.observe(element);
        });
    }
})();
