/* ==========================================================
   Big Drop Visitor Widget — Clean v1.0.3
   Bug-fixed: no duplicates, no leaks, safe restore.
   ========================================================== */
(function () {
  'use strict';

  // -----------------------------------------------------------------
  // Guard: config must exist
  // -----------------------------------------------------------------
  if (typeof window.BD_WIDGET === 'undefined') return;

  var C = window.BD_WIDGET;

  // -----------------------------------------------------------------
  // Constants
  // -----------------------------------------------------------------
  var STORAGE_VISITOR = 'bd_widget_visitor_id';
  var STORAGE_HISTORY = 'bd_widget_history';
  var STORAGE_UNREAD  = 'bd_widget_unread_count';
  var MAX_HISTORY     = 100;

  // -----------------------------------------------------------------
  // State
  // -----------------------------------------------------------------
  var state = {
    open: false,
    started: false,
    visitorId: null,
    chatId: null,
    lastMessageId: 0,
    pollTimer: null,
    sending: false,
    history: [],
    unread: 0,
    pollInterval: 3000
  };

  // DOM refs (populated after root injection)
  var dom = {
    root: null,
    fab: null,
    panel: null,
    closeBtn: null,
    content: null,
    body: null,
    input: null,
    sendBtn: null,
    badge: null
  };

  // =============================================================
  // BOOT
  // =============================================================
  function boot() {
    if (document.getElementById('bd-widget-root')) return;

    injectRoot();
    cacheDom();
    bindEvents();
    restoreSession();

    // If we have a saved session, resume polling in the background.
    if (state.visitorId) {
      resumeSession();
      startPolling();
    }
  }

  // =============================================================
  // ROOT / PANEL / FAB
  // =============================================================
  function injectRoot() {
    var root = document.createElement('div');
    root.id = 'bd-widget-root';
    if (C.position === 'bottom-left') {
      root.classList.add('bd-widget-left');
    }
    root.style.setProperty('--bdw-color', C.color || '#7FD344');

    root.innerHTML =
      '<button type="button" class="bd-widget-fab" aria-label="Open chat">' +
        '<span class="bd-widget-fab-icon">💬</span>' +
        '<span class="bd-widget-badge" id="bdw-unread-badge" hidden>0</span>' +
      '</button>' +
      '<div class="bd-widget-panel" hidden>' +
        '<div class="bd-widget-head">' +
          '<div class="bd-widget-head-avatar">💧</div>' +
          '<div class="bd-widget-head-info">' +
            '<h3 class="bd-widget-head-title">' + escHtml(C.title) + '</h3>' +
            '<p class="bd-widget-head-sub">We usually reply in minutes</p>' +
          '</div>' +
          '<button type="button" class="bd-widget-close" aria-label="Close">✕</button>' +
        '</div>' +
        '<div class="bd-widget-content" id="bdw-content"></div>' +
        '<div class="bd-widget-branding">Powered by <strong>EazyLabz</strong></div>' +
      '</div>';

    document.body.appendChild(root);
  }

  function cacheDom() {
    dom.root     = document.getElementById('bd-widget-root');
    dom.fab      = dom.root.querySelector('.bd-widget-fab');
    dom.panel    = dom.root.querySelector('.bd-widget-panel');
    dom.closeBtn = dom.root.querySelector('.bd-widget-close');
    dom.content  = dom.root.querySelector('#bdw-content');
    dom.badge    = dom.root.querySelector('#bdw-unread-badge');
  }

  function bindEvents() {
    dom.fab.addEventListener('click', function () {
      togglePanel(!state.open);
    });
    dom.closeBtn.addEventListener('click', function () {
      togglePanel(false);
    });
  }

  // =============================================================
  // PANEL TOGGLE
  // =============================================================
  function togglePanel(open) {
    state.open = open;

    if (open) {
      dom.panel.removeAttribute('hidden');
      clearUnread();

      // Render appropriate view.
      if (!state.started) {
        renderStartForm();
      } else {
        renderChatView();
      }
    } else {
      dom.panel.setAttribute('hidden', '');
    }
  }

  // =============================================================
  // START FORM
  // =============================================================
  function renderStartForm() {
    dom.panel.classList.add('is-form');

    dom.content.innerHTML =
      '<div class="bd-widget-form" id="bdw-form">' +
        '<h3>' + escHtml(C.greeting) + '</h3>' +
        '<p>Tell us a bit about you so we can help you better.</p>' +
        '<input type="text" id="bdw-name" placeholder="Your name" autocomplete="name" maxlength="100">' +
        '<input type="tel" id="bdw-phone" placeholder="Phone number (optional)" autocomplete="tel" maxlength="30">' +
        '<input type="email" id="bdw-email" placeholder="Email (optional)" autocomplete="email" maxlength="150">' +
        '<button type="button" class="bd-widget-start" id="bdw-start">Start Chat</button>' +
        '<div class="bd-widget-error" id="bdw-start-error" hidden></div>' +
      '</div>';

    // Enter key submits.
    ['bdw-name', 'bdw-phone', 'bdw-email'].forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      el.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          startSession();
        }
      });
    });

    var startBtn = document.getElementById('bdw-start');
    if (startBtn) startBtn.addEventListener('click', startSession);
  }

  function startSession() {
    var nameEl  = document.getElementById('bdw-name');
    var phoneEl = document.getElementById('bdw-phone');
    var emailEl = document.getElementById('bdw-email');
    var errEl   = document.getElementById('bdw-start-error');
    var startBtn = document.getElementById('bdw-start');

    var name  = nameEl  ? nameEl.value.trim()  : '';
    var phone = phoneEl ? phoneEl.value.trim() : '';
    var email = emailEl ? emailEl.value.trim() : '';

    if (!name) {
      if (errEl) {
        errEl.textContent = 'Please enter your name.';
        errEl.hidden = false;
      }
      if (nameEl) nameEl.focus();
      return;
    }

    if (errEl) errEl.hidden = true;
    if (startBtn) {
      startBtn.disabled = true;
      startBtn.textContent = 'Starting…';
    }

    api('visitor/start', {
      method: 'POST',
      body: { name: name, phone: phone, email: email }
    }).then(function (res) {
      if (!res || !res.visitor_id) {
        throw new Error('Invalid server response');
      }

      state.visitorId    = res.visitor_id;
      state.chatId       = res.chat_id || 0;
      state.started      = true;
      state.lastMessageId = 0;
      state.history      = [];
      state.unread       = 0;

      persistSession();
      renderChatView();
      startPolling();
    }).catch(function (err) {
      if (errEl) {
        errEl.textContent = 'Could not start chat: ' + (err && err.message ? err.message : 'unknown error');
        errEl.hidden = false;
      }
      if (startBtn) {
        startBtn.disabled = false;
        startBtn.textContent = 'Start Chat';
      }
    });
  }

  // =============================================================
  // CHAT VIEW
  // =============================================================
  function renderChatView() {
    dom.panel.classList.remove('is-form');

    dom.content.innerHTML =
      '<div class="bd-widget-body" id="bdw-body"></div>' +
      '<div class="bd-widget-foot">' +
        '<textarea id="bdw-input" rows="1" placeholder="' + escAttr(C.i18n.placeholder) + '"></textarea>' +
        '<button type="button" class="bd-widget-send" id="bdw-send" aria-label="Send">➤</button>' +
      '</div>';

    // Cache new DOM refs.
    dom.body    = document.getElementById('bdw-body');
    dom.input   = document.getElementById('bdw-input');
    dom.sendBtn = document.getElementById('bdw-send');

    // Bind events.
    if (dom.input) {
      dom.input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          sendMessage();
        }
      });
      dom.input.addEventListener('input', function () {
        dom.input.style.height = 'auto';
        dom.input.style.height = Math.min(100, dom.input.scrollHeight) + 'px';
      });
    }
    if (dom.sendBtn) {
      dom.sendBtn.addEventListener('click', sendMessage);
    }

    // Render existing history.
    renderHistory();

    // Greeting messages on a fresh session.
    if (state.history.length === 0) {
      setTimeout(function () {
        pushMessage({
          sender_type: 'agent',
          message: C.greeting,
          created_at: nowIso()
        });
        pushMessage({
          sender_type: 'system',
          message: C.i18n.waiting
        });
      }, 300);
    }
  }

  function renderHistory() {
    if (!dom.body) return;
    dom.body.innerHTML = state.history.map(renderBubble).join('');
    scrollToBottom(false);
  }

  function renderBubble(m) {
    var type = m.sender_type || 'agent';
    var cls  = 'bd-widget-msg bd-widget-msg-' + type;

    if (type === 'system') {
      return '<div class="' + cls + '">' + escHtml(m.message) + '</div>';
    }
    return '<div class="' + cls + '">' +
      escHtml(m.message).replace(/\n/g, '<br>') +
      '</div>';
  }

  function pushMessage(m) {
    state.history.push(m);
    if (state.history.length > MAX_HISTORY) {
      state.history = state.history.slice(-MAX_HISTORY);
    }
    persistSession();

    if (dom.body) {
      dom.body.insertAdjacentHTML('beforeend', renderBubble(m));
      scrollToBottom(true);
    }
  }

  function scrollToBottom(smooth) {
    if (!dom.body) return;
    // Wait for the DOM to actually render before scrolling.
    requestAnimationFrame(function () {
      try {
        dom.body.scrollTo({
          top: dom.body.scrollHeight,
          behavior: smooth ? 'smooth' : 'auto'
        });
      } catch (e) {
        dom.body.scrollTop = dom.body.scrollHeight;
      }
    });
  }

  // =============================================================
  // SEND MESSAGE
  // =============================================================
  function sendMessage() {
    if (state.sending) return;
    if (!state.visitorId) return;
    if (!dom.input) return;

    var text = dom.input.value.trim();
    if (!text) return;

    state.sending = true;
    dom.input.value = '';
    dom.input.style.height = 'auto';
    if (dom.sendBtn) dom.sendBtn.disabled = true;

    // Optimistic UI: add message immediately.
    var optimistic = {
      id: null, // will be stamped by server response
      sender_type: 'visitor',
      message: text,
      created_at: nowIso()
    };
    pushMessage(optimistic);

    api('visitor/' + state.visitorId + '/send', {
      method: 'POST',
      body: { message: text }
    }).then(function (res) {
      if (!res || !res.id) {
        throw new Error('Invalid send response');
      }

      var serverId = parseInt(res.id, 10);

      // Track highest id so poll won't re-fetch.
      if (serverId > state.lastMessageId) {
        state.lastMessageId = serverId;
      }

      // Stamp the optimistic message with the server id so dedupe works.
      for (var i = state.history.length - 1; i >= 0; i--) {
        if (state.history[i].sender_type === 'visitor' &&
            state.history[i].message === text &&
            !state.history[i].id) {
          state.history[i].id = serverId;
          break;
        }
      }
      persistSession();
    }).catch(function (err) {
      pushMessage({
        sender_type: 'system',
        message: 'Failed to send: ' + (err && err.message ? err.message : 'unknown error')
      });
    }).then(function () {
      state.sending = false;
      if (dom.sendBtn) dom.sendBtn.disabled = false;
      if (dom.input) dom.input.focus();
    });
  }

  // =============================================================
  // RESUME / POLL
  // =============================================================
  function resumeSession() {
    if (!state.visitorId) return;

    api('visitor/' + state.visitorId + '/poll?after=0').then(function (data) {
      if (!data || !data.messages || !data.messages.length) return;

      state.history = data.messages.map(function (m) {
        return {
          id: parseInt(m.id, 10) || 0,
          sender_type: m.sender_type,
          message: m.message,
          created_at: m.created_at
        };
      });

      state.lastMessageId = state.history.reduce(function (max, m) {
        return Math.max(max, m.id || 0);
      }, 0);

      persistSession();
      if (state.open && state.started) {
        renderChatView();
      }
    }).catch(function () {
      // Session expired or invalid — reset local state.
      clearLocalSession();
    });
  }

  function startPolling() {
    stopPolling();

    // One immediate poll.
    pollMessages();

    state.pollTimer = setInterval(function () {
      pollMessages();
    }, state.pollInterval);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  function pollMessages() {
    if (!state.visitorId) return;

    api('visitor/' + state.visitorId + '/poll?after=' + state.lastMessageId)
      .then(function (data) {
        if (!data || !data.messages || !data.messages.length) return;

        data.messages.forEach(function (m) {
          var id = parseInt(m.id, 10) || 0;
          if (!id) return;

          // Skip if already in history.
          var alreadyShown = state.history.some(function (h) {
            return parseInt(h.id, 10) === id;
          });
          if (alreadyShown) return;

          // Handle our own echoed message (still pending with no id).
          if (m.sender_type === 'visitor') {
            var matched = false;
            for (var i = state.history.length - 1; i >= 0; i--) {
              if (state.history[i].sender_type === 'visitor' &&
                  state.history[i].message === m.message &&
                  !state.history[i].id) {
                state.history[i].id = id;
                matched = true;
                break;
              }
            }
            if (matched) {
              if (id > state.lastMessageId) state.lastMessageId = id;
              persistSession();
              return;
            }
          }

          // New message from server.
          if (id > state.lastMessageId) state.lastMessageId = id;

          pushMessage({
            id: id,
            sender_type: m.sender_type,
            message: m.message,
            created_at: m.created_at
          });

          if (m.sender_type === 'agent' && !state.open) {
            incrementUnread();
            playPing();
          }
        });

        persistSession();
      })
      .catch(function () {
        // Silent: network hiccup, retry next poll.
      });
  }

  // =============================================================
  // UNREAD BADGE
  // =============================================================
  function incrementUnread() {
    state.unread = (state.unread || 0) + 1;
    updateUnreadBadge();
    persistSession();
  }

  function clearUnread() {
    state.unread = 0;
    updateUnreadBadge();
    persistSession();
  }

  function updateUnreadBadge() {
    if (!dom.badge) return;
    if (state.unread > 0) {
      dom.badge.textContent = state.unread > 9 ? '9+' : String(state.unread);
      dom.badge.hidden = false;
    } else {
      dom.badge.hidden = true;
    }
  }

  // =============================================================
  // SOUND
  // =============================================================
  var audioCtx = null;
  function playPing() {
    try {
      if (!audioCtx) {
        var AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return;
        audioCtx = new AC();
      }
      // Some browsers require a user gesture before audio.
      if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(function () {});
      }
      var osc = audioCtx.createOscillator();
      var gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(880, audioCtx.currentTime);
      osc.frequency.exponentialRampToValueAtTime(1200, audioCtx.currentTime + 0.1);
      gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.4);
      osc.connect(gain).connect(audioCtx.destination);
      osc.start();
      osc.stop(audioCtx.currentTime + 0.4);
    } catch (e) { /* silent */ }
  }

  // =============================================================
  // PERSISTENCE
  // =============================================================
  function restoreSession() {
    try {
      var savedVisitor = localStorage.getItem(STORAGE_VISITOR);
      if (savedVisitor) {
        state.visitorId = savedVisitor;
        state.started = true;
      }

      var savedHistory = localStorage.getItem(STORAGE_HISTORY);
      if (savedHistory) {
        var parsed = JSON.parse(savedHistory);
        if (Array.isArray(parsed)) {
          state.history = parsed;
          state.history.forEach(function (m) {
            var id = parseInt(m.id, 10) || 0;
            if (id > state.lastMessageId) state.lastMessageId = id;
          });
        }
      }

      var savedUnread = localStorage.getItem(STORAGE_UNREAD);
      if (savedUnread) {
        var u = parseInt(savedUnread, 10);
        if (!isNaN(u) && u >= 0) state.unread = u;
      }

      // Update badge based on restored state.
      updateUnreadBadge();
    } catch (e) {
      // Storage blocked or corrupted — start fresh.
      state.history = [];
      state.lastMessageId = 0;
      state.unread = 0;
    }
  }

  function persistSession() {
    try {
      if (state.visitorId) {
        localStorage.setItem(STORAGE_VISITOR, state.visitorId);
      }
      localStorage.setItem(STORAGE_HISTORY, JSON.stringify(state.history.slice(-MAX_HISTORY)));
      localStorage.setItem(STORAGE_UNREAD, String(state.unread || 0));
    } catch (e) { /* silent */ }
  }

  function clearLocalSession() {
    try {
      localStorage.removeItem(STORAGE_VISITOR);
      localStorage.removeItem(STORAGE_HISTORY);
      localStorage.removeItem(STORAGE_UNREAD);
    } catch (e) { /* silent */ }

    state.visitorId = null;
    state.chatId = null;
    state.started = false;
    state.history = [];
    state.lastMessageId = 0;
    state.unread = 0;
    stopPolling();
    updateUnreadBadge();
  }

  // =============================================================
  // API
  // =============================================================
  function api(path, options) {
    options = options || {};
    var url = C.restUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
    var headers = {};

    // Visitor endpoints are public — no nonce needed.
    if (path.indexOf('visitor/') !== 0) {
      headers['X-WP-Nonce'] = C.nonce;
    }

    if (options.body && typeof options.body !== 'string') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    return fetch(url, {
      method: options.method || 'GET',
      headers: headers,
      body: options.body || null,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; }
        catch (e) { data = { message: text }; }

        if (!res.ok) {
          var err = new Error((data && data.message) ? data.message : ('HTTP ' + res.status));
          err.status = res.status;
          err.data = data;
          throw err;
        }
        return data;
      });
    });
  }

  // =============================================================
  // UTILITIES
  // =============================================================
  function escHtml(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  function escAttr(str) { return escHtml(str); }

  function nowIso() {
    try { return new Date().toISOString(); }
    catch (e) { return '' + Date.now(); }
  }

  // =============================================================
  // READY
  // =============================================================
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();