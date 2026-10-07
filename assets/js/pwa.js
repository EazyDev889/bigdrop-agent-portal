/* ==========================================================
   Big Drop PWA — Service Worker registration + Web Push
   ========================================================== */
(function () {
  'use strict';

  if (typeof window.BD_PWA === 'undefined') return;

  var C = window.BD_PWA;

  // ==========================================================
  // Register service worker
  // ==========================================================
  function register() {
    if (!('serviceWorker' in navigator)) {
      console.info('[BD PWA] Service Worker not supported.');
      return;
    }

    window.addEventListener('load', function () {
      navigator.serviceWorker.register(C.swUrl, { scope: C.scope || '/' })
        .then(function (reg) {
          console.info('[BD PWA] Service Worker registered at scope:', reg.scope);

          // Check for existing subscription.
          return reg.pushManager.getSubscription().then(function (sub) {
            if (sub) {
              // Make sure the server knows about this subscription.
              sendSubscriptionToServer(sub);
            }
          });
        })
        .catch(function (err) {
          console.warn('[BD PWA] Service Worker registration failed:', err);
        });
    });
  }

  // ==========================================================
  // Permission + subscribe
  // ==========================================================
  function requestPermission() {
    if (!('Notification' in window)) {
      console.warn('[BD PWA] Notifications not supported.');
      return Promise.resolve('unsupported');
    }

    if (Notification.permission === 'granted') {
      subscribe();
      return Promise.resolve('granted');
    }

    if (Notification.permission === 'denied') {
      return Promise.resolve('denied');
    }

    return Notification.requestPermission().then(function (perm) {
      if (perm === 'granted') {
        subscribe();
      }
      return perm;
    });
  }

  // ==========================================================
  // Subscribe to Web Push
  // ==========================================================
  function subscribe() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      console.warn('[BD PWA] Push not supported in this browser.');
      return Promise.resolve(null);
    }

    if (!C.vapidKey) {
      console.warn('[BD PWA] No VAPID key configured.');
      return Promise.resolve(null);
    }

    return navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (existing) {
        if (existing) {
          // Already subscribed — sync to server.
          return sendSubscriptionToServer(existing).then(function () {
            return existing;
          });
        }

        var appServerKey = urlBase64ToUint8Array(C.vapidKey);
        return reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: appServerKey
        }).then(function (sub) {
          console.info('[BD PWA] Push subscription created.');
          return sendSubscriptionToServer(sub).then(function () {
            return sub;
          });
        });
      });
    }).catch(function (err) {
      console.warn('[BD PWA] Subscribe failed:', err);
      return null;
    });
  }

  // ==========================================================
  // Unsubscribe
  // ==========================================================
  function unsubscribe() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      return Promise.resolve(false);
    }

    return navigator.serviceWorker.ready.then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        if (!sub) return false;
        return sub.unsubscribe().then(function (success) {
          if (success) {
            console.info('[BD PWA] Unsubscribed.');
          }
          return success;
        });
      });
    });
  }

  // ==========================================================
  // Send subscription to WP REST endpoint
  // ==========================================================
  function sendSubscriptionToServer(sub) {
    var payload;
    try {
      payload = sub.toJSON();
    } catch (e) {
      console.warn('[BD PWA] Failed to serialize subscription:', e);
      return Promise.resolve(false);
    }

    if (!payload || !payload.endpoint || !payload.keys) {
      return Promise.resolve(false);
    }

    var url = C.restUrl.replace(/\/$/, '') + '/push/subscribe';

    return fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': C.nonce
      },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json().then(function () {
        console.info('[BD PWA] Subscription synced with server.');
        return true;
      });
    }).catch(function (err) {
      console.warn('[BD PWA] Failed to sync subscription:', err);
      return false;
    });
  }

  // ==========================================================
  // Utility: base64url → Uint8Array
  // ==========================================================
  function urlBase64ToUint8Array(base64String) {
    var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    var base64 = (base64String + padding)
      .replace(/-/g, '+')
      .replace(/_/g, '/');
    var rawData = window.atob(base64);
    var outputArray = new Uint8Array(rawData.length);
    for (var i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
  }

  // ==========================================================
  // Handle foreground push (fallback if browser doesn't
  // dispatch to SW when tab is focused — rare).
  // ==========================================================
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', function (event) {
      if (!event.data || !event.data.type) return;
      if (event.data.type === 'bd-notification-click') {
        // Redirect if needed (the SW usually handles this).
        if (event.data.url) {
          window.location.href = event.data.url;
        }
      }
    });
  }

  // ==========================================================
  // Expose public API
  // ==========================================================
  window.BD_PWA = window.BD_PWA || {};
  window.BD_PWA.requestPermission = requestPermission;
  window.BD_PWA.subscribe = subscribe;
  window.BD_PWA.unsubscribe = unsubscribe;
  window.BD_PWA.register = register;

  // Kick off service worker registration immediately.
  register();

  // ==========================================================
  // Show "Install App" prompt if available
  // ==========================================================
  var deferredPrompt = null;

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    showInstallBanner();
  });

  function showInstallBanner() {
    if (document.getElementById('bd-install-banner')) return;

    var banner = document.createElement('div');
    banner.id = 'bd-install-banner';
    banner.style.cssText = '' +
      'position:fixed;bottom:20px;left:50%;transform:translateX(-50%);' +
      'background:#1F0D5E;color:#fff;padding:14px 22px;border-radius:16px;' +
      'box-shadow:0 20px 40px rgba(31,13,94,.35);z-index:99999;' +
      'font-family:Inter,-apple-system,sans-serif;font-size:13.5px;' +
      'display:flex;align-items:center;gap:14px;max-width:520px;';
    banner.innerHTML = '' +
      '<span style="font-size:20px;">📱</span>' +
      '<span style="flex:1;font-weight:600;">Install the Big Drop app for quick access &amp; notifications.</span>' +
      '<button type="button" id="bd-install-btn" style="background:#7FD344;color:#1F0D5E;border:none;border-radius:10px;padding:9px 16px;font-weight:700;cursor:pointer;font-family:inherit;font-size:13px;">Install</button>' +
      '<button type="button" id="bd-install-close" style="background:transparent;color:#C6CAF0;border:none;cursor:pointer;font-size:18px;padding:0 4px;">×</button>';
    document.body.appendChild(banner);

    var installBtn = document.getElementById('bd-install-btn');
    var closeBtn = document.getElementById('bd-install-close');

    if (installBtn) {
      installBtn.addEventListener('click', function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choiceResult) {
          if (choiceResult.outcome === 'accepted') {
            console.info('[BD PWA] User accepted install prompt.');
          }
          deferredPrompt = null;
          banner.remove();
        });
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        banner.remove();
        // Don't show again for this session.
        try { sessionStorage.setItem('bd_install_dismissed', '1'); } catch (e) {}
      });
    }

    // If dismissed already this session, remove immediately.
    try {
      if (sessionStorage.getItem('bd_install_dismissed') === '1') {
        banner.remove();
      }
    } catch (e) {}
  }

  // ==========================================================
  // Auto-prompt permission after first interaction
  // (only if the user is on the portal and hasn't been asked)
  // ==========================================================
  var prompted = false;
  function maybeAutoPrompt() {
    if (prompted) return;
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'default') return;

    // Wait 10 seconds of active session before asking.
    setTimeout(function () {
      if (Notification.permission !== 'default') return;
      if (document.visibilityState !== 'visible') return;
      prompted = true;
      // The banner in portal.php will trigger requestPermission.
      // Here we just prepare the SW in case the banner is not shown.
      if (window.BD_PWA && typeof window.BD_PWA.subscribe === 'function') {
        // Don't subscribe without permission; just ensure the SW is ready.
        navigator.serviceWorker.ready.catch(function () {});
      }
    }, 10000);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', maybeAutoPrompt);
  } else {
    maybeAutoPrompt();
  }

})();