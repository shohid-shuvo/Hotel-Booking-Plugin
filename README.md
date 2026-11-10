# Aristocrats Hotel Core (`arc-hotel-core`)

Enterprise ASP.NET Hotel Management API integration for WordPress, WooCommerce, and Elementor Pro.

---

## 1. Server Cron Configuration (Recommended for Production)

To guarantee that real-time API retry queues, reconciliation crons, and Action Scheduler tasks execute with zero delay, disable default WordPress visitor-triggered cron and set up a true system cron.

### Step 1: Disable default WP-Cron in `wp-config.php`
Add the following line right before `/* That's all, stop editing! */`:
```php
define( 'DISABLE_WP_CRON', true );
```

### Step 2: Configure System Crontab (Linux / cPanel / Cloud)
Add a 1-minute system cron job using `crontab -e`:
```bash
* * * * * wget -q -O - https://your-hotel-domain.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```
*Or via CLI (preferred for low overhead):*
```bash
* * * * * php /var/www/html/wp-cron.php >/dev/null 2>&1
```

### Windows Server Task Scheduler
If running on Windows Server:
Create a task in Task Scheduler to run every 1 minute executing:
```cmd
C:\php\php.exe C:\path\to\wordpress\wp-cron.php
```

---

## 2. Full-Page Cache Exclusions (LiteSpeed, WP Rocket, WP-Optimize)

The plugin automatically sends `DONOTCACHEPAGE`, `DONOTCACHEDB`, `DONOTMINIFY`, and HTTP `no-store` headers on all live pages. However, to prevent edge-caching or CDN conflicts during heavy seasonal traffic, add the following paths to your cache plugin's **"Never Cache URLs"** list:

* `/room-search-results/(.*)`
* `/track-booking/(.*)`
* `/cart/(.*)`
* `/checkout/(.*)`
* `/checkout/order-received/(.*)`

---

## 3. Configuration Constants in `wp-config.php`

```php
// Production ASP.NET API Secret Key (Keep secret, never commit to git)
define( 'ARC_API_KEY', 'your-production-secret-api-key' );

// Offline Development / Staging Mock Mode (Serves local JSON fixtures)
// define( 'ARC_API_MOCK', true );
```

---

## 4. Architecture & Security Highlights

* **Single Source of Truth:** ASP.NET API is the sole authority for room availability, rates, and stay totals.
* **Strict Cart Separation:** Hotel room bookings and restaurant food orders cannot coexist in the same cart.
* **Anti-Double-Booking Guard:** Real-time overlap verification is executed immediately prior to payment authorization.
* **Idempotent Synchronization:** Atomic locking mechanism ensures that duplicate payment callbacks produce at most one reservation in the hotel software.
* **PII Redaction:** National ID, phone, email, and authentication secrets are permanently redacted in diagnostic log tables (`wp_arc_api_log`).
