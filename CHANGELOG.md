## 1.1.0 - Client app backend + operations

- NEW: client-app REST API (`bigdrop/v1`): phone + OTP sign-in with short-lived access tokens and rotating refresh tokens, bootstrap, profile/KYC, water orders (Water Bank, cash, wallet, mobile money), cancellations with fee, wallet and Water Bank append-only ledgers, payments with idempotent settlement, fault reports with resumable photo/video upload, relocation, kit return, feedback, rewards/referrals, updates, activity trail, support chat linked to the client account.
- NEW: shop and baskets read the same WooCommerce catalogue as the website; buying a basket creates a real WooCommerce order.
- NEW: portal screens under "Client app": App Dashboard, Water Orders, Fault Reports, App Clients (activation queue), App Control (updates, prices, zones, delivery agents, relocations, feedback, payments).
- NEW: wp-admin "Big Drop App" settings page (radius, fees, payments mode, SMS provider, messages).
- Database: additive migration (extends `bd_clients`, adds `bd_orders`, `bd_wallet_tx`, `bd_bank_tx`, `bd_payments`, `bd_tickets`, `bd_baskets`, ...). Runs automatically on upload.
- Existing agent/chat features are unchanged.

## 1.0.1
- Fixed: Reassign/Reopen modal was nested inside the hidden Performance view, so it opened invisibly from the Chatroom. Moved to the app root.

/* Admin-only styles for Big Drop settings pages */
.bd-admin-wrap .bd-card {
    background: #fff;
    border-radius: 20px;
    padding: 24px;
    box-shadow: 0 6px 24px rgba(31, 13, 94, 0.06);
    max-width: 900px;
    margin-top: 16px;
}
.bd-admin-wrap h1 {
    color: #1F0D5E;
    font-weight: 800;
}
.bd-admin-wrap .form-table th {
    color: #1F0D5E;
    font-weight: 700;
}