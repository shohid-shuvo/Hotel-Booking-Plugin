# Aristocrats Hotel Core 1.1.0

Custom WordPress, WooCommerce and Elementor integration for the documented ASP.NET public hotel booking API.

## Requirements and update

- PHP 8.2+, WordPress 6.2+, WooCommerce 8.2+, classic shortcode checkout, BDT room-booking currency.
- Keep the installed **New PHP - Conditional checkout** v2 snippet active in PHP / Run Everywhere mode. Keep the old conditional checkout snippet disabled.
- Back up the current plugin files and WordPress database. Deactivate the old Aristocrats Hotel Core, upload the provided `arc-hotel-core-1.1.0.zip`, replace if WordPress offers an update, and activate one copy of version 1.1.0. Do not uninstall/delete the old copy during a folder migration: its uninstall routine removes shared configuration.
- Existing `arc_settings`, room posts, container product and WooCommerce order metadata are retained. New installs have no implicit demo tenant; configure the hotel's own Company Code. `ARC_COMPANY_CODE` and `ARC_API_BASE_URL` in wp-config.php remain supported. `ARC_API_KEY` is optional until the backend deploys key authentication.
- Clear WordPress/minification/CDN caches after replacing plugin assets. Test both food checkout and a room checkout, including guest names, requests, payment and its ERP reference.

## Booking safety

Booking metadata captures the configured company; old or mismatched checkout sessions require room reselection. One API quote resolver validates dates and capacity, selects the cheapest eligible physical room deterministically, and preserves the API's price and nights. Checkout and reservation preflight verify the exact room and current price. Hotel discounts that change the API room fare block checkout; restaurant checkout is unaffected. Separate WooCommerce gateway fees remain in WooCommerce and are not recorded as room payment in ERP.

Reservation writes use connection-owned MySQL/MariaDB `GET_LOCK` locks for the order and physical room. Checkout fails before payment if room verification locking is unavailable. The order saves `sending` before POST and schedules a recovery observer. Timeouts, 5xx, invalid JSON and interrupted/ambiguous responses become `unknown`, with an admin alert. They are never automatically reposted. Only failures of read-only availability checks receive bounded WP-Cron retries.

The payload contains only fields present in `WebBookingRequest`. `externalReference` and `expectedTotalAmount` are not sent. The WooCommerce marker is carried in `paymentRemarks` / `specialRequests`. Received API references are saved before checking dates, room, tenant, guest and amounts. Confirmed references prevent another POST even when the order requires review. COD / Pay at Hotel use `amountPaid = 0`; unpaid bank transfers, cancelled and refunded orders cannot create reservations.

For unknown/failed orders, use the order meta box: check the ERP, enter its existing booking number to link it, or leave the reference empty only after confirming no reservation exists. The confirmation checkbox is mandatory. Older uncertain retries are quarantined for this check after upgrade. Linking an existing reference requires exact phone, company and dates; a reference already linked to another order is rejected.

## Public tracking and privacy

Tracking uses the live **GET track/{referenceOrPhone}** endpoint server-side, with exact booking reference, full normalized phone and company verification. WordPress returns only room/stay/payment information. Cancellation uses a separate nonce, fresh ownership lookup, rate limits and one `cancel-reservation-json` POST; the original API phone is sent. A confirmed cancellation updates the matching local order. Refunds remain manual.

The tracking JavaScript is included, supports multiple forms and text-only response rendering, refreshes cached guest nonces, and requires a new lookup after cancellation uncertainty. Thank-you polling requires the order key in addition to its nonce and displays manual/unknown terminal states. Successful synchronization sends an official confirmation email with masked guest ID. Logs redact names, identity numbers, phone/email, signatures/tokens and free-form requests; legacy raw diagnostic payloads are cleared once during upgrade without removing log rows or orders.

## Cron and caches

WP-Cron is the only queue. Room catalog synchronization follows the saved hourly/twice-daily/daily/manual interval. Daily reconciliation processes paginated upcoming orders, refreshes assigned rooms and payment data, and flags missing/changed stay amounts or dates for review. It never auto-refunds or silently changes the order's paid total. Monitoring reports actual WP-Cron tasks.

For production, configure a system cron to trigger WordPress once a minute, for example:

```text
* * * * * php /path/to/wordpress/wp-cron.php >/dev/null 2>&1
```

Set `DISABLE_WP_CRON` only after the system cron is working. Exclude `/cart/`, `/checkout/`, `/room-search-results/` and `/track-booking/` from page/CDN caches. API availability and all mutations are never cached. Hotel profile/category caches are tenant-scoped; cache flushes invalidate persistent object-cache generations too.

## Verification and practical limits

```text
php tests/test-suite.php
php tests/test-mock-mode.php
php tests/test-checkout-snippet.php --plugin-first
php tests/test-checkout-snippet.php --snippet-first
php tests/test-plugin-safety.php
php tests/test-api-client.php
php tests/test-api-client.php --missing-company
php tests/test-api-client.php --unsafe-url
php tests/test-api-contract.php
```

New regressions call the actual core classes with fixture HTTP and isolated WordPress/database stubs. They cover write uncertainty, reentrant callbacks, eligibility, metadata, physical room selection, fees, cancellation ownership, order-key polling, more than 50 reconciled orders, cache behavior and insecure/unconfigured transports. Real WordPress/HPOS storage, MySQL parallel workers, payment gateways and live booking writes still require staging verification.

The backend still lacks documented idempotency, atomic cross-channel overlap protection, authenticated public writes and website-payment source separation. Local locks and preflight checks reduce website races; they do not lock the receptionist API or guarantee that a room remains available while a guest pays. Paid conflicts need room reassignment/refund review. No full payment/deposit, cancellation-window or cash-drawer guarantee is inferred from the pending backend requirements. Deposit collection is outside v1 scope.
