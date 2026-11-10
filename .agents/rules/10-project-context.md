# Rule 10: Aristocrats project context (ALWAYS ON)

## Project

- Client project "Aristocrats": hotel room booking + restaurant food ordering.
- Stack: WordPress, Elementor Pro, WooCommerce, custom PHP plugin `arc-hotel-core`.
- Hotel backend: ASP.NET Hotel API at `https://hotelapi.dusrasoftltd.com` (multi-tenant by `companyCode`).
- Main goal: zero double-booking between receptionist software and website bookings.
- Read `.agents/skills/arc-hotel-ecosystem/SKILL.md` before any task that touches the API, booking, checkout or WooCommerce cart logic.

## Hard restrictions

1. Never use or reference any WordPress hotel plugin (WP Hotel Booking, `hb_room`, etc.). All hotel logic is custom PHP + the ASP.NET API.
2. Hotel rooms and restaurant food must never be in the same WooCommerce cart.
3. Prices come from the API and are applied server-side in `woocommerce_before_calculate_totals`. Never trust prices from the browser.
4. All API calls are server-side PHP. No API call and no secret in browser JS.
5. Never write code that depends on a "Part B" feature of SKILL.md. Those are not implemented yet.
6. Never hard-code room type IDs. Fetch them from `room-categories`.

## Code standards

- Every AJAX handler: nonce check, input sanitisation, output escaping, capability check where relevant.
- Code comments, docstrings, variable names, commit messages: English.
- Explanations to the user: Bangla script (বাংলা অক্ষরে). Never Banglish (Bangla in Latin letters).
- Be direct. No filler. Point out security or logic problems immediately, even if the user did not ask.
- Production-grade only. No "quick demo" shortcuts unless the user explicitly asks.
