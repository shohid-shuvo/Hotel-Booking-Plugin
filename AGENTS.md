# Agent Guidelines: Aristocrats Hotel Booking System (`arc-hotel-core`)

Welcome, AI Agent. This document outlines the fundamental architecture, operational rules, and project context for this repository. Read this completely before inspecting or modifying any code.

---

## 1. Project Context & Ecosystem

This project is a custom WordPress plugin (`arc-hotel-core`) integrating a WordPress/WooCommerce frontend with a multi-tenant ASP.NET Hotel ERP API (`Smart Hotel Management API - POS ERP Architecture`).

### 3-Tier Live Sync Architecture (100% Online Real-Time)
The system operates as a unified 3-tier ecosystem sharing a single central REST API & database:
1. **Super Admin Dashboard (ERP):** Admins configure room types, base rates, amenities, photos, and policies (`/api/Setup/roomtypes`, `/api/Rooms`, etc.).
2. **Receptionist Software (Frontdesk PMS):** Staff handle walk-ins, check-in, check-out, room changes, folios, and cashier shifts (`/api/Bookings/*`, `/api/Shifts/*`).
3. **Public Website Frontend (This Plugin):** Dynamically consumes Admin-configured data to render:
   - **Home Page:** Featured room cards (`room-categories`).
   - **Archive Page:** Complete room categories catalog.
   - **Single Room Page:** Detailed room type view (gallery, amenities, descriptions, live booking widget).
   - **Search & Checkout:** Live availability search (`available-rooms`) and WooCommerce checkout (`create-reservation-json`).

---

## 2. Mandatory Rules for All AI Agents

### CRITICAL: Live Reality vs. Pending Backend Requests
- **What is LIVE TODAY:** Documented in `.agents/skills/arc-hotel-ecosystem/SKILL.md` (Section 2) and `agent-instructions/hotel-all-Api-End-point.json`.
  - `GET /api/v1/public-booking/track/{referenceOrPhone}` is currently a **GET** request.
  - `WebBookingRequest` does **NOT** yet have `externalReference` or `expectedTotalAmount`.
  - Write endpoints do **NOT** yet require `X-Api-Key`.
  - All plugin PHP code MUST work against this live reality until the backend confirms deployment of updates.
- **What is PENDING BACKEND IMPLEMENTATION:** Documented in `agent-instructions/03-backend-api-requirements.md` (the list of requested changes sent to the backend developer). Do not assume these changes exist in the live API yet.

### Language Rules
- **Discussions with the User:** MUST be written in **Bangla using Bengali script (বাংলা)**. Never use Banglish (Latin script) when explaining or conversing with the user.
- **Code & Comments:** All code, comments, docstrings, and commit messages MUST be written strictly in **English**.

### Zero Third-Party Hotel Plugins
- Do NOT use or reference third-party WordPress hotel plugins (e.g., `WP Hotel Booking`, `hb_room`).
- All hotel room handling is 100% custom PHP talking directly to the ASP.NET REST API endpoints specified in `agent-instructions/hotel-all-Api-End-point.json`.

### Separation of Concerns
- **Cart Separation:** Hotel room bookings and Restaurant food orders must never coexist in the same WooCommerce cart.
- **Price Authority:** The website NEVER dictates room prices. Prices are governed by the API based on the Admin Dashboard configuration.
- **Checkout Guard:** Perform a pre-payment verification check (`available-rooms`) at `woocommerce_after_checkout_validation` to prevent double-booking before payment gateway redirection.

---

## 3. Key Documentation References

When working on specific areas, refer to these primary documents:
- **Skill Reference:** `.agents/skills/arc-hotel-ecosystem/SKILL.md` (Detailed 3-tier flow, live contracts vs pending requests).
- **Backend API Requirements:** `agent-instructions/03-backend-api-requirements.md` (Actionable list sent to backend developer).
- **API Swagger Schema:** `agent-instructions/hotel-all-Api-End-point.json` (Exact live Swagger definition).
- **Master Architecture Rules:** `agent-instructions/project_master_architecture_rules.md`.
- **Implementation Instructions:** `instructions.md`.
- **Plugin Gap Analysis:** `need.md`.
