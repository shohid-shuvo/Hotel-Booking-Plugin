# ARC Hotel Core — আমাদের যা যা দরকার

> **ফ্রেমিং:** এই তালিকাটা ধরে নিচ্ছে **আজ যেসব endpoint বাস্তবে আছে, শুধু সেগুলোই** ভবিষ্যতেও থাকবে।
> ব্যাকএন্ড থেকে নতুন কিছু আসবে না — কোনো প্রতিশ্রুতি নেই। `hold` নেই, `release` নেই, idempotency key নেই,
> webhook নেই, `changes?since=` নেই, `expectedTotalAmount` নেই, `externalReference` নেই, cancellation-fee
> ফিল্ড নেই। নিচের সবকিছু **WordPress প্লাগইনের ভেতরেই**, বাস্তব ASP.NET API-এর বিরুদ্ধে সমাধান করতে হবে।
>
> ইমার্জেন্সি অনুযায়ী সাজানো: আজ সাইট লাইভ করতে হলে আগে P0, ঠিক এই ক্রমে।

---

## ০. বাস্তবে যে API সারফেসটুকু আছে (লাইভ Swagger দিয়ে যাচাই করা)

| মেথড | path | নোট |
|---|---|---|
| GET | `/api/v1/public-booking/hotel-info` | অ্যানোনিমাস। null হলে কিছু key বাদ পড়ে যায়। |
| GET | `/api/v1/public-booking/room-categories` | অ্যানোনিমাস। |
| GET | `/api/v1/public-booking/available-rooms` | অ্যানোনিমাস। **`children` ফিল্টার নেই।** |
| POST | `/api/v1/public-booking/create-reservation` | write। **idempotency সাপোর্ট নেই।** |
| POST | `/api/v1/public-booking/create-reservation-json` | write। একই সীমাবদ্ধতা। |
| GET | `/api/v1/public-booking/track/{referenceOrPhone}` | reference **বা** ফোন দিয়ে বুকিং পড়া যায়, অ্যানোনিমাস। |
| POST | `/api/v1/public-booking/cancel-reservation` | write। refund/charge তথ্য ফেরত দেয় না। |
| POST | `/api/v1/public-booking/cancel-reservation-json` | write। একই। |

**যা নেই (এগুলো ধরে ডিজাইন করা যাবে না):** room hold/lock, inventory release, idempotency key,
`externalReference` echo, `expectedTotalAmount` যাচাই, error code, pagination, webhook, event feed,
health probe, রুম-টাইপ অনুযায়ী লাইভ কাউন্ট, `children` প্যারামিটার, cancellation policy API।

---

## ১. P0 — লাইভ হওয়া আটকায় (টাকা, ওভারসেল, বুকিং দখল)

- [ ] **১.১ ভুয়া idempotency সরান।** `externalReference` ও `expectedTotalAmount` API চুপচাপ উপেক্ষা করে, তাই প্রতিটি retry একটা **আসল ডুপ্লিকেট রিজার্ভেশন** বানায়। দরকার লোকাল write-ahead ledger: `order_id → attempt_id → request hash → api_response_id`, POST করার **আগেই** সেভ হবে; আর প্রতিটি retry-এর আগে `track/{ref}` কল করে "আগেরটা ল্যান্ড করেছে কি না" মীমাংসা করার resolver।
- [ ] **১.২ sync lock সত্যিই atomic করুন।** `get_option()` + `update_option()` হলো read-then-write race — দুটো concurrent worker একসাথে পার হয়ে যায়। দরকার `add_option()` / `$wpdb->query( "INSERT IGNORE …" )` সেমান্টিক্স, expiry সহ।
- [ ] **১.৩ double-submit-এ double-charge বন্ধ করুন।** payment gateway callback + `woocommerce_order_status_processing` + manual retry — তিনটাই ফায়ার হতে পারে। দরকার একটাই order state machine (`pending → reserving → reserved | failed`), compare-and-swap দিয়ে এনফোর্সড।
- [ ] **১.৪ hold endpoint ছাড়াই ওভারসেল আটকান।** দুজন গেস্ট শেষ রুমটার জন্য পেমেন্ট করতে পারে। API-তে hold নেই, তাই শেষ কয়েক সেকেন্ডে প্লাগইনকেই serialise করতে হবে: `roomTypeId + checkIn + checkOut`-এ DB advisory lock, তারপর `available-rooms` আবার চেক, লকের ভেতরেই POST, শেষে lock release।
- [ ] **১.৫ "যে দাম দেখানো হলো = যে দাম চার্জ হলো = API-র দাম" নিশ্চিত করুন।** এখন কুপন কার্ট টোটালকে API টোটালের নিচে নামিয়ে দিতে পারে, তখন `Payment_Mapper` `FullPayment`-কে `Deposit` বানিয়ে ফেলে। দরকার একটা authoritative price function — search, cart, checkout, API payload সবাই একটাই ব্যবহার করবে; মিল না হলে অর্ডার reject।
- [ ] **১.৬ CSRF বাইপাস সরান।** `Cart_Guard::handle_book_room_action()`-এ শুধু WooCommerce session থাকলেই `$nonce_valid = true` হয়ে যায়। এটা কাজ করা CSRF ফাঁক — যেকোনো থার্ড-পার্টি পেজ ভিজিটরের কার্টে ইচ্ছেমতো রুম ঢোকাতে পারে। nonce বাধ্যতামূলক হতে হবে; একমাত্র ব্যতিক্রম `Cache_Safety`-এর fresh-nonce endpoint।
- [ ] **১.৭ ডিফল্টে আসল টেন্যান্ট কখনো শিপ করবেন না।** `company_code` ডিফল্ট `GPH01` (পাবলিক ডেমো টেন্যান্ট)। কনফিগার না করা ইনস্টল আসল বুকিং ডেমো ডেটাবেসে লিখে ফেলবে। অপারেটর টেন্যান্ট নিশ্চিত না করা পর্যন্ত hard-fail করতে হবে।
- [ ] **১.৮ `api_base_url`-এ HTTPS বাধ্যতামূলক করুন।** এখন কোনো scheme চেক নেই। http হয়ে গেলে বা টাইপো হলে গেস্টের নাম, ফোন, NID, পাসপোর্ট প্লেইনটেক্সটে চলে যায়।
- [ ] **১.৯ ক্যানসেলে auth গেট বসান।** `cancel-reservation` পাবলিক এবং শুধু reference নেয়; প্লাগইনের cancel AJAX-এ রেট লিমিট নেই, API-ও মালিকানার প্রমাণ চায় না। যে কেউ reference আন্দাজ করে বুকিং নষ্ট করতে পারে। দরকার: track-then-match (ফোন বুকিংয়ের সাথে মিলতে হবে) + কড়া রেট লিমিট + audit trail।
- [ ] **১.১০ লগে PII লিক বন্ধ করুন।** `Api_Logger` কিছু ফিল্ড রিড্যাক্ট করে কিন্তু `guestName` সেভ করে রাখে; আর `track/{phone}` ফোন নম্বর URL-এ পাঠায় — যেটা access log, proxy ও diagnostics টেবিলে জমা হয়।

---

## ২. P1 — গেস্ট যা টের পাবে

- [ ] **২.১ `children` মানুন।** API প্যারামিটারটা উপেক্ষা করে এবং `maxChildren: 0` রুম ফেরত দেয়, তাই ৪ জনের পরিবারকে ২ জনের রুম দেখানো হয়। `maxAdults`/`maxChildren` দিয়ে ক্লায়েন্ট সাইডে ফিল্টার করুন এবং সৎভাবে "কোনো রুম মিলছে না" দেখান।
- [ ] **২.২ অনির্দিষ্ট দাম ঠিক করুন।** একই রুম টাইপ একই রেসপন্সে **৮৫০০ এবং ৯৫০০ — দুই দামেই** এসেছে। স্পষ্ট নিয়ম দরকার (সর্বনিম্ন, বা stable-key অনুযায়ী প্রথম), এবং search, cart, checkout — তিন জায়গায় একই নিয়ম, নাহলে গেস্ট এক দাম দেখে আরেক দাম দেয়।
- [ ] **২.৩ `availableRoomsNow`-কে শুধু সাজসজ্জা ভাবুন।** এটা সব ক্যাটাগরিতে `totalRooms`-এর সমান, অর্থাৎ এটা ইনভেন্টরি নয়। "মাত্র ২টা বাকি" ব্যাজে বা availability সিদ্ধান্তে কখনো ব্যবহার করবেন না।
- [ ] **২.৪ বাদ পড়া nullable ফিল্ড নরমালাইজ করুন।** `description` ও `thumbnailUrl` **key-ই থাকে না**, null নয়। প্রতিটি read path-এ normaliser দরকার, নাহলে PHP warning আর টেমপ্লেটে খালি কার্ড।
- [ ] **২.৫ search results grouping ঠিক করুন।** এখন grouping/price resolution ভুল, তাই multi-night ও multi-room সার্চে ভুল টোটাল দেখায়।
- [ ] **২.৬ availability চেককে অর্থবহ করুন।** `Checkout_Validation` শুধু "টাইপটা আছে" নিশ্চিত করে আর **প্রথম** মিলে যাওয়া ররের দামের সাথে তুলনা করে। তারিখ, গেস্ট সংখ্যা এবং ঠিক ওই স্টের সঠিক দাম — সব যাচাই করতে হবে।
- [ ] **২.৭ ফিরে এলে cart আবার যাচাই করুন।** গেস্ট এক ঘণ্টা checkout পেজে বসে থাকতে পারে। তারিখ, দাম, availability — submit-এ আবার চেক করতে হবে, শুধু add-to-cart-এ নয়।
- [ ] **২.৮ হার্ডকোড করা হোটেল পরিচয় সরান।** `review-booking.php`-এ হোটেলের নাম, ৫ তারার গ্লিফ এবং ১৪:০০/১২:০০ সময় হার্ডকোড। চারটাই `hotel-info` বা সেটিংস থেকে আসা উচিত।
- [ ] **২.৯ রুম টোটালের বিরুদ্ধে কুপন আবার যাচাই করুন।** কুপন যেন কখনো রুম সাবটোটালকে API টোটালের নিচে নামাতে না পারে — clamp করুন, ব্লক করুন, বা নতুন করে quote নিন।

---

## ৩. P1 — অপারেশনাল নির্ভরযোগ্যতা

- [ ] **৩.১ HPOS query ঠিক করুন।** `wc_get_orders()`-এ `meta_key`/`meta_value` HPOS-এ উপেক্ষিত হয়, তাই reconcile আর failed-sync অ্যাডমিন নোটিশ আধুনিক স্টোরে **শূন্য** অর্ডার দেখে।
- [ ] **৩.২ একটাই scheduler বাছুন।** কোড `wp_schedule_single_event` (WP-Cron) ব্যবহার করে, অথচ README/TRD Action Scheduler বলে; তাই `Action_Scheduler_Status` ও `Notices` এমন কিউ মনিটর করে যা কখনো ব্যবহৃত হয় না। হয় migrate করুন, নাহলে মনিটরটা মুছে দিন।
- [ ] **৩.৩ retry সীমিত ও দৃশ্যমান করুন।** exponential backoff-এর কঠিন সীমা, dead-letter state এবং অ্যাডমিনে দৃশ্যমান কারণ থাকতে হবে — চুপচাপ `catch ( \Throwable )` নয়।
- [ ] **৩.৪ প্রমাণের ভিত্তিতে reconcile করুন, আশার ভিত্তিতে নয়।** API-তে `changes?since=` নেই, তাই প্রতিটি আসন্ন স্টের জন্য `track/{ref}` পোল করে লোকাল order meta-র সাথে মিলিয়ে দেখতে হবে।
- [ ] **৩.৫ দূরের বুকিং আছে কিন্তু লোকালে নেই** (POST আর save-এর মাঝে crash) এবং **উল্টোটাও** (লোকাল অর্ডার, রিমোট বুকিং নেই) — দুই দিকেরই repair action দরকার।
- [ ] **৩.৬ uninstall-এ পরিষ্কার করুন।** `arc_daily_reconcile` কখনো unschedule হয় না, `arc_lock_*` option কখনো ডিলিট হয় না।
- [ ] **৩.৭ cache hygiene।** `Api_Logger` **প্রতিটি** API কলে `SHOW TABLES` চালায়; উত্তরটা static/option-এ ক্যাশ করুন, আর production-এ পুরো payload লগ করা বন্ধ করুন।

---

## ৪. P2 — SaaS রূপান্তর (প্লাগইনকে প্রোডাক্ট বানানো)

- [ ] **৪.১ টেন্যান্ট আইসোলেশন।** প্রতিটি সেটিং, লগ রো, cache key ও cron সাইট/টেন্যান্ট অনুযায়ী namespace হবে। কোনো কিছুই একক হোটেল ধরে নিতে পারবে না।
- [ ] **৪.২ self-serve onboarding।** উইজার্ড: base URL + company code + API key নেবে, আসল `hotel-info` probe চালাবে, পাস না হলে বুকিং চালু করবে না।
- [ ] **৪.৩ remote configuration + kill switch।** ভেন্ডর যেন প্লাগইন রিলিজ ছাড়াই বুকিং গ্লোবালি (বা টেন্যান্ট অনুযায়ী) বন্ধ করতে পারে — যেমন ASP.NET API ডাউন হলে।
- [ ] **৪.৪ লাইসেন্সিং ও আপডেট চ্যানেল।** signed license check, update server, প্ল্যান অনুযায়ী feature flag (শুধু search vs full booking vs multi-property)।
- [ ] **৪.৫ white-label লেয়ার।** হোটেলের নাম, লোগো, তারকা রেটিং, সময়, রঙ ও ইমেইল ব্র্যান্ডিং কনফিগ থেকে আসবে — কোথাও হার্ডকোড স্ট্রিং থাকবে না।
- [ ] **৪.৬ হেলথ ড্যাশবোর্ড।** API latency, error rate, sync backlog, dead letter, শেষ সফল রিজার্ভেশন — এক স্ক্রিনে অপারেটর পড়তে পারবে।
- [ ] **৪.৭ support bundle export।** এক ক্লিকে রিড্যাক্টেড diagnostics ZIP (সেটিংস, শেষ N লগ রো, ভার্সন) — কোনো PII ছাড়া।
- [ ] **৪.৮ মিটারিং।** টেন্যান্ট অনুযায়ী search/reservation গুনুন, যাতে প্ল্যান বিল করা যায় এবং abuse ধরা পড়ে।
- [ ] **৪.৯ ব্যাকএন্ড চাহিদা — ট্র্যাক করুন, কিন্তু কখনো ব্লকার বানাবেন না।** যা থাকলে অনেক ভালো হতো তার একটা জীবন্ত তালিকা রাখুন (hold endpoint, idempotency key, `children`, `changes?since=`, cancellation fee) — কিন্তু উপরের প্রতিটি আইটেম **সেগুলো ছাড়াই** শিপ করতে হবে।

---

## ৫. "বিক্রি করার উপযোগী" হওয়ার সংজ্ঞা

- [ ] Retry করা sync কখনো দ্বিতীয় আসল রিজার্ভেশন বানাতে পারবে না (fault injection দিয়ে প্রমাণিত)।
- [ ] শেষ রুমের জন্য দুটো simultaneous checkout — দুটোই সফল হতে পারবে না।
- [ ] দেখানো দাম, cart টোটাল ও API `totalPrice` — কুপনসহ প্রমাণযোগ্যভাবে অভিন্ন।
- [ ] প্রতিটি write endpoint authenticated, nonce-protected, rate-limited ও audited।
- [ ] URL, লগ বা export-এ কোনো PII নেই।
- [ ] ফ্রেশ ইনস্টল ডেমো টেন্যান্টে লিখতে পারবে না।
- [ ] Reconcile লোকাল-only ও রিমোট-only — দুই ধরনের বুকিংই ধরে repair করতে পারে।
- [ ] Uninstall-এর পর কোনো option, cron, lock বা table অবশিষ্ট থাকে না।
