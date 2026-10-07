/* ==========================================================
   Big Drop — Service Worker
   Handles: offline cache, push notifications, click routing
   Served from /sw.js (root scope) via plugin rewrite.
   ========================================================== */

var BD_VERSION = '{{BD_VERSION}}';
var BD_URL     = '{{BD_URL}}';
var HOME_URL   = '{{HOME_URL}}';

var CACHE_NAME = 'bigdrop-v' + BD_VERSION;
var PRECACHE_URLS = [
  BD_URL + 'assets/css/portal.css',
  BD_URL + 'assets/css/widget.css',
  BD_URL + 'assets/js/portal.js',
  BD_URL + 'assets/js/widget.js',
  BD_URL + 'assets/js/pwa.js',
  BD_URL + 'assets/icons/icon-192.png',
  BD_URL + 'assets/icons/icon-512.png',
  BD_URL + 'assets/sounds/ping.mp3'
];

// ==========================================================
// Install — precache core assets
// ==========================================================
self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function (cache) {
      return cache.addAll(PRECACHE_URLS).catch(function (err) {
        // Individual asset failures shouldn't break the SW.
        console.warn('[BD SW] Precache partial failure:', err);
      });
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

// ==========================================================
// Activate — clean old caches
// ==========================================================
self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) {
          return k.indexOf('bigdrop-') === 0 && k !== CACHE_NAME;
        }).map(function (k) {
          return caches.delete(k);
        })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

// ==========================================================
// Fetch — smart caching
// - Never cache REST / API calls
// - Network-first for HTML
// - Cache-first for static assets
// ==========================================================
self.addEventListener('fetch', function (event) {
  var request = event.request;
  if (request.method !== 'GET') return;

  var url = new URL(request.url);

  // Skip non-http(s).
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  // Never intercept WP REST calls.
  if (url.pathname.indexOf('/wp-json/') !== -1) return;
  if (url.pathname.indexOf('/wp-admin/') !== -1 && url.pathname.indexOf('/admin-ajax.php') === -1) return;

  // Only handle same-origin.
  if (url.origin !== location.origin) return;

  // Static assets from our plugin — cache-first.
  if (url.pathname.indexOf('/wp-content/plugins/bigdrop-agent-portal/') !== -1) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // HTML navigation — network-first with offline fallback.
  if (request.mode === 'navigate' || (request.headers.get('accept') || '').indexOf('text/html') !== -1) {
    event.respondWith(networkFirstHTML(request));
    return;
  }

  // Otherwise — pass through.
});

function cacheFirst(request) {
  return caches.match(request).then(function (cached) {
    if (cached) return cached;
    return fetch(request).then(function (response) {
      if (!response || response.status !== 200 || response.type !== 'basic') {
        return response;
      }
      var copy = response.clone();
      caches.open(CACHE_NAME).then(function (cache) {
        cache.put(request, copy);
      });
      return response;
    }).catch(function () {
      return caches.match(request);
    });
  });
}

function networkFirstHTML(request) {
  return fetch(request).then(function (response) {
    if (!response || response.status !== 200) return response;
    var copy = response.clone();
    caches.open(CACHE_NAME).then(function (cache) {
      cache.put(request, copy);
    });
    return response;
  }).catch(function () {
    return caches.match(request).then(function (cached) {
      if (cached) return cached;
      // Fallback to a minimal offline page.
      return new Response(
        '<!doctype html><html><head><meta charset="utf-8"><title>Offline — Big Drop</title>' +
        '<style>body{font-family:Inter,system-ui,sans-serif;background:#F5F7FC;color:#1A1A1A;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px;text-align:center}' +
        '.card{background:#fff;border-radius:20px;padding:40px 32px;max-width:420px;box-shadow:0 12px 40px rgba(31,13,94,.12)}' +
        'h1{color:#1F0D5E;margin:0 0 8px;font-size:22px}p{color:#6C6F8C;margin:0 0 20px;font-size:14px}' +
        'a{display:inline-block;background:#7FD344;color:#1F0D5E;text-decoration:none;padding:12px 24px;border-radius:12px;font-weight:700}' +
        '</style></head><body><div class="card">' +
        '<h1>You are offline</h1>' +
        '<p>Big Drop is trying to reconnect. Check your internet and try again.</p>' +
        '<a href="' + HOME_URL + '">Retry</a>' +
        '</div></body></html>',
        { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
      );
    });
  });
}

// ==========================================================
// Push notifications
// ==========================================================
self.addEventListener('push', function (event) {
  var data = {
    title: 'Big Drop',
    body: 'You have a new notification.',
    url: HOME_URL,
    tag: 'bigdrop',
    icon: BD_URL + 'assets/icons/icon-192.png'
  };

  if (event.data) {
    try {
      var parsed = event.data.json();
      if (parsed.title) data.title = parsed.title;
      if (parsed.body)  data.body  = parsed.body;
      if (parsed.url)   data.url   = parsed.url;
      if (parsed.tag)   data.tag   = parsed.tag;
      if (parsed.icon)  data.icon  = parsed.icon;
    } catch (e) {
      data.body = event.data.text();
    }
  }

  var options = {
    body: data.body,
    icon: data.icon,
    badge: BD_URL + 'assets/icons/badge-72.png',
    tag: data.tag,
    renotify: true,
    requireInteraction: false,
    vibrate: [120, 60, 120],
    data: { url: data.url },
    actions: [
      { action: 'open',  title: 'Open' },
      { action: 'close', title: 'Dismiss' }
    ]
  };

  event.waitUntil(
    self.registration.showNotification(data.title, options)
  );
});

// ==========================================================
// Notification click — focus or open
// ==========================================================
self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  var action = event.action;
  if (action === 'close') return;

  var target = (event.notification.data && event.notification.data.url) || HOME_URL;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      // Find an existing Big Drop tab.
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url.indexOf('/agent-portal/') !== -1 && 'focus' in client) {
          // Optionally navigate to URL.
          if (client.navigate) {
            client.navigate(target);
          }
          return client.focus();
        }
      }
      // Otherwise open a new window.
      if (clients.openWindow) {
        return clients.openWindow(target);
      }
    })
  );
});

// ==========================================================
// Notification close (analytics / cleanup hook)
// ==========================================================
self.addEventListener('notificationclose', function (event) {
  // Placeholder for future analytics.
});

// ==========================================================
// Message handler (from page → SW)
// ==========================================================
self.addEventListener('message', function (event) {
  if (!event.data) return;

  if (event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }

  if (event.data.type === 'CHECK_SUBSCRIPTION') {
    self.registration.pushManager.getSubscription().then(function (sub) {
      event.source && event.source.postMessage({
        type: 'SUBSCRIPTION_STATUS',
        subscribed: !!sub
      });
    });
  }
});