# ASP.NET Hotel API — Required Updates for Website & 3-Tier Sync

**Target Audience:** Backend API Developer / Solution Architect  
**From:** WordPress Frontend Team (`arc-hotel-core`)  
**Based on:** `agent-instructions/hotel-all-Api-End-point.json` (Swagger Audit)  
**Core Principle:** **Centralize all business rules and inventory authority in the API.** The Admin Dashboard manages setup; Receptionist PMS manages operations; the Website only showcases and accepts guest bookings. Everything runs 100% online in real time.

---

## 1. Executive Summary & 3-Tier Ecosystem Architecture

The hotel operates on three synchronized interfaces backed by a single central ASP.NET REST API and database:

1. **Super Admin Dashboard (ERP):** Admins configure room types, base rates, amenities, photos, and policies (`/api/Setup/roomtypes`, `/api/Rooms`, etc.).
2. **Receptionist Software (Frontdesk PMS):** Staff handle live reservations, walk-ins, check-in, check-out, room shifts, and cashier shifts (`/api/Bookings/*`, `/api/Shifts/*`).
3. **Public Website Frontend (arc-hotel-core):** Dynamically consumes Admin-configured data to render:
   - **Home Page:** Featured room cards (`room-categories`).
   - **Archive Page:** Catalog of all available room categories.
   - **Single Room Page:** Detailed room type view (gallery, amenities, descriptions, live booking widget).
   - **Live Search & Booking:** Date range search (`available-rooms`) and WooCommerce checkout (`create-reservation-json`).

---

## 2. P0 — Critical Requirements (Required Before Live Launch)

The following items are strictly necessary to prevent double-booking, financial discrepancies, unauthorized API usage, and guest data exposure:

### 1. `externalReference` (Idempotency) in `WebBookingRequest`
- **Swagger Status:** In `components.schemas.WebBookingRequest`, `additionalProperties: false` is set and NO `externalReference` or `orderId` field exists.
- **Requirement:** Add `externalReference` (string, max 100, nullable) to `WebBookingRequest`. This field must be indexed and unique per tenant (`companyCode`).
- **Behavior:** If a request with the same `externalReference` is received again (e.g., payment gateway callback retry or user refresh), the API must return the **original booking record (`HTTP 200`)** instead of inserting a duplicate reservation.
- **Impact if missing:** Payment gateway retries or network timeouts will create multiple duplicate reservations in the hotel database for a single guest payment, blocking extra rooms unnecessarily.

### 2. API Key Authentication (`X-Api-Key`) on Public Write Endpoints
- **Swagger Status:** `/api/v1/public-booking/create-reservation-json` and `cancel-reservation-json` have `security: undefined`.
- **Requirement:** Enforce a header-based `X-Api-Key` on all public write endpoints (`create-reservation`, `create-reservation-json`, `cancel-reservation`, `cancel-reservation-json`).
  - The API key must be strictly bound to a single `companyCode` (tenant). A mismatched key and `companyCode` must return `HTTP 401 Unauthorized`.
- **Impact if missing:** Anyone inspecting network traffic or reading the public Swagger UI can execute POST requests directly against the API, creating fake reservations without paying or cancelling real guest bookings.

### 3. Atomic Overlap Protection in `create-reservation`
- **Swagger Status:** Swagger has no room lock or hold mechanism. Bookings are inserted directly.
- **Requirement:** When executing `create-reservation`, the calendar overlap check and the database insert must run within a **single atomic transaction** (Serializable isolation level / DB row lock).
  - If a conflict occurs (room already reserved for those dates), roll back and immediately return `HTTP 409 Conflict` with `errorCode: "ROOM_NOT_AVAILABLE"`.
- **Impact if missing:** If an online guest and a frontdesk receptionist confirm the same room at the exact same millisecond, both bookings will succeed, resulting in an overbooking disaster where two guests arrive for the same room.

### 4. Server-Side Price Authority (`expectedTotalAmount`) & Client Discount Removal
- **Swagger Status:** `WebBookingRequest` accepts `discountAmount` directly from the client (`"discountAmount": { "type": "number", "default": 0 }`) and has no price verification field.
- **Requirement:**
  - Ignore or remove client-supplied `discountAmount` in public booking requests. Discounts must only be generated via server-validated promo codes.
  - Add `expectedTotalAmount` (number) to `WebBookingRequest`. The backend must calculate `nights × ratePerNight` based on Admin-configured rates; if the server calculation differs from `expectedTotalAmount`, return `HTTP 409 Conflict` (`errorCode: "PRICE_MISMATCH"`).
- **Impact if missing:** Malicious users could tamper with browser payloads, sending `discountAmount: 9999` to book a 10,000 BDT room for 1 BDT.

### 5. Secure Track & Cancel Endpoints (PII Protection)
- **Swagger Status:** `GET /api/v1/public-booking/track/{referenceOrPhone}` is an anonymous GET endpoint that accepts a phone number and returns a full list (`WebBookingTrackDtoList`) of all bookings associated with that phone number, exposing names, emails, dates, and amounts.
- **Requirement:**
  - Deprecate phone-only lookups via GET.
  - Implement tracking as a POST request requiring both `bookingNumber` and `phone` in the request body:
    `POST /api/v1/public-booking/track { "bookingNumber": "...", "phone": "..." }`
  - In `cancel-reservation-json`, continue validating both `bookingNumber` and `phone` before allowing cancellation.
- **Impact if missing:** Anyone can query random phone numbers (e.g. `01711...`) via GET requests to scrape full personal identities, booking dates, and financial records of hotel guests (severe data privacy breach).

### 6. Separate Online Payments from Cashier Shifts (`CashierShift`)
- **Swagger Status:** `/api/Shifts` and `CashierShift` aggregate `bkashCollected`, `cardCollected`, `totalSystemAmount`, and `discrepancy`.
- **Requirement:** Bookings created through `public-booking` must be marked with `source = "Website"`. When computing active cashier shifts (`/api/Shifts/current`, `/api/Shifts/close`), exclude all transactions where `source == "Website"`.
- **Impact if missing:** Online payments (bKash/card gateway) will be mixed into the frontdesk receptionist's counter cash drawer report, causing an unavoidable cash discrepancy and audit failure during shift handovers.

### 7. Consistent Machine-Readable Error Codes & HTTP Statuses
- **Swagger Status:** Swagger only defines HTTP `200` responses with a generic `ApiResponse { succeeded, message, errors, data }`.
- **Requirement:** Return semantic HTTP status codes along with specific error codes inside `ApiResponse.errors`:
  - `HTTP 409 Conflict` -> `["ROOM_NOT_AVAILABLE"]` or `["PRICE_MISMATCH"]`
  - `HTTP 400 Bad Request` -> `["INVALID_DATES"]`
  - `HTTP 401 Unauthorized` -> `["INVALID_API_KEY"]`
  - `HTTP 422 Unprocessable` -> `["VALIDATION_ERROR: ..."]`
- **Impact if missing:** The website cannot distinguish between a taken room, invalid dates, or system failure, forcing generic error messages that confuse customers and lead to cart abandonment.

### 8. Dedicated Aristocrats Tenant & Staging Credentials
- **Swagger Status:** All default schemas are prefilled with `"GPH01"` (Grand Palace Hotel demo tenant).
- **Requirement:**
  - Create a dedicated tenant (`companyCode`) for Aristocrats.
  - Provide a staging environment with sample room types and rates for end-to-end integration testing.
  - Disable public Swagger UI on the production environment.
- **Impact if missing:** Live integration tests will pollute demo accounts, and an open Swagger UI on production exposes internal ERP endpoints.

---

## 3. Data Mapping for Website Pages (Admin -> Website)

Ensure that `GET /api/v1/public-booking/room-categories` returns all fields populated in the Admin Dashboard:
- `categoryName`: Displayed on Home page cards, Archive catalog, and Single Room title.
- `description`: Displayed on Single Room detail page.
- `basePricePerNight`: Displayed on room cards and fare calculations.
- `maxAdults` / `maxChildren`: Used for capacity badges and search filtering.
- `thumbnailUrl`: Used for room card thumbnails and review booking vouchers.
- `imageUrls[]`: Rendered as the photo gallery slider on the Single Room page.
- `amenities[]`: Rendered as feature icons/badges (e.g. WiFi, AC, Geyser, Balcony).

---

## 4. Summary Matrix for Backend Developers

| # | Item | Target Endpoint / Schema | Expected Action |
|---|---|---|---|
| **1** | Idempotency | `WebBookingRequest` | Add `externalReference` string; return existing booking on duplicate. |
| **2** | Write Auth | Public write endpoints | Require `X-Api-Key` header bound to tenant. |
| **3** | Overlap Lock | `create-reservation` | DB transaction with Serializable / Row Lock; return 409 on conflict. |
| **4** | Price Authority | `WebBookingRequest` | Add `expectedTotalAmount`; calculate price on server; ignore client discount. |
| **5** | Track Privacy | `track` endpoint | Require POST body with `bookingNumber` + `phone`. |
| **6** | Shift Separation | `/api/Shifts/*` | Tag booking as `source = "Website"`; exclude from cashier cash drawer. |
| **7** | Error Codes | `ApiResponse` wrapper | Return semantic HTTP status (409, 400, 401) and readable error codes. |
| **8** | Tenant Setup | Database / Server | Provision Aristocrats tenant, staging credentials, and secure production Swagger. |
