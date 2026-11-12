<?php
/**
 * install_prompt.php — one offer, on the dashboard, to keep the portal on the
 * home screen.
 *
 * It is a card in the page rather than a floating sheet on purpose: the review
 * prompt already owns the floating corner, the chat launcher sits in the other,
 * and a third thing sliding up over a guest's bill is how a portal starts
 * feeling like an advert.
 *
 * It shows itself only when it can actually do something:
 *   • a Chromium browser that has offered `beforeinstallprompt` — the button
 *     then calls the browser's own install prompt;
 *   • Safari on iOS, where that event does not exist: the card shows the two
 *     taps (Share → Add to Home Screen) instead, and no button that would do
 *     nothing;
 *   • `?install=1`, so the card can be reviewed without waiting for a browser
 *     to offer it (see the header comment in portal.php).
 *
 * It never shows if the portal is already running as an installed app, and
 * "Not now" silences it for the rest of the session — an invitation the second
 * time is nagging.
 */
?>
<aside class="install-card" id="portalInstallCard" hidden aria-labelledby="installTitle">
    <img class="install-card__mark" src="pics/icon-192.png" width="48" height="48" alt="" aria-hidden="true">

    <div class="install-card__copy">
        <h2 class="install-card__title" id="installTitle"><?= ui_t('Keep the portal on your home screen', 'Ilagay ang portal sa home screen mo') ?></h2>
        <p class="install-card__text"><?= ui_t(
            'It opens straight to your stay — and shows a plain notice instead of a browser error when the wifi drops.',
            'Dumederetso ito sa pamamalagi mo — at may simpleng paalala kapag nawalan ng wifi, hindi error ng browser.'
        ) ?></p>
        <p class="install-card__hint" data-install-hint hidden><?= ui_t(
            'Tap Share, then Add to Home Screen.',
            'Pindutin ang Share, pagkatapos ang Add to Home Screen.'
        ) ?></p>
    </div>

    <div class="install-card__actions">
        <button type="button" class="install-card__accept" data-install-accept><?= ui_t('Add to home screen', 'Idagdag sa home screen') ?></button>
        <button type="button" class="install-card__dismiss" data-install-dismiss><?= ui_t('Not now', 'Mamaya na') ?></button>
    </div>
</aside>

<script>
(function () {
    var card = document.getElementById('portalInstallCard');
    if (!card) return;

    var accept  = card.querySelector('[data-install-accept]');
    var dismiss = card.querySelector('[data-install-dismiss]');
    var hint    = card.querySelector('[data-install-hint]');
    var KEY     = 'marle-portal-install';
    var offer   = null;

    function read(store) { try { return store.getItem(KEY); } catch (e) { return null; } }
    function write(store, value) { try { store.setItem(KEY, value); } catch (e) {} }

    function alreadyAnApp() {
        return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
               window.navigator.standalone === true;
    }
    function silenced() {
        return read(sessionStorage) === 'dismissed' || read(localStorage) === 'installed';
    }
    function show() {
        card.hidden = false;
    }
    function hide(remember) {
        card.hidden = true;
        if (remember === 'dismissed') write(sessionStorage, 'dismissed');
        if (remember === 'installed') write(localStorage, 'installed');
    }

    if (alreadyAnApp()) return;

    window.addEventListener('beforeinstallprompt', function (event) {
        // Stop Chrome's own mini-bar; this card asks in the portal's voice.
        event.preventDefault();
        offer = event;
        if (!silenced()) show();
    });

    window.addEventListener('appinstalled', function () {
        hide('installed');
    });

    if (accept) {
        accept.addEventListener('click', function () {
            var pending = offer;
            offer = null;
            if (!pending || typeof pending.prompt !== 'function') { hide('dismissed'); return; }

            pending.prompt();

            // userChoice resolves whichever way the guest answered, and the
            // appinstalled event is the one that really settles it. An offer
            // without userChoice is still an offer Chrome accepts.
            var choice = pending.userChoice;
            if (choice && typeof choice.then === 'function') {
                choice.then(function (result) {
                    if (result && result.outcome === 'accepted') hide('installed');
                    else hide('dismissed');
                }, function () { hide('dismissed'); });
            } else {
                hide('installed');
            }
        });
    }

    if (dismiss) dismiss.addEventListener('click', function () { hide('dismissed'); });

    var forced = /[?&]install=1(?:&|$)/.test(window.location.search);
    var ua = navigator.userAgent || '';
    var isIos = /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && 'ontouchend' in document);
    var isSafari = /safari/i.test(ua) && !/chrome|chromium|crios|android|fxios|edg/i.test(ua);

    if (isIos && isSafari && !silenced()) {
        // No beforeinstallprompt ever arrives here, so the card teaches the
        // two taps rather than offering a button with nothing behind it.
        if (hint) hint.hidden = false;
        if (accept) accept.hidden = true;
        show();
    } else if (forced && !alreadyAnApp()) {
        show();
    }
})();
</script>
