/* ==========================================================
   Big Drop Agent Portal — Main SPA (Optimized v1.6.4)
   Fixed: Reopen/Reassign buttons in chatroom thread now work.
          Uses event delegation to survive re-renders.
   ========================================================== */
(function () {
  'use strict';

  if (typeof window.BD === 'undefined') {
    console.warn('[BD] window.BD config missing — script aborted.');
    return;
  }
  var config = window.BD;

  var state = {
    view: 'dashboard',
    chats: [],
    tabCounts: { new: 0, active: 0, resolved: 0, unresolved: 0 },
    perfFrom: null, perfTo: null, perfAgentId: '', perfRange: '7d', perfTab: 'performance',
    histSearch: '', histStatus: 'all', histPage: 1, histPerPage: 25, histTotalPages: 1,
    agentsList: [],
    activeChatId: null, activeTab: 'new',
    messages: {}, lastMessageId: {},
    notifications: [], unreadCount: 0, lastNotifId: 0,
    soundEnabled: !!config.settings.soundEnabled,
    pollTimers: {}, heartbeatTimer: null, notificationTimer: null,
    isPollingChat: false, drafts: {},
    cachedCanned: null,
    emptyStreak: 0,
    notifInitialSyncDone: false
  };

  var reassignState = { chatId: null, reopenMode: false };
  var $app, $sidebar, $topbarTitle, $toastContainer;
  var modalBound = false;
  // FIX: Guard flag to prevent double-binding thread delegation
  var threadDelegationBound = false;

  function closeReassignModal() {
    var modal = document.getElementById('bd-reassign-modal');
    if (modal) {
      modal.hidden = true; modal.style.display = 'none';
      var sel = document.getElementById('bd-reassign-select');
      if (sel) sel.innerHTML = '<option value="">Select agent…</option>';
    }
    reassignState.chatId = null; reassignState.reopenMode = false;
  }

  function boot() {
    $app = document.getElementById('bigdrop-app');
    if (!$app) { console.warn('[BD] app container not found — aborting boot.'); return; }
    if (boot.done) return;
    boot.done = true;

    $sidebar = document.getElementById('bd-sidebar');
    $topbarTitle = document.getElementById('bd-topbar-title');
    $toastContainer = document.getElementById('bd-toasts');

    initThemeToggle(); initSidebar(); initNavigation(); initNotifications();
    initSoundToggle(); initGlobalSearch(); initPushPrompt();
    
    // Bind the reassign modal ONCE at boot time
    bindReassignModal();
    // FIX: Bind thread button delegation ONCE at boot time
    bindThreadDelegation();
    
    injectRefreshButton();
    checkVersionAndRefresh();

    routeFromHash(); closeReassignModal();
    startHeartbeat();
    
    loadLastSeenNotifId();
    fetchNotifications().then(function () {
      state.notifInitialSyncDone = true;
      startNotificationPoll();
    });

    loadDashboard(); loadRoster();

    window.addEventListener('online', function () {
      toast('Connection restored. Syncing queued messages...', 'success');
      document.body.classList.remove('bd-offline-mode');
      flushOfflineQueue();
      if (state.activeChatId && !state.isPollingChat) startChatPoll(state.activeChatId);
    });
    window.addEventListener('offline', function () {
      toast('You are offline. Messages will be queued.', 'warning');
      document.body.classList.add('bd-offline-mode');
    });

    console.log('[BD] Portal booted successfully.');
  }

  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); }
  else { setTimeout(boot, 0); }
  window.BDBoot = boot;

  // ==========================================================
  // FIX: THREAD BUTTON DELEGATION
  // Uses a single delegated listener on #bd-thread that handles
  // clicks on reopen/reassign buttons. This survives re-renders
  // triggered by the long-polling loop.
  // ==========================================================
  function bindThreadDelegation() {
    if (threadDelegationBound) return;
    threadDelegationBound = true;

    var thread = document.getElementById('bd-thread');
    if (!thread) {
      console.warn('[BD] Cannot bind thread delegation: #bd-thread not found.');
      return;
    }

    thread.addEventListener('click', function (e) {
      var target = e.target;

      // Walk up to find a button (in case user clicked an inner element)
      while (target && target !== thread) {
        if (target.id === 'bd-reopen-chat') {
          e.preventDefault();
          e.stopPropagation();
          if (state.activeChatId) {
            console.log('[BD] Reopen clicked via delegation for chat:', state.activeChatId);
            openReassignModal(state.activeChatId, true);
          }
          return;
        }
        if (target.id === 'bd-reassign-chat') {
          e.preventDefault();
          e.stopPropagation();
          if (state.activeChatId) {
            console.log('[BD] Reassign clicked via delegation for chat:', state.activeChatId);
            openReassignModal(state.activeChatId, false);
          }
          return;
        }
        target = target.parentNode;
      }
    });

    console.log('[BD] Thread button delegation bound.');
  }

  // ==========================================================
  // NOTIFICATION ID PERSISTENCE
  // ==========================================================
  function loadLastSeenNotifId() {
    try {
      var saved = localStorage.getItem('bd_last_notif_id');
      if (saved) {
        state.lastNotifId = parseInt(saved, 10) || 0;
      }
    } catch (e) {}
  }

  function saveLastSeenNotifId(id) {
    try {
      localStorage.setItem('bd_last_notif_id', String(id));
    } catch (e) {}
  }

  // ==========================================================
  // HARD REFRESH & VERSION CHECK
  // ==========================================================
  function hardRefreshApp(showMessage) {
    console.log('[BD] Starting hard refresh...');
    try {
      var keysToKeep = ['bd_theme', 'bd_sidebar_collapsed', 'bd_sound', 'bd_app_version', 'bd_last_notif_id'];
      var allKeys = [];
      for (var i = 0; i < localStorage.length; i++) allKeys.push(localStorage.key(i));
      allKeys.forEach(function(key) {
        if (keysToKeep.indexOf(key) === -1) localStorage.removeItem(key);
      });
    } catch (e) {}
    try { sessionStorage.clear(); } catch (e) {}

    if ('caches' in window) {
      caches.keys().then(function(names) {
        names.forEach(function(name) {
          if (name.indexOf('bigdrop') !== -1 || name.indexOf('bd-') !== -1) caches.delete(name);
        });
      });
    }
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations().then(function(registrations) {
        registrations.forEach(function(reg) {
          if (reg.scope.indexOf('bigdrop') !== -1 || reg.scope.indexOf(window.location.origin) !== -1) reg.unregister();
        });
      });
    }

    if (showMessage !== false) toast('Refreshing app... Clearing cache.', 'success');
    setTimeout(function() { window.location.reload(true); }, 800);
  }

  function checkVersionAndRefresh() {
    var currentVersion = config.version || '1.0.0';
    var storedVersion = null;
    try { storedVersion = localStorage.getItem('bd_app_version'); } catch (e) {}

    if (storedVersion && storedVersion !== currentVersion) {
      toast('New update available! Refreshing...', 'success');
      try { localStorage.setItem('bd_app_version', currentVersion); } catch (e) {}
      setTimeout(function() { hardRefreshApp(false); }, 1500);
    } else if (!storedVersion) {
      try { localStorage.setItem('bd_app_version', currentVersion); } catch (e) {}
    }
  }

  function injectRefreshButton() {
    var topbarRight = document.querySelector('.bd-topbar-right');
    if (!topbarRight || document.getElementById('bd-refresh-btn')) return;

    var btn = document.createElement('button');
    btn.type = 'button';
    btn.id = 'bd-refresh-btn';
    btn.className = 'bd-icon-btn';
    btn.title = 'Refresh App (Clear Cache)';
    btn.innerHTML = '↻';
    btn.style.cssText = 'font-size:18px;font-weight:700;';

    btn.addEventListener('click', function(e) {
      e.preventDefault();
      if (confirm('Refresh the app? This will clear the cache and reload to get the latest features.')) {
        hardRefreshApp(true);
      }
    });

    var statusPill = topbarRight.querySelector('.bd-status-pill');
    if (statusPill) topbarRight.insertBefore(btn, statusPill);
    else topbarRight.appendChild(btn);

    document.addEventListener('keydown', function(e) {
      if (e.ctrlKey && e.shiftKey && e.key === 'R') {
        e.preventDefault();
        hardRefreshApp(true);
      }
    });
  }

  // ==========================================================
  // THEME
  // ==========================================================
  function initThemeToggle() {
    var toggleBtn = document.getElementById('bd-theme-toggle');
    var savedTheme = null;
    try { savedTheme = localStorage.getItem('bd_theme'); } catch (e) {}
    var systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    var currentTheme = savedTheme || (systemPrefersDark ? 'dark' : 'light');
    applyTheme(currentTheme);
    if (!toggleBtn) return;
    toggleBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var current = document.documentElement.getAttribute('data-theme') || (systemPrefersDark ? 'dark' : 'light');
      var newTheme = current === 'dark' ? 'light' : 'dark';
      applyTheme(newTheme);
      try { localStorage.setItem('bd_theme', newTheme); } catch (err) {}
    });
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
        var hasSaved = false;
        try { hasSaved = !!localStorage.getItem('bd_theme'); } catch (err) {}
        if (!hasSaved) applyTheme(e.matches ? 'dark' : 'light');
      });
    }
  }

  function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme === 'dark' ? 'dark' : 'light');
  }

  // ==========================================================
  // SIDEBAR & NAVIGATION
  // ==========================================================
  function initSidebar() {
    var toggleBtn = document.getElementById('bd-sidebar-toggle');
    var iconEl = toggleBtn ? toggleBtn.querySelector('.bd-toggle-icon') : null;
    try {
      if (localStorage.getItem('bd_sidebar_collapsed') === 'true') {
        $app.classList.add('bd-collapsed');
        if (iconEl) iconEl.textContent = '▶';
      }
    } catch (e) {}
    if (!toggleBtn) return;
    toggleBtn.addEventListener('click', function () {
      $app.classList.toggle('bd-collapsed');
      var collapsed = $app.classList.contains('bd-collapsed');
      if (iconEl) iconEl.textContent = collapsed ? '▶' : '◀';
      try { localStorage.setItem('bd_sidebar_collapsed', collapsed ? 'true' : 'false'); } catch (e) {}
    });
    if (window.innerWidth < 900) {
      $app.classList.add('bd-collapsed');
      if (iconEl) iconEl.textContent = '▶';
    }
  }

  function initNavigation() {
    document.querySelectorAll('.bd-nav-item[data-view]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        var view = link.getAttribute('data-view'); if (!view) return;
        setHash(view); switchView(view);
      });
    });
    document.querySelectorAll('[data-goto]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var view = btn.getAttribute('data-goto'); if (!view) return;
        setHash(view); switchView(view);
      });
    });
    window.addEventListener('hashchange', function () {
      var hash = (location.hash || '#dashboard').replace('#', '');
      if (hash && hash !== state.view) switchView(hash);
    });
  }

  function setHash(view) {
    try {
      if (window.history && window.history.replaceState) window.history.replaceState(null, '', '#' + view);
      else location.hash = '#' + view;
    } catch (e) { try { location.hash = '#' + view; } catch (e2) {} }
  }

  function routeFromHash() {
    var hash = (location.hash || '#dashboard').replace('#', '');
    if (!hash) hash = 'dashboard';
    if (!document.getElementById('bd-view-' + hash)) hash = 'dashboard';
    switchView(hash);
  }

  function switchView(view) {
    if (state.view === view && view !== 'chatroom' && view !== 'internal') return;
    closeReassignModal();
    var targetView = document.getElementById('bd-view-' + view);
    if (!targetView) { view = 'dashboard'; targetView = document.getElementById('bd-view-dashboard'); }
    if (!targetView) return;
    state.view = view;
    document.querySelectorAll('.bd-nav-item').forEach(function (a) {
      a.classList.toggle('is-active', a.getAttribute('data-view') === view);
    });
    document.querySelectorAll('.bd-view').forEach(function (v) { v.classList.remove('is-active'); });
    targetView.classList.add('is-active');

    var labels = {
      dashboard: 'Dashboard', chatroom: 'Chatroom', internal: 'Team Chat',
      performance: 'Agent Performance', clients: 'Client Accounts',
      canned: 'Pre-Reply Messages', agents: 'Add an Agent'
    };
    if ($topbarTitle) $topbarTitle.textContent = labels[view] || 'Dashboard';

    if (view === 'dashboard') { loadDashboard(); loadRoster(); }
    else if (view === 'chatroom') loadChats();
    else if (view === 'internal' && window.BDInternalChat && typeof window.BDInternalChat.refresh === 'function') window.BDInternalChat.refresh();
    else if (view === 'performance') loadPerformance();
    else if (view === 'canned') loadCanned();
    else if (view === 'agents') loadAgents();
    else if (view === 'clients') initClientsSearch();
  }

  // ==========================================================
  // API WRAPPER
  // ==========================================================
  function api(path, options) {
    options = options || {};
    var url = config.restUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
    var headers = { 'X-WP-Nonce': config.nonce };
    if (options.body && typeof options.body !== 'string') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }
    var timeoutMs = options.timeoutMs || 45000;
    var controller = null;
    var timeoutId = null;
    var fetchOpts = {
      method: options.method || 'GET',
      headers: Object.assign(headers, options.headers || {}),
      body: options.body || null,
      credentials: 'same-origin'
    };

    if (typeof AbortController !== 'undefined') {
      controller = new AbortController();
      fetchOpts.signal = controller.signal;
      timeoutId = setTimeout(function () { controller.abort(); }, timeoutMs);
    }

    return fetch(url, fetchOpts).then(function (res) {
      if (timeoutId) clearTimeout(timeoutId);
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; } catch (e) { data = { message: text }; }
        if (!res.ok) {
          var err = new Error((data && data.message) || ('HTTP ' + res.status));
          err.data = data; err.status = res.status;
          throw err;
        }
        return data;
      });
    }).catch(function (err) {
      if (timeoutId) clearTimeout(timeoutId);
      if (err && err.name === 'AbortError') {
        var e2 = new Error('Request timed out');
        e2.isTimeout = true;
        throw e2;
      }
      throw err;
    });
  }

  function getAdaptivePollInterval() {
    if (!navigator.onLine) return 0;
    var conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
    if (conn) {
      if (conn.effectiveType === 'slow-2g' || conn.effectiveType === '2g') return 20000;
      if (conn.effectiveType === '3g') return 10000;
      if (conn.saveData) return 15000;
    }
    return 5000;
  }

  // ==========================================================
  // DASHBOARD & ROSTER
  // ==========================================================
  function loadDashboard() {
    api('stats/dashboard').then(function (data) {
      setText('bd-stat-today', data.today);
      setText('bd-stat-waiting', data.today_pending);
      setText('bd-stat-resolution', data.resolution_rate + '%');
      setText('bd-stat-response', formatResponseTime(data.avg_response));
      setText('bd-stat-today-trend', trendLabel(data.today, 'today'));
      setText('bd-stat-rate-trend', data.resolution_rate + '%');
      setText('bd-mini-all-time', data.all_time);
      setText('bd-mini-resolved', data.all_resolved);
      setText('bd-mini-unresolved', data.all_unresolved);
      setText('bd-mini-visitors', data.all_visitors);
      var sub = document.getElementById('bd-welcome-sub');
      if (sub) {
        var waiting = data.today_pending;
        sub.textContent = waiting > 0
          ? 'You have ' + waiting + ' chat' + (waiting === 1 ? '' : 's') + ' waiting in the queue.'
          : 'All caught up. Great work!';
      }
      updateDonut(data);
      drawWeeklyChart(data.weekly || []);
    }).catch(function (err) { console.warn('[BD] Dashboard load failed:', err.message); });
  }

  function updateDonut(data) {
    var total = data.all_time || 0, resolved = data.all_resolved || 0, unresolved = data.all_unresolved || 0;
    var pending = Math.max(0, total - resolved - unresolved);
    var rPct = total ? Math.round((resolved / total) * 100) : 0;
    var pPct = total ? Math.round((pending / total) * 100) : 0;
    var uPct = Math.max(0, 100 - rPct - pPct);
    var donut = document.getElementById('bd-donut');
    if (donut) {
      donut.style.background = 'conic-gradient(#7FD344 0 ' + rPct + '%, #4E7CDD ' + rPct + '% ' +
        (rPct + pPct) + '%, #E9EBF2 ' + (rPct + pPct) + '% 100%)';
    }
    setText('bd-donut-value', rPct + '%');
    setText('bd-legend-resolved', rPct + '%');
    setText('bd-legend-pending', pPct + '%');
    setText('bd-legend-unresolved', uPct + '%');
  }

  function drawWeeklyChart(weekly) {
    var svg = document.getElementById('bd-chart-week'); if (!svg) return;
    var linesGroup = svg.querySelector('#bd-chart-lines');
    var labelsEl = document.getElementById('bd-chart-labels');
    if (!linesGroup) return;
    var W = 520, H = 180, padTop = 20, padBottom = 30, padX = 20;
    var counts = weekly.map(function (w) { return parseInt(w.count, 10) || 0; });
    var max = Math.max(1, Math.max.apply(null, counts));
    var stepX = (W - padX * 2) / Math.max(1, counts.length - 1);
    var points = counts.map(function (c, i) {
      var x = padX + i * stepX;
      var y = H - padBottom - (c / max) * (H - padTop - padBottom);
      return [x, y];
    });
    var polyPts = points.map(function (p) { return p[0].toFixed(1) + ',' + p[1].toFixed(1); }).join(' ');
    var areaPts = polyPts + ' ' + (W - padX) + ',' + (H - padBottom) + ' ' + padX + ',' + (H - padBottom);
    linesGroup.innerHTML = '';
    var area = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
    area.setAttribute('fill', 'url(#bdGrad1)');
    area.setAttribute('points', areaPts);
    linesGroup.appendChild(area);
    var poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
    poly.setAttribute('fill', 'none');
    poly.setAttribute('stroke', '#7FD344');
    poly.setAttribute('stroke-width', '3');
    poly.setAttribute('stroke-linecap', 'round');
    poly.setAttribute('stroke-linejoin', 'round');
    poly.setAttribute('points', polyPts);
    linesGroup.appendChild(poly);
    points.forEach(function (p, i) {
      var c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      c.setAttribute('cx', p[0]); c.setAttribute('cy', p[1]); c.setAttribute('r', 4);
      c.setAttribute('fill', i === points.length - 1 ? '#1F0D5E' : '#7FD344');
      linesGroup.appendChild(c);
    });
    if (labelsEl) {
      labelsEl.innerHTML = '';
      weekly.forEach(function (w) {
        var span = document.createElement('span');
        span.textContent = w.label || '';
        labelsEl.appendChild(span);
      });
    }
  }

  function loadRoster() {
    api('roster').then(function (rows) {
      var container = document.getElementById('bd-roster'); if (!container) return;
      if (!rows.length) { container.innerHTML = '<div class="bd-empty">No agents.</div>'; return; }
      container.innerHTML = rows.map(function (r) {
        var statusLabel = r.status === 'online' ? 'Online' : r.status === 'taking' ? 'Taking chats' : 'Offline';
        return '<div class="bd-roster-row"><div class="bd-roster-avatar">' +
          esc(r.initials || initials(r.name)) +
          '<span class="bd-status-dot is-' + escAttr(r.status) + '"></span></div>' +
          '<div class="bd-roster-name">' + esc(r.name) + '</div>' +
          '<div class="bd-roster-status">' + statusLabel + '</div></div>';
      }).join('');
    }).catch(function (err) { console.warn('[BD] Roster load failed:', err.message); });
  }

  // ==========================================================
  // CHATROOM
  // ==========================================================
  function initChatTabs() {
    var tabs = document.querySelectorAll('.bd-conv-tab'); if (!tabs.length) return;
    tabs.forEach(function (tab) {
      if (tab.dataset.bdBound === '1') return;
      tab.dataset.bdBound = '1';
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        state.activeTab = tab.getAttribute('data-tab') || 'new';
        loadChats();
      });
    });
  }

  function loadChats() {
    initChatTabs();
    api('chats?status=' + encodeURIComponent(state.activeTab || 'new')).then(function (data) {
      state.chats = (data && data.rows) || [];
      state.tabCounts = (data && data.counts) || {};
      renderConvList(state.chats);
      renderTabCounts(state.tabCounts);
      updateChatNavBadge(state.chats);
    }).catch(function (err) { console.warn('[BD] Chats load failed:', err.message); });
  }

  function renderTabCounts(counts) {
    var map = { new: 'bd-tab-count-new', active: 'bd-tab-count-active', resolved: 'bd-tab-count-resolved', unresolved: 'bd-tab-count-unresolved' };
    Object.keys(map).forEach(function (k) {
      var el = document.getElementById(map[k]); if (!el) return;
      var n = parseInt(counts[k], 10) || 0;
      el.textContent = n; el.hidden = n === 0;
    });
  }

  function renderConvList(rows) {
    var container = document.getElementById('bd-conv-list'); if (!container) return;
    if (!rows || !rows.length) {
      container.innerHTML = '<div class="bd-empty">No chats in this tab.</div>';
      return;
    }
    container.innerHTML = rows.map(function (c) {
      var waiting = parseInt(c.waiting_seconds, 10) || 0;
      var isUrgent = parseInt(c.priority, 10) === 2 || waiting > (config.settings.escalateMinutes * 60);
      var unread = parseInt(c.unread_count, 10) || 0;
      var timeStr = timeAgo(c.updated_at || c.created_at);
      var preview = c.last_message ? truncate(c.last_message, 48) : 'No messages yet';
      var badges = '';
      if (isUrgent) badges += '<span class="bd-conv-badge is-urgent">' + formatWaiting(waiting) + '</span>';
      if (unread > 0) badges += '<span class="bd-conv-badge is-unread">' + unread + ' new</span>';
      var isActive = state.activeChatId === parseInt(c.id, 10) ? ' is-active' : '';
      var agentAvatar = c.agent_initials
        ? '<div class="bd-conv-agent-avatar" title="' + escAttr(c.agent_name || '') + '">' + esc(c.agent_initials) + '</div>'
        : '';
      return '<div class="bd-conv-item' + isActive + '" data-chat-id="' + c.id + '">' +
        '<div class="bd-conv-item-top">' +
          '<div class="bd-conv-name">' + esc(c.visitor_name || ('Visitor #' + c.id)) + '</div>' +
          '<div class="bd-conv-right">' + agentAvatar + '<div class="bd-conv-time">' + timeStr + '</div></div>' +
        '</div>' +
        '<div class="bd-conv-preview">' + esc(preview) + '</div>' +
        (badges ? '<div class="bd-conv-badges">' + badges + '</div>' : '') +
      '</div>';
    }).join('');
    container.querySelectorAll('.bd-conv-item').forEach(function (item) {
      item.addEventListener('click', function () {
        openChat(parseInt(item.getAttribute('data-chat-id'), 10));
      });
    });
    if (!state.activeChatId && window.innerWidth > 900) {
      var first = container.querySelector('.bd-conv-item');
      if (first) openChat(parseInt(first.getAttribute('data-chat-id'), 10));
    }
  }

  function updateChatNavBadge(rows) {
    var badge = document.getElementById('bd-nav-chat-badge'); if (!badge) return;
    var newCount = rows.filter(function (c) { return c.status === 'new'; }).length;
    if (newCount > 0) { badge.textContent = newCount; badge.hidden = false; }
    else { badge.hidden = true; }
  }

  function openChat(chatId) {
    if (state.activeChatId && state.activeChatId !== chatId) stopChatPoll(state.activeChatId);
    api('chats/' + chatId).then(function (data) {
      var chat = data.chat;
      state.activeChatId = chatId;
      if (state.lastMessageId[chatId] === undefined) state.lastMessageId[chatId] = 0;
      document.querySelectorAll('.bd-conv-item').forEach(function (item) {
        item.classList.toggle('is-active', parseInt(item.getAttribute('data-chat-id'), 10) === chatId);
      });
      state.messages[chatId] = data.messages || [];
      state.messages[chatId].forEach(function (m) {
        var id = parseInt(m.id, 10);
        if (id > state.lastMessageId[chatId]) state.lastMessageId[chatId] = id;
      });
      renderThread(chat, data.messages);
      startChatPoll(chatId);
    }).catch(function (err) { toast('Failed to open chat: ' + err.message, 'error'); });
  }

  function renderThread(chat, messages) {
    var thread = document.getElementById('bd-thread'); if (!thread) return;
    var level = config.internalLevel || 'agent';
    var canManage = (level === 'team_lead' || level === 'admin');
    var isMine = parseInt(chat.assigned_agent_id, 10) === parseInt(config.currentUser.id, 10);
    var isClosed = chat.status === 'resolved' || chat.status === 'unresolved';
    var isActive = chat.status === 'active' || (chat.assigned_agent_id && !isClosed);
    var isNew = chat.status === 'new';
    var canReply = !(isClosed && !canManage);
    var waiting = parseInt(chat.waiting_seconds, 10) || 0;

    var resolveBtn = '';
    if (isClosed) {
      if (canManage) {
        resolveBtn = '<button type="button" class="bd-btn" id="bd-reopen-chat">Reopen</button>' +
          '<button type="button" class="bd-btn bd-btn-ghost" id="bd-reassign-chat" style="background:#F5F7FC;color:#1F0D5E;">Reassign</button>';
      } else {
        resolveBtn = '<span class="bd-thread-locked">Ask a team lead to reopen</span>';
      }
    } else {
      var canResolve = canManage || isMine || isNew;
      if (canResolve) {
        resolveBtn = '<button type="button" class="bd-btn" id="bd-resolve-chat">Resolve</button>' +
          '<button type="button" class="bd-btn bd-btn-ghost" id="bd-unresolve-chat" style="background:#FEE6E2;color:#C11501;">Unresolve</button>' +
          (isActive && (isMine || canManage)
            ? '<button type="button" class="bd-btn bd-btn-ghost" id="bd-release-chat" style="background:#F5F7FC;color:#1F0D5E;">Release</button>'
            : '');
      }
    }

    var footerHtml = canReply
      ? '<div class="bd-thread-foot">' +
          '<select id="bd-canned-select"><option value="">Canned reply…</option></select>' +
          '<textarea id="bd-reply-input" placeholder="' + escAttr(config.i18n.typeReply) + '" rows="1"></textarea>' +
          '<button type="button" class="bd-btn" id="bd-send-btn">' + esc(config.i18n.send) + '</button>' +
        '</div>'
      : '<div class="bd-thread-foot bd-thread-foot-locked"><div class="bd-thread-locked-msg">This chat is closed. Only Team Leads and Admins can reply.</div></div>';

    thread.innerHTML =
      '<div class="bd-thread-head">' +
        '<div class="bd-thread-title">' +
          '<div class="bd-thread-name">' + esc(chat.visitor_name || ('Visitor #' + chat.id)) + '</div>' +
          '<div class="bd-thread-meta">' +
            '<span>' + esc(chat.visitor_phone || 'No phone') + '</span>' +
            '<span>' + esc(chat.visitor_email || 'No email') + '</span>' +
            (chat.agent_name ? '<span>Agent: ' + esc(chat.agent_name) + '</span>' : '') +
            (waiting > 0 ? '<span class="bd-waiting">Waiting: ' + formatWaiting(waiting) + '</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="bd-thread-actions">' + resolveBtn + '</div>' +
      '</div>' +
      '<div class="bd-thread-body">' + (messages || []).map(function (m) { return renderMessage(m, chat); }).join('') + '</div>' +
      footerHtml;

    var body = thread.querySelector('.bd-thread-body');
    if (body) body.scrollTop = body.scrollHeight;
    bindThreadActions(chat);
  }

  function renderMessage(m, chat) {
    var isVisitor = m.sender_type === 'visitor';
    var isSystem = m.sender_type === 'system';
    var time = formatTime(m.created_at);

    if (isSystem) {
      return '<div class="bd-bubble bd-bubble-system">' +
        esc(m.message) +
        (m.system_agent_name ? ' <em>by ' + esc(m.system_agent_name) + '</em>' : '') +
        '<div class="bd-bubble-time">' + time + '</div>' +
      '</div>';
    }

    var bubbleClass = isVisitor ? 'bd-bubble-visitor' : 'bd-bubble-agent';
    var name = isVisitor ? (chat.visitor_name || 'Visitor') : (m.sender_name || 'Agent');

    return '<div class="bd-bubble ' + bubbleClass + '">' +
      '<div style="font-size:11px;font-weight:700;margin-bottom:2px;opacity:0.85;">' + esc(name) + '</div>' +
      '<div>' + esc(m.message).replace(/\n/g, '<br>') + '</div>' +
      '<div class="bd-bubble-time">' + time + '</div>' +
    '</div>';
  }

  function bindThreadActions(chat) {
    // FIX: Removed direct binding for reopen/reassign.
    // These are handled by the delegated listener in bindThreadDelegation().

    var sendBtn = document.getElementById('bd-send-btn');
    var input = document.getElementById('bd-reply-input');
    var canned = document.getElementById('bd-canned-select');

    if (sendBtn && input) {
      sendBtn.addEventListener('click', function () {
        var text = input.value.trim(); if (!text || !state.activeChatId) return;
        input.value = ''; input.style.height = 'auto';
        sendMessage(state.activeChatId, text);
      });
      input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendBtn.click(); }
      });
      input.addEventListener('input', function () {
        input.style.height = 'auto'; input.style.height = input.scrollHeight + 'px';
      });
    }

    if (canned) {
      loadCannedForSelect(canned);
      canned.addEventListener('change', function () {
        var val = canned.value; if (!val || !input) return;
        var item = (state.cachedCanned || []).find(function (c) { return parseInt(c.id, 10) === parseInt(val, 10); });
        if (item) { input.value = item.message; input.focus(); canned.value = ''; }
      });
    }

    var resolveBtn = document.getElementById('bd-resolve-chat');
    if (resolveBtn) resolveBtn.addEventListener('click', function () {
      api('chats/' + chat.id + '/resolve', { method: 'POST', body: { status: 'resolved' } })
        .then(function () { openChat(chat.id); })
        .catch(function (err) { toast('Failed: ' + err.message, 'error'); });
    });

    var unresolveBtn = document.getElementById('bd-unresolve-chat');
    if (unresolveBtn) unresolveBtn.addEventListener('click', function () {
      api('chats/' + chat.id + '/resolve', { method: 'POST', body: { status: 'unresolved' } })
        .then(function () { openChat(chat.id); })
        .catch(function (err) { toast('Failed: ' + err.message, 'error'); });
    });

    var releaseBtn = document.getElementById('bd-release-chat');
    if (releaseBtn) releaseBtn.addEventListener('click', function () {
      api('chats/' + chat.id + '/release', { method: 'POST' })
        .then(function () { state.activeChatId = null; loadChats(); })
        .catch(function (err) { toast('Failed: ' + err.message, 'error'); });
    });
  }

  function loadCannedForSelect(select) {
    if (state.cachedCanned) { populateCannedSelect(select); return; }
    api('canned').then(function (data) {
      state.cachedCanned = data || [];
      populateCannedSelect(select);
    }).catch(function () {});
  }

  function populateCannedSelect(select) {
    if (!select || !state.cachedCanned) return;
    select.innerHTML = '<option value="">Canned reply…</option>' +
      state.cachedCanned.map(function (c) { return '<option value="' + c.id + '">' + esc(c.header) + '</option>'; }).join('');
  }

  // ==========================================================
  // CHAT LONG-POLLING
  // ==========================================================
  function startChatPoll(chatId) {
    stopChatPoll(chatId);
    state.isPollingChat = true;
    state.emptyStreak = 0;

    function poll() {
      if (!state.isPollingChat || state.activeChatId !== chatId) return;
      if (!navigator.onLine) {
        state.pollTimers[chatId] = setTimeout(poll, 10000);
        return;
      }

      var lastId = state.lastMessageId[chatId] || 0;
      api('chats/' + chatId + '/stream?after=' + lastId, { timeoutMs: 35000 })
        .then(function (data) {
          if (data.messages && data.messages.length) {
            data.messages.forEach(function (m) {
              var id = parseInt(m.id, 10);
              if (id > (state.lastMessageId[chatId] || 0)) {
                state.lastMessageId[chatId] = id;
                var exists = state.messages[chatId].some(function(msg) { return parseInt(msg.id, 10) === id; });
                if (!exists) {
                  state.messages[chatId].push(m);
                  if (m.sender_type === 'visitor') playPing();
                }
              }
            });
            
            var chat = data.chat || null;
            if (chat) renderThread(chat, state.messages[chatId]);
            state.emptyStreak = 0;
          } else {
            state.emptyStreak++;
          }

          var delay = data.timeout ? 100 : 800;
          if (state.emptyStreak > 3) delay = Math.min(10000, delay * Math.pow(1.5, state.emptyStreak - 3));

          state.pollTimers[chatId] = setTimeout(poll, delay);
        })
        .catch(function (err) {
          var delay = (err && err.isTimeout) ? 500 : 3000;
          state.pollTimers[chatId] = setTimeout(poll, delay);
        });
    }
    poll();
  }

  function stopChatPoll(chatId) {
    state.isPollingChat = false;
    if (state.pollTimers[chatId]) {
      clearTimeout(state.pollTimers[chatId]);
      delete state.pollTimers[chatId];
    }
  }

  // ==========================================================
  // SEND MESSAGE + OFFLINE QUEUE
  // ==========================================================
  function sendMessage(chatId, messageText) {
    if (!navigator.onLine) {
      queueOfflineMessage(chatId, messageText);
      return;
    }

    renderOptimisticMessage(chatId, messageText);

    api('chats/' + chatId + '/messages', { method: 'POST', body: { message: messageText } })
      .then(function (res) {
        if (res && res.id) {
          var msgId = parseInt(res.id, 10);
          state.lastMessageId[chatId] = Math.max(state.lastMessageId[chatId] || 0, msgId);
          
          var newMsg = {
            id: msgId,
            chat_id: chatId,
            sender_type: 'agent',
            sender_id: parseInt(config.currentUser.id, 10),
            sender_name: config.currentUser.name,
            message: messageText,
            created_at: res.time || new Date().toISOString().replace('T', ' ').substring(0, 19)
          };
          
          var exists = state.messages[chatId].some(function(m) { return parseInt(m.id, 10) === msgId; });
          if (!exists) {
            state.messages[chatId].push(newMsg);
          }
        }
      })
      .catch(function (err) {
        toast('Failed to send: ' + err.message, 'error');
        queueOfflineMessage(chatId, messageText);
      });
  }

  function queueOfflineMessage(chatId, messageText) {
    var queue = [];
    try { queue = JSON.parse(localStorage.getItem('bd_offline_queue') || '[]'); } catch (e) {}
    queue.push({ chatId: chatId, message: messageText, timestamp: Date.now() });
    try { localStorage.setItem('bd_offline_queue', JSON.stringify(queue)); } catch (e) {}
    toast('Message queued. Will send when online.', 'warning');
    renderOptimisticMessage(chatId, messageText);
  }

  function renderOptimisticMessage(chatId, text) {
    var thread = document.getElementById('bd-thread'); if (!thread) return;
    var body = thread.querySelector('.bd-thread-body'); if (!body) return;
    var div = document.createElement('div');
    div.className = 'bd-bubble bd-bubble-agent bd-bubble-pending';
    div.innerHTML =
      '<div style="font-size:11px;font-weight:700;margin-bottom:2px;opacity:0.85;">You</div>' +
      '<div>' + esc(text).replace(/\n/g, '<br>') + '</div>' +
      '<div class="bd-bubble-time">Sending…</div>';
    body.appendChild(div);
    body.scrollTop = body.scrollHeight;
  }

  function flushOfflineQueue() {
    var queue = [];
    try { queue = JSON.parse(localStorage.getItem('bd_offline_queue') || '[]'); } catch (e) {}
    if (queue.length === 0) return;
    toast('Syncing queued messages…', 'success');
    var sentCount = 0;

    function sendNext() {
      if (queue.length === 0) {
        try { localStorage.removeItem('bd_offline_queue'); } catch (e) {}
        if (sentCount > 0) {
          toast(sentCount + ' message(s) synced!', 'success');
          if (state.activeChatId) state.lastMessageId[state.activeChatId] = 0;
        }
        return;
      }
      var item = queue.shift();
      api('chats/' + item.chatId + '/messages', { method: 'POST', body: { message: item.message } })
        .then(function () {
          sentCount++;
          try { localStorage.setItem('bd_offline_queue', JSON.stringify(queue)); } catch (e) {}
          sendNext();
        })
        .catch(function () {
          queue.unshift(item);
          try { localStorage.setItem('bd_offline_queue', JSON.stringify(queue)); } catch (e) {}
        });
    }
    sendNext();
  }

  // ==========================================================
  // PERFORMANCE + HISTORY
  // ==========================================================
  function loadPerformance() {
    var fromInput = document.getElementById('bd-perf-from');
    var toInput = document.getElementById('bd-perf-to');
    var agentSel = document.getElementById('bd-perf-agent');
    var rangeSel = document.getElementById('bd-perf-range');

    if (fromInput && !fromInput.dataset.bdBound) {
      fromInput.dataset.bdBound = '1';
      fromInput.value = state.perfFrom || dateStr(-7);
      if (toInput) toInput.value = state.perfTo || dateStr(0);
      if (agentSel) agentSel.value = state.perfAgentId || '';
      if (rangeSel) rangeSel.value = state.perfRange || '7d';

      var runQuery = function () {
        state.perfFrom = fromInput.value;
        state.perfTo = toInput ? toInput.value : dateStr(0);
        state.perfAgentId = agentSel ? agentSel.value : '';
        state.perfRange = rangeSel ? rangeSel.value : '7d';
        loadPerformanceSummary();
        loadPerformanceTable();
      };

      fromInput.addEventListener('change', runQuery);
      if (toInput) toInput.addEventListener('change', runQuery);
      if (agentSel) agentSel.addEventListener('change', runQuery);
      if (rangeSel) {
        rangeSel.addEventListener('change', function () {
          var days = parseInt(rangeSel.value, 10) || 7;
          state.perfRange = rangeSel.value;
          fromInput.value = dateStr(-days);
          if (toInput) toInput.value = dateStr(0);
          runQuery();
        });
      }
    }

    var presets = document.querySelectorAll('.bd-preset');
    presets.forEach(function (btn) {
      if (btn.dataset.bdBound === '1') return;
      btn.dataset.bdBound = '1';
      btn.addEventListener('click', function () {
        presets.forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        var range = btn.getAttribute('data-range') || '7d';
        var days = range === 'today' ? 0 : range === '30d' ? 30 : range === 'month' ? 30 : 7;
        state.perfRange = range;
        if (fromInput) fromInput.value = dateStr(-days);
        if (toInput) toInput.value = dateStr(0);
        state.perfFrom = fromInput ? fromInput.value : dateStr(-days);
        state.perfTo = toInput ? toInput.value : dateStr(0);
        loadPerformanceSummary();
        loadPerformanceTable();
      });
    });

    var applyBtn = document.getElementById('bd-perf-apply');
    if (applyBtn && !applyBtn.dataset.bdBound) {
      applyBtn.dataset.bdBound = '1';
      applyBtn.addEventListener('click', function () {
        state.perfFrom = fromInput ? fromInput.value : dateStr(-7);
        state.perfTo = toInput ? toInput.value : dateStr(0);
        state.perfAgentId = agentSel ? agentSel.value : '';
        loadPerformanceSummary();
        loadPerformanceTable();
      });
    }

    initPerformanceTabs();
    loadPerformanceSummary();
    loadPerformanceTable();
  }

  function loadPerformanceSummary() {
    var summaryContainer = document.querySelector('.bd-perf-summary');
    if (!summaryContainer) return;

    var from = state.perfFrom || dateStr(-7);
    var to = state.perfTo || dateStr(0);
    var url = 'stats/summary?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    if (state.perfAgentId) url += '&agent_id=' + encodeURIComponent(state.perfAgentId);

    api(url).then(function (data) {
      summaryContainer.innerHTML =
        '<div class="bd-perf-summary-item is-success"><div class="bd-perf-summary-label">Chats Taken</div><div class="bd-perf-summary-value">' + (data.chats_taken || 0) + '</div></div>' +
        '<div class="bd-perf-summary-item is-success"><div class="bd-perf-summary-label">Resolved</div><div class="bd-perf-summary-value">' + (data.resolved || 0) + '</div></div>' +
        '<div class="bd-perf-summary-item is-warn"><div class="bd-perf-summary-label">Unresolved</div><div class="bd-perf-summary-value">' + (data.unresolved || 0) + '</div></div>' +
        '<div class="bd-perf-summary-item is-fast"><div class="bd-perf-summary-label">Resolved ≤3min</div><div class="bd-perf-summary-value">' + (data.resolved_under_3min || 0) + '</div></div>' +
        '<div class="bd-perf-summary-item is-time"><div class="bd-perf-summary-label">Avg Response</div><div class="bd-perf-summary-value">' + formatResponseTime(data.avg_response_seconds) + '</div></div>';
    }).catch(function (err) {
      console.warn('[BD] Summary failed:', err.message);
    });
  }

  function loadPerformanceTable() {
    var tbody = document.getElementById('bd-perf-body') || document.querySelector('#bd-view-performance tbody');
    if (!tbody) {
      console.warn('[BD] Performance table body not found.');
      return;
    }

    tbody.innerHTML = '<tr><td colspan="8" class="bd-empty">Loading…</td></tr>';

    var from = state.perfFrom || dateStr(-7);
    var to = state.perfTo || dateStr(0);
    var url = 'stats/agents?from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to);
    if (state.perfAgentId) url += '&agent_id=' + encodeURIComponent(state.perfAgentId);

    api(url).then(function (rows) {
      if (!rows || !rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="bd-empty">No agent activity in this period.</td></tr>';
        return;
      }

      var canReassign = (config.internalLevel === 'team_lead' || config.internalLevel === 'admin');

      tbody.innerHTML = rows.map(function (a) {
        var rate = parseInt(a.resolution_rate, 10) || 0;
        return '<tr>' +
          '<td><strong>' + esc(a.name || '—') + '</strong></td>' +
          '<td>' + (a.chats_taken || 0) + '</td>' +
          '<td>' + (a.resolved || 0) + '</td>' +
          '<td>' + (a.unresolved || 0) + '</td>' +
          '<td>' + (a.resolved_under_3min || 0) + '</td>' +
          '<td>' + formatResponseTime(a.avg_response_seconds) + '</td>' +
          '<td>' + rate + '%</td>' +
          '<td class="bd-hist-actions">' +
            (canReassign && a.id ? '<button type="button" class="bd-btn bd-btn-ghost-dark bd-view-agent" data-agent-id="' + a.id + '">View</button>' : '') +
          '</td>' +
        '</tr>';
      }).join('');

      tbody.querySelectorAll('.bd-view-agent').forEach(function (btn) {
        btn.addEventListener('click', function () {
          state.perfAgentId = btn.getAttribute('data-agent-id');
          var sel = document.getElementById('bd-perf-agent');
          if (sel) sel.value = state.perfAgentId;
          loadPerformanceSummary();
          loadPerformanceTable();
        });
      });
    }).catch(function (err) {
      console.error('[BD] Performance table failed:', err);
      tbody.innerHTML = '<tr><td colspan="8" class="bd-empty" style="color:var(--bd-red);">Failed to load.</td></tr>';
    });
  }

  function dateStr(offsetDays) {
    var d = new Date();
    d.setDate(d.getDate() + offsetDays);
    return d.toISOString().split('T')[0];
  }

  function initPerformanceTabs() {
    var tabs = document.querySelectorAll('.bd-perf-tab');
    if (!tabs.length) return;
    tabs.forEach(function (tab) {
      if (tab.dataset.bdBound === '1') return;
      tab.dataset.bdBound = '1';
      tab.addEventListener('click', function () {
        tabs.forEach(function (t) { t.classList.remove('is-active'); });
        tab.classList.add('is-active');
        state.perfTab = tab.getAttribute('data-tab') || 'performance';

        var perfPanel = document.getElementById('bd-perf-panel-performance');
        var histPanel = document.getElementById('bd-perf-panel-history');

        if (perfPanel && histPanel) {
          if (state.perfTab === 'performance') {
            perfPanel.classList.add('is-active');
            histPanel.classList.remove('is-active');
            loadPerformanceTable();
          } else {
            perfPanel.classList.remove('is-active');
            histPanel.classList.add('is-active');
            loadChatHistory();
          }
        }
      });
    });
  }

  function loadChatHistory() {
    var tbody = document.getElementById('bd-hist-body');
    if (!tbody) {
      var perfView = document.getElementById('bd-view-performance');
      if (perfView) tbody = perfView.querySelector('#bd-hist-body') || perfView.querySelector('tbody');
    }
    if (!tbody) {
      console.warn('[BD] History table body not found.');
      return;
    }

    tbody.innerHTML = '<tr><td colspan="7" class="bd-empty">Loading…</td></tr>';

    var from = state.perfFrom || dateStr(-7);
    var to = state.perfTo || dateStr(0);
    var query = 'from=' + encodeURIComponent(from) +
      '&to=' + encodeURIComponent(to) +
      '&status=' + encodeURIComponent(state.histStatus || 'all') +
      '&page=' + (state.histPage || 1) +
      '&per_page=' + (state.histPerPage || 25);
    if (state.perfAgentId) query += '&agent_id=' + encodeURIComponent(state.perfAgentId);
    if (state.histSearch) query += '&search=' + encodeURIComponent(state.histSearch);

    api('stats/chat-history?' + query).then(function (data) {
      state.histTotalPages = data.pages || 1;
      renderHistoryTable(data.chats || [], tbody);
      renderHistoryPagination(data.page, data.pages, data.total);
    }).catch(function (err) {
      console.error('[BD] History API failed:', err.message);
      tbody.innerHTML = '<tr><td colspan="7" class="bd-empty" style="color:var(--bd-red);">Failed to load history.</td></tr>';
    });
  }

  function renderHistoryTable(chats, tbody) {
    if (!tbody) tbody = document.getElementById('bd-hist-body');
    if (!tbody) return;

    if (!chats.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="bd-empty">No chats found for this period.</td></tr>';
      return;
    }
    var canReassign = (config.internalLevel === 'team_lead' || config.internalLevel === 'admin');
    tbody.innerHTML = chats.map(function (c) {
      var statusCls = 'bd-status-' + (c.status || 'new');
      var resolutionText = (c.resolution_time_seconds > 0) ? formatResponseTime(c.resolution_time_seconds) : '—';
      return '<tr data-chat-id="' + c.id + '">' +
        '<td>' + formatDateTime(c.created_at) + '</td>' +
        '<td><strong>' + esc(c.visitor_name || 'Visitor #' + c.id) + '</strong><div class="bd-hist-sub">' + esc(c.visitor_phone || '') + '</div></td>' +
        '<td>' + (c.agent_name ? esc(c.agent_name) : '<em>Unassigned</em>') + '</td>' +
        '<td><span class="bd-hist-badge ' + statusCls + '">' + esc(c.status) + '</span></td>' +
        '<td>' + (c.message_count || 0) + '</td>' +
        '<td>' + resolutionText + '</td>' +
        '<td class="bd-hist-actions">' +
          '<button type="button" class="bd-btn bd-btn-ghost-dark bd-open-chat" data-chat-id="' + c.id + '">Open</button>' +
          (canReassign ? '<button type="button" class="bd-btn bd-btn-ghost-dark bd-reassign-chat" data-chat-id="' + c.id + '">Reassign</button>' : '') +
        '</td>' +
      '</tr>';
    }).join('');

    tbody.querySelectorAll('.bd-open-chat').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-chat-id'), 10);
        setHash('chatroom'); switchView('chatroom');
        setTimeout(function () { openChat(id); }, 250);
      });
    });
    tbody.querySelectorAll('.bd-reassign-chat').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openReassignModal(parseInt(btn.getAttribute('data-chat-id'), 10), false);
      });
    });
  }

  function renderHistoryPagination(page, pages, total) {
    var el = document.getElementById('bd-hist-pagination');
    if (!el) return;
    if (pages <= 1) {
      el.innerHTML = '<div class="bd-perf-pagination-info">' + total + ' chat' + (total === 1 ? '' : 's') + '</div>';
      return;
    }
    var html = '<div class="bd-perf-pagination-info">' + total + ' chats · page ' + page + ' of ' + pages + '</div><div class="bd-perf-pagination-controls">';
    if (page > 1) html += '<button type="button" class="bd-page-btn" data-page="' + (page - 1) + '">‹ Prev</button>';
    var start = Math.max(1, page - 2), end = Math.min(pages, start + 4);
    start = Math.max(1, end - 4);
    for (var i = start; i <= end; i++) {
      html += '<button type="button" class="bd-page-btn' + (i === page ? ' is-current' : '') + '" data-page="' + i + '">' + i + '</button>';
    }
    if (page < pages) html += '<button type="button" class="bd-page-btn" data-page="' + (page + 1) + '">Next ›</button>';
    html += '</div>';
    el.innerHTML = html;
    el.querySelectorAll('.bd-page-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        state.histPage = parseInt(btn.getAttribute('data-page'), 10);
        loadChatHistory();
      });
    });
  }

  function formatDateTime(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // ==========================================================
  // CANNED REPLIES
  // ==========================================================
  function loadCanned() {
    var form = document.getElementById('bd-canned-form');
    var listContainer = document.getElementById('bd-canned-list');
    if (!listContainer) return;

    api('canned').then(function (data) {
      state.cachedCanned = data || [];
      if (!data || !data.length) {
        listContainer.innerHTML = '<div class="bd-empty">No canned replies yet. Create one above.</div>';
        return;
      }
      listContainer.innerHTML = data.map(function (c) {
        return '<div class="bd-canned-item" style="background:var(--bd-card);border:1px solid var(--bd-border);border-radius:12px;padding:16px;margin-bottom:12px;">' +
          '<div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:8px;">' +
            '<div>' +
              '<div style="font-weight:700;color:var(--bd-navy);font-size:14px;">' + esc(c.header || 'No header') + '</div>' +
              '<div style="font-size:12px;color:var(--bd-muted);margin-top:4px;white-space:pre-wrap;">' + esc(c.message || '') + '</div>' +
              (c.attachment_url ? '<div style="font-size:11px;color:var(--bd-blue);margin-top:4px;">📎 ' + esc(c.attachment_url) + '</div>' : '') +
            '</div>' +
            '<button type="button" class="bd-btn bd-btn-ghost" data-delete-id="' + c.id + '" style="padding:6px 12px;font-size:11px;color:var(--bd-red);">Delete</button>' +
          '</div>' +
        '</div>';
      }).join('');

      listContainer.querySelectorAll('[data-delete-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = parseInt(btn.getAttribute('data-delete-id'), 10);
          if (confirm('Delete this canned reply?')) {
            api('canned/' + id, { method: 'DELETE' }).then(function () {
              toast('Deleted', 'success');
              state.cachedCanned = null;
              loadCanned();
            }).catch(function (err) { toast('Failed: ' + err.message, 'error'); });
          }
        });
      });
    }).catch(function (err) {
      console.warn('[BD] Failed to load canned replies:', err.message);
      listContainer.innerHTML = '<div class="bd-empty" style="color:var(--bd-red);">Failed to load canned replies.</div>';
    });

    if (form && !form.dataset.bdBound) {
      form.dataset.bdBound = '1';
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var header = document.getElementById('bd-canned-header');
        var message = document.getElementById('bd-canned-message');
        var attachment = document.getElementById('bd-canned-attachment');
        var saveBtn = form.querySelector('button[type="submit"]');
        if (!header || !message) return;

        var payload = { header: header.value.trim(), message: message.value.trim() };
        if (attachment) payload.attachment_url = attachment.value.trim();
        if (!payload.header || !payload.message) { toast('Header and message are required', 'error'); return; }

        if (saveBtn) saveBtn.disabled = true;
        api('canned', { method: 'POST', body: payload }).then(function () {
          toast('Saved!', 'success');
          header.value = ''; message.value = '';
          if (attachment) attachment.value = '';
          state.cachedCanned = null;
          loadCanned();
        }).catch(function (err) {
          toast('Failed: ' + err.message, 'error');
        }).then(function () { if (saveBtn) saveBtn.disabled = false; });
      });
    }
  }

  // ==========================================================
  // REASSIGN / REOPEN MODAL
  // ==========================================================
  function bindReassignModal() {
    if (modalBound) return;
    modalBound = true;
    
    var modal = document.getElementById('bd-reassign-modal');
    if (!modal) return;
    
    var cancel = document.getElementById('bd-reassign-cancel');
    var confirm = document.getElementById('bd-reassign-confirm');
    
    if (cancel) {
      cancel.onclick = function (e) {
        e.preventDefault(); e.stopPropagation();
        closeReassignModal();
      };
    }
    
    if (confirm) {
      confirm.onclick = function (e) {
        e.preventDefault(); e.stopPropagation();
        confirmReassign();
      };
    }
    
    modal.onclick = function (e) {
      if (e.target === modal) closeReassignModal();
    };
    
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var m = document.getElementById('bd-reassign-modal');
        if (m && !m.hidden) closeReassignModal();
      }
    });
    
    var content = modal.querySelector('.bd-reassign-modal');
    if (content) {
      content.onclick = function (e) { e.stopPropagation(); };
    }
  }

  function openReassignModal(chatId, reopenMode) {
    bindReassignModal();
    
    var modal = document.getElementById('bd-reassign-modal');
    var info = document.getElementById('bd-reassign-info');
    var sel = document.getElementById('bd-reassign-select');
    
    if (!modal || !sel) {
      toast('Error: Modal not found. Please refresh the page.', 'error');
      return;
    }
    
    reassignState.chatId = chatId;
    reassignState.reopenMode = !!reopenMode;
    
    var titleEl = modal.querySelector('h3');
    var confirmBtn = document.getElementById('bd-reassign-confirm');
    
    if (titleEl) titleEl.textContent = reopenMode ? 'Reopen Chat' : 'Reassign Chat';
    if (confirmBtn) confirmBtn.textContent = reopenMode ? 'Reopen' : 'Reassign';
    
    if (info) {
      var row = document.querySelector('tr[data-chat-id="' + chatId + '"], .bd-conv-item[data-chat-id="' + chatId + '"]');
      var visitorName = '';
      if (row) {
        var nameEl = row.querySelector('.bd-conv-name, td strong');
        if (nameEl) visitorName = nameEl.textContent;
      }
      info.textContent = visitorName ? ('Chat with ' + visitorName) : ('Chat #' + chatId);
    }
    
    sel.innerHTML = '<option value="">Loading agents…</option>';
    modal.hidden = false;
    modal.style.display = 'flex';
    
    api('stats/agents-list').then(function (rows) {
      state.agentsList = rows || [];
      populateReassignSelect(sel);
    }).catch(function (err) {
      sel.innerHTML = '<option value="">Failed to load agents</option>';
      toast('Failed to load agents: ' + err.message, 'error');
    });
  }

  function populateReassignSelect(sel) {
    var others = [];
    state.agentsList.forEach(function (a) {
      if (parseInt(a.id, 10) !== parseInt(config.currentUser.id, 10)) others.push(a);
    });
    if (others.length === 0) { sel.innerHTML = '<option value="">No other agents available</option>'; return; }
    var html = '<option value="">Select agent…</option>';
    others.forEach(function (a) { html += '<option value="' + a.id + '">' + esc(a.name) + '</option>'; });
    sel.innerHTML = html;
  }

  function confirmReassign() {
    var sel = document.getElementById('bd-reassign-select');
    if (!sel || !reassignState.chatId) return;
    var agentId = parseInt(sel.value, 10);
    if (!agentId) { toast('Please select an agent', 'error'); return; }
    var confirmBtn = document.getElementById('bd-reassign-confirm');
    if (confirmBtn) confirmBtn.disabled = true;
    var endpoint = reassignState.reopenMode
      ? 'chats/' + reassignState.chatId + '/reopen'
      : 'chats/' + reassignState.chatId + '/reassign';
    api(endpoint, { method: 'POST', body: { agent_id: agentId } }).then(function () {
      toast(reassignState.reopenMode ? 'Chat reopened!' : 'Chat reassigned!', 'success');
      closeReassignModal();
      loadChats();
      if (state.view === 'performance') loadChatHistory();
      if (state.activeChatId) openChat(state.activeChatId);
    }).catch(function (err) {
      toast('Failed: ' + err.message, 'error');
    }).then(function () { if (confirmBtn) confirmBtn.disabled = false; });
  }

  // ==========================================================
  // CLIENTS SEARCH & AGENTS
  // ==========================================================
  function initClientsSearch() {
    var input = document.getElementById('bd-client-q');
    var btn = document.getElementById('bd-client-search-btn');
    if (!input || !btn || btn.dataset.bdBound) return;
    btn.dataset.bdBound = '1';

    var runSearch = function () {
      var q = input.value.trim();
      if (q.length < 2) {
        var el0 = document.getElementById('bd-client-result');
        if (el0) el0.innerHTML = '<div class="bd-empty">Enter at least 2 characters.</div>';
        return;
      }
      btn.disabled = true;
      api('clients/search?q=' + encodeURIComponent(q)).then(function (rows) {
        var el = document.getElementById('bd-client-result'); if (!el) return;
        if (!rows.length) { el.innerHTML = '<div class="bd-empty">No matching client found.</div>'; return; }
        el.innerHTML = rows.map(function (c) {
          return '<div class="bd-client-grid" style="margin-bottom:16px;">' +
            '<div><div class="bd-client-field-label">Client Name</div><div class="bd-client-field-value">' + esc(c.full_name || '—') + '</div></div>' +
            '<div><div class="bd-client-field-label">Account Number</div><div class="bd-client-field-value">' + esc(c.account_number) + '</div></div>' +
            '<div><div class="bd-client-field-label">Phone</div><div class="bd-client-field-value">' + esc(c.phone || '—') + '</div></div>' +
            '<div><div class="bd-client-field-label">ID Number</div><div class="bd-client-field-value">' + esc(c.id_number || '—') + '</div></div>' +
            '<div><div class="bd-client-field-label">KYC Status</div><div class="bd-client-field-value">' + esc(c.kyc_status || '—') + '</div></div>' +
            '<div><div class="bd-client-field-label">Account Status</div><div class="bd-client-field-value">' + esc(c.account_status || '—') + '</div></div>' +
            '<div><div class="bd-client-field-label">Plan Selected</div><div class="bd-client-field-value">' + esc(c.plan_selected || '—') + '</div></div>' +
            '<div><div class="bd-client-field-label">Payment Status</div><div class="bd-client-field-value">' + esc(c.payment_status || '—') + '</div></div>' +
          '</div>';
        }).join('');
      }).catch(function (err) {
        toast('Search failed: ' + err.message, 'error');
      }).then(function () { btn.disabled = false; });
    };
    btn.addEventListener('click', runSearch);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
    });
  }

  function loadAgents() {
    var container = document.getElementById('bd-agent-list'); if (!container) return;
    api('agents').then(function (rows) {
      if (!rows.length) { container.innerHTML = '<div class="bd-empty">No agents yet.</div>'; return; }
      container.innerHTML = rows.map(function (a) {
        return '<div class="bd-list-item"><div class="bd-list-item-info">' +
          '<div class="bd-list-item-title">' + esc(a.name) + '</div>' +
          '<div class="bd-list-item-sub">' + esc(a.email) + ' · ' + esc(a.username) + ' · ' + esc(a.role) + '</div>' +
        '</div></div>';
      }).join('');
    });

    var form = document.getElementById('bd-agent-form');
    if (form && !form.dataset.bdBound) {
      form.dataset.bdBound = '1';
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var status = document.getElementById('bd-agent-status');
        var payload = {
          name: document.getElementById('bd-agent-name').value.trim(),
          surname: document.getElementById('bd-agent-surname').value.trim(),
          position: document.getElementById('bd-agent-position').value,
          username: document.getElementById('bd-agent-username').value.trim(),
          email: document.getElementById('bd-agent-email').value.trim(),
          password: document.getElementById('bd-agent-password').value
        };
        if (status) { status.textContent = 'Saving…'; status.style.color = '#6C6F8C'; }
        api('agents', { method: 'POST', body: payload }).then(function () {
          if (status) { status.textContent = '✅ Agent saved!'; status.style.color = '#2E6B34'; }
          form.reset(); loadAgents();
        }).catch(function (err) {
          if (status) { status.textContent = '❌ ' + err.message; status.style.color = '#C11501'; }
        });
      });
    }
  }

  // ==========================================================
  // NOTIFICATIONS
  // ==========================================================
  function initNotifications() {
    var btn = document.getElementById('bd-notif-btn');
    var panel = document.getElementById('bd-notif-panel');
    var markAll = document.getElementById('bd-notif-mark-all');
    if (!btn || !panel) return;
    if (btn.dataset.bdBound === '1') return;
    btn.dataset.bdBound = '1';
    panel.setAttribute('hidden', ''); panel.style.display = 'none';

    btn.onclick = function (e) {
      e.preventDefault(); e.stopPropagation();
      var currentlyHidden = panel.hasAttribute('hidden');
      if (currentlyHidden) { panel.removeAttribute('hidden'); panel.style.display = 'block'; }
      else { panel.setAttribute('hidden', ''); panel.style.display = 'none'; }
    };
    document.addEventListener('click', function (e) {
      var p = document.getElementById('bd-notif-panel');
      var b = document.getElementById('bd-notif-btn');
      if (!p || !b || p.hasAttribute('hidden') || p.contains(e.target) || b.contains(e.target)) return;
      p.setAttribute('hidden', ''); p.style.display = 'none';
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { panel.setAttribute('hidden', ''); panel.style.display = 'none'; }
    });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });

    if (markAll) {
      markAll.onclick = function (e) {
        e.preventDefault(); e.stopPropagation();
        document.querySelectorAll('.bd-notif-item.is-unread').forEach(function (it) { it.classList.remove('is-unread'); });
        setNotifBadge(0);
        panel.setAttribute('hidden', ''); panel.style.display = 'none';
        api('notifications/read', { method: 'POST' }).catch(function () {});
      };
    }
  }

  function fetchNotifications() {
    return api('notifications').then(function (data) {
      state.notifications = data.items || [];
      var unreadInList = state.notifications.filter(function (n) { return !parseInt(n.is_read, 10); }).length;
      state.unreadCount = Math.max(data.unread || 0, unreadInList);
      renderNotifications(state.notifications);
      setNotifBadge(state.unreadCount);
      
      if (state.lastNotifId === 0 && state.notifications.length > 0) {
        var newestId = parseInt(state.notifications[0].id, 10) || 0;
        state.lastNotifId = newestId;
        saveLastSeenNotifId(newestId);
      }
    }).catch(function (err) {
      console.warn('[BD] fetchNotifications failed:', err.message);
    });
  }

  function renderNotifications(items) {
    var list = document.getElementById('bd-notif-list'); if (!list) return;
    if (!items.length) { list.innerHTML = '<div class="bd-notif-empty">No notifications.</div>'; return; }
    list.innerHTML = items.map(function (n) {
      var cls = 'bd-notif-item' + (parseInt(n.is_read, 10) ? '' : ' is-unread');
      return '<div class="' + cls + '" data-notif-id="' + n.id + '">' +
        '<div class="bd-notif-item-title">' + esc(n.title) + '</div>' +
        '<div class="bd-notif-item-body">' + esc(n.body || '') + '</div>' +
        '<div class="bd-notif-item-time">' + timeAgo(n.created_at) + '</div>' +
      '</div>';
    }).join('');
    list.querySelectorAll('[data-notif-id]').forEach(function (item) {
      item.addEventListener('click', function () {
        var nid = parseInt(item.getAttribute('data-notif-id'), 10);
        var notif = state.notifications.find(function (n) { return parseInt(n.id, 10) === nid; });
        var panel = document.getElementById('bd-notif-panel');
        if (panel) { panel.setAttribute('hidden', ''); panel.style.display = 'none'; }
        item.classList.remove('is-unread');
        if (notif && notif.reference_id) {
          setHash('chatroom'); switchView('chatroom');
          setTimeout(function () { openChat(parseInt(notif.reference_id, 10)); }, 250);
        }
        api('notifications/read', { method: 'POST' })
          .then(function () { return api('notifications'); })
          .then(function (data) { setNotifBadge(data.unread || 0); })
          .catch(function () {});
      });
    });
  }

  function setNotifBadge(count) {
    var badge = document.getElementById('bd-notif-badge'); if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      badge.hidden = false;
    } else { badge.hidden = true; }
  }

  function startNotificationPoll() {
    if (state.notificationTimer) clearTimeout(state.notificationTimer);

    function poll() {
      if (!navigator.onLine) {
        state.notificationTimer = setTimeout(poll, 8000);
        return;
      }
      
      var sinceId = state.lastNotifId || 0;
      api('notifications/poll?since=' + sinceId).then(function (data) {
        if (data.unread !== undefined) setNotifBadge(data.unread);
        if (data.waiting_chats !== undefined) {
          var badge = document.getElementById('bd-nav-chat-badge');
          if (badge) { badge.textContent = data.waiting_chats; badge.hidden = data.waiting_chats === 0; }
        }
        
        if (data.notifications && data.notifications.length) {
          data.notifications.forEach(function (n) {
            var nid = parseInt(n.id, 10) || 0;
            if (nid > state.lastNotifId) {
              state.lastNotifId = nid;
              saveLastSeenNotifId(nid);
              state.notifications.unshift(n);
              playPing();
              showDesktopNotification(n);
            }
          });
          renderNotifications(state.notifications.slice(0, 30));
        }
        
        if (state.view === 'chatroom' && !document.hidden) loadChats();
        flushOfflineQueue();
      }).catch(function () {
        // silent
      }).then(function () {
        var interval = getAdaptivePollInterval();
        state.notificationTimer = setTimeout(poll, interval || 8000);
      });
    }
    poll();
  }

  function showDesktopNotification(n) {
    if (!config.settings.desktopNotify) return;
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    try {
      var opts = {
        body: n.body || '',
        icon: config.assetsUrl + 'icons/icon-192.png',
        badge: config.assetsUrl + 'icons/badge-72.png',
        tag: 'bd-' + n.id,
        renotify: true,
        requireInteraction: false
      };
      if (navigator.serviceWorker && navigator.serviceWorker.ready) {
        navigator.serviceWorker.ready.then(function (reg) {
          reg.showNotification(n.title, opts);
        }).catch(function () {
          try {
            var notif = new Notification(n.title, opts);
            notif.onclick = function () { window.focus(); setHash('chatroom'); switchView('chatroom'); notif.close(); };
          } catch (e) {}
        });
      } else {
        var n2 = new Notification(n.title, opts);
        n2.onclick = function () { window.focus(); setHash('chatroom'); switchView('chatroom'); n2.close(); };
      }
    } catch (e) {
      console.warn('[BD] Desktop notification failed:', e);
    }
  }

  // ==========================================================
  // SOUND, HEARTBEAT, PUSH, SEARCH
  // ==========================================================
  function initSoundToggle() {
    var btn = document.getElementById('bd-sound-toggle');
    if (!btn) return;
    if (!state.soundEnabled) btn.classList.add('is-muted');
    btn.addEventListener('click', function () {
      state.soundEnabled = !state.soundEnabled;
      btn.classList.toggle('is-muted', !state.soundEnabled);
      try { localStorage.setItem('bd_sound', state.soundEnabled ? '1' : '0'); } catch (e) {}
    });
    try {
      if (localStorage.getItem('bd_sound') === '0') {
        state.soundEnabled = false;
        btn.classList.add('is-muted');
      }
    } catch (e) {}
  }

  function playPing() {
    if (!state.soundEnabled) return;
    var audio = document.getElementById('bd-ping');
    if (!audio) return;
    try {
      audio.currentTime = 0;
      audio.volume = 0.6;
      audio.play().catch(function () {});
    } catch (e) {}
  }

  function startHeartbeat() {
    var beat = function () {
      if (!navigator.onLine) return;
      api('heartbeat', { method: 'POST' }).catch(function () {});
    };
    beat();
    state.heartbeatTimer = setInterval(beat, 120000);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        if (state.heartbeatTimer) { clearInterval(state.heartbeatTimer); state.heartbeatTimer = null; }
      } else {
        if (!state.heartbeatTimer) {
          beat();
          state.heartbeatTimer = setInterval(beat, 120000);
        }
      }
    });
  }

  function initPushPrompt() {
    var prompt = document.getElementById('bd-push-prompt');
    var enableBtn = document.getElementById('bd-push-enable');
    var dismissBtn = document.getElementById('bd-push-dismiss');
    if (!prompt) return;
    if (!('Notification' in window)) {
      if (prompt.parentNode) prompt.parentNode.removeChild(prompt);
      return;
    }
    if (Notification.permission === 'granted' || Notification.permission === 'denied') {
      if (prompt.parentNode) prompt.parentNode.removeChild(prompt);
      return;
    }
    try {
      if (localStorage.getItem('bd_push_prompt_dismissed') === '1') {
        if (prompt.parentNode) prompt.parentNode.removeChild(prompt);
        return;
      }
    } catch (e) {}

    setTimeout(function () {
      if (prompt.parentNode) prompt.removeAttribute('hidden');
    }, 3000);

    function closeBanner(remember) {
      prompt.setAttribute('hidden', '');
      if (remember) {
        try { localStorage.setItem('bd_push_prompt_dismissed', '1'); } catch (e) {}
      }
    }

    if (enableBtn) {
      enableBtn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        closeBanner(true);
        try {
          if (window.BD_PWA && typeof window.BD_PWA.requestPermission === 'function') {
            window.BD_PWA.requestPermission();
          } else if (Notification.requestPermission) {
            Notification.requestPermission().then(function (perm) {
              if (perm === 'granted' && window.BD_PWA && typeof window.BD_PWA.subscribe === 'function') {
                window.BD_PWA.subscribe();
              }
            });
          }
        } catch (err) {}
      });
    }
    if (dismissBtn) {
      dismissBtn.addEventListener('click', function (e) {
        e.preventDefault(); e.stopPropagation();
        closeBanner(true);
      });
    }
  }

  function initGlobalSearch() {
    var input = document.getElementById('bd-global-search');
    if (!input) return;
    var timer = null;
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = input.value.trim();
        if (q.length < 2) return;
        if (config.canViewClients && document.getElementById('bd-view-clients')) {
          setHash('clients'); switchView('clients');
          setTimeout(function () {
            var cq = document.getElementById('bd-client-q');
            if (cq) {
              cq.value = q;
              var b = document.getElementById('bd-client-search-btn');
              if (b) b.click();
            }
          }, 200);
        } else if (document.getElementById('bd-view-chatroom')) {
          setHash('chatroom'); switchView('chatroom');
        }
      }, 500);
    });
  }

  // ==========================================================
  // HELPERS
  // ==========================================================
  function setText(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
  }
  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  function escAttr(str) { return esc(str); }
  function truncate(str, n) {
    str = String(str || '');
    return str.length > n ? str.slice(0, n - 1) + '…' : str;
  }
  function initials(name) {
    var parts = String(name || '?').trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }
  function formatTime(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }
  function timeAgo(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    var diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h';
    return Math.floor(diff / 86400) + 'd';
  }
  function formatWaiting(seconds) {
    if (seconds < 60) return seconds + 's';
    var m = Math.floor(seconds / 60);
    if (m < 60) return m + 'm';
    return Math.floor(m / 60) + 'h';
  }
  function formatResponseTime(seconds) {
    seconds = parseInt(seconds, 10) || 0;
    if (seconds === 0) return '—';
    if (seconds < 60) return seconds + 's';
    var m = Math.floor(seconds / 60);
    if (m < 60) return m + 'm';
    return (m / 60).toFixed(1) + 'h';
  }
  function trendLabel(val, type) {
    if (!val) return '—';
    return '+' + Math.min(99, parseInt(val, 10)) + (type === 'today' ? '' : '%');
  }
  function toast(msg, type) {
    if (!$toastContainer) return;
    var el = document.createElement('div');
    el.className = 'bd-toast' + (type ? ' is-' + type : '');
    el.textContent = msg;
    $toastContainer.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity 0.25s, transform 0.25s';
      el.style.opacity = '0';
      el.style.transform = 'translateX(30px)';
      setTimeout(function () { el.remove(); }, 300);
    }, 3600);
  }

  // ==========================================================
  // PUBLIC API
  // ==========================================================
  window.BDPortal = {
    switchView: switchView,
    reload: function () {
      if (state.view === 'dashboard') loadDashboard();
      if (state.view === 'chatroom') loadChats();
      if (state.view === 'performance') { loadPerformanceSummary(); loadPerformanceTable(); }
    },
    hardRefresh: hardRefreshApp
  };
})();