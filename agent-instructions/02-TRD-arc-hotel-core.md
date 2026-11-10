# arc-hotel-core — Technical Requirements Document (TRD)

Version 1.0 | 2026-11-01 | Read together with `01-PRD-arc-hotel-core.md` and `03-backend-api-requirements.md`.

> Instructions for the AI agent: build in the milestone order in section 15. Do not add features outside the PRD. Do not use any third-party hotel plugin. All code comments in English. Follow WordPress Coding Standards. Every milestone must satisfy the Definition of Done in section 16.

---

## 1. Stack and Constraints

- PHP 8.3+, WordPress (latest stable), WooCommerce (latest stable, **HPOS compatible**, classic shortcode checkout), Elementor + Elementor Pro (latest stable).
- Action Scheduler (bundled with WooCommerce) is the only queue. No extra libraries required; no Composer dependency in v1 (use a simple PSR-4 style autoloader).
- Backend: Smart Hotel Management API (ASP.NET). Base URL and company code come from settings; secret from `wp-config.php`.
- No custom tables except one diagnostic log table (section 12).
- Plugin slug `arc-hotel-core`, text domain `arc-hotel-core`, namespace `ARC\HotelCore`, global prefix `arc_`.

## 2. Architecture Principles

1. **API is the source of truth.** WP never computes price, nights, or availability.
2. **Server-side only API calls.** The browser talks only to WordPress (admin-ajax/REST with nonce); the secret key never leaves PHP.
3. **Never trust the browser.** Price, room, totals are re-fetched server-side at add-to-cart and at checkout.
4. **Idempotent writes.** One WooCommerce order produces at most one API reservation.
5. **Fail safe.** If the API is unreachable, never charge without a path to recover (block before payment; retry after payment).
6. **Scoped loading.** Room checkout CSS/JS only when the cart has a room.

## 3. Folder Structure

```
arc-hotel-core/
├── arc-hotel-core.php            # header, constants, HPOS declaration, bootstrap
├── uninstall.php
├── includes/
│   ├── Autoloader.php
│   ├── Plugin.php                # wires all modules
│   ├── Settings/Settings.php, Settings_Page.php
│   ├── Api/Api_Client.php        # single HTTP gateway
│   ├── Api/Api_Cache.php         # transient helpers
│   ├── Api/Api_Mock.php          # dev fixtures (pre_http_request)
│   ├── Api/Api_Logger.php
│   ├── Rooms/Room_Sync.php       # API -> CPT arc_room
│   ├── Rooms/Room_Post_Type.php
│   ├── Search/Search_Service.php, Search_Controller.php
│   ├── Cart/Cart_Guard.php       # add-to-cart, validation, separation, price override
│   ├── Checkout/Checkout_Mode.php, Checkout_Fields.php, Checkout_Validation.php
│   ├── Payment/Gateway_Pay_At_Hotel.php, Payment_Mapper.php, Gateway_Filter.php
│   ├── Booking/Reservation_Sync.php, Order_Meta.php, Reconcile.php
│   ├── Track/Track_Controller.php, Cancel_Controller.php
│   ├── Admin/Order_Meta_Box.php, Orders_Columns.php, Log_Page.php, Notices.php
│   ├── Emails/Room_Confirmation_Email.php, Admin_Alert_Email.php
│   └── Elementor/Widgets/*.php, Shortcodes.php
├── templates/                    # overridable by theme: arc-hotel-core/*.php
├── assets/css, assets/js         # split: global-small, room-checkout, search
├── languages/
└── tests/
    ├── fixtures/*.json           # built from the OpenAPI spec
    └── (PHPUnit / WP test cases)
```

## 4. Settings and Secrets

Option `arc_settings` (array, autoload = no):

| Key | Notes |
|---|---|
| `api_base_url` | e.g. `https://hotelapi.dusrasoftltd.com`, https only |
| `company_code` | Aristocrats tenant code (not demo `GPH01`) |
| `cache_ttl_info` / `cache_ttl_rooms` | defaults 30 min / 10 min |
| `max_stay_nights`, `max_advance_days` | UI limits only; API remains authority |
| `room_gateways` | array of gateway ids allowed for room checkout |
| `pay_at_hotel_enabled` | bool |
| `cancellation_policy_text` | fallback if API does not return one |

Secrets (constants in `wp-config.php`): `ARC_API_KEY`, optional `ARC_API_MOCK` (true = fixtures). Settings UI shows only "key detected / missing".

## 5. API Client (`Api_Client`)

Single entry point; no other class calls `wp_remote_*` for the hotel API.

```php
// Signature contract (implementation by agent)
public function get( string $path, array $query = [], array $opts = [] ): Api_Result;
public function post( string $path, array $body = [], array $opts = [] ): Api_Result;
```

Behaviour:
- Always send `companyCode`, header `X-Api-Key`, `Accept: application/json`, `X-Correlation-Id` (uuid per request).
- Timeout 10 s (8 s for reads), 1 retry only on connection error for GET.
- Parse standard wrapper `{succeeded, message, errors, data}`. Return `Api_Result` with `ok()`, `data()`, `error_code()`, `message()`, `http_status()`.
- Map `errorCode` from backend: `ROOM_NOT_AVAILABLE`, `PRICE_CHANGED`, `HOLD_EXPIRED`, `INVALID_DATES`, `VALIDATION`, `RATE_LIMITED`, `UNAUTHORIZED`. Unknown errors become generic user-safe messages; raw detail goes to the log only.
- Dates: always send ISO `Y-m-d\TH:i:s` in Asia/Dhaka; use `wp_timezone()` helpers, never `date()`.
- Caching: `hotel-info`, `room-categories` cached via transients with the TTLs above; **`available-rooms`, `hold`, `create-reservation`, `track` are never cached**.
- Logger records: time, endpoint, method, status, duration, correlation id, success flag; request bodies are redacted (no NID, phone, email).

## 6. Endpoint Usage Map

| Feature | API call | Cache |
|---|---|---|
| Header/footer/contact | `GET hotel-info` | 30 min |
| Room CPT sync + archive | `GET room-categories` | 10 min |
| Search | `GET available-rooms` (+ `children`) | none |
| Hold (preferred) | `POST hold` | none |
| Final check (fallback) | `GET available-rooms` | none |
| Reservation | `POST create-reservation-json` | none |
| Track | `POST track` (fallback `GET track/{ref}` until backend adds POST) | none |
| Cancel | `POST cancel-reservation-json` | none |
| Reconcile | `GET changes?since=` (fallback: `track` per booking) | none |

## 7. Room Mirror (`arc_room` CPT)

- `Room_Sync` upserts one post per `roomTypeId` (`_arc_room_type_id` meta, unique). Runs via Action Scheduler every 15 min and on "Sync now".
- Stores: title (`categoryName`), content (`description`) only if post content is empty or flagged auto, meta for capacity, amenities, image URLs, `_arc_from_price` snapshot, `_arc_synced_at`.
- Removed categories become `draft`, never deleted automatically.
- Slug rewrite: `/rooms/{slug}`. Register Elementor Theme Builder support for single/archive.
- Live widgets (availability, price) always call the API, not the snapshot.

## 8. Search Flow

1. Search form (GET) → Search Results page with `check_in, check_out, adults, children, room_type`.
2. `Search_Service` validates: dates parse, `check_in >= today`, `check_out > check_in`, stay ≤ `max_stay_nights`, adults ≥ 1, counts within limits.
3. Calls `available-rooms`; groups result rows by `roomTypeId` in PHP; for each group: count, cheapest `pricePerNight`, `totalPrice`, `nights`, representative `roomId`.
4. Rate limit: transient counter per hashed IP, 30 searches/min → friendly 429 message.
5. "Book Now" posts `{roomTypeId, check_in, check_out, adults, children}` with **nonce**. No price field is accepted.

## 9. Cart (`Cart_Guard`)

Room container: one hidden, virtual, "sold individually" WooCommerce product. Marked `_is_hotel_room = yes` and in `product_cat` `hotel-rooms`. Tax status = none. Not visible in shop/search/sitemap; created on activation if missing.

Hooks:
- `woocommerce_add_to_cart_validation`: reject food+room mix; for rooms re-run API availability server-side; reject invalid dates/guest counts. On success obtain price from API (or hold response).
- `woocommerce_add_cart_item_data`: attach `arc_room` array:
  `check_in, check_out, nights, adults, children, room_type_id, api_room_id, rate_per_night, total_price, hold_id, hold_expires_at, unique_key`. `unique_key` (hash) prevents Woo from merging bookings.
- Before add, empty any existing room line (one room per cart).
- `woocommerce_get_cart_item_from_session`: restore data.
- `woocommerce_before_calculate_totals` (priority 20): `$item['data']->set_price( $item['arc_room']['total_price'] )`, quantity forced 1. Display "per night × nights" in line item meta only.
- `woocommerce_check_cart_items`: if hold expired or check-in is in the past → remove item and add notice; if cart has food + room → error notice with nonce link `?arc_clear_cart=1&_wpnonce=…`.
- Clear-cart handler: verify nonce + capability-free (guest) context, `WC()->cart->empty_cart()`, redirect.

## 10. Checkout (`Checkout_Mode`, `Checkout_Fields`, `Checkout_Validation`)

Helper `arc_cart_has( 'room' | 'food' )` used everywhere.

**Food mode** (cart has no room): fields only Full name, Mobile, Email, District, Delivery address; no hotel assets.

**Room mode:**
- `woocommerce_checkout_fields`: unset billing address/city/state/postcode/country/company, shipping fields; keep first/last→ single "Full name" mapping, phone, email.
- Add fields: `arc_id_number`, `arc_guest_2..arc_guest_8` (render count = adults − 1), `arc_bed_type` (radio), `arc_arrival_time` (select), `arc_req_*` (checkboxes), `arc_special_note` (textarea), `arc_terms` (required checkbox).
- Validation (`woocommerce_after_checkout_validation`):
  - Phone `^\d{8,15}$` after stripping `+`, spaces, dashes.
  - ID: NID `^\d{10}$|^\d{13}$|^\d{17}$` or passport `^[A-Z0-9]{6,9}$` (uppercased).
  - Guest names present for each extra adult.
  - **Final availability check:** if hold exists → confirm hold still valid (`POST hold/{id}/confirm-check` or equivalent); else call `available-rooms` and confirm the chosen room (or any same-type room at same price) is still free. If not → `wc_add_notice( …, 'error' )`, checkout stops before payment.
  - Re-compare API total with cart total; mismatch → error and cart refresh.
- "Review Your Booking" card rendered at `woocommerce_checkout_before_customer_details` using template `templates/checkout/review-booking.php` (hotel name, stars, thumbnail, check-in/out + times from `hotel-info`, nights badge, guests, price summary, policy).
- CSS/JS enqueue guarded by `is_checkout() && arc_cart_has('room')`.
- Order creation: `woocommerce_checkout_create_order` saves meta (section 11). Do not store NID in plain order notes.

Gateway filtering (`Gateway_Filter` on `woocommerce_available_payment_gateways`):
- Room cart → only `room_gateways` + `arc_pay_at_hotel` (if enabled).
- Food cart → remove `arc_pay_at_hotel`.

`Gateway_Pay_At_Hotel`: extends `WC_Payment_Gateway`, `process_payment()` sets status `on-hold`, reduces nothing, enqueues sync, returns thank-you redirect.

## 11. Order Meta (HPOS-safe via `$order->update_meta_data`)

| Key | Value |
|---|---|
| `_arc_order_type` | `room` / `food` |
| `_arc_booking` | array `check_in, check_out, nights, adults, children, room_type_id, rate, total` |
| `_arc_id_number` | NID/passport (admin-only display, masked elsewhere) |
| `_arc_guests`, `_arc_requests`, `_arc_bed_type`, `_arc_arrival`, `_arc_note` | guest inputs |
| `_arc_sync_status` | `pending` / `synced` / `failed` / `manual` |
| `_arc_sync_attempts`, `_arc_sync_last_error` | diagnostics |
| `_arc_aspnet_booking_id`, `_arc_aspnet_reservation_no`, `_arc_aspnet_room_number`, `_arc_aspnet_reg_token` | from API |
| `_arc_api_payment_status` | latest known |

## 12. Reservation Sync (`Reservation_Sync`)

Triggers: `woocommerce_payment_complete`, `woocommerce_order_status_processing`, `woocommerce_order_status_completed` (safety), and Pay-at-Hotel `process_payment`. All route to `Reservation_Sync::maybe_sync( $order_id )`.

`maybe_sync`:
1. Return if `_arc_order_type !== 'room'` or `_arc_sync_status === 'synced'`.
2. Acquire an atomic lock: `add_option( 'arc_lock_' . $order_id, time(), '', 'no' )` returns false if it already exists (delete stale locks > 2 min).
3. Build payload (`Payment_Mapper`) and `POST create-reservation-json`.
4. Payload fields: `companyCode, checkInDate, checkOutDate, roomTypeId, roomId, holdId, adults, children, fullName, phone, email, nationalIdOrPassport, specialRequests (merged string), paymentOption, paymentMethod, amountPaid, transactionReference, paymentRemarks, externalReference = "WC-{order_id}", expectedTotalAmount`.
5. `specialRequests` merge order: `Bed: … | Arrival: … | Extra guests: … | Requests: … | Note: …`.
6. Success → save API meta, status `synced`, order note, set Woo status `processing`/`completed` per settings, trigger confirmation emails, release lock.
7. Failure → `_arc_sync_status = pending`, attempt counter, schedule retry via Action Scheduler at +1, +5, +15, +60, +180 minutes (unique action per order). Business errors (`ROOM_NOT_AVAILABLE`, `PRICE_CHANGED`, `HOLD_EXPIRED`) skip retries → `manual` (admin must refund or re-assign). After final failure: `failed`, order `on-hold`, admin alert email, admin notice.
8. Always release lock in `finally`.

`Payment_Mapper`:

| Woo gateway id (configurable map) | `paymentMethod` |
|---|---|
| `arc_pay_at_hotel` | 1 Cash (amountPaid 0, option PayAtHotel) |
| card gateways | 2 |
| bKash | 3 |
| Nagad | 4 |
| bank transfer | 5 |
| SSLCommerz / others | 6 |

`paymentOption`: `PayAtHotel` if amountPaid = 0; `FullPayment` if paid = total; `Deposit` reserved for P2. `transactionReference`: gateway transaction id (fallback `WC-{order_id}`).

Thank-you page: if `_arc_sync_status !== 'synced'`, render "Confirmation in progress" and poll a nonce-protected AJAX endpoint every 5 s (max 2 min).

## 13. Track and Cancel

- Track form fields: booking number + phone. Server-side: normalise phone (strip `+88`, spaces), call API, return generic "No booking found for these details" for any mismatch.
- Rate limit: 5 attempts / 15 min per IP + per booking number (transients).
- Cancel: same verification, then API cancel; on success add order note by `_arc_aspnet_reservation_no` lookup, send emails. Display API-returned refund/charge info when available. No automatic Woo refund in v1.
- Pages never expose NID.

## 14. Admin, Cron and Emails

- Meta box on order edit screen (HPOS screen id aware): booking details, masked ID with "reveal" for `manage_woocommerce`, sync status, "Retry sync" button (nonce + capability).
- Orders list: column "Type / Sync" and filter dropdown.
- Log page (capability `manage_woocommerce`): last 30 days; table `{prefix}arc_api_log` created on activation; daily cleanup via Action Scheduler.
- Reconcile (daily + manual): for orders with future check-in and `synced`, fetch status; if cancelled/changed → order note + admin alert.
- Emails: extend `WC_Email`; templates overridable; ID masked as `••••••1234`.
- Admin alert triggers: sync failed, reconcile mismatch, API unreachable > 15 min (health check cron).

## 15. Elementor and Shortcodes

Widgets extend `\Elementor\Widget_Base`, registered on `elementor/widgets/register`, category "Aristocrats Hotel".

| Widget | Shortcode |
|---|---|
| Search Bar | `[arc_search]` |
| Room Types Grid | `[arc_rooms columns="3"]` |
| Room Availability (single room) | `[arc_room_booking]` |
| Search Results | `[arc_results]` |
| Track Booking | `[arc_track]` |
| Hotel Info | `[arc_hotel_info field="phone"]` |

All render through templates; all widgets expose typography/color/spacing controls; no inline styles with fixed values.

## 16. Security Checklist

- Nonce on all AJAX/admin-post; capability checks on admin actions.
- `sanitize_text_field`, `wp_unslash`, strict type casts for ints/dates; `esc_html/attr/url` on every output; `wp_kses_post` only for admin-authored HTML.
- No secrets, NID, phone or email in URLs, logs, JS variables or HTML source.
- `wp_remote_*` with `sslverify = true`, https base URL enforced.
- Rate limiting on search, track, cancel, add-to-cart.
- HPOS declared compatible via `FeaturesUtil::declare_compatibility`.
- Block Checkout declared incompatible (cleanly) in v1.
- `uninstall.php` removes options, log table, scheduled actions; **keeps** orders and meta.

## 17. Build Order (milestones for the agent)

1. **M1:** bootstrap, autoloader, settings page, `Api_Client`, `Api_Mock` + fixtures, logger/table.
2. **M2:** `arc_room` CPT + `Room_Sync`, Hotel Info and Room Grid widgets, single/archive templates.
3. **M3:** Search form/results, rate limit, add-to-cart, `Cart_Guard` (price override, separation).
4. **M4:** Checkout mode/fields/validation, review card, gateway filter, Pay-at-Hotel gateway, final availability check.
5. **M5:** `Reservation_Sync`, mapper, retry queue, order meta, thank-you polling, emails.
6. **M6:** Track/cancel, order meta box, columns, log page, reconcile cron.
7. **M7:** Security review, race-condition test, i18n (bn), docs, packaging.

## 18. Definition of Done (per milestone)

- Works in mock mode and against staging API.
- PHPCS (WordPress standard) passes; no PHP warnings/notices with `WP_DEBUG` on.
- Every public input path has nonce + sanitization + escaping.
- Unit tests for: payment mapper, validators (phone/ID), cart price override, sync idempotency, retry scheduling.
- Manual test pass of the relevant PRD scenarios (section 7).
- No third-party hotel plugin referenced anywhere.

## 19. Final Staging Tests (before go-live)

1. Receptionist and website book the same room within the same second (script both) → exactly one succeeds, the other gets a clean error and no charge/refund path is needed.
2. Fire payment callback twice → one API reservation.
3. Kill API during sync → retries run, admin alerted.
4. Tamper cart price via POST/devtools → ignored.
5. Load test: 50 concurrent searches → API and WP stay responsive; rate limit works.
