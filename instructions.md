# ARC Hotel Core — Implementation Instructions (Phased)

All code, comments, and commit messages in **English**.
Work through phases in order. Do not start a phase until the previous phase's exit criteria pass.
Each task names the files it touches so the change stays reviewable.

---

## Phase 0 — Baseline and safety net

**Goal:** make the current build incapable of quietly losing money while we refactor.

### Tasks

1. **Freeze writes behind a switch.**
   Add `ARC_BOOKING_ENABLED` (default `false` in code, settable in `wp-config.php`) and check it at the top of `Reservation_Sync::maybe_sync()` and `Track_Controller::ajax_cancel()`.
   Return a clear "temporarily unavailable" state instead of a silent failure.

2. **Pin the API contract.**
   Keep `agent-instructions/hotel-all-Api-End-point.json` as the single source of truth. Add a small `tests/test-api-contract.php` that asserts every path string used in `includes/` exists in that JSON. Fail the test if someone calls a phantom endpoint.

3. **Add a real integration harness.**
   `tests/test-suite.php` currently re-implements the logic it claims to test. Replace it with tests that call the real classes, and add one fixture-driven test per API call. Keep the existing stubs, but only for WordPress functions.

4. **Document the known hazards.**
   Add a `SECURITY.md` listing every accepted risk (public Swagger, no API idempotency, no hold endpoint) and the plugin-side mitigation for each.

### Exit criteria
- Plugin can be installed with booking disabled and no writes are possible.
- Contract test fails when a non-existent endpoint is referenced.
- Tests import real classes, not copies.

---

## Phase 1 — Security hardening (do this before any feature work)

**Goal:** every write path is authenticated, nonce-protected, rate-limited, and audited.

### 1.1 Remove the CSRF bypass
File: `includes/Cart/Cart_Guard.php` (≈ lines 90–100)

- Delete the branch that sets `$nonce_valid = true` when `WC()->session->has_session()`.
- A missing/invalid nonce must always `wc_add_notice( ..., 'error' )` and `wp_safe_redirect()` back.
- Keep the `Cache_Safety` fresh-nonce REST route as the supported path for cached pages, and make `assets/js/hotel-search.js` the only client that refreshes it.
- Update `tests/test-suite.php`: the assertion that the bypass is "correct behaviour" must be **deleted**, replaced by a test that asserts a request without a nonce is rejected.

### 1.2 Authenticate cancellation
File: `includes/Track/Track_Controller.php`

- Require the caller to supply the **booking reference and the phone number on the booking**, then verify both against `track/{reference}` before calling `cancel-reservation`.
- Add a per-IP and per-reference rate limit (reuse the existing `arc_rate_track_*` transient pattern, separate key space: `arc_rate_cancel_*`).
- Add a nonce check that is **not** shared with tracking.
- Log every cancellation attempt to `arc_api_log` with the order ID and outcome.

### 1.3 Fix secrets and transport
Files: `includes/Settings/Settings.php`, `includes/Settings/Settings_Page.php`, `includes/Api/Api_Client.php`

- `api_base_url` must be validated with `wp_http_validate_url()` **and** `str_starts_with( $url, 'https://' )`; reject `http://` with a settings error.
- Never store the API key in `arc_settings`. Read it only from `ARC_API_KEY` or an environment variable; the settings screen should show "defined / not defined", never the value.
- Add `sslverify => true` explicitly to every `wp_remote_*` call and remove any code path that can set it to `false` outside a local constant.

### 1.4 Fix PII handling
Files: `includes/Api/Api_Logger.php`, `includes/Track/Track_Controller.php`, `includes/Api/Api_Client.php`

- `guestName`, phone, email, NID, and passport must be hashed or masked before insertion into `arc_api_log`.
- **Never** put a phone number in a URL path. Change tracking to a POST-with-query or a reference-only lookup, and total the phone only in the request body.
- Redact the same fields in the admin log viewer (`includes/Admin/Log_Page.php`).

### 1.5 Capability and output escaping sweep
- Every admin AJAX handler must call `check_ajax_referer()` **and** `current_user_can( 'manage_woocommerce' )`.
- Fix the escaped-tag bug in `includes/Admin/Notices.php` (an `<a>` is passed through `esc_html()`); build the link with `sprintf()` and `esc_url()`.
- Re-check every `echo` in `templates/` for `esc_html` / `esc_attr` / `esc_url`.

### 1.6 Hard-fail on unsafe defaults
File: `includes/Settings/Settings.php`

- Remove `company_code => 'GPH01'`. Ship an empty default and show a blocking admin notice until the operator sets their own tenant.
- Same for `api_base_url`: no vendor demo URL as the default.

### Exit criteria
- A request with no nonce cannot add a room to the cart.
- A cancellation without a matching phone is rejected and rate-limited.
- No phone number appears in any URL or log row.
- An unconfigured install cannot reach the API at all.

---

## Phase 2 — Money correctness

**Goal:** the guest is charged exactly what the API will charge, in every path.

### 2.1 One pricing function
Create `includes/Pricing/Price_Resolver.php` with a single method:
`resolve( int $room_type_id, string $check_in, string $check_out, int $adults, int $children ): Price_Quote`

It must:
- call `available-rooms` with the exact dates and guest counts;
- pick **one deterministic row per room type** (documented rule — lowest `totalPrice`, tie-broken by the stable key) and never let two callers pick differently;
- return `total`, `per_night`, `currency`, `room_type_id`, `raw_row_id`.

Then make `Shortcodes::render_search_results()`, `Cart_Guard::attach_room_data()`, and `Checkout_Validation` all call it. No other code may compute a room price.

### 2.2 Coupon guard
File: `includes/Cart/Cart_Guard.php`

- After coupons are applied, if `cart_room_total < quote->total`, block checkout with an explicit message and a link back to the results page.
- Never let a coupon silently reduce the amount sent to the API.

### 2.3 Fix payment-option mapping
Files: `includes/Payment/Payment_Mapper.php`, `includes/Booking/Reservation_Sync.php`

- Map from **what was actually collected**, not from the gateway name alone: `paid_amount >= quote->total → FullPayment`, otherwise `Deposit`.
- Reject the order (do not downgrade silently) when `paid_amount` is neither zero nor at least the deposit configured for that tenant.
- Unit-test the matrix: cash / card / bKash / partial / full / zero.

### 2.4 Order state machine
File: `includes/Booking/Reservation_Sync.php`

- Introduce explicit states on order meta: `arc_state = pending | reserving | reserved | failed | cancelled`.
- Every transition is compare-and-swap (`add_option` style or `$wpdb->query` with `WHERE arc_state = ?`).
- Idempotent entry: if state is `reserved`, return success immediately without touching the API.

### Exit criteria
- A coupon that makes the total too low blocks checkout.
- Partial payment can never be recorded as full payment.
- Re-entering `maybe_sync` while `reserved` performs zero HTTP writes.

---

## Phase 3 — Idempotency and oversell defence (no `hold` endpoint available)

**Goal:** survive retries and races using only what the API offers.

### 3.1 Atomic lock
File: `includes/Booking/Reservation_Sync.php`

Replace `get_option`/`update_option` with:
```php
if ( ! add_option( $lock_key, time(), '', 'no' ) ) {
    $held = (int) get_option( $lock_key, 0 );
    if ( $held && ( time() - $held ) < 120 ) {
        return; // another worker owns this order
    }
    update_option( $lock_key, time(), 'no' ); // stale, take over
}
```
Also register a shutdown/`finally` release so a fatal error cannot wedge the order for 120s.

### 3.2 Write-ahead ledger
Add table `{prefix}arc_sync_ledger`:
`id, order_id, attempt, request_hash, state(pending|sent|confirmed|failed), remote_ref, http_status, created_at, updated_at`

- Insert `pending` **before** the POST.
- On any ambiguous result (timeout, 5xx, empty body), transition to `sent` and resolve later — never blindly retry.
- Resolver: `track/{order_ref}` first; if the booking is there, mark `confirmed` and stop.

### 3.3 Oversell serialisation
File: `includes/Checkout/Checkout_Validation.php`

- Acquire a MySQL advisory lock: `SELECT GET_LOCK( CONCAT('arc_room_', %d, '_', %s, '_', %s), 5 )`.
- Inside the lock: call `available-rooms`, confirm the room type still exists for those exact dates, re-run `Price_Resolver`.
- POST the reservation inside the same lock, then `RELEASE_LOCK`.
- Document clearly that this only serialises **this WordPress site**; cross-site oversell remains an accepted backend risk.

### Exit criteria
- Fault-injection test: kill the process between POST and save, then run the resolver — exactly one remote reservation exists.
- Two parallel checkouts for one remaining room: exactly one succeeds, the other gets a clean "sold out".

---

## Phase 4 — Search and data normalisation

### 4.1 Normaliser
Create `includes/Api/Room_Normalizer.php` mapping the raw API row to a stable shape with defaults for **missing keys**, not just nulls: `description`, `thumbnailUrl`, `imageUrls`, `amenities`, `maxAdults`, `maxChildren`, `totalPrice`.

### 4.2 Guest-count filtering
File: `includes/Elementor/Shortcodes.php`

- The API ignores `children`. Filter results locally: `adults <= maxAdults && children <= maxChildren`.
- Show a real empty state ("No room fits 2 adults + 2 children") rather than a wrong room.

### 4.3 Kill fake scarcity
- Never display or branch on `availableRoomsNow`. Treat it as informational metadata only.

### 4.4 Fix result grouping
- Group by `roomTypeId`, not by display name; sum nights × nightly rate per line item; never mix two price rows for the same type in one card.

### 4.5 Remove hard-coded identity
File: `templates/checkout/review-booking.php`

- Hotel name, star rating, check-in/out times, and logo must come from `hotel-info` (cached) with settings fallback. Delete the literal `Hotel Aristocrats & Suites`, the five stars, and `14:00` / `12:00`.

### Exit criteria
- A 2-adult + 2-child search never returns a room with `maxChildren = 0`.
- Missing `description` / `thumbnailUrl` render no warnings and no broken markup.
- Checkout shows the tenant's real name and times.

---

## Phase 5 — Reliability

### 5.1 HPOS
Files: `includes/Booking/Reconcile.php`, `includes/Admin/Notices.php`

Replace `meta_key`/`meta_value` queries with `wc_get_orders()` + `meta_query`, or query the order meta table through `WC_Data_Store`. Add a test that runs under HPOS enabled.

### 5.2 One scheduler
- Choose WP-Cron or Action Scheduler, then delete the other.
- If Action Scheduler: replace `wp_schedule_single_event` in `Reservation_Sync` and register recurrences with `as_schedule_recurring_action`.
- If WP-Cron: delete `Action_Scheduler_Status` and its admin surface, or relabel it "WP-Cron queue".
- Update `README.md` so it stops promising Action Scheduler when the code does not use it.

### 5.3 Bounded retries
- Max 5 attempts, exponential backoff with jitter, hard cap on delay.
- After the cap: `arc_state = failed`, admin notice, email to the operator, and a visible "Retry now" button.
- Never swallow exceptions: log class, message, order ID, and correlation ID (no PII).

### 5.4 Reconciliation
- Poll `track/{ref}` for every upcoming stay in the next N days.
- Detect: remote-cancelled, remote-modified (dates/price), remote-missing, local-missing.
- Provide one "Repair" action per case and record the outcome.

### 5.5 Uninstall
File: `uninstall.php`

- Unschedule `arc_daily_reconcile` and every `arc_*` recurrence.
- Delete all `arc_lock_*` and `arc_rate_*` options/transients.
- Drop `arc_sync_ledger` and `arc_api_log` (with an explicit opt-in flag to keep logs).
- Leave orders and order meta intact — that part is already correct.

### Exit criteria
- Reconcile finds orders on an HPOS store.
- Exactly one scheduler exists in the codebase.
- Uninstall leaves no `arc_*` rows in `wp_options`.

---

## Phase 6 — Observability and admin UX

- Replace the per-call `SHOW TABLES` in `Api_Logger::install_table()` gating with a cached version check.
- Settings screen: allow **zero** room gateways; currently the checkbox logic makes "none" impossible.
- Log viewer: filter by endpoint, status, order, and correlation ID; show a redacted payload preview.
- Add a "last successful reservation" timestamp to the settings header.
- Add a health panel: API reachability (via `hotel-info`), average latency, error rate over 24h, sync backlog, dead-letter count.

---

## Phase 7 — SaaS transformation

### 7.1 Tenant model
- Introduce `arc_tenant_id` (site hash or license key) and namespace every cache key, log row, lock, and cron with it.
- Migrate existing `arc_settings` into a tenant-scoped structure with a one-time upgrade routine.

### 7.2 Onboarding wizard
- Step 1: base URL (HTTPS only) → live `hotel-info` probe.
- Step 2: company code → live `room-categories` probe; show the returned hotel name so the operator confirms the tenant.
- Step 3: API key status → attempt one read; never echo the key.
- Step 4: payment gateways for rooms (zero allowed).
- Step 5: test booking in sandbox mode, then enable.
- Booking stays disabled until all steps pass.

### 7.3 Licensing and remote control
- Signed license validation against a vendor endpoint, cached, with a grace period.
- Remote flags: `booking_enabled`, `max_rooms_per_order`, `allowed_gateways`, `search_only_mode`, `maintenance_message`.
- Kill switch must degrade gracefully: search still works, booking shows the maintenance message.

### 7.4 White-label
- Move hotel name, logo, rating, times, colors, and email branding into tenant config.
- Remove every literal hotel string from `templates/`.

### 7.5 Metering and support
- Count searches and reservations per tenant per day (aggregate only, no PII).
- One-click redacted support bundle: versions, settings (secrets removed), last 200 log rows, cron status.

---

## Phase 8 — Release gate

Ship only when all of these are true:

1. `tests/test-api-contract.php` passes — no phantom endpoints.
2. Fault-injection test proves a retry cannot duplicate a reservation.
3. Parallel-checkout test proves oversell is serialised locally.
4. Price equality test proves quote == cart == API for coupons, partial payments, and multi-night stays.
5. CSRF test proves a session-only request cannot add a room.
6. HPOS test passes with HPOS enabled.
7. Uninstall test leaves no `arc_*` residue.
8. A fresh install with no configuration cannot reach the API or write a booking.
9. `phpcs` (WordPress standard) and PHP 8.2 static analysis are clean.
10. `SECURITY.md` is updated with every remaining accepted risk.

---

## Standing rules

- **Never** add a third-party hotel/booking plugin dependency. Everything stays in this plugin.
- **Never** call an endpoint that is not in `hotel-all-Api-End-point.json`.
- **Never** trust a client-supplied price, date, guest count, discount, or reference.
- **Never** retry a write without first checking whether the previous attempt landed.
- Every new option, transient, table, and cron must be prefixed `arc_` and cleaned up on uninstall.
- Comments and identifiers in English; user-facing strings wrapped in the `arc-hotel-core` text domain.
