<?php
/**
 * sw_register.php — turns the portal into something that can be added to a
 * home screen and survive a dropped connection.
 *
 * Registered from every portal screen (includes/footer.php) and from the login
 * page, because the worker has to be running and in control of portal.php
 * before the browser will offer to install the portal at all.
 *
 * Three deliberate properties:
 *   • scope './' — the worker covers /portal/ and nothing else, so the public
 *     site is never served by it, and closing the portal session does not leave
 *     a worker controlling the brochure.
 *   • it waits for `load`, so registering never competes with the page's own
 *     first paint, fonts or its bill/chat requests.
 *   • a failure is silent on purpose: browsers without service workers, or a
 *     guest whose browser refuses to register one, simply keep the online-only
 *     portal. Nothing about the stay depends on this file.
 */
?>
<script>
(function () {
    if (!('serviceWorker' in navigator) || !window.isSecureContext) return;
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {
            /* offline support and the install offer stay off; the portal works */
        });
    });
})();
</script>
