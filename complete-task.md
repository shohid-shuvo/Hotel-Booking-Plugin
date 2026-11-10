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
├── documentsForAllMDfile/          # Master documentation & active WP snippets
│   ├── active-wordpress-snippets.md# EXACT Code Snippets running on live WordPress site
│   └── complete-task.md            # Mirror of this complete task guide
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
│   │   └── Room_Sync.php           # Syncs room categories, rates, images from API
│   │
│   ├── Settings/                   # Admin Settings & Cloud Health
│   │   ├── Settings.php            # Option getters/setters with defaults & validation
│   │   └── Settings_Page.php       # Settings UI, WooCommerce health check & manual sync buttons
│   │
│   └── Track/                      # Guest Self-Service Tracking
│       └── Track_Controller.php    # Handles booking lookup and cancellation
│
├── templates/                      # Frontend PHP Fallback Templates
│   ├── single-arc_room.php         # Single room template (supports Elementor Theme Builder do_location)
│   ├── archive-arc_room.php        # Room archive template (supports Elementor Theme Builder do_location)
│   └── checkout/
│       └── review-booking.php      # Review Booking summary card
│
├── assets/                         # Frontend CSS & JS Assets
│   ├── css/
│   │   ├── hotel-core.css          # Main styling for rooms, search widgets, cards
│   │   └── room-checkout.css       # OTA-style checkout styles
│   └── js/
│       ├── hotel-search.js         # Date calculation, fresh nonce retrieval
│       ├── room-checkout.js        # Checkout interaction scripts
│       └── thank-you-poll.js       # Real-time polling on thank-you page
│
└── tests/
    └── test-suite.php              # Automated test suite (60 test cases)
```

---

## 3. Mandatory Development Rules

1. **Language Rules:**
   - Discussions with the user MUST be written in **Bangla using Bengali script (বাংলা)**.
   - All code, comments, docstrings, and commit messages MUST be written strictly in **English**.
2. **Zero Third-Party Hotel Plugins:**
   - Do NOT use or reference third-party WordPress hotel plugins (`WP Hotel Booking`, `hb_room`, etc.). All hotel logic is 100% custom PHP talking directly to the ASP.NET REST API.
3. **Cart Separation:**
   - Hotel room bookings and Restaurant food orders must never coexist in the same WooCommerce cart.
4. **Server Price Authority:**
   - The website never dictates room prices. Prices are governed strictly by the central API (`pricePerNight` and `totalPrice`).
5. **Checkout Pre-Payment Guard:**
   - Pre-payment verification check (`available-rooms`) at `woocommerce_after_checkout_validation` prevents double-booking before payment gateway redirection.

---

## 4. Single Room Booking & Direct Checkout Flow

The booking flow operates as follows:
1. **User visits Single Room Page:** (Rendered either via Elementor Theme Builder Single Post template or `templates/single-arc_room.php`).
2. **Widget `[arc_room_booking]`:** Renders check-in date, check-out date, adults, and children dropdowns.
3. **User Clicks "Book Now • Proceed to Checkout":**
   - Submits to `admin-post.php` (`action = arc_book_room`).
   - `Room_Booking_Form::handle()` verifies nonces (supports both logged-in and guest cached contexts).
   - Initializes WooCommerce Cart & Session in admin context (`wc_load_cart()`).
   - Calls API `api/v1/public-booking/available-rooms` to verify availability and retrieve official rates.
   - Empties existing cart items (strict separation).
   - Adds the virtual container product (`_is_hotel_room = yes`) with `$booking_data`.
   - Redirects directly to `wc_get_checkout_url()`.
4. **WooCommerce Checkout Page:**
   - If the custom Code Snippet (`arc_cfg`) is active in WordPress, `arc-hotel-core` yields gracefully to let the snippet render its OTA checkout layout and custom fields.
   - User enters guest details (NID/Passport, requests, arrival time).
   - Selects payment method (Pay at Hotel / bKash / Card) and clicks "Confirm Booking".
5. **Post-Payment:**
   - `Reservation_Sync.php` calls `api/v1/public-booking/create-reservation-json`.
   - Order metadata updated with ERP reservation number.

---

## 5. Active WordPress Code Snippets (WPCode / Code Snippets)

The client runs custom PHP snippets in their WordPress environment via the **WPCode** / **Code Snippets** plugin. Full code and documentation are saved in:
👉 [`documentsForAllMDfile/active-wordpress-snippets.md`](./documentsForAllMDfile/active-wordpress-snippets.md)

Key Highlights:
1. **Aristocrats – Professional Conditional Checkout:** Handles OTA-style checkout fields, cart conflict rules (food vs room), Fare Summary, Important Information box, and custom checkout styling (`#place_order { background: #fcc800; ... }`).
2. **Compatibility with `arc-hotel-core`:** `Checkout_Fields.php` automatically detects `function_exists('arc_cfg')` to yield and avoid duplicating cards or checkout fields, preventing PHP fatal errors and maintaining smooth checkout flow.
3. **Revisions Limit Snippet:** Limits post revisions to 3 to prevent database bloating on shared hosting (`wuaze.com`).

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
