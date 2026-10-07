/* ==========================================================
   Big Drop - Internal Team Chat (v1.0.5)
   Fixed: Double-sending/rendering race condition with poller
   Fixed: Rapid-click thread loading overlaps
   ========================================================== */
(function () {
  'use strict';

  if (typeof window.BD === 'undefined') return;

  var config = window.BD;

  var state = {
    channel: 'updates',
    threads: [],
    activeThreadId: null,
    messages: [],
    lastMessageId: 0,
    level: config.internalLevel || 'agent',
    pollTimer: null,
    unread: { updates: 0, tickets: 0, total: 0 },
    pendingAttachment: null,
    pendingPreviewUrl: null,
    isPosting: false,
    isLoadingThread: false
  };

  var $container;

  var ICONS = {
    paperclip: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>',
    send: '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>'
  };

  // ============================================================
  // BOOT
  // ============================================================
  function boot() {
    $container = document.getElementById('bd-internal-chat');
    if (!$container) return;
    if (boot.done) return;
    boot.done = true;

    renderShell();
    loadThreads();
    refreshUnread();
    startUnreadPoll();
    console.log('[BD IChat] Booted.');
  }

  function initWhenReady(attemptsLeft) {
    if (attemptsLeft === undefined) attemptsLeft = 40;

    var container = document.getElementById('bd-internal-chat');
    if (container && container.innerHTML.trim() === '') {
      boot.done = false;
      boot();
      return;
    }
    if (container && boot.done) return;

    if (attemptsLeft <= 0) {
      console.warn('[BD IChat] Container #bd-internal-chat never appeared.');
      return;
    }

    setTimeout(function () { initWhenReady(attemptsLeft - 1); }, 100);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { initWhenReady(); });
  } else {
    initWhenReady();
  }

  window.addEventListener('hashchange', function () {
    if (location.hash === '#internal') {
      setTimeout(initWhenReady, 100);
    }
  });

  window.BDInternalChat = {
    refresh: function () {
      if (boot.done) {
        loadThreads();
        refreshUnread();
      } else {
        initWhenReady();
      }
    }
  };

  // ============================================================
  // RENDER SHELL
  // ============================================================
  function renderShell() {
    var canPostUpdates = (state.level === 'team_lead' || state.level === 'admin');

    $container.innerHTML =
      '<div class="bd-ichat">' +
        '<div class="bd-ichat-sidebar">' +
          '<div class="bd-ichat-channels">' +
            '<div class="bd-ichat-channel is-active" data-channel="updates">' +
              'Updates <span class="bd-ichat-channel-count" id="bd-ichat-count-updates" hidden>0</span>' +
            '</div>' +
            '<div class="bd-ichat-channel" data-channel="tickets">' +
              'Tickets <span class="bd-ichat-channel-count" id="bd-ichat-count-tickets" hidden>0</span>' +
            '</div>' +
          '</div>' +
          '<div class="bd-ichat-new">' +
            '<button type="button" id="bd-ichat-new-btn">' +
              (canPostUpdates ? '+ New Update' : '+ New Support Ticket') +
            '</button>' +
          '</div>' +
          '<div class="bd-ichat-threads" id="bd-ichat-threads"></div>' +
        '</div>' +
        '<div class="bd-ichat-thread-view" id="bd-ichat-thread-view">' +
          '<div class="bd-ichat-empty">' +
            '<div class="bd-ichat-empty-icon">[ ]</div>' +
            '<h3>Select a thread</h3>' +
            '<p>Choose an update or ticket from the list.</p>' +
          '</div>' +
        '</div>' +
      '</div>';

    $container.querySelectorAll('.bd-ichat-channel').forEach(function (tab) {
      tab.addEventListener('click', function () {
        $container.querySelectorAll('.bd-ichat-channel').forEach(function (t) {
          t.classList.remove('is-active');
        });
        tab.classList.add('is-active');
        state.channel = tab.getAttribute('data-channel') || 'updates';
        state.activeThreadId = null;
        renderThreadViewEmpty();
        loadThreads();
        updateNewButtonLabel();
      });
    });

    var newBtn = document.getElementById('bd-ichat-new-btn');
    if (newBtn) newBtn.addEventListener('click', openNewThreadModal);
  }

  function updateNewButtonLabel() {
    var btn = document.getElementById('bd-ichat-new-btn');
    if (!btn) return;
    var canPostUpdates = (state.level === 'team_lead' || state.level === 'admin');
    if (state.channel === 'updates') {
      btn.textContent = canPostUpdates ? '+ New Update' : '+ New Support Ticket';
    } else {
      btn.textContent = '+ New Support Ticket';
    }
  }

  // ============================================================
  // API HELPER
  // ============================================================
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

  // ============================================================
  // LOAD THREADS
  // ============================================================
  function loadThreads() {
    api('internal/threads?channel=' + encodeURIComponent(state.channel))
      .then(function (data) {
        state.threads = data.threads || [];
        state.level = data.level || state.level;
        renderThreadsList();
      })
      .catch(function (err) {
        console.warn('[BD IChat] loadThreads failed:', err.message);
      });
  }

  function renderThreadsList() {
    var list = document.getElementById('bd-ichat-threads');
    if (!list) return;

    if (!state.threads.length) {
      list.innerHTML = '<div class="bd-empty" style="padding:30px 18px;">' +
        (state.channel === 'updates' ? 'No updates yet.' : 'No tickets yet.') +
        '</div>';
      return;
    }

    list.innerHTML = state.threads.map(function (t) {
      var isActive = state.activeThreadId === parseInt(t.id, 10) ? ' is-active' : '';
      var isClosed = t.status === 'closed' ? ' is-closed' : '';
      var preview = t.last_message ? String(t.last_message).slice(0, 60) : 'No messages yet';
      var time = timeAgo(t.last_at || t.updated_at || t.created_at);
      var priorityCls = t.priority === 'high' || t.priority === 'urgent' ? 'is-priority' : 'is-normal';

      return '<div class="bd-ichat-thread' + isActive + isClosed + '" data-thread-id="' + t.id + '">' +
          '<div class="bd-ichat-thread-top">' +
            '<div class="bd-ichat-thread-title">' + esc(t.title || '(no title)') + '</div>' +
            '<div class="bd-ichat-thread-time">' + time + '</div>' +
          '</div>' +
          '<div class="bd-ichat-thread-preview">' + esc(preview) + '</div>' +
          '<div class="bd-ichat-thread-badges">' +
            '<span class="bd-ichat-badge ' + (t.status === 'closed' ? 'is-closed' : 'is-open') + '">' +
              (t.status === 'closed' ? 'Closed' : 'Open') +
            '</span>' +
            '<span class="bd-ichat-badge ' + priorityCls + '">' + esc(t.priority) + '</span>' +
          '</div>' +
        '</div>';
    }).join('');

    list.querySelectorAll('.bd-ichat-thread').forEach(function (el) {
      el.addEventListener('click', function () {
        var id = parseInt(el.getAttribute('data-thread-id'), 10);
        openThread(id);
      });
    });
  }

  // ============================================================
  // OPEN THREAD
  // ============================================================
  function openThread(threadId) {
    // Prevent overlapping requests if the same thread is clicked rapidly
    if (state.activeThreadId === threadId && state.isLoadingThread) return;
    
    state.isLoadingThread = true;
    state.activeThreadId = threadId;
    state.lastMessageId = 0;

    document.querySelectorAll('.bd-ichat-thread').forEach(function (el) {
      el.classList.toggle('is-active', parseInt(el.getAttribute('data-thread-id'), 10) === threadId);
    });

    api('internal/threads/' + threadId).then(function (data) {
      state.messages = data.messages || [];
      state.level = data.level || state.level;
      state.messages.forEach(function (m) {
        var id = parseInt(m.id, 10);
        if (id > state.lastMessageId) state.lastMessageId = id;
      });
      renderThreadView(data.thread);
      startThreadPoll();
      refreshUnread();
    }).catch(function (err) {
      toast('Failed to open thread: ' + err.message, 'error');
    }).then(function() {
      state.isLoadingThread = false;
    });
  }

  function renderThreadView(thread) {
    var view = document.getElementById('bd-ichat-thread-view');
    if (!view) return;

    var isClosed = thread.status === 'closed';
    var canReply = canReplyTo(thread);
    var canManage = (state.level === 'team_lead' || state.level === 'admin');
    var canReopen = canManage || (parseInt(thread.created_by, 10) === parseInt(config.currentUser.id, 10));

    var actionBtn = '';
    if (isClosed && canReopen) {
      actionBtn = '<button type="button" id="bd-ichat-reopen">Reopen</button>';
    } else if (!isClosed && canManage) {
      actionBtn = '<button type="button" id="bd-ichat-close">Close</button>';
    }

    view.innerHTML =
      '<div class="bd-ichat-thread-head">' +
        '<div class="bd-ichat-thread-info">' +
          '<div class="bd-ichat-thread-heading">' + esc(thread.title || '(no title)') + '</div>' +
          '<div class="bd-ichat-thread-sub">' +
            '<span>By ' + esc(thread.author_name || 'Agent') + '</span>' +
            '<span>' + timeAgo(thread.created_at) + '</span>' +
            '<span>' + (isClosed ? 'Closed' : 'Open') + '</span>' +
          '</div>' +
        '</div>' +
        '<div class="bd-ichat-thread-actions">' + actionBtn + '</div>' +
      '</div>' +
      '<div class="bd-ichat-messages" id="bd-ichat-messages"></div>' +
      (canReply ? renderComposer() : renderLockedNotice(thread));

    var messagesEl = document.getElementById('bd-ichat-messages');
    if (messagesEl) {
      renderMessages(messagesEl);
      scrollToBottom();
    }

    var closeBtn = document.getElementById('bd-ichat-close');
    var reopenBtn = document.getElementById('bd-ichat-reopen');
    if (closeBtn) closeBtn.addEventListener('click', function () { setThreadStatus(thread.id, 'closed'); });
    if (reopenBtn) reopenBtn.addEventListener('click', function () { setThreadStatus(thread.id, 'open'); });

    bindComposer();
  }

  function canReplyTo(thread) {
    if (thread.status === 'closed') return false;
    if (thread.channel === 'updates') {
      return (state.level === 'team_lead' || state.level === 'admin');
    }
    if (thread.channel === 'tickets') {
      if (state.level === 'agent') {
        return parseInt(thread.created_by, 10) === parseInt(config.currentUser.id, 10);
      }
      return true;
    }
    return false;
  }

  function renderLockedNotice(thread) {
    var msg = '';
    if (thread.status === 'closed') {
      msg = 'This thread is closed. Reopen to reply.';
    } else if (thread.channel === 'updates') {
      msg = 'Only team leads and admins can post company updates.';
    } else {
      msg = 'You cannot reply to this thread.';
    }
    return '<div class="bd-ichat-notice">[Locked] ' + esc(msg) + '</div>';
  }

  // ============================================================
  // RENDER MESSAGES
  // ============================================================
  function renderMessages(container) {
    if (!state.messages.length) {
      container.innerHTML = '<div class="bd-empty">No messages yet.</div>';
      return;
    }
    container.innerHTML = state.messages.map(renderOneMessage).join('');
  }

  function renderOneMessage(m) {
    var isMine = parseInt(m.sender_id, 10) === parseInt(config.currentUser.id, 10);
    var isSystem = parseInt(m.is_system, 10) === 1;

    if (isSystem) {
      return '<div class="bd-ichat-msg is-system">' +
          '<div class="bd-ichat-msg-body">' +
            '<div class="bd-ichat-msg-text">' + esc(m.message) + '</div>' +
          '</div>' +
        '</div>';
    }

    var roleLabel = '';
    var roleCls = '';
    var role = (m.sender_role || '').toLowerCase();
    if (role === 'admin') { roleLabel = 'Admin'; roleCls = 'is-admin'; }
    else if (role === 'team_lead') { roleLabel = 'Team Lead'; roleCls = 'is-team-lead'; }
    else if (role === 'agent') { roleLabel = 'Agent'; }

    return '<div class="bd-ichat-msg' + (isMine ? ' is-mine' : '') + '">' +
        '<div class="bd-ichat-msg-avatar">' + esc(initials(m.sender_name || 'User')) + '</div>' +
        '<div class="bd-ichat-msg-body">' +
          '<div class="bd-ichat-msg-head">' +
            esc(m.sender_name || 'User') +
            (roleLabel ? ' <span class="bd-ichat-msg-role ' + roleCls + '">' + esc(roleLabel) + '</span>' : '') +
          '</div>' +
          (m.message ? '<div class="bd-ichat-msg-text">' + esc(m.message) + '</div>' : '') +
          (m.attachment_url ? renderAttachment(m) : '') +
          '<div class="bd-ichat-msg-time">' + timeAgo(m.created_at) + '</div>' +
        '</div>' +
      '</div>';
  }

  function renderAttachment(m) {
    var type = (m.attachment_type || '').toLowerCase();
    var url = m.attachment_url;
    var name = m.attachment_name || 'Attachment';
    var size = formatSize(m.attachment_size);

    if (type.indexOf('image/') === 0) {
      return '<div class="bd-ichat-attachment">' +
          '<a href="' + escAttr(url) + '" target="_blank" rel="noopener">' +
            '<img src="' + escAttr(url) + '" alt="' + escAttr(name) + '" loading="lazy">' +
          '</a>' +
        '</div>';
    }
    if (type.indexOf('video/') === 0) {
      return '<div class="bd-ichat-attachment">' +
          '<video controls preload="metadata" src="' + escAttr(url) + '"></video>' +
        '</div>';
    }
    if (type.indexOf('audio/') === 0) {
      return '<div class="bd-ichat-attachment">' +
          '<audio controls preload="metadata" src="' + escAttr(url) + '"></audio>' +
        '</div>';
    }
    return '<a class="bd-ichat-attachment bd-ichat-attachment-file" href="' + escAttr(url) + '" target="_blank" rel="noopener">' +
        '<div class="bd-ichat-attachment-icon">DOC</div>' +
        '<div style="min-width:0;">' +
          '<div>' + esc(name) + '</div>' +
          '<div class="bd-ichat-attachment-meta">' + esc(size) + '</div>' +
        '</div>' +
      '</a>';
  }

  // ============================================================
  // COMPOSER
  // ============================================================
  function renderComposer() {
    return '<div class="bd-ichat-composer">' +
        '<div id="bd-ichat-attach-strip"></div>' +
        '<div class="bd-ichat-composer-row">' +
          '<input type="file" id="bd-ichat-file" hidden ' +
            'accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">' +
          '<button type="button" class="bd-ichat-composer-btn" id="bd-ichat-attach-btn" title="Attach file">' +
            ICONS.paperclip +
          '</button>' +
          '<textarea id="bd-ichat-input" placeholder="Type your message..." rows="1"></textarea>' +
          '<button type="button" class="bd-ichat-composer-btn is-primary" id="bd-ichat-send" title="Send">' +
            ICONS.send +
          '</button>' +
        '</div>' +
      '</div>';
  }

  function bindComposer() {
    var input = document.getElementById('bd-ichat-input');
    var sendBtn = document.getElementById('bd-ichat-send');
    var attachBtn = document.getElementById('bd-ichat-attach-btn');
    var fileInput = document.getElementById('bd-ichat-file');

    if (!input || !sendBtn || !attachBtn || !fileInput) return;

    input.addEventListener('input', function () {
      input.style.height = 'auto';
      input.style.height = Math.min(140, input.scrollHeight) + 'px';
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
      }
    });
    sendBtn.addEventListener('click', sendMessage);
    attachBtn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', handleFileSelected);

    renderAttachStrip();
  }

  function handleFileSelected(e) {
    var file = e.target.files && e.target.files[0];
    if (!file) return;

    if (state.pendingPreviewUrl) {
      try { URL.revokeObjectURL(state.pendingPreviewUrl); } catch (err) {}
      state.pendingPreviewUrl = null;
    }

    state.pendingAttachment = file;
    renderAttachStrip();
    e.target.value = '';
  }

  function buildAttachPreviewHtml(file, removeId, stateRef) {
    var type = (file.type || '').toLowerCase();
    var thumbHtml = '';

    if (type.indexOf('image/') === 0) {
      if (!stateRef.pendingPreviewUrl) {
        try { stateRef.pendingPreviewUrl = URL.createObjectURL(file); } catch (e) {}
      }
      thumbHtml = '<div class="thumb" style="background-image:url(' +
        escAttr(stateRef.pendingPreviewUrl || '') + ')"></div>';
    } else if (type.indexOf('video/') === 0) {
      thumbHtml = '<div class="thumb is-video">VIDEO</div>';
    } else if (type.indexOf('audio/') === 0) {
      thumbHtml = '<div class="thumb is-audio">AUDIO</div>';
    } else if (type.indexOf('pdf') !== -1) {
      thumbHtml = '<div class="thumb is-pdf">PDF</div>';
    } else {
      thumbHtml = '<div class="thumb is-file">FILE</div>';
    }

    return '<div class="bd-ichat-attach-preview">' +
        thumbHtml +
        '<div class="meta">' +
          '<div class="name">' + esc(file.name) + '</div>' +
          '<div class="size">' + formatSize(file.size) + '</div>' +
        '</div>' +
        '<button type="button" id="' + removeId + '" title="Remove">&times;</button>' +
      '</div>';
  }

  function renderAttachStrip() {
    var strip = document.getElementById('bd-ichat-attach-strip');
    if (!strip) return;

    var file = state.pendingAttachment;
    if (!file) {
      strip.innerHTML = '';
      return;
    }

    strip.innerHTML = buildAttachPreviewHtml(file, 'bd-ichat-remove-attach', state);

    var removeBtn = document.getElementById('bd-ichat-remove-attach');
    if (removeBtn) {
      removeBtn.addEventListener('click', function () {
        if (state.pendingPreviewUrl) {
          try { URL.revokeObjectURL(state.pendingPreviewUrl); } catch (e) {}
          state.pendingPreviewUrl = null;
        }
        state.pendingAttachment = null;
        renderAttachStrip();
      });
    }
  }

  // ============================================================
  // SEND MESSAGE
  // ============================================================
  function sendMessage() {
    if (state.isPosting) return;
    var input = document.getElementById('bd-ichat-input');
    var sendBtn = document.getElementById('bd-ichat-send');
    if (!input || !state.activeThreadId) return;

    var text = input.value.trim();
    var file = state.pendingAttachment;

    if (!text && !file) return;

    state.isPosting = true;
    if (sendBtn) sendBtn.disabled = true;

    var uploadPromise = file ? uploadFile(file) : Promise.resolve(null);

    uploadPromise.then(function (uploadResult) {
      var payload = { message: text };
      if (uploadResult) {
        payload.attachment_id   = uploadResult.attachment_id;
        payload.attachment_url  = uploadResult.attachment_url;
        payload.attachment_name = uploadResult.attachment_name;
        payload.attachment_type = uploadResult.attachment_type;
        payload.attachment_size = uploadResult.attachment_size;
      }
      return api('internal/threads/' + state.activeThreadId + '/messages', {
        method: 'POST',
        body: payload
      }).then(function (res) {
        res._uploadResult = uploadResult;
        return res;
      });
    }).then(function (res) {
      input.value = '';
      input.style.height = 'auto';

      if (state.pendingPreviewUrl) {
        try { URL.revokeObjectURL(state.pendingPreviewUrl); } catch (e) {}
        state.pendingPreviewUrl = null;
      }
      state.pendingAttachment = null;
      renderAttachStrip();

      var msgId = parseInt(res.id, 10);
      
      // FIX: Check if the poller already added this message to prevent double-rendering
      var exists = state.messages.some(function(m) { return parseInt(m.id, 10) === msgId; });

      if (!exists) {
        var newMsg = {
          id: msgId,
          sender_id: parseInt(config.currentUser.id, 10),
          sender_name: config.currentUser.name,
          sender_role: state.level,
          message: text,
          created_at: res.time
        };
        if (res._uploadResult) {
          newMsg.attachment_url  = res._uploadResult.attachment_url || '';
          newMsg.attachment_name = res._uploadResult.attachment_name || '';
          newMsg.attachment_type = res._uploadResult.attachment_type || '';
          newMsg.attachment_size = res._uploadResult.attachment_size || 0;
        }

        state.messages.push(newMsg);
        if (msgId > state.lastMessageId) state.lastMessageId = msgId;

        var messagesEl = document.getElementById('bd-ichat-messages');
        if (messagesEl) {
          if (messagesEl.querySelector('.bd-empty')) messagesEl.innerHTML = '';
          messagesEl.insertAdjacentHTML('beforeend', renderOneMessage(newMsg));
          scrollToBottom();
        }
      }

      loadThreads();
    }).catch(function (err) {
      toast('Failed to send: ' + err.message, 'error');
    }).then(function () {
      state.isPosting = false;
      if (sendBtn) sendBtn.disabled = false;
      var i2 = document.getElementById('bd-ichat-input');
      if (i2) i2.focus();
    });
  }

  // ============================================================
  // FILE UPLOAD
  // ============================================================
  function uploadFile(file) {
    var formData = new FormData();
    formData.append('action', 'bd_upload_media');
    formData.append('_wpnonce', config.internalUploadNonce || '');
    formData.append('file', file);

    return fetch(config.ajaxUrl, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    }).then(function (res) {
      return res.text().then(function (text) {
        var data = null;
        try { data = text ? JSON.parse(text) : null; }
        catch (e) { throw new Error('Server returned invalid response: ' + text.slice(0, 200)); }

        if (!data || !data.success) {
          var msg = (data && data.data && data.data.message) ? data.data.message : 'Upload failed';
          throw new Error(msg);
        }
        return data.data;
      });
    });
  }

  // ============================================================
  // STATUS CHANGE
  // ============================================================
  function setThreadStatus(threadId, status) {
    api('internal/threads/' + threadId + '/status', {
      method: 'POST',
      body: { status: status }
    }).then(function () {
      toast('Thread ' + status, 'success');
      loadThreads();
      openThread(threadId);
    }).catch(function (err) {
      toast('Failed: ' + err.message, 'error');
    });
  }

  // ============================================================
  // NEW THREAD MODAL (with attachment support)
  // ============================================================
  function openNewThreadModal() {
    var canPostUpdates = (state.level === 'team_lead' || state.level === 'admin');
    var defaultChannel = (state.channel === 'updates' && canPostUpdates) ? 'updates' : 'tickets';

    var modalAttachment = null;
    var modalPreviewUrl = null;

    var modal = document.createElement('div');
    modal.className = 'bd-ichat-modal-backdrop';
    modal.innerHTML =
      '<div class="bd-ichat-modal">' +
        '<h3>' + (defaultChannel === 'updates' ? 'New Company Update' : 'New Support Ticket') + '</h3>' +
        '<p class="hint">' +
          (defaultChannel === 'updates'
            ? 'Agents will see this update. They cannot reply.'
            : 'Team leads and admins will be able to reply.') +
        '</p>' +
        '<label>Title</label>' +
        '<input type="text" id="bd-ichat-modal-title" maxlength="200" ' +
          'placeholder="' + (defaultChannel === 'updates' ? 'e.g. New product launch' : 'e.g. Need help with XYZ') + '">' +
        '<label>Message</label>' +
        '<textarea id="bd-ichat-modal-message" placeholder="Write details..."></textarea>' +
        (defaultChannel === 'tickets' ? (
          '<label>Priority</label>' +
          '<select id="bd-ichat-modal-priority">' +
            '<option value="normal">Normal</option>' +
            '<option value="high">High</option>' +
            '<option value="urgent">Urgent</option>' +
          '</select>'
        ) : '') +
        '<label>Attachment (optional)</label>' +
        '<div id="bd-ichat-modal-attach-strip"></div>' +
        '<input type="file" id="bd-ichat-modal-file" hidden ' +
          'accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.zip">' +
        '<button type="button" class="bd-ichat-modal-attach-btn" id="bd-ichat-modal-attach-btn">' +
          '+ Attach a file (image, video, audio, PDF, doc)' +
        '</button>' +
        '<div class="bd-ichat-modal-actions">' +
          '<button type="button" class="is-cancel" id="bd-ichat-modal-cancel">Cancel</button>' +
          '<button type="button" class="is-primary" id="bd-ichat-modal-create">Create</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(modal);

    var cancelBtn = document.getElementById('bd-ichat-modal-cancel');
    var createBtn = document.getElementById('bd-ichat-modal-create');
    var attachBtn = document.getElementById('bd-ichat-modal-attach-btn');
    var fileInput = document.getElementById('bd-ichat-modal-file');
    var attachStrip = document.getElementById('bd-ichat-modal-attach-strip');

    function renderModalAttachStrip() {
      if (!attachStrip) return;

      if (!modalAttachment) {
        attachStrip.innerHTML = '';
        if (attachBtn) attachBtn.style.display = 'block';
        return;
      }

      if (attachBtn) attachBtn.style.display = 'none';

      var stateRef = { pendingPreviewUrl: modalPreviewUrl };
      attachStrip.innerHTML = buildAttachPreviewHtml(modalAttachment, 'bd-ichat-modal-remove-attach', stateRef);
      modalPreviewUrl = stateRef.pendingPreviewUrl;

      var removeBtn = document.getElementById('bd-ichat-modal-remove-attach');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          if (modalPreviewUrl) {
            try { URL.revokeObjectURL(modalPreviewUrl); } catch (e) {}
            modalPreviewUrl = null;
          }
          modalAttachment = null;
          renderModalAttachStrip();
        });
      }
    }

    function cleanupAndClose() {
      if (modalPreviewUrl) {
        try { URL.revokeObjectURL(modalPreviewUrl); } catch (e) {}
        modalPreviewUrl = null;
      }
      if (modal.parentNode) modal.parentNode.removeChild(modal);
    }

    if (attachBtn) {
      attachBtn.addEventListener('click', function () { fileInput.click(); });
    }
    if (fileInput) {
      fileInput.addEventListener('change', function (e) {
        var file = e.target.files && e.target.files[0];
        if (!file) return;

        if (modalPreviewUrl) {
          try { URL.revokeObjectURL(modalPreviewUrl); } catch (e) {}
          modalPreviewUrl = null;
        }
        modalAttachment = file;
        renderModalAttachStrip();
        e.target.value = '';
      });
    }

    cancelBtn.addEventListener('click', cleanupAndClose);
    modal.addEventListener('click', function (e) {
      if (e.target === modal) cleanupAndClose();
    });
    document.addEventListener('keydown', function escListener(e) {
      if (e.key === 'Escape') {
        cleanupAndClose();
        document.removeEventListener('keydown', escListener);
      }
    });

    createBtn.addEventListener('click', function () {
      var title = (document.getElementById('bd-ichat-modal-title').value || '').trim();
      var message = (document.getElementById('bd-ichat-modal-message').value || '').trim();
      var priorityEl = document.getElementById('bd-ichat-modal-priority');
      var priority = priorityEl ? priorityEl.value : 'normal';

      if (!title) {
        toast('Please enter a title', 'error');
        return;
      }

      createBtn.disabled = true;
      var originalLabel = createBtn.textContent;

      var uploadPromise = modalAttachment
        ? (function () {
            createBtn.textContent = 'Uploading file...';
            return uploadFile(modalAttachment);
          })()
        : Promise.resolve(null);

      uploadPromise.then(function (uploadResult) {
        createBtn.textContent = 'Creating...';
        var payload = {
          channel: defaultChannel,
          title: title,
          message: message,
          priority: priority
        };
        if (uploadResult) {
          payload.attachment_id   = uploadResult.attachment_id;
          payload.attachment_url  = uploadResult.attachment_url;
          payload.attachment_name = uploadResult.attachment_name;
          payload.attachment_type = uploadResult.attachment_type;
          payload.attachment_size = uploadResult.attachment_size;
        }
        return api('internal/threads', {
          method: 'POST',
          body: payload
        });
      }).then(function (res) {
        cleanupAndClose();
        toast('Created!', 'success');
        state.channel = defaultChannel;
        document.querySelectorAll('.bd-ichat-channel').forEach(function (t) {
          t.classList.toggle('is-active', t.getAttribute('data-channel') === defaultChannel);
        });
        updateNewButtonLabel();
        loadThreads();
        setTimeout(function () { openThread(res.thread_id); }, 300);
      }).catch(function (err) {
        toast('Failed: ' + err.message, 'error');
        createBtn.disabled = false;
        createBtn.textContent = originalLabel;
      });
    });
  }

  // ============================================================
  // POLLING
  // ============================================================
  function startThreadPoll() {
    stopThreadPoll();
    state.pollTimer = setInterval(pollThread, 4000);
  }

  function stopThreadPoll() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  function pollThread() {
    if (!state.activeThreadId) return;
    api('internal/threads/' + state.activeThreadId + '/poll?after=' + state.lastMessageId)
      .then(function (data) {
        var newMsgs = (data && data.messages) || [];
        if (!newMsgs.length) return;

        var messagesEl = document.getElementById('bd-ichat-messages');
        newMsgs.forEach(function (m) {
          var id = parseInt(m.id, 10);
          if (id > state.lastMessageId) state.lastMessageId = id;
          state.messages.push(m);
          if (messagesEl) {
            if (messagesEl.querySelector('.bd-empty')) messagesEl.innerHTML = '';
            messagesEl.insertAdjacentHTML('beforeend', renderOneMessage(m));
          }
        });
        scrollToBottom();
      }).catch(function () {});
  }

  function startUnreadPoll() {
    setInterval(refreshUnread, 15000);
  }

  function refreshUnread() {
    api('internal/unread').then(function (data) {
      state.unread = data;
      updateChannelBadges();
      updateNavBadge();
    }).catch(function () {});
  }

  function updateChannelBadges() {
    var u = document.getElementById('bd-ichat-count-updates');
    var t = document.getElementById('bd-ichat-count-tickets');
    if (u) {
      if (state.unread.updates > 0) {
        u.textContent = state.unread.updates;
        u.hidden = false;
      } else {
        u.hidden = true;
      }
    }
    if (t) {
      if (state.unread.tickets > 0) {
        t.textContent = state.unread.tickets;
        t.hidden = false;
      } else {
        t.hidden = true;
      }
    }
  }

  function updateNavBadge() {
    var badge = document.getElementById('bd-nav-internal-badge');
    if (!badge) return;
    if (state.unread.total > 0) {
      badge.textContent = state.unread.total;
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }
  }

  // ============================================================
  // HELPERS
  // ============================================================
  function renderThreadViewEmpty() {
    var view = document.getElementById('bd-ichat-thread-view');
    if (!view) return;
    view.innerHTML =
      '<div class="bd-ichat-empty">' +
        '<div class="bd-ichat-empty-icon">[ ]</div>' +
        '<h3>Select a thread</h3>' +
        '<p>Choose an update or ticket from the list.</p>' +
      '</div>';
  }

  function scrollToBottom() {
    var el = document.getElementById('bd-ichat-messages');
    if (!el) return;
    requestAnimationFrame(function () {
      el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    });
  }

  function esc(str) {
    return String(str == null ? '' : str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }
  function escAttr(s) { return esc(s); }

  function initials(name) {
    var parts = String(name || '?').trim().split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  function timeAgo(iso) {
    if (!iso) return '';
    var d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    var diff = Math.floor((Date.now() - d.getTime()) / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
    if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
    return Math.floor(diff / 86400) + 'd ago';
  }

  function formatSize(bytes) {
    bytes = parseInt(bytes, 10) || 0;
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function toast(msg, type) {
    var container = document.getElementById('bd-toasts');
    if (!container) return;
    var el = document.createElement('div');
    el.className = 'bd-toast' + (type ? ' is-' + type : '');
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity 0.25s, transform 0.25s';
      el.style.opacity = '0';
      el.style.transform = 'translateX(30px)';
      setTimeout(function () { el.remove(); }, 300);
    }, 3600);
  }
})();