# arc-hotel-core — Product Requirements Document (PRD)

Version 1.0 | 2026-11-01 | Client: Aristocrats | Owner: DUSRA Soft Limited

---

## 1. Summary

`arc-hotel-core` is a custom WordPress plugin that turns a WordPress + Elementor Pro + WooCommerce site into the **online booking front office** of a hotel whose inventory lives in the **ASP.NET Smart Hotel Management API**.

Guest experience target: comparable to HotelPress / VikBooking (search, room pages, booking, payment, tracking, cancellation).
Architecture difference: the plugin **never owns inventory**. The ASP.NET API is the single source of truth for rooms, rates, availability and reservations. WordPress is only presentation + payment + order management.

The same WooCommerce store also sells restaurant food. Room checkout and food checkout must coexist without interfering with each other (conditional checkout).

### 1.1 Goals
1. Zero double-booking between receptionist software and website.
2. Professional OTA-style booking flow (Booking.com-like checkout card).
3. Fully editable design in Elementor Pro (widgets, templates, no hard-coded layouts).
4. Payments through WooCommerce gateways (bKash, Nagad, SSLCommerz, card, etc.).
5. Fast, secure, maintainable code that any developer or AI agent can extend.

### 1.2 Non-goals (v1)
- No room/rate/inventory management inside WordPress (done in ASP.NET software).
- No channel manager / OTA sync.
- No coupon engine, loyalty, multi-room cart, seasonal-rate editor, or reports in WP.
- No third-party hotel plugin (WP Hotel Booking, MotoPress, etc.) in code or as a dependency.
- No live food-order tracking (out of contract scope).

---

## 2. Users

| User | Needs |
|---|---|
| Guest | Search dates, see real availability and price, book, pay, get confirmation, track, cancel |
| Receptionist | Uses ASP.NET software only. Must see website bookings immediately and must be unable to double-sell |
| Site admin (hotel manager) | See website orders, sync status, fix failed syncs, edit page design, change settings |
| Restaurant customer | Order food with simple checkout (no hotel fields) |
| Developer / Agent | Clean structure, hooks, mock API for development without backend |

---

## 3. Functional Requirements

Priority: **P0** = required for launch, **P1** = should have, **P2** = later.

### A. Connection and Settings
- FR-A1 (P0) Settings page: API base URL, company code, connection test button, cache TTLs, enabled payment modes, enabled gateways for rooms, cancellation policy text (fallback), check-in/out time display.
- FR-A2 (P0) API secret key is read from `wp-config.php` constant (`ARC_API_KEY`), never stored in DB, never shown in UI or sent to browser.
- FR-A3 (P0) Status panel: API reachable, last successful call, tenant name from `hotel-info`, pending/failed sync counts.
- FR-A4 (P0) Dev "mock mode" (constant) that serves fixture responses so the plugin can be built and tested before the backend is finished.

### B. Room Showcase
- FR-B1 (P0) Room types are mirrored from `room-categories` into a custom post type `arc_room` (one post per `roomTypeId`) on schedule + "Sync now" button. Gives SEO URLs, Elementor Theme Builder templates and Loop Grid support.
- FR-B2 (P0) Room detail shows: gallery, description, amenities, capacity (adults/children), "from" price, live availability CTA.
- FR-B3 (P0) Price and availability shown on pages are **live from API**; the mirrored snapshot is only a fallback for archive grids and is labelled "from".
- FR-B4 (P1) Admin may add extra marketing content to the room post with Elementor; API-owned fields (name, price, capacity, amenities, images) are read-only in WP.

### C. Availability Search
- FR-C1 (P0) Search bar: check-in, check-out, adults, children, optional room type. Date picker with min date = today, check-out > check-in, max stay configurable.
- FR-C2 (P0) Submit goes to Search Results page via GET (shareable URL). Results are grouped by room type with: photo, name, capacity, amenities, nights, per-night price, **total for stay**, rooms left (if low), "Book Now".
- FR-C3 (P0) Empty state with friendly message and nearest alternative action (change dates).
- FR-C4 (P0) Search is rate-limited per IP to protect the API.
- FR-C5 (P1) Remember last search in session to prefill the form.

### D. Cart and Booking Rules
- FR-D1 (P0) "Book Now" adds a room line item to the WooCommerce cart. Price is taken **from the API at that moment, server-side**, never from the browser.
- FR-D2 (P0) Cart item holds: check-in, check-out, nights, adults, children, room type, assigned room, rate/night, total, hold id (if available).
- FR-D3 (P0) One room booking per cart, quantity locked to 1. Adding a new room replaces the previous room item.
- FR-D4 (P0) Food items and room items cannot coexist. Show a clear error with a one-click "Clear cart" (nonce-protected).
- FR-D5 (P0) Cart re-validates on every cart/checkout load (dates still valid, not expired, price unchanged).

### E. Conditional Checkout
- FR-E1 (P0) **Food mode**: standard WooCommerce checkout, simplified fields (Full name, Mobile, Email, District, Delivery address). No hotel UI/CSS/labels.
- FR-E2 (P0) **Room mode** (OTA-style):
  - Hide physical address/shipping fields.
  - "Review Your Booking" card: hotel name, room photo/type, check-in/out with times, nights badge, guests, price breakdown, cancellation policy.
  - Fields: full name, mobile (8–15 digits), email, NID/Passport (mandatory, validated), additional adult names (one per extra adult), bed type, estimated arrival time, additional-request checkboxes, special request note, mandatory terms checkbox.
- FR-E3 (P0) Room-mode CSS/JS loads **only** when the cart contains a room (no impact on food checkout or other pages).
- FR-E4 (P0) Payment gateways are filtered by mode: room mode shows only gateways enabled for rooms (+ "Pay at Hotel" if enabled); food mode never shows "Pay at Hotel".

### F. Payment Modes
- FR-F1 (P0) `FullPayment`: guest pays 100% online via Woo gateway.
- FR-F2 (P0) `PayAtHotel`: custom gateway "Pay at Hotel", no online payment, reservation is created immediately; admin can disable it.
- FR-F3 (P2) `Deposit`: guest pays a configured % online; remaining due at hotel. Needs backend support for partial payment status. Not in v1 unless client requires.
- FR-F4 (P0) Payment method mapped automatically from the Woo gateway to API `paymentMethod` (1–6). Not user-editable.

### G. Final Verification and Reservation Sync
- FR-G1 (P0) Immediately before payment, plugin re-checks availability (or confirms the hold) with the API. If unavailable, checkout stops with a clear message **before** the guest is charged.
- FR-G2 (P0) After successful payment (or on order creation for Pay at Hotel), plugin creates the reservation in the API exactly once (idempotent).
- FR-G3 (P0) If the API call fails, the order is **not lost**: status `on-hold`, automatic retries with back-off, admin alert after final failure, "Needs manual action" flag.
- FR-G4 (P0) Order stores API results: booking id, booking number, assigned room number, registration token, API payment status.
- FR-G5 (P0) Thank-you page and email show the **official API booking number** (e.g. `WEB-20261028-1234`). If sync is still pending, the page shows "confirmation in progress" and auto-updates.

### H. Track and Cancel
- FR-H1 (P0) Track Booking page: booking number **and** phone required. Shows status, room, dates, payment status, balance due.
- FR-H2 (P0) Cancel from the track page (booking number + phone + optional reason) calls the API, adds an order note, sends emails. Refund is handled by admin in WooCommerce (v1) and the UI states this clearly.
- FR-H3 (P1) Optional digital registration card / signature link using `registrationToken`.

### I. Admin Tools
- FR-I1 (P0) Order screen meta box: booking number, room, dates, guests, ID (masked), sync status, retry button, "open in API" reference.
- FR-I2 (P0) Orders list column + filter: Room/Food, Sync status.
- FR-I3 (P0) API log viewer (last 30 days, redacted) for support.
- FR-I4 (P1) Daily reconcile: for upcoming bookings, compare API status with order; if cancelled/changed in the hotel software, add order note and alert admin (never auto-refund).

### J. Emails
- FR-J1 (P0) Customer confirmation email (room): booking number, dates, room, guests, amount paid/due, policy, contact. NID masked.
- FR-J2 (P0) Admin new-booking email, and admin "sync failed" alert.
- FR-J3 (P1) Cancellation email to guest and admin.

### K. Elementor and Shortcodes
- FR-K1 (P0) Elementor widgets (each also available as shortcode): Search Bar, Room Types Grid, Room Availability (on room detail), Search Results, Track Booking, Hotel Info (name/phone/times/address).
- FR-K2 (P0) Every widget has style controls (typography, colors, spacing, layout columns) and works inside Theme Builder templates.
- FR-K3 (P0) Auto-create pages on activation: Rooms (archive), Search Results, Track Booking. Reuses WooCommerce Checkout page.

---

## 4. Business Rules

| Rule | Value |
|---|---|
| Currency | BDT |
| Time zone | Asia/Dhaka for all dates |
| Check-in / out | Taken from API `hotel-info` (default 14:00 / 12:00) |
| Night count | Calculated by API only |
| Price authority | API only; plugin never recalculates or accepts browser prices |
| Mandatory guest ID | NID (10, 13 or 17 digits) or passport (6–9 alphanumeric) |
| Guests | adults ≤ room `maxAdults`, children ≤ `maxChildren`; one name per additional adult |
| Cart | One room per cart; no mixing with food |
| Cancellation | Policy text shown at checkout; refund manual in v1 |
| Tax/VAT | Room price from API is treated as final; Woo tax is disabled for room product (see open decisions) |

---

## 5. Non-Functional Requirements

- **Performance:** room/hotel info cached; availability never cached; every API call timeout ≤ 10 s; room-mode assets only when needed; images lazy-loaded WebP from API; no blocking API call on pages that do not need it.
- **Security:** nonce on every state-changing request; sanitize input, escape output; API key server-side only; no PII in URLs or logs; NID masked in emails/admin lists; rate limiting on search/track/cancel.
- **Compatibility:** latest stable WordPress, WooCommerce (HPOS enabled and declared compatible), Elementor Pro; PHP 8.3+. Classic (shortcode) checkout supported; Block Checkout is out of scope in v1 (declare incompatibility cleanly).
- **Reliability:** idempotent reservation sync, retries, no silent failure.
- **i18n:** English + Bangla, all strings translatable, text domain `arc-hotel-core`.
- **Accessibility/Responsive:** keyboard-usable forms, labelled inputs, mobile-first layouts.
- **Maintainability:** WordPress Coding Standards, English code comments, prefix `arc_`, no inline secrets, uninstall cleanup option.

---

## 6. Open Decisions (need answer before / during build)

| # | Decision | Recommended |
|---|---|---|
| D1 | Mirror room types as CPT `arc_room` | **Yes** (SEO + Elementor templates) |
| D2 | Hold endpoint in API available? | **Yes** (see backend doc); plugin supports fallback without it |
| D3 | Is VAT/service charge included in API room price? | Included; Woo tax off for room product |
| D4 | Cancellation/refund rules | Policy text from API; manual refund v1 |
| D5 | Deposit payment | Not in v1 |
| D6 | Aristocrats own `companyCode`, staging API, API key | Required from backend |

---

## 7. Acceptance Test Scenarios

1. Guest searches valid dates and sees only rooms that are free in the receptionist software.
2. Receptionist books room X; guest search no longer shows X.
3. Guest is filling checkout; receptionist books the same room; guest's payment is blocked before charge with a clear message.
4. Browser-tampered price (devtools / modified POST) has no effect on the cart total.
5. Cart with food + room is blocked with a working "Clear cart" button.
6. Food checkout shows no hotel fields or styling; room checkout shows OTA layout.
7. Successful online payment creates exactly one API reservation, even if the payment callback fires twice.
8. API down during sync: order stays `on-hold`, retries run, admin is alerted, manual retry button works, guest sees "pending" then confirmation.
9. Track with wrong phone returns a generic "not found"; correct pair shows details; brute force is rate-limited.
10. Cancel from site updates API and order note; admin can refund in Woo.
11. NID is masked in customer email and admin list; never present in any URL.
12. Mobile (360 px) booking flow is fully usable.

---

## 8. Release Plan

| Phase | Scope |
|---|---|
| M1 | Plugin skeleton, settings, API client, mock mode, logs |
| M2 | Room CPT sync, room pages, Elementor widgets (info, grid) |
| M3 | Search + results + add-to-cart + cart rules |
| M4 | Room checkout, validation, pre-payment re-check, gateways, Pay at Hotel |
| M5 | Reservation sync, retry queue, order meta, emails, thank-you |
| M6 | Track/cancel, admin meta box/columns, reconcile cron |
| M7 | Hardening: security review, load test, double-booking race test on staging, i18n, docs |
