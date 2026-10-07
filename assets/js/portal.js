/* ==========================================================
   Big Drop Agent Portal — Main SPA  (Clean v1.3.0)
   Added: Dark/Light Mode Toggle & Persistence
   ========================================================== */
(function () {
  'use strict';

  // -----------------------------------------------------------------
  // Guard
  // -----------------------------------------------------------------
  if (typeof window.BD === 'undefined') {
    console.warn('[BD] window.BD config missing — script aborted.');
    return;
  }
  var config = window.BD;

  // -----------------------------------------------------------------
  // State
  // -----------------------------------------------------------------
  var state = {
    view: 'dashboard',
    chats: [],
    tabCounts: { new: 0, active: 0, resolved: 0, unresolved: 0 },
    perfFrom: null,
    perfTo: null,
    perfAgentId: '',
    perfRange: '7d',
    perfTab: 'performance',
    histSearch: '',
    histStatus: 'all',
    histPage: 1,
    histPerPage: 25,
    histTotalPages: 1,
    agentsList: [],
    activeChatId: null,
    activeTab: 'new',
    messages: {},
    lastMessageId: {},
    notifications: [],
    unreadCount: 0,
    lastNotifId: 0,
    soundEnabled: !!config.settings.soundEnabled,
    pollTimers: {},
    heartbeatTimer: null,
    notificationTimer: null,
    isPollingChat: false,
    drafts: {}
  };

  // Reassign modal state
  var reassignState = { chatId: null, reopenMode: false };

  // DOM shortcuts
  var $app, $sidebar, $topbarTitle, $toastContainer;

  // =============================================================
  // MODAL AUTO-CLOSE HELPERS
  // =============================================================
  function closeReassignModal() {
    var modal = document.getElementById('bd-reassign-modal');
    if (modal) {
      modal.hidden = true;
      modal.style.display = 'none';
      var sel = document.getElementById('bd-reassign-select');
      if (sel) sel.innerHTML = '<option value="">Select agent…</option>';
    }
    reassignState.chatId = null;
    reassignState.reopenMode = false;
  }

  // =============================================================
  // BOOT
  // =============================================================
  function boot() {
    $app = document.getElementById('bigdrop-app');
    if (!$app) {
      console.warn('[BD] app container not found — aborting boot.');
      return;
    }

    if (boot.done) return;
    boot.done = true;

    $sidebar        = document.getElementById('bd-sidebar');
    $topbarTitle    = document.getElementById('bd-topbar-title');
    $toastContainer = document.getElementById('bd-toasts');

    // 1. Initialize Theme First (to avoid flash of wrong theme)
    initThemeToggle();

    // 2. Initialize other modules
    initSidebar();
    initNavigation();
    initNotifications();
    initSoundToggle();
    initGlobalSearch();
    initPushPrompt();

    routeFromHash();
    closeReassignModal(); // Ensure modals start hidden

    startHeartbeat();
    startNotificationPoll();

    loadDashboard();
    loadRoster();
    fetchNotifications();

    console.log('[BD] Portal booted successfully.');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    setTimeout(boot, 0);
  }

  window.BDBoot = boot;

  // =============================================================
  // THEME TOGGLE (Dark / Light Mode)
  // =============================================================
  function initThemeToggle() {
    var toggleBtn = document.getElementById('bd-theme-toggle');
    
    // Determine current theme (saved preference > system preference > light)
    var savedTheme = localStorage.getItem('bd_theme');
    var systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    var currentTheme = savedTheme || (systemPrefersDark ? 'dark' : 'light');

    // Apply the theme on initial load
    applyTheme(currentTheme);

    // If toggle button doesn't exist, just exit (theme is still applied)
    if (!toggleBtn) return;

    // Handle click event
    toggleBtn.addEventListener('click', function(e) {
      e.preventDefault();
      
      var current = document.documentElement.getAttribute('data-theme') || 
                    (systemPrefersDark ? 'dark' : 'light');
      var newTheme = current === 'dark' ? 'light' : 'dark';
      
      applyTheme(newTheme);
      localStorage.setItem('bd_theme', newTheme);
    });

    // Listen for system preference changes (only if user hasn't manually chosen)
    if (window.matchMedia) {
      window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(e) {
        if (!localStorage.getItem('bd_theme')) {
          applyTheme(e.matches ? 'dark' : 'light');
        }
      });
    }
  }

  function applyTheme(theme) {
    if (theme === 'dark') {
      document.documentElement.setAttribute('data-theme', 'dark');
    } else {
      document.documentElement.setAttribute('data-theme', 'light');
    }
  }

  // =============================================================
  // SIDEBAR
  // =============================================================
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
      try {
        localStorage.setItem('bd_sidebar_collapsed', collapsed ? 'true' : 'false');
      } catch (e) {}
    });

    if (window.innerWidth < 900) {
      $app.classList.add('bd-collapsed');
      if (iconEl) iconEl.textContent = '▶';
    }
  }

  // =============================================================
  // NAVIGATION
  // =============================================================
  function initNavigation() {
    document.querySelectorAll('.bd-nav-item[data-view]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var view = link.getAttribute('data-view');
        if (!view) return;
        setHash(view);
        switchView(view);
      });
    });

    document.querySelectorAll('[data-goto]').forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        var view = btn.getAttribute('data-goto');
        if (!view) return;
        setHash(view);
        switchView(view);
      });
    });

    window.addEventListener('hashchange', function () {
      var hash = (location.hash || '#dashboard').replace('#', '');
      if (hash && hash !== state.view) switchView(hash);
    });
  }

  function setHash(view) {
    try {
      if (window.history && window.history.replaceState) {
        window.history.replaceState(null, '', '#' + view);
      } else {
        location.hash = '#' + view;
      }
    } catch (e) {
      try { location.hash = '#' + view; } catch (e2) {}
    }
  }

  function routeFromHash() {
    var hash = (location.hash || '#dashboard').replace('#', '');
    if (!hash) hash = 'dashboard';
    if (!document.getElementById('bd-view-' + hash)) hash = 'dashboard';
    switchView(hash);
  }

  function switchView(view) {
    console.log('[BD] switchView:', view);
    closeReassignModal();

    var targetView = document.getElementById('bd-view-' + view);
    if (!targetView) {
      console.warn('[BD] view not found:', view, '— falling back to dashboard');
      view = 'dashboard';
      targetView = document.getElementById('bd-view-dashboard');
    }
    if (!targetView) {
      console.error('[BD] dashboard view not found either.');
      return;
    }

    state.view = view;

    document.querySelectorAll('.bd-nav-item').forEach(function (a) {
      a.classList.toggle('is-active', a.getAttribute('data-view') === view);
    });

    document.querySelectorAll('.bd-view').forEach(function (v) {
      v.classList.remove('is-active');
    });

    targetView.classList.add('is-active');

    var labels = {
      dashboard: 'Dashboard',
      chatroom: 'Chatroom',
      internal: 'Team Chat',
      performance: 'Agent Performance',
      clients: 'Client Accounts',
      canned: 'Pre-Reply Messages',
      agents: 'Add an Agent'
    };
    if ($topbarTitle) $topbarTitle.textContent = labels[view] || 'Dashboard';

    if (view === 'dashboard') {
      loadDashboard();
      loadRoster();
    } else if (view === 'chatroom') {
      loadChats();
    } else if (view === 'internal') {
      if (window.BDInternalChat && typeof window.BDInternalChat.refresh === 'function') {
        window.BDInternalChat.refresh();
      }
    } else if (view === 'performance') {
      loadPerformance();
    } else if (view === 'canned') {
      loadCanned();
    } else if (view === 'agents') {
      loadAgents();
    }
  }

  // =============================================================
  // API HELPER
  // =============================================================
  function api(path, options) {
    options = options || {};
    var url = config.restUrl.replace(/\/$/, '') + '/' + path.replace(/^\//, '');
    var headers = { 'X-WP-Nonce': config.nonce };

    if (options.body && typeof options.body !== 'string') {
      headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(options.body);
    }

    return fetch(url, {
      method: options.method || 'GET',
      headers: Object.assign(headers, options.headers || {}),
      body: options.body || null,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; }
        catch (e) { data = { message: text }; }
        if (!res.ok) {
          var err = new Error((data && data.message) || ('HTTP ' + res.status));
          err.data = data;
          err.status = res.status;
          throw err;
        }
        return data;
      });
    });
  }

  // =============================================================
  // DASHBOARD
  // =============================================================
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
    }).catch(function (err) {
      console.warn('[BD] Dashboard load failed:', err.message);
    });
  }

  function updateDonut(data) {
    var total      = data.all_time || 0;
    var resolved   = data.all_resolved || 0;
    var unresolved = data.all_unresolved || 0;
    var pending    = Math.max(0, total - resolved - unresolved);

    var rPct = total ? Math.round((resolved / total) * 100) : 0;
    var pPct = total ? Math.round((pending / total) * 100) : 0;
    var uPct = Math.max(0, 100 - rPct - pPct);

    var donut = document.getElementById('bd-donut');
    if (donut) {
      donut.style.background =
        'conic-gradient(#7FD344 0 ' + rPct + '%, ' +
        '#4E7CDD ' + rPct + '% ' + (rPct + pPct) + '%, ' +
        '#E9EBF2 ' + (rPct + pPct) + '% 100%)';
    }
    setText('bd-donut-value', rPct + '%');
    setText('bd-legend-resolved', rPct + '%');
    setText('bd-legend-pending', pPct + '%');
    setText('bd-legend-unresolved', uPct + '%');
  }

  function drawWeeklyChart(weekly) {
    var svg = document.getElementById('bd-chart-week');
    if (!svg) return;
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

    var polyPts = points.map(function (p) {
      return p[0].toFixed(1) + ',' + p[1].toFixed(1);
    }).join(' ');

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
      c.setAttribute('cx', p[0]);
      c.setAttribute('cy', p[1]);
      c.setAttribute('r', 4);
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

  // =============================================================
  // ROSTER
  // =============================================================
  function loadRoster() {
    api('roster').then(function (rows) {
      var container = document.getElementById('bd-roster');
      if (!container) return;

      if (!rows.length) {
        container.innerHTML = '<div class="bd-empty">No agents.</div>';
        return;
      }

      container.innerHTML = rows.map(function (r) {
        var statusLabel = r.status === 'online' ? 'Online'
          : r.status === 'taking' ? 'Taking chats'
          : 'Offline';
        return '<div class="bd-roster-row">' +
            '<div class="bd-roster-avatar">' + esc(r.initials || initials(r.name)) +
              '<span class="bd-status-dot is-' + escAttr(r.status) + '"></span>' +
            '</div>' +
            '<div class="bd-roster-name">' + esc(r.name) + '</div>' +
            '<div class="bd-roster-status">' + statusLabel + '</div>' +
          '</div>';
      }).join('');
    }).catch(function (err) {
      console.warn('[BD] Roster load failed:', err.message);
    });
  }

  // =============================================================
  // CHATROOM
  // =============================================================
  function initChatTabs() {
    var tabs = document.querySelectorAll('.bd-conv-tab');
    if (!tabs.length) return;

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
    var status = state.activeTab || 'new';
    api('chats?status=' + encodeURIComponent(status)).then(function (data) {
      state.chats = (data && data.rows) || [];
      state.tabCounts = (data && data.counts) || {};
      renderConvList(state.chats);
      renderTabCounts(state.tabCounts);
      updateChatNavBadge(state.chats);
    }).catch(function (err) {
      console.warn('[BD] Chats load failed:', err.message);
    });
  }

  function renderTabCounts(counts) {
    var map = {
      new: 'bd-tab-count-new',
      active: 'bd-tab-count-active',
      resolved: 'bd-tab-count-resolved',
      unresolved: 'bd-tab-count-unresolved'
    };
    Object.keys(map).forEach(function (k) {
      var el = document.getElementById(map[k]);
      if (!el) return;
      var n = parseInt(counts[k], 10) || 0;
      el.textContent = n;
      el.hidden = n === 0;
    });
  }

  function renderConvList(rows) {
    var container = document.getElementById('bd-conv-list');
    if (!container) return;

    if (!rows || !rows.length) {
      container.innerHTML = '<div class="bd-empty">No chats in this tab.</div>';
      return;
    }

    container.innerHTML = rows.map(function (c) {
      var waiting = parseInt(c.waiting_seconds, 10) || 0;
      var isUrgent = parseInt(c.priority, 10) === 2 ||
                     waiting > (config.settings.escalateMinutes * 60);
      var unread = parseInt(c.unread_count, 10) || 0;
      var timeStr = timeAgo(c.updated_at || c.created_at);
      var preview = c.last_message ? truncate(c.last_message, 48) : 'No messages yet';
      var badges = '';
      if (isUrgent) badges += '<span class="bd-conv-badge is-urgent">' + formatWaiting(waiting) + '</span>';
      if (unread > 0) badges += '<span class="bd-conv-badge is-unread">' + unread + ' new</span>';

      var isActive = state.activeChatId === parseInt(c.id, 10) ? ' is-active' : '';

      var agentAvatar = '';
      if (c.agent_initials) {
        agentAvatar = '<div class="bd-conv-agent-avatar" title="' +
          escAttr(c.agent_name || '') + '">' +
          esc(c.agent_initials) +
        '</div>';
      }

      return '<div class="bd-conv-item' + isActive + '" data-chat-id="' + c.id + '">' +
          '<div class="bd-conv-item-top">' +
            '<div class="bd-conv-name">' + esc(c.visitor_name || ('Visitor #' + c.id)) + '</div>' +
            '<div class="bd-conv-right">' +
              agentAvatar +
              '<div class="bd-conv-time">' + timeStr + '</div>' +
            '</div>' +
          '</div>' +
          '<div class="bd-conv-preview">' + esc(preview) + '</div>' +
          (badges ? '<div class="bd-conv-badges">' + badges + '</div>' : '') +
        '</div>';
    }).join('');

    container.querySelectorAll('.bd-conv-item').forEach(function (item) {
      item.addEventListener('click', function () {
        var id = parseInt(item.getAttribute('data-chat-id'), 10);
        openChat(id);
      });
    });

    if (!state.activeChatId && window.innerWidth > 900) {
      var first = container.querySelector('.bd-conv-item');
      if (first) {
        openChat(parseInt(first.getAttribute('data-chat-id'), 10));
      }
    }
  }

  function updateChatNavBadge(rows) {
    var badge = document.getElementById('bd-nav-chat-badge');
    if (!badge) return;
    var newCount = rows.filter(function (c) { return c.status === 'new'; }).length;
    if (newCount > 0) {
      badge.textContent = newCount;
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function openChat(chatId) {
    if (state.activeChatId && state.activeChatId !== chatId) {
      stopChatPoll(state.activeChatId);
    }

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
    }).catch(function (err) {
      toast('Failed to open chat: ' + err.message, 'error');
    });
  }

  function renderThread(chat, messages) {
    var thread = document.getElementById('bd-thread');
    if (!thread) return;

    var level = config.internalLevel || 'agent';
    var canManage = (level === 'team_lead' || level === 'admin');
    var isMine = parseInt(chat.assigned_agent_id, 10) === parseInt(config.currentUser.id, 10);

    var isResolved = chat.status === 'resolved';
    var isUnresolved = chat.status === 'unresolved';
    var isClosed = isResolved || isUnresolved;

    var isActive = chat.status === 'active' || (chat.assigned_agent_id && !isClosed);
    var isNew = chat.status === 'new';

    // Determine if the current user can reply to this chat
    var canReply = true;
    if (isClosed && !canManage) {
      canReply = false; // Agents cannot reply to closed chats
    }

    var waiting = parseInt(chat.waiting_seconds, 10) || 0;
    var resolveBtn = '';

    if (isClosed) {
      if (canManage) {
        resolveBtn =
          '<button type="button" class="bd-btn" id="bd-reopen-chat">Reopen</button>' +
          '<button type="button" class="bd-btn bd-btn-ghost" id="bd-reassign-chat" style="background:#F5F7FC;color:#1F0D5E;">Reassign</button>';
      } else {
        resolveBtn = '<span class="bd-thread-locked">Ask a team lead to reopen</span>';
      }
    } else {
      var canResolve = canManage || isMine || isNew;
      if (canResolve) {
        resolveBtn =
          '<button type="button" class="bd-btn" id="bd-resolve-chat">Resolve</button>' +
          '<button type="button" class="bd-btn bd-btn-ghost" id="bd-unresolve-chat" style="background:#FEE6E2;color:#C11501;">Unresolve</button>' +
          (isActive && (isMine || canManage)
            ? '<button type="button" class="bd-btn bd-btn-ghost" id="bd-release-chat" style="background:#F5F7FC;color:#1F0D5E;">Release</button>'
            : '');
      }
    }

    // Build the footer based on permissions
    var footerHtml = '';
    if (canReply) {
      footerHtml =
        '<div class="bd-thread-foot">' +
          '<select id="bd-canned-select"><option value="">Canned reply…</option></select>' +
          '<textarea id="bd-reply-input" placeholder="' + escAttr(config.i18n.typeReply) + '" rows="1"></textarea>' +
          '<button type="button" class="bd-btn" id="bd-send-btn">' + esc(config.i18n.send) + '</button>' +
        '</div>';
    } else {
      footerHtml =
        '<div class="bd-thread-foot bd-thread-foot-locked">' +
          '<div class="bd-thread-locked-msg">🔒 This chat is closed. Only Team Leads and Admins can reply.</div>' +
        '</div>';
    }

    thread.innerHTML =
      '<div class="bd-thread-head">' +
        '<div class="bd-thread-title">' +
          '<div class="bd-thread-name">' + esc(chat.visitor_name || ('Visitor #' + chat.id)) + '</div>' +
          '<div class="bd-thread-meta">' +
            '<span>' + esc(chat.visitor_phone || 'No phone') + '</span>' +
            '<span>' + esc(chat.visitor_email || 'No email') + '</span>' +
            (chat.agent_name ? '<span>Agent: ' + esc(chat.agent_name) + '</span>' : '') +
            (waiting > 0 ? '<span>Waiting ' + formatWaiting(waiting) + '</span>' : '') +
          '</div>' +
        '</div>' +
        '<div class="bd-thread-actions">' + resolveBtn + '</div>' +
      '</div>' +
      '<div class="bd-thread-body" id="bd-thread-body"></div>' +
      footerHtml;

    var body = document.getElementById('bd-thread-body');
    if (body) {
      body.innerHTML = messages.map(renderBubble).join('') ||
        '<div class="bd-empty">No messages yet. Say hello!</div>';
      requestAnimationFrame(function () {
        scrollThreadBottom();
        setTimeout(scrollThreadBottom, 80);
      });
    }

    // Bind event listeners only if the footer was rendered
    if (canReply) {
      var sendBtn = document.getElementById('bd-send-btn');
      var input = document.getElementById('bd-reply-input');
      var cannedSel = document.getElementById('bd-canned-select');

      if (sendBtn) {
        sendBtn.onclick = null;
        sendBtn.addEventListener('click', sendReply);
      }
      if (input) {
        input.onkeydown = null;
        input.addEventListener('keydown', function (e) {
          if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendReply();
          }
        });
        input.addEventListener('input', function () {
          input.style.height = 'auto';
          input.style.height = Math.min(120, input.scrollHeight) + 'px';
          state.drafts[chat.id] = input.value;
        });
        if (state.drafts[chat.id]) {
          input.value = state.drafts[chat.id];
          input.style.height = 'auto';
          input.style.height = Math.min(120, input.scrollHeight) + 'px';
        }
      }
      if (cannedSel) {
        cannedSel.onchange = null;
        cannedSel.addEventListener('change', onCannedSelect);
      }
      loadCannedIntoSelect();
    }

    // Bind the action buttons (Resolve, Unresolve, Reopen, etc.)
    var resolveBtnEl = document.getElementById('bd-resolve-chat');
    var unresolveBtnEl = document.getElementById('bd-unresolve-chat');
    var reopenBtnEl = document.getElementById('bd-reopen-chat');
    var releaseBtnEl = document.getElementById('bd-release-chat');
    var reassignBtnEl = document.getElementById('bd-reassign-chat');

    if (resolveBtnEl)   resolveBtnEl.addEventListener('click', function () { resolveChat(chat.id, 'resolved'); });
    if (unresolveBtnEl) unresolveBtnEl.addEventListener('click', function () { resolveChat(chat.id, 'unresolved'); });
    if (reopenBtnEl)    reopenBtnEl.addEventListener('click', function () { reopenChat(chat.id); });
    if (releaseBtnEl)   releaseBtnEl.addEventListener('click', function () { releaseChat(chat.id); });
    if (reassignBtnEl)  reassignBtnEl.addEventListener('click', function () { openReassignModal(chat.id, false); });
  }

  function renderBubble(m) {
    var cls = 'bd-bubble bd-bubble-' + (m.sender_type || 'visitor');
    if (m.sender_type === 'system') {
      var meta = '';
      if (m.system_agent_name) {
        meta = ' by <strong>' + esc(m.system_agent_name) + '</strong>';
      }
      var time = m.created_at ? ' · <span class="bd-sys-time">' + formatTime(m.created_at) + '</span>' : '';
      var text = String(m.message || '').replace(/\.\s*$/, '');
      return '<div class="' + cls + '">' + esc(text) + meta + time + '</div>';
    }
    return '<div class="' + cls + '">' +
      esc(m.message).replace(/\n/g, '<br>') +
      '<div class="bd-bubble-time">' + formatTime(m.created_at) + '</div>' +
      '</div>';
  }

  function scrollThreadBottom() {
    var body = document.getElementById('bd-thread-body');
    if (!body) return;
    var dist = body.scrollHeight - body.scrollTop - body.clientHeight;
    if (dist < 600) {
      body.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
    } else {
      body.scrollTop = body.scrollHeight;
    }
  }

  function sendReply() {
    var input = document.getElementById('bd-reply-input');
    var sendBtn = document.getElementById('bd-send-btn');
    if (!input || !state.activeChatId) return;

    // Permission Check
    var level = config.internalLevel || 'agent';
    var canManage = (level === 'team_lead' || level === 'admin');
    var currentChat = state.chats.find(function(c) { return parseInt(c.id, 10) === state.activeChatId; });

    if (currentChat && (currentChat.status === 'resolved' || currentChat.status === 'unresolved')) {
      if (!canManage) {
        toast('Cannot reply: This chat is closed. Only Team Leads and Admins can reply.', 'error');
        return;
      }
    }

    var text = input.value.trim();
    if (!text) return;

    var chatId = state.activeChatId;

    var optimistic = {
      id: 'tmp-' + Date.now(),
      sender_type: 'agent',
      message: text,
      created_at: new Date().toISOString()
    };
    state.messages[chatId] = state.messages[chatId] || [];
    state.messages[chatId].push(optimistic);

    var body = document.getElementById('bd-thread-body');
    if (body) {
      if (body.querySelector('.bd-empty')) body.innerHTML = '';
      body.insertAdjacentHTML('beforeend', renderBubble(optimistic));
      scrollThreadBottom();
    }

    input.value = '';
    input.style.height = 'auto';
    delete state.drafts[chatId];
    if (sendBtn) sendBtn.disabled = true;

    api('chats/' + chatId + '/messages', {
      method: 'POST',
      body: { message: text }
    }).then(function (res) {
      var list = state.messages[chatId] || [];
      for (var i = 0; i < list.length; i++) {
        if (list[i].id === optimistic.id) {
          list[i].id = res.id;
          list[i].created_at = res.time;
          break;
        }
      }
      if (res.id > state.lastMessageId[chatId]) {
        state.lastMessageId[chatId] = parseInt(res.id, 10);
      }
      scrollThreadBottom();
      loadChats();
    }).catch(function (err) {
      toast('Failed to send: ' + err.message, 'error');
    }).then(function () {
      if (sendBtn) sendBtn.disabled = false;
      var i2 = document.getElementById('bd-reply-input');
      if (i2) i2.focus();
    });
  }

  function resolveChat(chatId, status) {
    api('chats/' + chatId + '/resolve', {
      method: 'POST',
      body: { status: status }
    }).then(function () {
      toast('Chat marked as ' + status, 'success');
      stopChatPoll(chatId);
      state.activeChatId = null;
      loadChats();
      loadDashboard();

      var thread = document.getElementById('bd-thread');
      if (thread) {
        thread.innerHTML =
          '<div class="bd-thread-empty">' +
            '<div class="bd-thread-empty-icon">✅</div>' +
            '<h3>Chat ' + status + '</h3>' +
            '<p>Pick another conversation to continue.</p>' +
          '</div>';
      }
    }).catch(function (err) {
      toast('Failed: ' + err.message, 'error');
    });
  }

  function reopenChat(chatId) {
    openReassignModal(chatId, true);
  }

  function releaseChat(chatId) {
    if (!confirm('Release this chat back to the shared queue?')) return;
    api('chats/' + chatId + '/release', { method: 'POST' }).then(function () {
      toast('Chat released.', 'success');
      stopChatPoll(chatId);
      state.activeChatId = null;
      loadChats();
      renderThreadResetUI();
    }).catch(function (err) {
      toast('Failed: ' + err.message, 'error');
    });
  }

  function renderThreadResetUI() {
    var thread = document.getElementById('bd-thread');
    if (!thread) return;
    thread.innerHTML =
      '<div class="bd-thread-empty">' +
        '<div class="bd-thread-empty-icon">📭</div>' +
        '<h3>Chat released</h3>' +
        '<p>Pick another conversation to continue.</p>' +
      '</div>';
  }

  function startChatPoll(chatId) {
    stopChatPoll(chatId);
    var interval = Math.max(2, config.settings.pollChat || 3) * 1000;
    state.pollTimers['chat-' + chatId] = setInterval(function () {
      pollChat(chatId);
    }, interval);
  }

  function stopChatPoll(chatId) {
    var key = 'chat-' + chatId;
    if (state.pollTimers[key]) {
      clearInterval(state.pollTimers[key]);
      delete state.pollTimers[key];
    }
  }

  function pollChat(chatId) {
    if (state.isPollingChat) return;
    state.isPollingChat = true;

    var after = state.lastMessageId[chatId] || 0;

    api('chats/' + chatId + '/poll?after=' + after).then(function (data) {
      var newMsgs = (data && data.messages) || [];
      if (!newMsgs.length) return;

      newMsgs.forEach(function (m) {
        var id = parseInt(m.id, 10);
        if (id > (state.lastMessageId[chatId] || 0)) {
          state.lastMessageId[chatId] = id;
        }
        var body = document.getElementById('bd-thread-body');
        if (body) {
          if (body.querySelector('.bd-empty')) body.innerHTML = '';
          body.insertAdjacentHTML('beforeend', renderBubble(m));
        }
        if (m.sender_type === 'visitor') playPing();
      });
      requestAnimationFrame(scrollThreadBottom);
    }).catch(function () {}).then(function () {
      state.isPollingChat = false;
    });
  }

  // =============================================================
  // CANNED REPLIES
  // =============================================================
  function loadCannedIntoSelect() {
    var sel = document.getElementById('bd-canned-select');
    if (!sel) return;

    api('canned').then(function (rows) {
      sel.innerHTML = '<option value="">Canned reply…</option>' +
        rows.map(function (r) {
          return '<option value="' + r.id + '" data-message="' + escAttr(r.message) + '">' +
            esc(r.header) + '</option>';
        }).join('');
    }).catch(function () {});
  }

  function onCannedSelect(e) {
    var sel = e.target;
    var opt = sel.options[sel.selectedIndex];
    if (!opt) return;
    var msg = opt.getAttribute('data-message');
    if (!msg) return;

    var input = document.getElementById('bd-reply-input');
    if (input) {
      input.value = msg;
      input.focus();
      input.dispatchEvent(new Event('input'));
    }
    sel.selectedIndex = 0;
  }

  function loadCanned() {
    var container = document.getElementById('bd-canned-list');
    if (!container) return;

    api('canned').then(function (rows) {
      if (!rows.length) {
        container.innerHTML = '<div class="bd-empty">No canned replies yet.</div>';
        return;
      }

      container.innerHTML = rows.map(function (r) {
        return '<div class="bd-list-item">' +
            '<div class="bd-list-item-info">' +
              '<div class="bd-list-item-title">' + esc(r.header) + '</div>' +
              '<div class="bd-list-item-sub">' + esc(r.message) + '</div>' +
            '</div>' +
            '<div class="bd-list-item-actions">' +
              '<button type="button" class="is-danger" data-delete-canned="' + r.id + '">Delete</button>' +
            '</div>' +
          '</div>';
      }).join('');

      container.querySelectorAll('[data-delete-canned]').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.getAttribute('data-delete-canned');
          if (!confirm('Delete this reply?')) return;
          api('canned/' + id, { method: 'DELETE' }).then(function () {
            toast('Deleted.', 'success');
            loadCanned();
          }).catch(function (err) {
            toast('Failed: ' + err.message, 'error');
          });
        });
      });
    });
  }

  // =============================================================
  // PERFORMANCE
  // =============================================================
  function loadPerformance() {
    closeReassignModal();

    if (!state.perfFrom || !state.perfTo) {
      var range = rangeForPreset(state.perfRange);
      state.perfFrom = range.from;
      state.perfTo   = range.to;
    }

    var fromEl = document.getElementById('bd-perf-from');
    var toEl   = document.getElementById('bd-perf-to');
    if (fromEl) fromEl.value = state.perfFrom;
    if (toEl)   toEl.value   = state.perfTo;

    var agentWrap = document.getElementById('bd-perf-agent-wrap');
    var agentSel  = document.getElementById('bd-perf-agent');
    if (agentWrap && agentSel) {
      if (config.internalLevel === 'agent') {
        agentWrap.hidden = true;
      } else {
        agentWrap.hidden = false;
        if (!agentSel.dataset.loaded) {
          api('stats/agents-list').then(function (rows) {
            state.agentsList = rows || [];
            var html = '<option value="">All agents</option>';
            state.agentsList.forEach(function (a) {
              html += '<option value="' + a.id + '">' + esc(a.name) + '</option>';
            });
            agentSel.innerHTML = html;
            agentSel.dataset.loaded = '1';
          }).catch(function () {});
        }
      }
    }

    var query = 'from=' + encodeURIComponent(state.perfFrom)
              + '&to=' + encodeURIComponent(state.perfTo)
              + (state.perfAgentId ? '&agent_id=' + encodeURIComponent(state.perfAgentId) : '');

    fetchSummary(query);
    fetchPerformanceTable(query);
    bindPerfEvents();
  }

  function rangeForPreset(preset) {
    var today = new Date();
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var fmt = function (d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
    var to = fmt(today);

    if (preset === 'today') {
      return { from: to, to: to };
    }
    if (preset === '7d') {
      var d7 = new Date(today.getTime() - 6 * 86400000);
      return { from: fmt(d7), to: to };
    }
    if (preset === '30d') {
      var d30 = new Date(today.getTime() - 29 * 86400000);
      return { from: fmt(d30), to: to };
    }
    if (preset === 'month') {
      var first = new Date(today.getFullYear(), today.getMonth(), 1);
      return { from: fmt(first), to: to };
    }
    return { from: to, to: to };
  }

  function fetchSummary(query) {
    api('stats/summary?' + query).then(function (data) {
      setText('bd-sum-chats', data.chats_taken);
      setText('bd-sum-resolved', data.resolved);
      setText('bd-sum-unresolved', data.unresolved);
      setText('bd-sum-fast', data.resolved_under_3min);
      setText('bd-sum-response', formatResponseTime(data.avg_response_seconds));
    }).catch(function () {});
  }

  function fetchPerformanceTable(query) {
    var tbody = document.getElementById('bd-perf-body');
    if (tbody) tbody.innerHTML = '<tr><td colspan="8" class="bd-empty">Loading…</td></tr>';

    api('stats/performance?' + query).then(function (data) {
      if (!tbody) return;
      if (!data.rows || !data.rows.length) {
        tbody.innerHTML = '<tr><td colspan="8" class="bd-empty">No data for this range.</td></tr>';
        return;
      }

      var exportBtn = document.getElementById('bd-perf-export');
      if (exportBtn && config.internalLevel !== 'agent') {
        exportBtn.hidden = false;
      }

      tbody.innerHTML = data.rows.map(function (r) {
        var isBest = r.is_best;
        var rateCls = r.resolution_rate >= 80 ? 'is-good'
                    : r.resolution_rate >= 60 ? 'is-mid' : 'is-low';

        return '<tr>' +
            '<td><strong>' + esc(r.agent_name) + '</strong></td>' +
            '<td>' + r.chats_taken + '</td>' +
            '<td>' + r.chats_resolved + '</td>' +
            '<td>' + r.chats_unresolved + '</td>' +
            '<td>' + r.resolved_under_3min + '</td>' +
            '<td>' + formatResponseTime(r.avg_response_seconds) + '</td>' +
            '<td><span class="bd-rate ' + rateCls + '">' + r.resolution_rate + '%</span></td>' +
            '<td>' +
              (r.agent_id ? '<button type="button" class="bd-btn bd-btn-ghost-dark bd-view-chats" data-agent-id="' + r.agent_id + '">View chats</button>' : '') +
              (isBest ? ' <span class="bd-best">★ Best</span>' : '') +
            '</td>' +
          '</tr>';
      }).join('');

      tbody.querySelectorAll('.bd-view-chats').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = btn.getAttribute('data-agent-id');
          state.perfAgentId = id;
          var agentSel = document.getElementById('bd-perf-agent');
          if (agentSel) agentSel.value = id;
          state.perfTab = 'history';
          activatePerfTab('history');
          state.histPage = 1;
          loadChatHistory();
        });
      });
    }).catch(function (err) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="8" class="bd-empty">Failed: ' + esc(err.message) + '</td></tr>';
    });
  }

  function activatePerfTab(tabName) {
    document.querySelectorAll('.bd-perf-tab').forEach(function (t) {
      t.classList.toggle('is-active', t.getAttribute('data-tab') === tabName);
    });
    document.querySelectorAll('.bd-perf-tab-panel').forEach(function (p) {
      p.classList.toggle('is-active', p.id === 'bd-perf-panel-' + tabName);
    });

    if (tabName === 'history') {
      loadChatHistory();
    }
  }

  function bindPerfEvents() {
    var wrap = document.getElementById('bd-view-performance');
    if (!wrap || wrap.dataset.bdBound === '1') return;
    wrap.dataset.bdBound = '1';

    wrap.querySelectorAll('.bd-preset').forEach(function (btn) {
      btn.addEventListener('click', function () {
        wrap.querySelectorAll('.bd-preset').forEach(function (b) { b.classList.remove('is-active'); });
        btn.classList.add('is-active');
        state.perfRange = btn.getAttribute('data-range') || '7d';
        var range = rangeForPreset(state.perfRange);
        state.perfFrom = range.from;
        state.perfTo   = range.to;
        loadPerformance();
      });
    });

    var fromEl = document.getElementById('bd-perf-from');
    var toEl   = document.getElementById('bd-perf-to');
    var agentSel = document.getElementById('bd-perf-agent');

    if (fromEl) fromEl.addEventListener('change', function () { state.perfFrom = fromEl.value; });
    if (toEl)   toEl.addEventListener('change',   function () { state.perfTo   = toEl.value;   });
    if (agentSel) agentSel.addEventListener('change', function () { state.perfAgentId = agentSel.value; });

    var applyBtn = document.getElementById('bd-perf-apply');
    if (applyBtn) {
      applyBtn.addEventListener('click', function () {
        state.histPage = 1;
        loadPerformance();
      });
    }

    wrap.querySelectorAll('.bd-perf-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        var tabName = t.getAttribute('data-tab');
        state.perfTab = tabName;
        activatePerfTab(tabName);
      });
    });

    var histSearchBtn = document.getElementById('bd-hist-search-btn');
    var histSearchInput = document.getElementById('bd-hist-search');
    var histStatusSel = document.getElementById('bd-hist-status');

    if (histSearchBtn && histSearchInput) {
      histSearchBtn.addEventListener('click', function () {
        state.histSearch = histSearchInput.value.trim();
        state.histPage = 1;
        loadChatHistory();
      });
      histSearchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          histSearchBtn.click();
        }
      });
    }

    if (histStatusSel) {
      histStatusSel.addEventListener('change', function () {
        state.histStatus = histStatusSel.value;
        state.histPage = 1;
        loadChatHistory();
      });
    }

    var exportBtn = document.getElementById('bd-perf-export');
    if (exportBtn) {
      exportBtn.addEventListener('click', exportHistoryCsv);
    }

    bindReassignModal();
  }

  function loadChatHistory() {
    var tbody = document.getElementById('bd-hist-body');
    if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="bd-empty">Loading…</td></tr>';

    var query = 'from=' + encodeURIComponent(state.perfFrom)
              + '&to=' + encodeURIComponent(state.perfTo)
              + '&status=' + encodeURIComponent(state.histStatus)
              + '&page=' + state.histPage
              + '&per_page=' + state.histPerPage
              + (state.perfAgentId ? '&agent_id=' + encodeURIComponent(state.perfAgentId) : '')
              + (state.histSearch ? '&search=' + encodeURIComponent(state.histSearch) : '');

    api('stats/chat-history?' + query).then(function (data) {
      state.histTotalPages = data.pages || 1;
      renderHistoryTable(data.chats || []);
      renderHistoryPagination(data.page, data.pages, data.total);
    }).catch(function (err) {
      if (tbody) tbody.innerHTML = '<tr><td colspan="7" class="bd-empty">Failed: ' + esc(err.message) + '</td></tr>';
    });
  }

  function renderHistoryTable(chats) {
    var tbody = document.getElementById('bd-hist-body');
    if (!tbody) return;

    if (!chats.length) {
      tbody.innerHTML = '<tr><td colspan="7" class="bd-empty">No chats found.</td></tr>';
      return;
    }

    var canReassign = (config.internalLevel === 'team_lead' || config.internalLevel === 'admin');

    tbody.innerHTML = chats.map(function (c) {
      var statusCls = 'bd-status-' + (c.status || 'new');
      var resolutionText = (c.resolution_time_seconds > 0)
        ? formatResponseTime(c.resolution_time_seconds)
        : '—';
      var canOpen = canOpenChat(c);
      var openBtn = canOpen
        ? '<button type="button" class="bd-btn bd-btn-ghost-dark bd-open-chat" data-chat-id="' + c.id + '">Open</button>'
        : '';

      return '<tr data-chat-id="' + c.id + '">' +
          '<td>' + formatDateTime(c.created_at) + '</td>' +
          '<td>' +
            '<strong>' + esc(c.visitor_name || 'Visitor #' + c.id) + '</strong>' +
            '<div class="bd-hist-sub">' + esc(c.visitor_phone || '') + '</div>' +
          '</td>' +
          '<td>' + (c.agent_name ? esc(c.agent_name) : '<em>Unassigned</em>') + '</td>' +
          '<td><span class="bd-hist-badge ' + statusCls + '">' + esc(c.status) + '</span></td>' +
          '<td>' + (c.message_count || 0) + '</td>' +
          '<td>' + resolutionText + '</td>' +
          '<td class="bd-hist-actions">' +
            openBtn +
            (canReassign ? '<button type="button" class="bd-btn bd-btn-ghost-dark bd-reassign-chat" data-chat-id="' + c.id + '">Reassign</button>' : '') +
          '</td>' +
        '</tr>';
    }).join('');

    tbody.querySelectorAll('.bd-open-chat').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-chat-id'), 10);
        setHash('chatroom');
        switchView('chatroom');
        setTimeout(function () { openChat(id); }, 250);
      });
    });

    tbody.querySelectorAll('.bd-reassign-chat').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = parseInt(btn.getAttribute('data-chat-id'), 10);
        openReassignModal(id, false);
      });
    });
  }

  function canOpenChat(chat) {
    if (config.internalLevel === 'team_lead' || config.internalLevel === 'admin') return true;
    return true;
  }

  function renderHistoryPagination(page, pages, total) {
    var el = document.getElementById('bd-hist-pagination');
    if (!el) return;

    if (pages <= 1) {
      el.innerHTML = '<div class="bd-perf-pagination-info">' + total + ' chat' + (total === 1 ? '' : 's') + '</div>';
      return;
    }

    var html = '<div class="bd-perf-pagination-info">' + total + ' chats · page ' + page + ' of ' + pages + '</div>';
    html += '<div class="bd-perf-pagination-controls">';

    if (page > 1) {
      html += '<button type="button" class="bd-page-btn" data-page="' + (page - 1) + '">‹ Prev</button>';
    }

    var start = Math.max(1, page - 2);
    var end   = Math.min(pages, start + 4);
    start     = Math.max(1, end - 4);

    for (var i = start; i <= end; i++) {
      html += '<button type="button" class="bd-page-btn' + (i === page ? ' is-current' : '') + '" data-page="' + i + '">' + i + '</button>';
    }

    if (page < pages) {
      html += '<button type="button" class="bd-page-btn" data-page="' + (page + 1) + '">Next ›</button>';
    }

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
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
         + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  // ---- Reassign / Reopen modal ----
  function bindReassignModal() {
    var modal = document.getElementById('bd-reassign-modal');
    if (!modal || modal.dataset.bdBound === '1') return;
    modal.dataset.bdBound = '1';

    var cancel = document.getElementById('bd-reassign-cancel');
    var confirm = document.getElementById('bd-reassign-confirm');

    if (cancel) {
      cancel.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        closeReassignModal();
      });
    }

    if (confirm) {
      confirm.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        confirmReassign();
      });
    }

    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeReassignModal();
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        var m = document.getElementById('bd-reassign-modal');
        if (m && !m.hidden) closeReassignModal();
      }
    });

    var content = modal.querySelector('.bd-reassign-modal');
    if (content) {
      content.addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }
  }

  function openReassignModal(chatId, reopenMode) {
    var modal = document.getElementById('bd-reassign-modal');
    var info  = document.getElementById('bd-reassign-info');
    var sel   = document.getElementById('bd-reassign-select');
    if (!modal || !sel) return;

    reassignState.chatId = chatId;
    reassignState.reopenMode = !!reopenMode;

    var titleEl = modal.querySelector('h3');
    var confirmBtn = document.getElementById('bd-reassign-confirm');
    if (titleEl) {
      titleEl.textContent = reopenMode ? 'Reopen Chat' : 'Reassign Chat';
    }
    if (confirmBtn) {
      confirmBtn.textContent = reopenMode ? 'Reopen' : 'Reassign';
    }

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
    modal.style.display = '';

    api('stats/agents-list').then(function (rows) {
      state.agentsList = rows || [];
      populateReassignSelect(sel);
    }).catch(function (err) {
      sel.innerHTML = '<option value="">Failed to load agents</option>';
      console.warn('[BD] agents-list failed:', err.message);
    });
  }

  function populateReassignSelect(sel) {
    var others = [];
    state.agentsList.forEach(function (a) {
      if (parseInt(a.id, 10) === parseInt(config.currentUser.id, 10)) return;
      others.push(a);
    });

    if (others.length === 0) {
      sel.innerHTML = '<option value="">No other agents available</option>';
      return;
    }

    var html = '<option value="">Select agent…</option>';
    others.forEach(function (a) {
      html += '<option value="' + a.id + '">' + esc(a.name) + '</option>';
    });
    sel.innerHTML = html;
  }

  function confirmReassign() {
    var sel = document.getElementById('bd-reassign-select');
    if (!sel || !reassignState.chatId) return;

    var agentId = parseInt(sel.value, 10);
    if (!agentId) {
      toast('Please select an agent', 'error');
      return;
    }

    var confirmBtn = document.getElementById('bd-reassign-confirm');
    if (confirmBtn) confirmBtn.disabled = true;

    var endpoint = reassignState.reopenMode
      ? 'chats/' + reassignState.chatId + '/reopen'
      : 'chats/' + reassignState.chatId + '/reassign';

    api(endpoint, {
      method: 'POST',
      body: { agent_id: agentId }
    }).then(function () {
      toast(reassignState.reopenMode ? 'Chat reopened!' : 'Chat reassigned!', 'success');
      closeReassignModal();

      loadChats();
      if (state.view === 'performance') loadChatHistory();
    }).catch(function (err) {
      toast('Failed: ' + err.message, 'error');
    }).then(function () {
      if (confirmBtn) confirmBtn.disabled = false;
    });
  }

  // ---- CSV export ----
  function exportHistoryCsv() {
    if (config.internalLevel === 'agent') {
      toast('Not allowed', 'error');
      return;
    }

    var query = 'from=' + encodeURIComponent(state.perfFrom)
              + '&to=' + encodeURIComponent(state.perfTo)
              + '&status=' + encodeURIComponent(state.histStatus)
              + '&page=1&per_page=100'
              + (state.perfAgentId ? '&agent_id=' + encodeURIComponent(state.perfAgentId) : '')
              + (state.histSearch ? '&search=' + encodeURIComponent(state.histSearch) : '');

    toast('Preparing CSV…', 'success');

    api('stats/chat-history?' + query).then(function (data) {
      var rows = data.chats || [];
      if (!rows.length) {
        toast('No chats to export', 'error');
        return;
      }

      var headers = ['Chat ID', 'Date', 'Visitor', 'Phone', 'Email', 'Agent', 'Status', 'Messages', 'Resolution (s)'];
      var lines = [headers.join(',')];

      rows.forEach(function (c) {
        var line = [
          c.id,
          c.created_at,
          c.visitor_name || '',
          c.visitor_phone || '',
          c.visitor_email || '',
          c.agent_name || '',
          c.status || '',
          c.message_count || 0,
          c.resolution_time_seconds || 0
        ].map(function (v) {
          return '"' + String(v).replace(/"/g, '""') + '"';
        }).join(',');
        lines.push(line);
      });

      var csv = lines.join('\n');
      var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
      var url  = URL.createObjectURL(blob);
      var link = document.createElement('a');
      link.href = url;
      link.download = 'bigdrop-chat-history-' + state.perfFrom + '-to-' + state.perfTo + '.csv';
      document.body.appendChild(link);
      link.click();
      setTimeout(function () {
        URL.revokeObjectURL(url);
        link.remove();
      }, 100);
    }).catch(function (err) {
      toast('Export failed: ' + err.message, 'error');
    });
  }

  // =============================================================
  // CLIENTS
  // =============================================================
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
        var el = document.getElementById('bd-client-result');
        if (!el) return;
        if (!rows.length) {
          el.innerHTML = '<div class="bd-empty">No matching client found.</div>';
          return;
        }
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
      }).then(function () {
        btn.disabled = false;
      });
    };

    btn.addEventListener('click', runSearch);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
    });
  }

  // =============================================================
  // AGENTS
  // =============================================================
  function loadAgents() {
    var container = document.getElementById('bd-agent-list');
    if (!container) return;

    api('agents').then(function (rows) {
      if (!rows.length) {
        container.innerHTML = '<div class="bd-empty">No agents yet.</div>';
        return;
      }
      container.innerHTML = rows.map(function (a) {
        return '<div class="bd-list-item">' +
            '<div class="bd-list-item-info">' +
              '<div class="bd-list-item-title">' + esc(a.name) + '</div>' +
              '<div class="bd-list-item-sub">' + esc(a.email) + ' · ' + esc(a.username) + ' · ' + esc(a.role) + '</div>' +
            '</div>' +
          '</div>';
      }).join('');
    });

    var form = document.getElementById('bd-agent-form');
    if (form && !form.dataset.bdBound) {
      form.dataset.bdBound = '1';
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var status = document.getElementById('bd-agent-status');
        var payload = {
          name:      document.getElementById('bd-agent-name').value.trim(),
          surname:   document.getElementById('bd-agent-surname').value.trim(),
          position:  document.getElementById('bd-agent-position').value,
          username:  document.getElementById('bd-agent-username').value.trim(),
          email:     document.getElementById('bd-agent-email').value.trim(),
          password:  document.getElementById('bd-agent-password').value
        };
        if (status) { status.textContent = 'Saving…'; status.style.color = '#6C6F8C'; }

        api('agents', { method: 'POST', body: payload }).then(function () {
          if (status) { status.textContent = '✅ Agent saved!'; status.style.color = '#2E6B34'; }
          form.reset();
          loadAgents();
        }).catch(function (err) {
          if (status) { status.textContent = '❌ ' + err.message; status.style.color = '#C11501'; }
        });
      });
    }
  }

  // =============================================================
  // NOTIFICATIONS
  // =============================================================
  function initNotifications() {
    var btn = document.getElementById('bd-notif-btn');
    var panel = document.getElementById('bd-notif-panel');
    var markAll = document.getElementById('bd-notif-mark-all');

    if (!btn || !panel) {
      console.warn('[BD] Notification bell or panel not found in DOM.');
      return;
    }

    if (btn.dataset.bdBound === '1') return;
    btn.dataset.bdBound = '1';

    panel.setAttribute('hidden', '');
    panel.style.display = 'none';

    btn.onclick = function (e) {
      e.preventDefault();
      e.stopPropagation();

      var currentlyHidden = panel.hasAttribute('hidden');
      if (currentlyHidden) {
        panel.removeAttribute('hidden');
        panel.style.display = 'block';
      } else {
        panel.setAttribute('hidden', '');
        panel.style.display = 'none';
      }
    };

    document.addEventListener('click', function (e) {
      var p = document.getElementById('bd-notif-panel');
      var b = document.getElementById('bd-notif-btn');
      if (!p || !b) return;
      if (p.hasAttribute('hidden')) return;
      if (p.contains(e.target)) return;
      if (b.contains(e.target)) return;
      p.setAttribute('hidden', '');
      p.style.display = 'none';
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        panel.setAttribute('hidden', '');
        panel.style.display = 'none';
      }
    });

    panel.addEventListener('click', function (e) {
      e.stopPropagation();
    });

    if (markAll) {
      markAll.onclick = function (e) {
        e.preventDefault();
        e.stopPropagation();

        document.querySelectorAll('.bd-notif-item.is-unread').forEach(function (it) {
          it.classList.remove('is-unread');
        });
        setNotifBadge(0);

        panel.setAttribute('hidden', '');
        panel.style.display = 'none';

        api('notifications/read', { method: 'POST' }).catch(function () {});
      };
    }

    console.log('[BD] Notification panel initialized.');
  }

  function fetchNotifications() {
    api('notifications').then(function (data) {
      state.notifications = data.items || [];

      var unreadInList = state.notifications.filter(function (n) {
        return !parseInt(n.is_read, 10);
      }).length;

      state.unreadCount = Math.max(data.unread || 0, unreadInList);

      renderNotifications(state.notifications);
      setNotifBadge(state.unreadCount);

      if (state.notifications.length) {
        state.lastNotifId = parseInt(state.notifications[0].id, 10) || 0;
      }
    }).catch(function () {});
  }

  function renderNotifications(items) {
    var list = document.getElementById('bd-notif-list');
    if (!list) return;

    if (!items.length) {
      list.innerHTML = '<div class="bd-notif-empty">No notifications.</div>';
      return;
    }

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
        var notif = state.notifications.find(function (n) {
          return parseInt(n.id, 10) === nid;
        });

        var panel = document.getElementById('bd-notif-panel');
        if (panel) {
          panel.setAttribute('hidden', '');
          panel.style.display = 'none';
        }
        item.classList.remove('is-unread');

        if (notif && notif.reference_id) {
          setHash('chatroom');
          switchView('chatroom');
          setTimeout(function () {
            openChat(parseInt(notif.reference_id, 10));
          }, 250);
        }

        api('notifications/read', { method: 'POST' }).then(function () {
          api('notifications').then(function (data) {
            setNotifBadge(data.unread || 0);
          }).catch(function () {});
        });
      });
    });
  }

  function setNotifBadge(count) {
    var badge = document.getElementById('bd-notif-badge');
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : count;
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  function startNotificationPoll() {
    if (state.notificationTimer) clearInterval(state.notificationTimer);
    state.notificationTimer = setInterval(function () {
      api('notifications/poll?since=' + state.lastNotifId).then(function (data) {
        if (data.unread !== undefined) setNotifBadge(data.unread);

        if (data.waiting_chats !== undefined) {
          var badge = document.getElementById('bd-nav-chat-badge');
          if (badge) {
            if (data.waiting_chats > 0) {
              badge.textContent = data.waiting_chats;
              badge.hidden = false;
            } else {
              badge.hidden = true;
            }
          }
        }

        if (data.notifications && data.notifications.length) {
          data.notifications.forEach(function (n) {
            var nid = parseInt(n.id, 10);
            if (nid > state.lastNotifId) state.lastNotifId = nid;
            state.notifications.unshift(n);
            playPing();
            showDesktopNotification(n);
          });
          renderNotifications(state.notifications.slice(0, 30));
        }

        if (state.view === 'chatroom') loadChats();
      }).catch(function () {});
    }, Math.max(3, config.settings.pollNotify || 8) * 1000);
  }

  function showDesktopNotification(n) {
    if (!config.settings.desktopNotify) return;
    if (!('Notification' in window)) return;
    if (Notification.permission !== 'granted') return;

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
            notif.onclick = function () {
              window.focus();
              setHash('chatroom');
              switchView('chatroom');
              notif.close();
            };
          } catch (e) {}
        });
      } else {
        var n2 = new Notification(n.title, opts);
        n2.onclick = function () {
          window.focus();
          setHash('chatroom');
          switchView('chatroom');
          n2.close();
        };
      }
    } catch (e) {
      console.warn('[BD] Desktop notification failed:', e);
    }
  }

  // =============================================================
  // SOUND
  // =============================================================
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

  // =============================================================
  // HEARTBEAT
  // =============================================================
  function startHeartbeat() {
    var beat = function () {
      api('heartbeat', { method: 'POST' }).catch(function () {});
    };
    beat();
    state.heartbeatTimer = setInterval(beat, 120000);
  }

  // =============================================================
  // PUSH PROMPT
  // =============================================================
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
        e.preventDefault();
        e.stopPropagation();
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
        e.preventDefault();
        e.stopPropagation();
        closeBanner(true);
      });
    }
  }

  // =============================================================
  // GLOBAL SEARCH
  // =============================================================
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
          setHash('clients');
          switchView('clients');
          setTimeout(function () {
            var cq = document.getElementById('bd-client-q');
            if (cq) {
              cq.value = q;
              var b = document.getElementById('bd-client-search-btn');
              if (b) b.click();
            }
          }, 200);
        } else if (document.getElementById('bd-view-chatroom')) {
          setHash('chatroom');
          switchView('chatroom');
        }
      }, 500);
    });
  }

  // =============================================================
  // HELPERS
  // =============================================================
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
    var hh = ('0' + d.getHours()).slice(-2);
    var mm = ('0' + d.getMinutes()).slice(-2);
    return hh + ':' + mm;
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

  // =============================================================
  // EXPOSE
  // =============================================================
  window.BDPortal = {
    switchView: switchView,
    reload: function () {
      if (state.view === 'dashboard') loadDashboard();
      if (state.view === 'chatroom') loadChats();
    }
  };
})();