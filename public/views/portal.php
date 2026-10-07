<?php
/**
 * Main portal view — the SPA mount point.
 * Renders the full Big Drop Agent Portal interface.
 *
 * @package BigDrop
 */

defined( 'ABSPATH' ) || exit;

if ( ! BD_Roles::is_agent() ) {
    return;
}

$current_user = wp_get_current_user();
$agent_name   = BD_Roles::current_agent_name();
$agent_initials = BD_Roles::current_agent_initials();
$role_label   = current_user_can( 'manage_options' )
    ? __( 'Administrator', 'bigdrop' )
    : ( current_user_can( 'bd_manage_canned' ) ? __( 'Team Lead', 'bigdrop' ) : __( 'Agent', 'bigdrop' ) );
$is_admin     = BD_Roles::is_admin();
$can_view_clients = BD_Roles::can_view_clients();
?>

<div id="bigdrop-app" class="bd-app" data-view="dashboard">

    <!-- ============ SIDEBAR ============ -->
    <aside class="bd-sidebar" id="bd-sidebar">
        <div class="bd-sidebar-logo">
            <span class="bd-logo-mark">💧</span>
            <span class="bd-logo-text">Big <span>Drop</span></span>
        </div>

        <button type="button" class="bd-sidebar-toggle" id="bd-sidebar-toggle" aria-label="<?php esc_attr_e( 'Toggle sidebar', 'bigdrop' ); ?>">
            <span class="bd-toggle-icon">◀</span>
        </button>

        <nav class="bd-nav" aria-label="<?php esc_attr_e( 'Main navigation', 'bigdrop' ); ?>">
            <a class="bd-nav-item is-active" data-view="dashboard" href="#dashboard">
                <span class="bd-nav-icon">▦</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Dashboard', 'bigdrop' ); ?></span>
            </a>
            <a class="bd-nav-item" data-view="chatroom" href="#chatroom">
                <span class="bd-nav-icon">💬</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Chatroom', 'bigdrop' ); ?></span>
                <span class="bd-nav-badge" id="bd-nav-chat-badge" hidden>0</span>
            </a>
            <a class="bd-nav-item" data-view="internal" href="#internal">
                <span class="bd-nav-icon">📨</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Team Chat', 'bigdrop' ); ?></span>
                <span class="bd-nav-badge" id="bd-nav-internal-badge" hidden>0</span>
            </a>
            <a class="bd-nav-item" data-view="performance" href="#performance">
                <span class="bd-nav-icon">📈</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Agent Performance', 'bigdrop' ); ?></span>
            </a>
            <?php if ( $can_view_clients ) : ?>
            <a class="bd-nav-item" data-view="clients" href="#clients">
                <span class="bd-nav-icon">👤</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Client Accounts', 'bigdrop' ); ?></span>
            </a>
            <?php endif; ?>

            <?php if ( $is_admin ) : ?>
            <div class="bd-nav-divider"></div>
            <div class="bd-nav-group-label"><?php esc_html_e( 'Admin only', 'bigdrop' ); ?></div>
            <a class="bd-nav-item" data-view="canned" href="#canned">
                <span class="bd-nav-icon">✎</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Pre-Reply Messages', 'bigdrop' ); ?></span>
            </a>
            <a class="bd-nav-item" data-view="agents" href="#agents">
                <span class="bd-nav-icon">＋</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Add an Agent', 'bigdrop' ); ?></span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="bd-sidebar-footer">
            <a class="bd-nav-item bd-nav-logout" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">
                <span class="bd-nav-icon">↪</span>
                <span class="bd-nav-label"><?php esc_html_e( 'Log out', 'bigdrop' ); ?></span>
            </a>
        </div>

        <div class="bd-side-profile" title="<?php echo esc_attr( $agent_name ); ?>">
            <div class="bd-avatar"><?php echo esc_html( $agent_initials ); ?><span class="bd-status-dot is-online"></span></div>
            <div class="bd-side-profile-info">
                <div class="bd-side-profile-name"><?php echo esc_html( $agent_name ); ?></div>
                <div class="bd-side-profile-role"><?php echo esc_html( $role_label ); ?> — <?php esc_html_e( 'Online', 'bigdrop' ); ?></div>
            </div>
        </div>
    </aside>

    <!-- ============ MAIN ============ -->
    <div class="bd-main">

        <!-- Topbar -->
        <header class="bd-topbar">
            <div class="bd-topbar-left">
                <h1 class="bd-topbar-title" id="bd-topbar-title"><?php esc_html_e( 'Dashboard', 'bigdrop' ); ?></h1>
                <div class="bd-search">
                    <input type="search" id="bd-global-search" placeholder="<?php esc_attr_e( 'Search chats, clients…', 'bigdrop' ); ?>" autocomplete="off">
                </div>
            </div>
            <div class="bd-topbar-right">
                <button type="button" class="bd-theme-toggle" id="bd-theme-toggle" aria-label="Toggle Dark Mode">
                    <span class="bd-theme-icon-light">☀️</span>
                    <span class="bd-theme-icon-dark">🌙</span>
                </button>
                <button type="button" class="bd-icon-btn" id="bd-notif-btn" aria-label="<?php esc_attr_e( 'Notifications', 'bigdrop' ); ?>">
                    <span class="bd-bell">🔔</span>
                    <span class="bd-badge" id="bd-notif-badge" hidden>0</span>
                </button>
                <button type="button" class="bd-icon-btn" id="bd-sound-toggle" aria-label="<?php esc_attr_e( 'Toggle sound', 'bigdrop' ); ?>" title="<?php esc_attr_e( 'Sound alerts', 'bigdrop' ); ?>">
                    <span>🔊</span>
                </button>
                <span class="bd-status-pill"><span class="bd-status-dot is-online"></span><?php esc_html_e( 'Online', 'bigdrop' ); ?></span>
            </div>

            <!-- Notification dropdown -->
            <div class="bd-notif-panel" id="bd-notif-panel" hidden>
                <div class="bd-notif-panel-head">
                    <strong><?php esc_html_e( 'Notifications', 'bigdrop' ); ?></strong>
                    <button type="button" class="bd-link-btn" id="bd-notif-mark-all"><?php esc_html_e( 'Mark all read', 'bigdrop' ); ?></button>
                </div>
                <div class="bd-notif-list" id="bd-notif-list">
                    <div class="bd-notif-empty"><?php esc_html_e( 'No notifications.', 'bigdrop' ); ?></div>
                </div>
            </div>
        </header>

        <!-- ============ VIEW: DASHBOARD ============ -->
        <section class="bd-view is-active" id="bd-view-dashboard">
            <div class="bd-welcome">
                <div class="bd-welcome-text">
                    <h2><?php printf( esc_html__( 'Hi %s, welcome back!', 'bigdrop' ), esc_html( $agent_name ) ); ?></h2>
                    <p id="bd-welcome-sub"><?php esc_html_e( 'Loading your queue…', 'bigdrop' ); ?></p>
                    <div class="bd-welcome-actions">
                        <button type="button" class="bd-btn" data-goto="chatroom"><?php esc_html_e( 'Open Chatroom', 'bigdrop' ); ?></button>
                        <button type="button" class="bd-btn bd-btn-ghost" data-goto="performance"><?php esc_html_e( 'View my stats', 'bigdrop' ); ?></button>
                    </div>
                </div>
                <div class="bd-welcome-droplet"></div>
            </div>

            <div class="bd-stats">
                <div class="bd-stat bd-stat-c1">
                    <span class="bd-stat-trend" id="bd-stat-today-trend">—</span>
                    <div class="bd-stat-icon">💬</div>
                    <div class="bd-stat-num" id="bd-stat-today">0</div>
                    <div class="bd-stat-label"><?php esc_html_e( 'Incoming Chats Today', 'bigdrop' ); ?></div>
                </div>
                <div class="bd-stat bd-stat-c2">
                    <div class="bd-stat-icon">👁</div>
                    <div class="bd-stat-num" id="bd-stat-waiting">0</div>
                    <div class="bd-stat-label"><?php esc_html_e( 'Waiting in Queue', 'bigdrop' ); ?></div>
                </div>
                <div class="bd-stat bd-stat-c3">
                    <div class="bd-stat-icon">⚡</div>
                    <div class="bd-stat-num" id="bd-stat-response">—</div>
                    <div class="bd-stat-label"><?php esc_html_e( 'Avg Response Time', 'bigdrop' ); ?></div>
                </div>
                <div class="bd-stat bd-stat-c4">
                    <span class="bd-stat-trend" id="bd-stat-rate-trend">—</span>
                    <div class="bd-stat-icon">✔</div>
                    <div class="bd-stat-num" id="bd-stat-resolution">0%</div>
                    <div class="bd-stat-label"><?php esc_html_e( 'Resolution Rate', 'bigdrop' ); ?></div>
                </div>
            </div>

            <div class="bd-mini-stats">
                <div class="bd-mini">
                    <div class="bd-mini-icon bd-mini-a">Σ</div>
                    <div><div class="bd-mini-num" id="bd-mini-all-time">0</div><div class="bd-mini-label"><?php esc_html_e( 'All-Time Chats', 'bigdrop' ); ?></div></div>
                </div>
                <div class="bd-mini">
                    <div class="bd-mini-icon bd-mini-b">✔</div>
                    <div><div class="bd-mini-num" id="bd-mini-resolved">0</div><div class="bd-mini-label"><?php esc_html_e( 'All-Time Resolved', 'bigdrop' ); ?></div></div>
                </div>
                <div class="bd-mini">
                    <div class="bd-mini-icon bd-mini-a">!</div>
                    <div><div class="bd-mini-num" id="bd-mini-unresolved">0</div><div class="bd-mini-label"><?php esc_html_e( 'All-Time Unresolved', 'bigdrop' ); ?></div></div>
                </div>
                <div class="bd-mini">
                    <div class="bd-mini-icon bd-mini-b">🌐</div>
                    <div><div class="bd-mini-num" id="bd-mini-visitors">0</div><div class="bd-mini-label"><?php esc_html_e( 'All-Time Visitors', 'bigdrop' ); ?></div></div>
                </div>
            </div>

            <div class="bd-grid2">
                <div class="bd-panel">
                    <div class="bd-panel-head">
                        <h3><?php esc_html_e( 'Chats This Week', 'bigdrop' ); ?></h3>
                    </div>
                    <svg class="bd-chart" viewBox="0 0 520 180" width="100%" height="180" preserveAspectRatio="none" id="bd-chart-week">
                        <defs>
                            <linearGradient id="bdGrad1" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#7FD344" stop-opacity=".35"/>
                                <stop offset="100%" stop-color="#7FD344" stop-opacity="0"/>
                            </linearGradient>
                        </defs>
                        <g id="bd-chart-lines"></g>
                    </svg>
                    <div class="bd-chart-labels" id="bd-chart-labels"></div>
                </div>

                <div class="bd-panel">
                    <div class="bd-panel-head">
                        <h3><?php esc_html_e( 'Resolution Rate', 'bigdrop' ); ?></h3>
                    </div>
                    <div class="bd-donut" id="bd-donut">
                        <div class="bd-donut-inner">
                            <b id="bd-donut-value">0%</b>
                            <span><?php esc_html_e( 'Resolved', 'bigdrop' ); ?></span>
                        </div>
                    </div>
                    <div class="bd-legend" id="bd-legend">
                        <div class="bd-legend-row">
                            <span><span class="bd-swatch" style="background:#7FD344"></span><?php esc_html_e( 'Resolved', 'bigdrop' ); ?></span>
                            <span id="bd-legend-resolved">0%</span>
                        </div>
                        <div class="bd-legend-row">
                            <span><span class="bd-swatch" style="background:#4E7CDD"></span><?php esc_html_e( 'Pending', 'bigdrop' ); ?></span>
                            <span id="bd-legend-pending">0%</span>
                        </div>
                        <div class="bd-legend-row">
                            <span><span class="bd-swatch" style="background:#E9EBF2"></span><?php esc_html_e( 'Unresolved', 'bigdrop' ); ?></span>
                            <span id="bd-legend-unresolved">0%</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bd-panel">
                <div class="bd-panel-head">
                    <h3><?php esc_html_e( 'Agent Roster', 'bigdrop' ); ?></h3>
                </div>
                <div id="bd-roster" class="bd-roster">
                    <div class="bd-empty"><?php esc_html_e( 'Loading…', 'bigdrop' ); ?></div>
                </div>
            </div>
        </section>

        <!-- ============ VIEW: CHATROOM ============ -->
        <section class="bd-view" id="bd-view-chatroom">
            <div class="bd-chat-layout">
                <div class="bd-conv-list">
                    <div class="bd-conv-tabs">
                        <span class="bd-conv-tab is-active" data-tab="new">
                            <?php esc_html_e( 'New', 'bigdrop' ); ?>
                            <span class="bd-tab-count" id="bd-tab-count-new" hidden>0</span>
                        </span>
                        <span class="bd-conv-tab" data-tab="active">
                            <?php esc_html_e( 'Active', 'bigdrop' ); ?>
                            <span class="bd-tab-count" id="bd-tab-count-active" hidden>0</span>
                        </span>
                        <span class="bd-conv-tab" data-tab="resolved">
                            <?php esc_html_e( 'Resolved', 'bigdrop' ); ?>
                            <span class="bd-tab-count" id="bd-tab-count-resolved" hidden>0</span>
                        </span>
                        <span class="bd-conv-tab" data-tab="unresolved">
                            <?php esc_html_e( 'Unresolved', 'bigdrop' ); ?>
                            <span class="bd-tab-count" id="bd-tab-count-unresolved" hidden>0</span>
                        </span>
                    </div>
                    <div class="bd-conv-scroll" id="bd-conv-list">
                        <div class="bd-empty"><?php esc_html_e( 'Loading chats…', 'bigdrop' ); ?></div>
                    </div>
                </div>

                <div class="bd-thread" id="bd-thread">
                    <div class="bd-thread-empty">
                        <div class="bd-thread-empty-icon">💬</div>
                        <h3><?php esc_html_e( 'Select a chat', 'bigdrop' ); ?></h3>
                        <p><?php esc_html_e( 'Choose a conversation from the list to begin.', 'bigdrop' ); ?></p>
                    </div>
                </div>
            </div>
        </section>

        <!-- ============ VIEW: PERFORMANCE ============ -->
        <section class="bd-view" id="bd-view-performance">

            <!-- Summary pills -->
            <div class="bd-perf-summary" id="bd-perf-summary">
                <div class="bd-perf-summary-item">
                    <div class="bd-perf-summary-label">Chats Taken</div>
                    <div class="bd-perf-summary-value" id="bd-sum-chats">0</div>
                </div>
                <div class="bd-perf-summary-item is-success">
                    <div class="bd-perf-summary-label">Resolved</div>
                    <div class="bd-perf-summary-value" id="bd-sum-resolved">0</div>
                </div>
                <div class="bd-perf-summary-item is-warn">
                    <div class="bd-perf-summary-label">Unresolved</div>
                    <div class="bd-perf-summary-value" id="bd-sum-unresolved">0</div>
                </div>
                <div class="bd-perf-summary-item is-fast">
                    <div class="bd-perf-summary-label">Resolved ≤3min</div>
                    <div class="bd-perf-summary-value" id="bd-sum-fast">0</div>
                </div>
                <div class="bd-perf-summary-item is-time">
                    <div class="bd-perf-summary-label">Avg Response</div>
                    <div class="bd-perf-summary-value" id="bd-sum-response">—</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bd-perf-filters-wrap">
                <div class="bd-perf-filters-presets">
                    <button type="button" class="bd-preset" data-range="today">Today</button>
                    <button type="button" class="bd-preset is-active" data-range="7d">Last 7 days</button>
                    <button type="button" class="bd-preset" data-range="30d">Last 30 days</button>
                    <button type="button" class="bd-preset" data-range="month">This month</button>
                </div>
                <div class="bd-perf-filters-row">
                    <label>
                        From
                        <input type="date" id="bd-perf-from">
                    </label>
                    <label>
                        To
                        <input type="date" id="bd-perf-to">
                    </label>
                    <label id="bd-perf-agent-wrap" hidden>
                        Agent
                        <select id="bd-perf-agent">
                            <option value="">All agents</option>
                        </select>
                    </label>
                    <button type="button" class="bd-btn" id="bd-perf-apply">Apply</button>
                    <button type="button" class="bd-btn bd-btn-ghost-dark" id="bd-perf-export" hidden>Export CSV</button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="bd-perf-tabs">
                <button type="button" class="bd-perf-tab is-active" data-tab="performance">Performance</button>
                <button type="button" class="bd-perf-tab" data-tab="history">Chat History</button>
            </div>

            <!-- Tab: Performance Table -->
            <div class="bd-perf-tab-panel is-active" id="bd-perf-panel-performance">
                <div class="bd-panel">
                    <div class="bd-table-wrap">
                        <table class="bd-table" id="bd-perf-table">
                            <thead>
                                <tr>
                                    <th>Agent</th>
                                    <th>Chats Taken</th>
                                    <th>Resolved</th>
                                    <th>Unresolved</th>
                                    <th>Resolved ≤3min</th>
                                    <th>Avg Response</th>
                                    <th>Resolution Rate</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="bd-perf-body">
                                <tr><td colspan="8" class="bd-empty">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Tab: Chat History -->
            <div class="bd-perf-tab-panel" id="bd-perf-panel-history">
                <div class="bd-panel">
                    <div class="bd-hist-filters">
                        <input type="search" id="bd-hist-search" placeholder="Search visitor name, phone, or email">
                        <select id="bd-hist-status">
                            <option value="all">All statuses</option>
                            <option value="resolved">Resolved</option>
                            <option value="unresolved">Unresolved</option>
                            <option value="new">New</option>
                            <option value="active">Active</option>
                        </select>
                        <button type="button" class="bd-btn bd-btn-navy" id="bd-hist-search-btn">Search</button>
                    </div>

                    <div class="bd-table-wrap">
                        <table class="bd-table bd-hist-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Visitor</th>
                                    <th>Agent</th>
                                    <th>Status</th>
                                    <th>Msgs</th>
                                    <th>Resolution</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="bd-hist-body">
                                <tr><td colspan="7" class="bd-empty">Loading…</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bd-perf-pagination" id="bd-hist-pagination"></div>
                </div>
            </div>

            <!-- Reassign Modal -->
            <div class="bd-reassign-backdrop" id="bd-reassign-modal" hidden>
                <div class="bd-reassign-modal">
                    <h3>Reassign Chat</h3>
                    <p class="bd-reassign-info" id="bd-reassign-info"></p>
                    <label>Assign to</label>
                    <select id="bd-reassign-select"></select>
                    <div class="bd-reassign-actions">
                        <button type="button" class="bd-btn bd-btn-ghost-dark" id="bd-reassign-cancel">Cancel</button>
                        <button type="button" class="bd-btn" id="bd-reassign-confirm">Reassign</button>
                    </div>
                </div>
            </div>

        </section>
        
        <!-- ============ VIEW: TEAM CHAT ============ -->
        <section class="bd-view" id="bd-view-internal">
            <div id="bd-internal-chat"></div>
        </section>

        <!-- ============ VIEW: CLIENTS ============ -->
        <?php if ( $can_view_clients ) : ?>
        <section class="bd-view" id="bd-view-clients">
            <div class="bd-panel">
                <div class="bd-client-search">
                    <input type="search" id="bd-client-q" placeholder="<?php esc_attr_e( 'Search by name, account number, or phone', 'bigdrop' ); ?>">
                    <button type="button" class="bd-btn" id="bd-client-search-btn"><?php esc_html_e( 'Search', 'bigdrop' ); ?></button>
                </div>
                <div id="bd-client-result">
                    <div class="bd-empty"><?php esc_html_e( 'Enter a search term to find a client.', 'bigdrop' ); ?></div>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ============ VIEW: CANNED REPLIES ============ -->
        <?php if ( $is_admin ) : ?>
        <section class="bd-view" id="bd-view-canned">
            <div class="bd-panel">
                <h3><?php esc_html_e( 'Add a Pre-Written Reply', 'bigdrop' ); ?></h3>
                <form id="bd-canned-form" class="bd-form">
                    <div class="bd-form-grid">
                        <input type="text" id="bd-canned-header" placeholder="<?php esc_attr_e( 'Header (internal only, not shown to client)', 'bigdrop' ); ?>" required>
                        <input type="url" id="bd-canned-attachment" placeholder="<?php esc_attr_e( 'Attachment URL (optional)', 'bigdrop' ); ?>">
                    </div>
                    <textarea id="bd-canned-message" rows="3" placeholder="<?php esc_attr_e( 'Reply message…', 'bigdrop' ); ?>" required></textarea>
                    <button type="submit" class="bd-btn bd-btn-navy"><?php esc_html_e( 'Save Reply', 'bigdrop' ); ?></button>
                    <span id="bd-canned-status" class="bd-inline-status"></span>
                </form>
            </div>
            <div class="bd-panel">
                <h3><?php esc_html_e( 'Current Pre-Written Replies', 'bigdrop' ); ?></h3>
                <div id="bd-canned-list"><div class="bd-empty"><?php esc_html_e( 'Loading…', 'bigdrop' ); ?></div></div>
            </div>
        </section>
        <?php endif; ?>

        <!-- ============ VIEW: AGENTS ============ -->
        <?php if ( $is_admin ) : ?>
        <section class="bd-view" id="bd-view-agents">
            <div class="bd-panel">
                <h3><?php esc_html_e( 'Add a New Agent', 'bigdrop' ); ?></h3>
                <form id="bd-agent-form" class="bd-form">
                    <div class="bd-form-grid">
                        <input type="text" id="bd-agent-name" placeholder="<?php esc_attr_e( 'Full Name', 'bigdrop' ); ?>" required>
                        <input type="text" id="bd-agent-surname" placeholder="<?php esc_attr_e( 'Surname', 'bigdrop' ); ?>" required>
                        <select id="bd-agent-position">
                            <option value="bd_agent"><?php esc_html_e( 'Agent', 'bigdrop' ); ?></option>
                            <option value="bd_team_lead"><?php esc_html_e( 'Team Lead', 'bigdrop' ); ?></option>
                        </select>
                        <input type="text" id="bd-agent-username" placeholder="<?php esc_attr_e( 'Login Username', 'bigdrop' ); ?>" required>
                        <input type="email" id="bd-agent-email" placeholder="<?php esc_attr_e( 'Email', 'bigdrop' ); ?>" required>
                        <input type="password" id="bd-agent-password" placeholder="<?php esc_attr_e( 'Passcode (min 6 chars)', 'bigdrop' ); ?>" minlength="6" required>
                    </div>
                    <button type="submit" class="bd-btn bd-btn-navy"><?php esc_html_e( 'Save Agent', 'bigdrop' ); ?></button>
                    <span id="bd-agent-status" class="bd-inline-status"></span>
                </form>
            </div>
            <div class="bd-panel">
                <h3><?php esc_html_e( 'Current System Users', 'bigdrop' ); ?></h3>
                <div id="bd-agent-list"><div class="bd-empty"><?php esc_html_e( 'Loading…', 'bigdrop' ); ?></div></div>
            </div>
        </section>
        <?php endif; ?>

    </div><!-- .bd-main -->

    <!-- Toast container -->
    <div class="bd-toasts" id="bd-toasts" aria-live="polite"></div>

    <!-- Push permission prompt -->
    <div class="bd-push-prompt" id="bd-push-prompt" hidden>
        <div class="bd-push-prompt-content">
            <div class="bd-push-prompt-icon">🔔</div>
            <div>
                <strong><?php esc_html_e( 'Enable desktop notifications', 'bigdrop' ); ?></strong>
                <p><?php esc_html_e( 'Get alerted the moment a new chat arrives, even when this tab is in the background.', 'bigdrop' ); ?></p>
            </div>
            <div class="bd-push-prompt-actions">
                <button type="button" class="bd-btn" id="bd-push-enable"><?php esc_html_e( 'Enable', 'bigdrop' ); ?></button>
                <button type="button" class="bd-btn bd-btn-ghost" id="bd-push-dismiss"><?php esc_html_e( 'Not now', 'bigdrop' ); ?></button>
            </div>
        </div>
    </div>

    <!-- Hidden audio element -->
    <audio id="bd-ping" preload="auto">
        <source src="<?php echo esc_url( BD_URL . 'assets/sounds/ping.mp3' ); ?>" type="audio/mpeg">
    </audio>
</div>