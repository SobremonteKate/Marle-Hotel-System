/* ==========================================================================
   Marle Guest Portal — service worker

   What this is for: the portal is the one part of the site a guest uses while
   they are already in the building, on hotel wifi, holding a phone. Losing the
   network should not leave them on the browser's error page, and adding the
   portal to the home screen should mean the shell loads from the device.

   What it deliberately does NOT do: cache the portal's own pages. Every one of
   them is a PHP document containing one guest's room number, bill and chat, and
   the cached copy of that document survives sign-out — the next person on that
   phone could open the tab and read it. So:

     • navigations  → network first, and the offline page (nothing else) on
                      failure. Page HTML is never written to a cache.
     • static files → stale-while-revalidate. CSS, JS, images, icons and the
                      manifest are the same for every guest and carry a ?v= for
                      releases, so serving from cache is safe and instant; a
                      lookup that misses on the exact ?v= URL falls back to the
                      bare name, so a release that happens while a guest is
                      offline still styles the page.
     • anything else (every .php endpoint, every POST, anything cross-origin
       such as the fonts CDN) → untouched: the browser talks to the network
                      exactly as it would with no service worker at all.

   No clock is read anywhere in this file: the offline page and the cache name
   are versioned by hand, which is also what the project's clock scan expects.
   ========================================================================== */

const VERSION = 'marle-portal-v1';
const OFFLINE_URL = 'offline.html';
const PRECACHE = [OFFLINE_URL];

const STATIC = /\.(?:css|js|mjs|png|jpe?g|gif|svg|webp|avif|ico|webmanifest|woff2?|ttf)$/i;

self.addEventListener('install', (event) => {
    event.waitUntil((async () => {
        const cache = await caches.open(VERSION);
        await cache.addAll(PRECACHE);
        await self.skipWaiting();
    })());
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(
            names
                .filter((name) => name.startsWith('marle-portal-') && name !== VERSION)
                .map((name) => caches.delete(name))
        );
        await self.clients.claim();
    })());
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // Anything that is not a plain GET is the app's business, not ours —
    // orders, extensions, chat messages and the session-expiry check all post.
    if (request.method !== 'GET') return;

    let url;
    try {
        url = new URL(request.url);
    } catch (error) {
        return;
    }

    // The fonts come from Google and the CSS/JS from CDNs: leave them to the
    // browser's own cache so nothing here can serve a stale stylesheet.
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(navigate(request));
        return;
    }

    if (STATIC.test(url.pathname)) {
        event.respondWith(staleWhileRevalidate(request));
    }

    // Everything else — the .php documents and their endpoints — falls through
    // to the network untouched.
});

async function navigate(request) {
    try {
        return await fetch(request);
    } catch (error) {
        const cached = await caches.match(OFFLINE_URL);
        return cached || new Response('You are offline.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
    }
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(VERSION);

    // Exact URL first. The second lookup ignores the ?v= release string, so a
    // guest who opens the portal offline right after a release gets the styles
    // we do have instead of an unstyled page — and a request for a bare
    // refresh.css is answered at all, rather than failing with nothing.
    const hit = (await cache.match(request)) || (await cache.match(request, { ignoreSearch: true }));

    const network = fetch(request).then((response) => {
        // Never store a response the server marked private or uncacheable, and
        // never store an error page over a good file.
        const cc = response.headers.get('Cache-Control') || '';
        if (response.ok && response.type === 'basic' && !/no-store|private/i.test(cc)) {
            cache.put(request, response.clone());
        }
        return response;
    }).catch(() => null);

    if (hit) return hit;

    const fresh = await network;
    if (fresh) return fresh;

    // A file this device has never fetched cannot be invented. Say so plainly —
    // a failed request is the truth, and the browser reports it against the
    // one asset rather than pretending the file was empty.
    return Response.error();
}
