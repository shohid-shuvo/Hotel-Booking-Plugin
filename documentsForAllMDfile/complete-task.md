# Master Project Guide & AI Context: Aristocrats Hotel Booking System (`arc-hotel-core`)

> **Note for AI Models (Claude Code, Gemini, ChatGPT, etc.):**
> Read this document completely before writing or editing code. It explains the entire architecture of `arc-hotel-core`, the completed modules, the data flow, the live API contracts, and the current implementation status of the **Elementor Theme Builder + WooCommerce Single Room Booking & Conditional Checkout Flow**.

---

## 1. Project Overview & Ecosystem

`arc-hotel-core` is a custom WordPress & WooCommerce plugin that turns WordPress into the public-facing booking engine for a hotel, integrating directly with a central multi-tenant ASP.NET Hotel ERP API (**Smart Hotel Management POS ERP**).

### 3-Tier Unified Architecture
1. **Super Admin Dashboard (ERP):** Admins manage room types, base rates, amenities, photos, and policies via central REST API (`/api/Setup/roomtypes`, `/api/Rooms`, etc.).
2. **Receptionist Software (Frontdesk PMS):** Staff handle walk-ins, check-in, check-out, room switches, and folios (`/api/Bookings/*`).
3. **Public Website Frontend (This Plugin - `arc-hotel-core`):**
   - **Catalog & Archive:** Consumes synced room categories.
   - **Single Room View:** Detailed room category view (Elementor Theme Builder compatible, galleries, amenities, live booking widget).
   - **Live Booking & Checkout:** Real-time availability check, WooCommerce direct checkout, payment processing, and post-payment reservation synchronization.
   - **Guest Portal:** Real-time booking tracking and self-cancellation (`[arc_track]`).

---

## 2. Directory Structure & File Inventory

```text
sdl-hotel-booking/
├── arc-hotel-core.php              # Main bootstrap file, plugin headers, constants
├── uninstall.php                   # Cleanup routine on plugin uninstall
├── AGENTS.md                       # Core AI rules and developer contracts
├── complete-task.md                # THIS FILE: Complete context & task roadmap
│
├── includes/
│   ├── Plugin.php                  # Main Singleton orchestrator; loads all modules
│   │
│   ├── Admin/                      # WordPress Admin UI & HPOS
│   │   ├── Log_Page.php            # Diagnostic sync logs (developer-gated & endpoint masked)
│   │   ├── Notices.php             # Admin warning notices
│   │   ├── Order_Meta_Box.php      # HPOS-compatible hotel booking meta box on order edit
│   │   ├── Orders_Columns.php      # Adds "Type / Sync Status" column to Orders list
│   │   └── Action_Scheduler_Status.php # Monitors overdue Action Scheduler background tasks
│   │
│   ├── Api/                        # Central REST API Communication Gateway
│   │   ├── Api_Client.php          # wp_remote_get/post wrapper with timeout & SSL handling
│   │   ├── Api_Cache.php           # Transient cache helper for read endpoints
│   │   ├── Api_Logger.php          # Database logger (wp_arc_api_log) with PII redaction & endpoint masking
│   │   ├── Api_Mock.php            # Mock fixtures provider when ARC_API_MOCK is true
│   │   └── Api_Result.php          # Standardized response wrapper (ok(), data(), message(), code())
│   │
│   ├── Booking/                    # Post-Payment Sync & Reconciliation
│   │   ├── Reservation_Sync.php    # Creates reservation via API after payment; handles retries & polling
│   │   └── Reconcile.php           # Daily cron reconciliation of bookings against API
│   │
│   ├── Cache/                      # Full-Page Cache Compatibility (LiteSpeed, WP Rocket)
│   │   └── Cache_Safety.php        # Anti-cache headers, REST routes (/arc/v1/room-availability, /arc/v1/fresh-nonces)
│   │
│   ├── Cart/                       # WooCommerce Cart Isolation & Pricing Authority
│   │   └── Cart_Guard.php          # Prevents mixing hotel rooms with restaurant food; enforces API price authority
│   │
│   ├── Checkout/                   # Scoped Checkout Fields & Double-Booking Protection
│   │   ├── Checkout_Fields.php     # Adds NID/Passport, Arrival Time, Bed Preference; hooks Review Booking Card
│   │   └── Checkout_Validation.php # Pre-payment validation (woocommerce_after_checkout_validation) re-checks availability
│   │
│   ├── Elementor/                  # Elementor & Shortcode Integrations
│   │   ├── Elementor_Init.php      # Elementor category & dynamic tag / widget registration
│   │   ├── Shortcodes.php          # [arc_hotel_info], [arc_rooms], [arc_search], [arc_results], [arc_track]
│   │   ├── Room_Booking_Form.php   # [arc_room_booking] Single room booking form & admin_post direct checkout handler
│   │   └── Widgets/
│   │       ├── Widget_Room_Booking.php # Elementor Widget for Single Room Booking Card
│   │       └── Widget_Room_Grid.php    # Elementor Widget for Room Catalog Grid
│   │
│   ├── Emails/                     # Email Notifications
│   │   └── Email_Manager.php       # Sends urgent admin alerts when reservation sync fails
│   │
│   ├── Payment/                    # WooCommerce Payment Gateway Management
│   │   ├── Gateway_Filter.php      # Filters payment gateways specifically enabled for hotel rooms
│   │   ├── Gateway_Pay_At_Hotel.php# Custom "Pay at Hotel / Reception Desk" gateway
│   │   └── Payment_Mapper.php      # Maps WooCommerce payment gateways to ERP API paymentMethod flags
│   │
│   ├── Rooms/                      # Custom Post Type & Inventory Sync
│   │   ├── Room_Post_Type.php      # Registers `arc_room` CPT and `arc_room_type` taxonomy
│   │   └── Room_Sync.php           # Synchronizes room categories from API into `arc_room` posts
│   │
│   ├── Settings/                   # Plugin Settings & Tenant Configuration
│   │   ├── Settings.php            # Option getter/setter helper
│   │   └── Settings_Page.php       # Admin settings page (Company Code, stay limits, currency, cache flush)
│   │
│   └── Track/                      # Guest Self-Service Tracking & Cancellation
│       └── Track_Controller.php    # Handles AJAX tracking by Booking No + Phone, and cancellation
│
├── templates/                      # Frontend PHP Templates
│   ├── archive-arc_room.php        # Default archive fallback for hotel rooms
│   ├── single-arc_room.php         # Default single room fallback
│   └── checkout/
│       └── review-booking.php      # OTA-style booking review summary card for WooCommerce checkout
│
├── assets/                         # CSS & JavaScript
│   ├── css/
│   │   ├── hotel-core.css          # Main styling for room grids, badges, widgets
│   │   └── room-checkout.css       # OTA review card & scoped checkout styling
│   └── js/
│       ├── hotel-search.js         # Datepicker validation, stay duration limits, fresh nonces
│       ├── room-checkout.js        # Instant client validation for NID/Passport & Phone
│       └── thank-you-poll.js       # Asynchronous confirmation polling on order received page
│
└── tests/                          # Automated PHP Unit Test Suite
    ├── test-suite.php              # Automated test runner (60 tests passed)
    └── fixtures/                   # JSON fixtures for mock mode
```

---

## 3. How the Core Architecture Works

### A. The Hidden Virtual Product Container
WooCommerce requires a product in the cart to process checkout. We do **not** create individual WooCommerce products for each room type.
- On activation, `Plugin::ensure_room_container_product()` automatically creates a hidden virtual product named **"Hotel Room Reservation"** (`_is_hotel_room = 'yes'`).
- The product ID is stored in `get_option('arc_room_product_id')`.
- When a guest books a room, this virtual product is placed into the WooCommerce cart with custom cart item data containing both `arc_room` and `_arc_booking`:
  ```php
  $cart_item_data = [
      '_is_hotel_room' => 'yes',
      'arc_room'       => [
          'room_type_id'   => $room_type_id,
          'room_type_name' => $room_type_name,
          'room_post_id'   => $post_id,
          'room_id'        => $room_id,
          'room_number'    => $room_number,
          'check_in'       => $check_in,       // 'YYYY-MM-DD'
          'check_out'      => $check_out,      // 'YYYY-MM-DD'
          'nights'         => $nights,
          'adults'         => $adults,
          'children'       => $children,
          'rate_per_night' => $rate_per_night, // float from API
          'total_price'    => $total_price,    // float from API
          'thumbnail_url'  => $thumbnail_url,
      ],
      '_arc_booking'   => [ ...same... ] // preserved for order meta
  ];
  ```

### B. Cart Guard & Strict Price Authority (`Cart_Guard.php`)
1. **Cart Isolation:** Hotel bookings and restaurant food/merchandise orders can never coexist. If a user adds a room to a cart with restaurant items, the cart is emptied or blocked.
2. **Price Authority:** The website front-end never dictates room pricing. In `woocommerce_before_calculate_totals`, `Cart_Guard::override_cart_price` reads `arc_room['total_price']` and sets `$cart_item['data']->set_price( $total_price )`.

### C. Scoped Checkout & Pre-Payment Anti-Double-Booking Guard
1. **Review Booking Card:** Automatically hooked via `woocommerce_before_checkout_form` by `Checkout_Fields::render_booking_review_card()`, displaying `templates/checkout/review-booking.php`.
2. **Scoped Fields (`Checkout_Fields.php`):** NID/Passport (`arc_id_number`), Arrival Time (`arc_arrival_time`), and Bed Preference (`arc_bed_type`) only display when `_is_hotel_room` is in the cart.
3. **Checkout Guard (`Checkout_Validation.php`):** At `woocommerce_after_checkout_validation`, before redirection to payment gateways, the plugin makes a live API call to `/api/v1/public-booking/available-rooms`. If the room was booked in ERP seconds before, checkout is halted with an error message to prevent double-booking.

### D. Post-Payment Sync (`Reservation_Sync.php`)
- When WooCommerce marks the order `processing` or `completed` (or `on-hold` for Pay at Hotel), `Reservation_Sync::maybe_sync()` sends `WebBookingRequest` to `/api/v1/public-booking/create-reservation-json`.
- Idempotency is preserved using a transient lock (`arc_sync_lock_{order_id}`).
- If the API is momentarily unavailable, Action Scheduler retries with exponential back-off (1m, 5m, 15m, 60m, 180m).

---

## 4. Status Update: Single Room Booking Form (`Room_Booking_Form.php`)

### What Was Created & Fixed (Now Live in Repository)
The file `includes/Elementor/Room_Booking_Form.php` has been created, integrated, and verified against the actual codebase.

1. **Class Structure & Initialization:**
   - Class: `\ARC\HotelCore\Elementor\Room_Booking_Form`
   - Initialized in `includes/Plugin.php`:
     ```php
     Room_Booking_Form::init();
     ```
   - Registers shortcode `[arc_room_booking]` and actions `admin_post_arc_book_room` / `admin_post_nopriv_arc_book_room`.
   - `Shortcodes::render_single_room_booking()` and `Widget_Room_Booking.php` now delegate directly to `Room_Booking_Form::render()`.

2. **Accurate API Client Integration (`fetch_available`):**
   - Correctly instantiates `new Api_Client()`.
   - Calls instance method `$client->get( 'api/v1/public-booking/available-rooms', ... )` with `[ 'use_cache' => false ]`.
   - Correctly passes ISO timestamps: `$check_in . 'T14:00:00'` and `$check_out . 'T12:00:00'`.
   - Correctly uses methods `$result->ok()` and `$result->data()` on `Api_Result`.

3. **Cart Item Data Compatibility:**
   - Injects **both** `arc_room` and `_arc_booking` keys into `$cart_item_data`.
   - This ensures `Cart_Guard.php`, `Checkout_Fields.php`, `Checkout_Validation.php`, and `Order_Meta_Box.php` all recognize the item simultaneously without key mismatches.

4. **Cache & Nonce Resilience (LiteSpeed Cache / WP Rocket):**
   - The form renders `name="arc_book_nonce"`.
   - `Cache_Safety::rest_get_fresh_nonces()` has been updated to generate `arc_book_room` nonces.
   - `assets/js/hotel-search.js` automatically fetches fresh nonces via `/wp-json/arc/v1/fresh-nonces` on cached HTML pages and updates `input[name="arc_book_nonce"]`.
   - Form handler accepts both `arc_book_room` and `arc_add_room_to_cart` nonces.

5. **Dynamic Settings & UI:**
   - Respects max stay nights from `Settings::get( 'max_stay_nights', 30 )`.
   - Displays "Rates from" header using room category's `_arc_from_price`.
   - Clean datepickers, guest dropdowns, and "Book Now • Proceed to Checkout" button.
   - On submission: verifies dates -> queries API -> calculates price -> empties cart -> adds virtual room container product -> redirects directly to `wc_get_checkout_url()`.

---

## 5. Next Steps & Areas for Claude Code to Help

The core backend and WooCommerce booking bridge are now completely functional and tested (60/60 unit tests pass).

The remaining tasks for Claude Code / the developer are focused on **Theme Builder layout & frontend polish**:

1. **Elementor Theme Builder Single Room Template:**
   - Place either the shortcode `[arc_room_booking]` OR the Elementor widget **Single Room Booking Card** into the Single Room template (e.g. inside a sticky column).
   - Test submitting the form on the frontend to verify the smooth redirect to WooCommerce Checkout.

2. **Elementor Theme Builder Archive Page Template:**
   - Use `[arc_rooms]` or the **Room Catalog Grid** Elementor widget on the hotel archive page to display the synced room categories.

3. **Checkout Page Styling / Customization:**
   - Verify that the WooCommerce Checkout page displays the **Review Your Booking Card** (`templates/checkout/review-booking.php`) and the guest identification fields (NID/Passport, Arrival Time, Bed Preference).
   - Adjust CSS styling if needed to match the hotel website's branding.

4. **Testing Payment Gateways:**
   - Test a complete checkout using **Pay at Hotel / Reception Desk** or WooCommerce test gateway to confirm the order reaches WooCommerce and queues for ERP sync.

---

## 6. How to Run Automated Verification
Whenever modifying PHP files, verify that all unit tests pass:
```bash
php tests/test-suite.php
```
Ensure syntax is clean:
```bash
php -l includes/Elementor/Room_Booking_Form.php
```

---

## 7. Active WordPress Code Snippets (WPCode / Code Snippets)

The client runs custom PHP snippets in their WordPress environment via the **WPCode** / **Code Snippets** plugin. Full code and documentation are saved in:
👉 [`documentsForAllMDfile/active-wordpress-snippets.md`](./active-wordpress-snippets.md)

Key Highlights:
1. **Aristocrats – Professional Conditional Checkout:** Handles OTA-style checkout fields, cart conflict rules (food vs room), Fare Summary, Important Information box, and custom checkout styling (`#place_order { background: #fcc800; ... }`).
2. **Compatibility with `arc-hotel-core`:** `Checkout_Fields.php` automatically detects `function_exists('arc_cfg')` to yield and avoid duplicating cards or checkout fields, preventing PHP fatal errors and maintaining smooth checkout flow.
3. **Revisions Limit Snippet:** Limits post revisions to 3 to prevent database bloating on shared hosting (`wuaze.com`).

