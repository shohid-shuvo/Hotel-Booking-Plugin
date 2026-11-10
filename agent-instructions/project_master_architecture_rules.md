# Project Master Note: Aristocrats (Hotel Booking & Restaurant E-Commerce System)

## 1. Core Project Overview & Tech Stack
* **Project Name:** Aristocrats
* **Platform & Builder:** WordPress, Elementor Pro
* **E-Commerce & Payment Processor:** WooCommerce (Handles both Restaurant Food orders without live tracking and Hotel Room booking payments).
* **Hotel Backend Software:** Dedicated ASP.NET Hotel Management Software (`Smart Hotel Management API - POS ERP Architecture`) connected via REST API (`https://hotelapi.dusrasoftltd.com`).
* **Core Objective:** Zero conflict (no double-booking) between Receptionist software bookings and Website guest bookings in a 100% online real-time synchronized ecosystem.

## 2. AI Role & Strict Language Rules
* **Role:** 20+ Years Experienced Senior Lead Web Developer & Solution Architect.
* **Optimization & Security:** Every solution must prioritize speed optimization, security (nonce verification, data sanitization, escaping, hidden API credentials), and enterprise coding standards.
* **Proactive Code Review:** Immediately point out architectural flaws, security risks, or logic mistakes and provide the professional standard solution.
* **Language Rule (STRICT):**
  * All explanations, discussions, and guidance MUST be written in **Bangla using Bengali script (বাংলা অক্ষরে, যেমন: "আমি আপনাকে এভাবে সাহায্য করব")** — NEVER use Banglish (Latin script) for explanations.
  * All comments inside code blocks (PHP, JS, CSS) MUST be written in **English**.

## 3. Strict Plugin Restriction (NO Third-Party Hotel Plugins)
* **Zero Hotel Plugin Dependency:** *WP Hotel Booking* (`hb_room`) or any other WordPress hotel booking plugin MUST NOT be used or referenced anywhere in the codebase.
* **100% Custom PHP + Real ASP.NET API:** All room category showcases, real-time availability checks, dynamic pricing, pre-payment overlap verification, and final booking confirmations will communicate directly with the live ASP.NET PublicBooking API (`/api/v1/public-booking/...`).

## 4. Conflict-Free Technical Workflow (`arc_` System)
1. **Room Identification in WooCommerce:**
   * A single virtual/hidden WooCommerce container product (or category-mapped room products) marked with custom meta `_is_hotel_room = 'yes'` or term `hotel-rooms` in `product_cat` will be used for checkout.
2. **Step 1 — Live Availability & Pricing Search:**
   * Guest selects `checkIn`, `checkOut`, `adults`, `children`, and optional `roomTypeId` on the Elementor Pro frontend.
   * PHP/AJAX calls `GET /api/v1/public-booking/available-rooms` with `companyCode` (e.g., `GPH01`).
   * The API checks real-time calendar overlaps against active receptionist reservations and returns available rooms along with `roomId`, `roomTypeId`, `nights`, `pricePerNight`, and `totalPrice`.
3. **Step 2 — Add to Cart & Dynamic Price Override:**
   * Clicking "Book Now" clears any conflicting cart items and injects the room into the WooCommerce cart with custom item data:
     * `check_in_date`, `check_out_date`, `adult_qty`, `child_qty`
     * `arc_room_type_id` (`roomTypeId` from API)
     * `arc_api_room_id` (`roomId` from API, or `0` to auto-assign best room)
     * `arc_api_rate_per_night` (`pricePerNight` from API)
     * `arc_api_total_price` (`totalPrice` from API)
   * `woocommerce_before_calculate_totals` overrides the WooCommerce product price with `arc_api_total_price` so prices cannot be manipulated from the browser.
4. **Step 3 — Strict Cart Separation (Food vs. Room):**
   * Food items and Hotel Rooms cannot be mixed in the same cart.
   * `woocommerce_add_to_cart_validation` and `woocommerce_check_cart_items` block mixed carts and display an error notice with a nonce-verified one-click "Clear Cart" (`?arc_clear_cart=1`) button.
5. **Step 4 — Scoped Conditional Checkout Page:**
   * **Food Checkout Mode:** Standard WooCommerce layout with simplified fields (Full Name, Mobile Number, Email, District, Delivery Address). No hotel CSS or hotel labels are applied.
   * **Room Checkout Mode (OTA-Style):**
     * Hides physical billing/shipping address fields (`billing_address_1`, `billing_state`, `billing_country`, etc.).
     * Displays the **"Review Your Booking"** card (Hotel name, stars, room thumbnail, Check-in/Check-out dates & times, total nights badge, guest count, and cancellation policy).
     * Collects Primary Guest Full Name, Mobile Number (validated 8–15 digits), Email, compulsory **NID / Passport Number** (`arc_id_number`, validated via Regex), dynamic Additional Adult Guest Names (`arc_guest_2` ... `arc_guest_8`), Bed Type radio buttons (`arc_bed_type`), Estimated Arrival Time dropdown (`arc_arrival_time`), Additional Requests checkboxes (`arc_req_*`), Special Requests note, and mandatory Terms checkbox (`arc_terms`).
     * Custom Grid & Fare Summary CSS is strictly scoped to Room Checkout (`if ( arc_cart_has( 'room' ) )`) so Food Checkout UI never breaks.
6. **Step 5 — Pre-Payment Real-Time Overlap Verification (Anti-Double-Booking):**
   * During `woocommerce_after_checkout_validation` (right before redirecting to payment or placing the order), WordPress calls `GET /api/v1/public-booking/available-rooms` one final time.
   * If a receptionist booked that room while the guest was filling out the checkout form, checkout is halted with an immediate error message before charging the guest.
7. **Step 6 — Final Reservation Sync to ASP.NET API:**
   * Triggered on `woocommerce_payment_complete` and `woocommerce_order_status_processing` (protected by `_arc_api_synced === 'yes'` flag to prevent duplicate calls).
   * Sends JSON payload to `POST /api/v1/public-booking/create-reservation-json` combining guest info, dates, `roomTypeId`, `roomId`, `paymentOption` (`"PayAtHotel"` | `"Deposit"` | `"FullPayment"`), `paymentMethod` (`1..6`), `amountPaid`, `transactionReference`, and merged `specialRequests` (Bed Type + Arrival Time + Extra Guests + Checkboxes + Notes).
8. **Step 7 — Order Meta Storage, Emails & Confirmation Voucher:**
   * Stores local & API response metadata in the WooCommerce order:
     * `_arc_order_type` (`'room'` or `'food'`)
     * `_arc_booking` (Array: `check_in`, `check_out`, `adults`, `children`)
     * `_arc_id_number`, `_arc_guests`, `_arc_requests`, `_arc_bed_type`, `_arc_arrival`
     * `_arc_api_synced` (`'yes'`)
     * `_arc_aspnet_booking_id` (`bookingId` int from API)
     * `_arc_aspnet_reservation_no` (`bookingNumber` string from API, e.g., `WEB-20261028-1234`)
     * `_arc_aspnet_room_number` (`roomNumber` assigned by API)
     * `_arc_aspnet_reg_token` (`registrationToken` from API)
   * Displays the formatted booking summary and official ASP.NET `bookingNumber` on the Thank-You Confirmation Card, Customer Email (with NID masked), and Admin Order screen.

---

## 5. 3-Tier Live Sync Ecosystem & Dynamic Page Mapping

The system has **zero offline components**. All three interfaces communicate with the same central ASP.NET REST API:

```
  Super Admin Dashboard (ERP)  <───>  Central ASP.NET REST API & DB  <───>  Public Website (arc-hotel-core)
                                             ▲
                                             │
                                  Receptionist PMS (Frontdesk)
```

### Dynamic Frontend Page Generation (Admin -> Website)
All room presentations on the website are dynamically rendered using data created in the Admin Dashboard:
1. **Home Page (Featured Room Cards):**
   - Consumes `GET /api/v1/public-booking/room-categories`.
   - Displays `categoryName`, `thumbnailUrl`, `basePricePerNight`, `maxAdults`, `maxChildren`, and key `amenities`.
2. **Archive Page (All Room Types Catalog):**
   - Consumes `GET /api/v1/public-booking/room-categories`.
   - Grid layout of all active room types with guest filters and "View Details" / "Book Now" buttons.
3. **Single Room Page:**
   - Consumes `GET /api/v1/public-booking/room-categories` (filtered by `roomTypeId`).
   - Renders full `description`, dynamic photo gallery from `imageUrls[]`, complete feature list from `amenities[]`, capacity, pricing, and an embedded date search/booking widget.
4. **Live Booking Lifecycle:**
   - Website booking (`create-reservation-json`) appears instantaneously on the Receptionist PMS with `source: "Website"` and the WooCommerce `externalReference`.
   - When Receptionist checks in the guest (`/api/Bookings/{id}/checkin-reservation`), the status immediately updates to `CheckedIn` on the website's `track` endpoint.
   - Cashier shift calculations (`/api/Shifts`) ignore online payments so frontdesk physical drawer reconciliations remain accurate.
