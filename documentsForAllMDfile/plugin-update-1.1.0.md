# Plugin update 1.1.0 - 2026-11-22

The runtime update is prepared locally; it has not been deployed to WordPress by this workspace.

## Installation

Keep the v2 PHP conditional checkout snippet active and older checkout copies disabled. Update the Aristocrats Hotel Core plugin from the release ZIP, ensuring only one core copy is active. Keep WooCommerce, payment gateways and the independent revisions-limit snippet active. Preserve the database and existing configuration; do not run the old uninstall routine during a folder migration.

After replacing files, clear the site/minification/CDN cache, confirm the configured Company Code with Test API Connection, and test food checkout plus a room booking. In Hotel Reservation Details, inspect the exact ERP reference, room, guests, requests and sync state.

## Runtime changes

- Shared date/quote/capacity validation for search, cart, checkout and final synchronization; deterministic price selection and exact physical room verification.
- API-configured check-in/out times in requests and emails; complete room ID aliases.
- Supported JSON request fields only; safe single-attempt writes, durable sending/unknown state, connection-owned order/room locks, read-only retries and crash observer.
- Explicit admin recovery after an ERP check; retain received references on amount or state conflicts.
- Exact private tracking, separate cancellation nonce, active tracking UI, order-key-protected confirmation polling and honest terminal states.
- Correct WP-Cron room schedule/monitoring/cleanup, HPOS plus CPT metadata-query adapter, paginated upcoming reconciliation and bootstrap registration of room photo/features shortcodes.
- Stronger log redaction, one-time legacy log payload migration, secure URL checks, tenant-aware caches and empty unconfigured defaults.
- Preserve guest/request order data with the installed v2 snippet. Keep gateway fees outside the ERP room payment and avoid recording COD as paid.

## Order state/metadata additions

`_arc_sync_status` includes `sending` and `unknown`. `_arc_sync_started_at` records the write-intent time; `_arc_sync_protocol` identifies safe v1.1.0 read retries. `_arc_api_total_amount`, `_arc_api_paid_amount`, `_arc_api_due_amount` store returned ERP amounts, and `_arc_confirmation_email_sent` prevents repeated official voucher sends. Existing ARC order keys are retained. The booking array records `company_code` to prevent a tenant switch from submitting old room data to a different property; room booking requires BDT because the current API has no alternate-currency quote field.

Options `arc_plugin_version`, `arc_log_privacy_version`, `arc_cache_generation`, and `arc_cron_last_run` support upgrade, cache invalidation and queue health. Uninstall removes these settings/diagnostics/events while preserving financial orders and room posts.

## Evidence and limits

The API evidence is the checked-in `agent-instructions/hotel-all-Api-End-point.json`: public hotel-info/category/availability GET requests, reference-based tracking GET, create-reservation-json with WebBookingRequest and cancel-reservation-json with WebCancelReservationRequest. No pending backend field or endpoint is required by the update.

See the root README for exact local verification commands and remaining backend/staging limits. The supplied suite uses actual core classes with WordPress/MySQL transport stubs; it is not proof of live gateway or concurrent ERP behavior. Cross-channel atomic overlap, backend idempotency, source accounting and write authentication remain backend work.
