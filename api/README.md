# Inventory ERP &mdash; External B2B Integration API Suite

## 1. Architectural Scope & Purpose

The `/api` directory houses the dedicated **B2B RESTful Integration Suite** of the Inventory Management ERP system. It is purposefully architected as an external API boundary designed for programmatic microservice-to-microservice communication across the distributed ERP ecosystem, connecting:
- **Procurement Team**: Automated Goods Receipt (GR) and Inbound Order creation.
- **Production Team**: Raw material requests, batch reservations, and finished goods handoffs.
- **Sales Team**: Sales delivery dispatches and outbound shipping confirmations.
- **Super Admin & Executive Dashboards**: Cross-facility stock balance reporting and audit logs.

### Why the Internal UI Uses Server-Side Rendering (SSR) Rather than AJAX to `/api`
The internal Inventory web portal (`views/`) employs a robust **Server-Side Rendered (SSR) MVC architecture** with HTTP POST transactions, session authentication, and CSRF token protection. This separation follows enterprise ERP best practices:
1. **Security Isolation**: Browser sessions utilize encrypted HTTP-only session cookies with strict CSRF tokens (`_csrf_token`), preventing cross-site request forgery. In contrast, the `/api` suite uses long-lived or scoped Bearer API tokens (`Authorization: Bearer <api_token>`), which should never be exposed in client-side browser JavaScript.
2. **Transactional Atomicity**: Web UI actions perform ACID database transactions with row-level locks (`FOR UPDATE`), immediate flash error messages, and synchronous validation without client-side network latency or decoupled race conditions.
3. **Audit Trail & Attribution**: UI actions bind directly to the authenticated session user, capturing IP address, user-agent, and warehouse facility context in `accountability_logs`.

---

## 2. Authentication & Authorization

All endpoints in `/api` (excluding public auth endpoints `/api/auth/login.php` and `/api/auth/password_reset_otp.php`) require Bearer token authentication in the HTTP header:

```http
Authorization: Bearer <your_api_token>
```

### Team-Based Role-Based Access Control (RBAC)
Every token is resolved to a `user_id`, `role`, and `team` (`Inventory`, `Procurement`, `Production`, `Sales`, `Admin`). Endpoints enforce strict department scoping:
- **Procurement**: Limited to `PURCHASE_ORDER` inbound receipts and `SUPPLIER_RETURN` outbound dispatches.
- **Production**: Limited to `PRODUCTION_RETURN` finished goods receipts and `MATERIAL_REQUEST` raw material dispatches.
- **Sales**: Limited to `SALES_DELIVERY` dispatches and customer return receipts.
- **Inventory & Super Admin**: Full cross-warehouse authority for receiving, dispatches, adjustments, transfers, and discard approvals.

---

## 3. Idempotency & Fault Tolerance

To prevent double-counting of inventory during network timeouts or client retries, state-changing endpoints (`POST`, `PUT`, `DELETE`) accept an optional but strongly recommended **Idempotency Key**:

```http
X-Idempotency-Key: <unique-uuid-v4>
```

When provided:
1. The API checks `api_idempotency_keys` for a matching key within the last 24 hours.
2. If the request was previously processed, the API instantly returns the cached response with `HTTP 200` without re-executing inventory debits or credits.
3. Concurrent duplicate requests are rejected with `HTTP 409 Conflict`.

---

## 4. API Endpoints Catalog

### 4.1 Authentication & Session
- `POST /api/auth/login.php` &mdash; Authenticate service account or user credentials, returns Bearer token and user profile.
- `GET  /api/auth/session.php` &mdash; Validate current token, retrieve permissions, assigned warehouse, and role.
- `POST /api/auth/logout.php` &mdash; Revoke session or invalidate token.
- `POST /api/auth/password_reset_otp.php` &mdash; Request or verify one-time password reset token (constant-time evaluation).

### 4.2 Document-Driven Order Queues
- `GET  /api/orders/inbound.php` &mdash; List pending inbound shipments awaiting warehouse receiving.
- `POST /api/orders/inbound.php` &mdash; Create a document-driven inbound order (e.g. by Procurement PO).
- `GET  /api/orders/outbound.php` &mdash; List pending outbound orders with live stock reservation status.
- `POST /api/orders/outbound.php` &mdash; Create an outbound delivery order or trigger warehouse dispatch.

### 4.3 Inventory Balances & Alerts
- `GET  /api/inventory/get.php` &mdash; Query real-time item stock balances across warehouses with filtering by classification, warehouse, and SKU.
- `GET  /api/inventory/finished_goods.php` &mdash; Query salable finished goods stock, batch numbers, and production dates.
- `GET  /api/inventory/alerts.php` &mdash; Low-stock thresholds, out-of-stock items, and reorder alerts.
- `GET  /api/inventory/notifications_poll.php` &mdash; Long-polling / real-time notification feed for stock events.

### 4.4 Inbound Stock Movements (Physical Receiving)
- `GET  /api/stock_in/get.php` &mdash; Fetch completed Stock In transactions, line items, and QC inspection metadata.
- `POST /api/stock_in/create.php` &mdash; Receive goods into warehouse, increment `stock.qty_on_hand`, log movement ledger.
- `POST /api/stock_in/cancel.php` &mdash; Reverse an inbound receipt and deduct received stock under ACID transaction.

### 4.5 Outbound Stock Movements (Physical Dispatch)
- `GET  /api/stock_out/get.php` &mdash; Fetch completed Stock Out transactions, destination references, and tracking numbers.
- `POST /api/stock_out/create.php` &mdash; Dispatch goods from warehouse, deduct `stock.qty_on_hand`, release reservations.
- `POST /api/stock_out/cancel.php` &mdash; Reverse an outbound dispatch and restore stock under ACID transaction.

### 4.6 Inter-Warehouse Transfers
- `GET  /api/stock_transfer/get.php` &mdash; Query transfer manifests, transit statuses (`pending`, `in_transit`, `received`, `cancelled`).
- `POST /api/stock_transfer/create.php` &mdash; Dispatch stock transfer from source warehouse (moves stock into in-transit state).
- `POST /api/stock_transfer/receive.php` &mdash; Receive in-transit stock into destination warehouse facility.
- `POST /api/stock_transfer/cancel.php` &mdash; Cancel pending transfer and restore inventory to originating warehouse.

### 4.7 Stock Adjustments (Cycle Counts & Audits)
- `GET  /api/stock_adjustment/get.php` &mdash; Fetch reconciliation records, discrepancy quantities, and audit history.
- `POST /api/stock_adjustment/create.php` &mdash; Submit inventory physical count variance for managerial review.
- `POST /api/stock_adjustment/approve.php` &mdash; Managerial approval/rejection executing ledger delta adjustments.

### 4.8 Bad Products & Damaged Items Discard
- `GET  /api/bad_products/get.php` &mdash; Query damaged/quarantined products awaiting inspection or disposal.
- `POST /api/bad_products/create.php` &mdash; Quarantine damaged items from warehouse stock into inspection queue.
- `POST /api/bad_products/confirm.php` &mdash; Confirm damage assessment and QA inspection results.
- `POST /api/bad_products/dispose.php` &mdash; Authorize and record physical destruction/discard of non-salable items.
- `POST /api/bad_products/cancel.php` &mdash; Release quarantined item back to available stock if inspection passes.

### 4.9 User Settings & Preferences
- `GET  /api/settings/preferences.php` &mdash; Retrieve user-specific UI preferences and notification settings.
- `POST /api/settings/preferences.php` &mdash; Update notification channels, default warehouse view, and display preferences.

---

## 5. Standard Response Format

All responses return standard JSON with RFC-compliant HTTP status codes:

### Success Response (`HTTP 200` / `HTTP 201`)
```json
{
  "success": true,
  "message": "Outbound order dispatched successfully.",
  "data": {
    "transaction_number": "OUT-000042",
    "order_number": "SO-2026-0089",
    "warehouse_id": 1,
    "items_dispatched": 2
  }
}
```

### Error Response (`HTTP 400`, `HTTP 401`, `HTTP 403`, `HTTP 404`, `HTTP 422`)
```json
{
  "success": false,
  "error": "Insufficient available inventory to fulfill requested quantity.",
  "code": "ERR_INSUFFICIENT_STOCK",
  "details": {
    "item_id": 14,
    "requested_quantity": 50,
    "available_quantity": 28
  }
}
```
