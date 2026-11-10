---
name: arc-hotel-ecosystem
description: >-
  Architectural guide, API contract reference, and development rules for the 3-Tier
  Hotel Ecosystem (Super Admin Dashboard, Receptionist PMS, and WordPress Public Website).
  Use whenever working on the sdl-hotel-booking / arc-hotel-core plugin, integrating with
  the ASP.NET Hotel API, or designing frontend room displays and booking flows.
  Clearly separates what the API does TODAY (verified against the OpenAPI JSON) from
  what the backend MUST still implement.
---

# Arc Hotel Ecosystem — AI Developer Skill & Architecture Reference

This document defines the architecture, API contracts, synchronization model and development rules for the **Aristocrats Hotel Booking System** (`arc-hotel-core` plugin + ASP.NET Hotel API).

> **READ THIS FIRST — how this file is organised**
>
> - **Part A** = the CURRENT, implemented API. Verified against `hotel-all-Api-End-point.json` (OpenAPI 3.0.1, v1). Code may rely on it.
> - **Part B** = REQUIRED backend changes. **NOT implemented yet.** Never write code that assumes any Part B feature exists.
> - **Part C** = WordPress plugin rules, including interim workarounds that are valid until Part B ships.
>
> If this file and the OpenAPI JSON ever disagree, **the JSON wins** and this file must be corrected.

- **Base URL:** `https://hotelapi.dusrasoftltd.com`
- **Multi-tenancy:** `companyCode` (e.g. `GPH01` Grand Palace, `SGL02` Seagull Resort) / `tenantId`.
- **Standard wrapper:** `{ "succeeded": bool, "message": string, "errors": [string], "data": ... }`

---

# PART A — CURRENT API (IMPLEMENTED)

## A1. The 3-Tier Architecture

All three tiers use ONE central ASP.NET REST API and database (single source of truth). No offline components.

```
 ┌────────────────────────────────────────────────────────────────────────┐
 │                      Central ASP.NET REST API & DB                     │
 └───────▲────────────────────────────────▲────────────────────────▲──────┘
         │ Bearer JWT                     │ Bearer JWT             │ NO auth today
         │ (POST /api/Auth/login)         │ (POST /api/Auth/login) │ (X-Api-Key = Part B)
 ┌───────▼─────────────┐   ┌──────────────▼───────────┐   ┌────────▼──────────────┐
 │ 1. Super Admin      │   │ 2. Receptionist PMS      │   │ 3. Public Website     │
 │    Dashboard (ERP)  │   │    (Frontdesk POS/PMS)   │   │    (arc-hotel-core)   │
 └─────────────────────┘   └──────────────────────────┘   └───────────────────────┘
```

### Tier 1 — Super Admin Dashboard (verified endpoints only)

Role: master configuration (rooms, categories, pricing, amenities, images, hotel policies). **The website NEVER defines prices, descriptions or policies; it only consumes what the Admin configured.**

| Area | Endpoints that EXIST |
|---|---|
| Auth | `POST /api/Auth/login` (`companyCode`, `username`, `password` → JWT `token`) |
| Room types | `GET /api/Setup/roomtypes`, `POST /api/Setup/roomtypes`, `DELETE /api/Setup/roomtypes/{id}` |
| Amenities | `GET /api/Setup/amenities`, `POST /api/Setup/amenities`, `DELETE /api/Setup/amenities/{id}` |
| Branches / Floors | `GET/POST /api/Setup/branches`, `DELETE /api/Setup/branches/{id}`, `GET/POST /api/Setup/floors`, `DELETE /api/Setup/floors/{id}` |
| Rooms | `GET/POST/PUT /api/Rooms` (PUT has NO `{id}` in the path; id is in the body), `GET/DELETE /api/Rooms/{id}`, `POST /api/Rooms/with-amenities`, `PUT /api/Rooms/{id}/status?status=1..5`, `GET /api/Rooms/matrix` |
| Room images | `GET /api/v1/rooms/{roomId}/images`, `POST /api/v1/rooms/{roomId}/images` (multipart, auto WebP), `PUT /api/v1/rooms/{roomId}/images/{imageId}/primary`, `DELETE /api/v1/rooms/{roomId}/images/{imageId}` |
| Tenant / hotel policy | `GET/POST/PUT /api/Tenants` (includes `standardCheckInTime`, `standardCheckOutTime`, `gracePeriodMinutes`, `lateCheckOutPolicy`) |

> There is NO `PUT /api/Setup/roomtypes/{id}`, NO `PUT /api/Setup/amenities/{id}` and NO `PUT /api/Rooms/{id}`. Do not call them. Updating a room type is done by `POST /api/Setup/roomtypes` with the existing `id` in the body only if the backend upsert behaviour is confirmed; verify before relying on it.

### Tier 2 — Receptionist PMS (verified endpoints)

Role: walk-ins, check-in/out, room shifts, folios, cashier shifts, housekeeping, night audit.

- Bookings: `GET /api/Bookings`, `GET /api/Bookings/{id}`, `POST /api/Bookings/walkin`, `POST /api/Bookings/advance-reserve`, `POST /api/Bookings/cancel-reservation`, `POST /api/Bookings/{id}/checkin-reservation`, `POST /api/Bookings/{id}/checkout`, `POST /api/Bookings/{id}/shift`, `POST /api/Bookings/{id}/extend`, `POST /api/Bookings/night-audit`
- Registration card: `GET /api/Bookings/{id}/reg-card`, `GET /api/Bookings/reg-card/by-token/{token}`, `POST /api/Bookings/save-signature`
- Folios: `GET /api/Folios/all`, `GET /api/Folios/booking/{bookingId}`, `GET /api/Folios/booking/{bookingId}/all-folios`, `POST /api/Folios/transactions`, `/payments`, `/post-charge`, `/discount`, `/split`, `/transfer-charge`
- Cashier shifts: `GET /api/Shifts/current`, `/summary`, `/history`, `POST /api/Shifts/open`, `/close`
- Other: `/api/Guests*`, `/api/Housekeeping*`, `/api/Inventory*`, `/api/Maintenance*`, `/api/Keycards*`, `/api/Ota*`, `/api/Reports/*`, `/api/Dashboard/stats`, `/api/ExchangeRates`, `/api/AuditLogs`
- POS (restaurant): `GET/POST/PUT /api/POS/menu`, `DELETE /api/POS/menu/{id}`, `POST /api/POS/order`, `GET /api/POS/room-history/{bookingId}`, `GET /api/POS/orders-by-date`

**Current limitation:** `Booking` and `Payment` schemas have NO `source` and NO `externalReference` field. Website reservations cannot be labelled "Website" on the receptionist screen, and online payments cannot be separated from the physical cash drawer. See Part B.

### Tier 3 — Public Website (`arc-hotel-core`)

Role: showcase rooms, live availability search, WooCommerce checkout, send confirmed bookings to the API.

## A2. The 6 Public Booking Endpoints (current contract)

Tag `PublicBooking`. **No authentication of any kind is enforced or documented today** (global `Bearer` scheme exists but these endpoints declare no requirement-specific key).

### 1) Hotel info
`GET /api/v1/public-booking/hotel-info?companyCode=`
→ `WebHotelInfoDto`: `hotelName`, `companyCode`, `contactPerson`, `phone`, `email`, `address`, `standardCheckInTime` (e.g. `"14:00"`), `standardCheckOutTime` (e.g. `"12:00"`).

### 2) Room categories
`GET /api/v1/public-booking/room-categories?companyCode=`
→ array of `WebRoomCategoryDto`: `roomTypeId`, `categoryName`, `description`, `basePricePerNight`, `maxAdults`, `maxChildren`, `totalRooms`, `availableRoomsNow`, `thumbnailUrl`, `imageUrls[]`, `amenities[]`.
- No filter parameters. A Single Room page must fetch the list and select by `roomTypeId` on the server (cache the result, see C3).
- `availableRoomsNow` is "right now", NOT for a date range. Never use it for date-based availability.

### 3) Available rooms (overlap checker)
`GET /api/v1/public-booking/available-rooms?checkIn=&checkOut=&roomTypeId=&adults=&companyCode=`
→ array of `WebAvailableRoomDto`: `roomId`, `roomNumber`, `roomTypeId`, `roomTypeName`, `description`, `floorName`, `buildingName`, `pricePerNight`, `nights`, `totalPrice`, `maxAdults`, `maxChildren`, `thumbnailUrl`, `imageUrls[]`, `amenities[]`.
- `roomTypeId` optional. There is NO `children` parameter: validate children against `maxChildren` in PHP.
- `nights` and `totalPrice` are computed by the API.
- This returns PHYSICAL rooms (one row per room), not categories.

### 4) Create reservation
`POST /api/v1/public-booking/create-reservation-json` (JSON; form variant `/create-reservation`)
`WebBookingRequest`:
- Required: `checkInDate`, `checkOutDate`, `fullName`, `phone`
- Stay: `companyCode`, `roomTypeId`, `roomId` (`0` = auto-assign best room of that type), `adults` (default 2), `children` (default 0), `specialRequests`
- Guest: `email`, `nationalIdOrPassport`, `address`
- Money: `promoCode`, `discountAmount`, `paymentOption` (`"PayAtHotel"` | `"Deposit"` | `"FullPayment"`), `paymentMethod` (int 1..6), `amountPaid`, `transactionReference`, `paymentRemarks`
→ `WebBookingConfirmationDto`: `bookingId`, `bookingNumber` (e.g. `WEB-20261028-1234`), `companyCode`, `hotelName`, `registrationToken`, `status`, guest fields, `roomNumber`, `roomType`, dates, `totalNights`, `ratePerNight`, `subTotal`, `discountAmount`, `totalAmount`, `paidAmount`, `dueAmount`, `paymentStatus`, `confirmationMessage`, `createdAt`.
- There is NO `externalReference` and NO `expectedTotalAmount` field today.

### 5) Track reservation
`GET /api/v1/public-booking/track/{referenceOrPhone}`
- Accepts a `bookingNumber` OR a guest phone number.
- → array of `WebBookingTrackDto` (bookingId, bookingNumber, status, guest name/phone/email, room, dates, amounts, paymentStatus, specialRequests, bookingDate).
- **SECURITY DEFECT:** phone-only lookup exposes guest PII to anyone. Do NOT expose this endpoint directly in browser JS. See B5 and C5.

### 6) Cancel reservation
`POST /api/v1/public-booking/cancel-reservation-json` (form variant `/cancel-reservation`)
`WebCancelReservationRequest`: `bookingNumber` (required), `phone` (required, must match), `reason`. → `BooleanApiResponse`.

## A3. Enumerations (verified)

| Enum | Values |
|---|---|
| `PaymentOption` (string) | `"PayAtHotel"` (amountPaid = 0), `"Deposit"` (0 < amountPaid < total), `"FullPayment"` (amountPaid = total) |
| `PaymentMethod` (int) | 1 Cash, 2 Card, 3 bKash, 4 Nagad, 5 Bank, 6 Other. Labels are inferred from `CashierShift` / `PaymentCollectionSummaryDto` field names; the OpenAPI enum itself is just `1..6`. Confirm with the backend team. |
| `BookingStatus` | 1..5 (Reserved, Checked-In, Checked-Out, Cancelled, No-Show: inferred ordering, confirm) |
| `RoomStatus` | 1..5 (Available, Occupied, Reserved, Dirty, Maintenance: inferred from `DashboardStats`, confirm) |
| `HousekeepingStatus` | 1..4 |
| `FolioTransactionType` | 1..10 |
| `RoomTypeId` | Demo data only: 1 Executive Suite, 2 Deluxe Couple, 3 Standard Twin. **Never hard-code. Always fetch from `room-categories`.** |

## A4. Digital registration card (optional pre-check-in)
- `GET /api/Bookings/reg-card/by-token/{token}` → `RegistrationCardDto`
- `POST /api/Bookings/save-signature` body `{ "bookingId": int, "signatureDataUrl": "data:image/png;base64,..." }`
- Both are token/id based and appear unauthenticated in the spec. Treat the `registrationToken` as a secret (never log it, never put it in URLs shared publicly).

## A5. Page → Data Mapping (Admin → Website)

| Page / Component | Source | Fields |
|---|---|---|
| Home room cards | `room-categories` | `categoryName`, `thumbnailUrl`, `basePricePerNight`, `maxAdults`, `maxChildren`, `amenities`, link to single page |
| Archive (all rooms) | `room-categories` | full list, grid, filter by guests/price in PHP, CTA |
| Single room page | `room-categories` (select by `roomTypeId` in PHP) | `categoryName`, `description`, `imageUrls[]`, `amenities[]`, capacities, `basePricePerNight`, live search widget |
| Live search results | `available-rooms` | `roomId`, `roomNumber`, `roomTypeId`, `pricePerNight`, `nights`, `totalPrice`, `imageUrls` |
| Review booking card | `available-rooms` + `hotel-info` | room details, check-in/out times, nights, total, guests |
| Header / footer | `hotel-info` | name, phone, email, address |

---

# PART B — REQUIRED BACKEND CHANGES (NOT IMPLEMENTED)

**Status of every item below: MISSING in the current OpenAPI JSON.** The backend is owned by DUSRA Soft, so these are DUSRA tasks. Until each one ships, use the interim workarounds in Part C. Do not write PHP that depends on a Part B feature.

| # | Requirement | Why it matters | Current state |
|---|---|---|---|
| B1 | **Idempotency via `externalReference`** (WooCommerce order number, unique index per `companyCode`). Duplicate call returns HTTP 200 with the existing reservation. | Prevents duplicate bookings on retries / timeouts. | Field missing |
| B2 | **Atomic overlap protection.** Room check + insert in ONE DB transaction (Serializable or row lock). On conflict return HTTP `409` with `errorCode: "ROOM_NOT_AVAILABLE"`. | Zero double-booking between website and reception. | Not documented; only `200` responses in spec |
| B3 | **API key auth (`X-Api-Key`)** bound to `companyCode` on `create-reservation-json` and `cancel-reservation-json`. | Today anyone can create paid-looking bookings or cancel others. | Missing; only `Bearer` scheme exists |
| B4 | **Server-side price authority.** Server computes the total from Admin rates, ignores client `discountAmount`, accepts `expectedTotalAmount`, and returns `409 PRICE_MISMATCH` on difference. Promo codes validated server-side. | Client currently controls `discountAmount` and `amountPaid`. | Missing |
| B5 | **Private track endpoint.** Replace phone-only GET with `POST /api/v1/public-booking/track` requiring BOTH `bookingNumber` and `phone`; rate-limit it; never return the national ID. | Closes the PII leak. | Currently `GET .../track/{referenceOrPhone}` |
| B6 | **`source` field** (`"Website"`) on Booking and Payment. Receptionist screen shows the Website badge and `externalReference`. Website payments are excluded from `/api/Shifts` cash totals. | Cashier drawer must reflect only physical cash. | Missing on both schemas |
| B7 | **Machine-readable errors.** Use `400`, `401`, `409`, `422` with `errorCode` inside the standard wrapper. | PHP can branch on codes instead of parsing messages. | Not documented |
| B8 | **Cancellation rules server-side.** Verify phone, enforce cancellation policy window, define refund handling for paid bookings. | Avoid cancelling a paid booking without a refund record. | `Cancel` request has no refund fields on the public endpoint |
| B9 | **`children` parameter on `available-rooms`.** | Capacity check is done in PHP only today. | Missing |
| B10 | **Rate limiting + CORS allow-list** for public endpoints. | Abuse protection. | Unknown |

Target contract once B1-B4 ship (for planning only):

```json
{
  "companyCode": "GPH01",
  "externalReference": "WC-1234",
  "expectedTotalAmount": 9000,
  "checkInDate": "2026-11-01T14:00:00",
  "checkOutDate": "2026-11-03T12:00:00",
  "roomTypeId": 2,
  "roomId": 0
}
```
Header: `X-Api-Key: <key bound to companyCode>`

---

# PART C — WORDPRESS PLUGIN RULES

## C1. Language & style
1. Code comments, docstrings, variable names, commit messages: **English**.
2. User-facing discussion/explanations: **Bangla script (বাংলা অক্ষরে)**. Never Banglish.
3. Prioritise speed, security (nonce verification, sanitisation, escaping, hidden credentials) and enterprise coding standards. Point out architecture flaws and security risks proactively.

## C2. Hard restrictions
1. **No third-party hotel plugins.** Never reference WP Hotel Booking, `hb_room`, etc. All logic is custom PHP calling the ASP.NET API.
2. **Cart separation:** Hotel Rooms and Restaurant Food NEVER mix in one cart (`woocommerce_add_to_cart_validation` + `woocommerce_check_cart_items`, nonce-protected "Clear Cart").
3. **Price tampering prevention:** override the WooCommerce price in `woocommerce_before_calculate_totals` from the API-verified `totalPrice`; never trust browser values.
4. **Pre-payment verification:** in `woocommerce_after_checkout_validation`, call `available-rooms` once more; halt checkout if the room is gone.

## C3. API client rules
- All API calls are **server-side PHP** (`wp_remote_get` / `wp_remote_post`), never from browser JS. The API URL, `companyCode` and (future) `X-Api-Key` live in `wp-config.php` constants or an encrypted option, never in front-end output.
- Set explicit timeouts (e.g. 10-15 s) and handle `WP_Error`, non-200 and `succeeded: false`.
- Cache `hotel-info` and `room-categories` with transients (e.g. 5-15 min). **Never cache `available-rooms`** or any booking call.
- Validate `children <= maxChildren` and `adults <= maxAdults` in PHP before showing "Book Now".
- Always resolve `roomTypeId` dynamically; never hard-code 1/2/3.

## C4. WooCommerce flow (`arc_` system) — summary
Full detail lives in `project_master_architecture_rules.md`. Core sequence:
1. Live search → `available-rooms`.
2. "Book Now" → clear conflicting cart, add hidden room product with meta: `check_in_date`, `check_out_date`, `adult_qty`, `child_qty`, `arc_room_type_id`, `arc_api_room_id`, `arc_api_rate_per_night`, `arc_api_total_price`.
3. Cart price override from `arc_api_total_price`.
4. Room checkout (OTA style): hide address fields, collect name, phone (8-15 digits), email, mandatory NID/passport (`arc_id_number`), extra guests, bed type, arrival time, requests, mandatory terms. CSS scoped to room carts only.
5. Pre-payment overlap check (C2.4).
6. Final sync on `woocommerce_payment_complete` / `woocommerce_order_status_processing`, guarded by `_arc_api_synced`.
7. Store order meta (`_arc_aspnet_booking_id`, `_arc_aspnet_reservation_no`, `_arc_aspnet_room_number`, `_arc_aspnet_reg_token`, etc.), show confirmation card, email with NID masked.

Payment mapping for the sync payload:
- Pay at hotel → `paymentOption: "PayAtHotel"`, `paymentMethod: 1`, `amountPaid: 0`
- Partial online → `"Deposit"`, method from gateway (2/3/4/5/6), `amountPaid` = paid amount
- Full online → `"FullPayment"`, `amountPaid` = order total
- `transactionReference` = gateway TrxID.

## C5. Interim workarounds (valid ONLY until the matching Part B item ships)

| Gap | Interim handling in WordPress |
|---|---|
| B1 no `externalReference` | Send the WooCommerce order number as `transactionReference` or prefix `paymentRemarks` with `WC-{order_id}` so reception can match it. Protect against double calls with `_arc_api_synced` PLUS a short-lived per-order lock (transient / `add_option` atomic lock) taken before the POST. On timeout, first query track by the returned/likely `bookingNumber` is not possible, so mark the order `_arc_api_sync_status = 'unknown'`, alert admin, and require a manual check before retrying. |
| B2 no atomic check | The pre-payment recheck (C2.4) reduces but cannot eliminate the race. Prefer a specific `roomId` over `0` at the final call, and send the final create immediately after payment success. Handle a failed create after a successful payment as a manual refund / reassign case with an admin alert. |
| B3 no API key | Keep the API URL and any gateway details out of public output; restrict the API at the server level (IP allow-list for the WordPress host if possible). |
| B4 no price authority | Send `discountAmount = 0` unless a WP-side coupon is applied, and cross-check API `totalAmount` vs the WooCommerce total after creation; log and alert on mismatch. |
| B5 open track | **Do not call the track endpoint from browser JS.** Build the "Track Booking" page as a server-side AJAX action that requires BOTH booking number and phone, verifies that the returned record's `phone` equals the submitted phone, rate-limits by IP, and returns only a minimal, non-PII subset. |
| B6 no `source` | Prefix `paymentRemarks` / `specialRequests` with `[WEBSITE WC-{id}]` so the receptionist can identify web bookings. Online payments cannot yet be excluded from cash-drawer totals automatically; flag this to the hotel's accountant. |
| B9 no `children` filter | Validate children in PHP against `maxChildren`. |
| `roomId = 0` ambiguity | The response contains the assigned `roomNumber`; always store it in `_arc_aspnet_room_number` and show it on the confirmation. |

## C6. Pre-commit review checklist
- [ ] No hard-coded room type IDs, prices, policies or credentials.
- [ ] Every AJAX handler: nonce check, capability/validation, sanitisation, escaped output.
- [ ] No API call from browser JS; no secret in page source.
- [ ] Booking sync is guarded against double submission.
- [ ] Mixed food + room cart is impossible.
- [ ] Hotel CSS and fields are scoped to room carts only.
- [ ] Code does not depend on any Part B feature unless that feature is confirmed live.

---

## Change log
- 2026-11-07: Rewrote against the OpenAPI JSON. Corrected the track endpoint (GET with path param, not POST). Removed non-existent Tier 1 `PUT` endpoints. Split implemented API (Part A) from required backend work (Part B). Added interim workarounds (C5) and security findings.
