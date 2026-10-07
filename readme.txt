=== Big Drop Agent Portal ===
Contributors: eazylabz
Tags: live chat, agent portal, pwa, notifications, support
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Complete agent portal with live chat, real-time notifications, PWA support, and admin management.

== Description ==

Big Drop Agent Portal is a complete, self-contained customer support chat system for WordPress.

It provides a modern agent dashboard, real-time visitor chat, canned replies, client account lookup, an internal team chat, browser push notifications, and full PWA (Progressive Web App) support — all without any external services or paid APIs.

= Zero external dependencies =

* No Node.js required
* No Redis required
* No Pusher/Ably account needed
* Works on Hostinger Business shared hosting
* Uses AJAX long-polling for real-time updates

= Features =

**Agent Dashboard**
* Live stats: daily chats, resolved, unresolved, response time
* Weekly activity chart
* Live agent roster with online/taking/offline states
* Beautiful gradient stat cards

**Chatroom**
* Three tabs: New / Unresolved / Resolved
* Automatic chat claim on reply
* Visitor info panel
* Typing and read indicators
* Priority escalation for chats waiting 3+ minutes
* Sound alerts on new messages

**Canned Replies**
* Header (internal only) + message body
* Optional attachment URL
* Insert with one click from chat
* Admin-managed

**Client Accounts**
* Search by name, account number, or phone
* Displays KYC, plan, payment status
* Data synced from your existing client system (via CSV import or REST API)

**Add an Agent**
* Create new WP users with the "Big Drop Agent" or "Big Drop Team Lead" role
* Assign username, email, password directly from the portal

**Notifications**
* In-app notification bell with unread badge
* Browser Web Push (works when tab is closed)
* Service worker for offline caching
* Auto-permission prompt on first login

**PWA Support**
* Installable on desktop and mobile
* Home screen icon
* Standalone window mode
* Offline fallback page

**Internal Team Chat**
* Agent-to-agent messages
* @mentions
* Timestamped log

**Security**
* Uses native WordPress authentication (no custom login)
* All REST endpoints nonce-protected
* Role-based access (bd_agent, bd_team_lead, administrator)
* Sanitized inputs, escaped outputs, prepared SQL

== Installation ==

1. Upload `bigdrop-agent-portal.zip` via **Plugins → Add New → Upload Plugin**.
2. Click **Activate**.
3. The plugin creates:
   * A new page "Agent Portal" with the `[bigdrop_portal]` shortcode.
   * 8 database tables prefixed with `wp_bd_`.
   * The `bd_agent` and `bd_team_lead` user roles.
   * VAPID keys for Web Push.
4. Go to **Big Drop → Settings** to configure.
5. Create agents via **Big Drop → Add Agent** or assign them the `bd_agent` role in WP Users.

== Frequently Asked Questions ==

= Does it work on shared hosting? =

Yes. It was specifically designed for Hostinger Business shared hosting with no Node.js, Redis, or external services required.

= Do I need HTTPS? =

Yes. HTTPS is required for PWA installation and Web Push notifications. Hostinger provides free SSL via Let's Encrypt.

= How real-time is it? =

The chat uses AJAX polling every 2 seconds while a chat is open. Notification polling runs every 8 seconds. This is reliable on shared hosting and typically delivers messages in under 2 seconds.

= Can I use it as a visitor chat widget on the frontend? =

Yes. Add `[bigdrop_widget]` to any page, or enable the floating widget from Settings.

= Are notifications really push (even when the browser is closed)? =

Yes, on Chrome, Edge, Firefox, and Safari 16.4+ (iOS requires installing the app to the home screen). VAPID keys are generated automatically on activation.

= Where is client data stored? =

In the `wp_bd_clients` table. You can import data via CSV (Big Drop → Settings → Import Clients) or push data via the REST endpoint `POST /wp-json/bigdrop/v1/clients`.

== Screenshots ==

1. Agent Dashboard with live stats.
2. Chatroom with sidebar and message thread.
3. Pre-Written Reply manager.
4. Client account lookup.
5. Add Agent form.
6. PWA install prompt on mobile.

== Changelog ==

= 1.0.0 =
* Initial release.
* Agent dashboard, chatroom, canned replies, client lookup, add-agent, notifications, PWA.

== Upgrade Notice ==

= 1.0.0 =
First release.