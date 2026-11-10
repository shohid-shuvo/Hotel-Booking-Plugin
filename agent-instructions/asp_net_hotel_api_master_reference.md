# Smart Hotel Management API (ASP.NET) — Complete Reference & Flags

* **Base URL:** `https://hotelapi.dusrasoftltd.com`
* **OpenAPI Version:** 3.0.1 (`v1`)
* **Architecture:** Multi-Company SaaS Hotel Management API with Repository Pattern and Multi-Tenancy (`companyCode` / `tenantId`).
* **Default Demo Company Code:** `GPH01` (Grand Palace) / `SGL02` (Seagull Resort).
* **Standard Response Wrapper:** Almost all endpoints return a standard JSON wrapper:
  ```json
  {
    "succeeded": true,
    "message": "string",
    "errors": ["string"],
    "data": { ... }
  }
  ```

---

## 1. Public Website Booking Endpoints (`PublicBooking` Tag)
These 6 endpoints are purpose-built for the WordPress public website and do not require staff login for standard public queries.

### 1.1 Get Hotel Profile & Policies
* **Endpoint:** `GET /api/v1/public-booking/hotel-info`
* **Query Parameter:** `companyCode` (string, e.g., `GPH01`)
* **Response Data (`WebHotelInfoDto`):**
  * `hotelName` (string)
  * `companyCode` (string)
  * `contactPerson` (string)
  * `phone` (string)
  * `email` (string)
  * `address` (string)
  * `standardCheckInTime` (string, e.g., `"14:00"`)
  * `standardCheckOutTime` (string, e.g., `"12:00"`)

### 1.2 Get Room Categories (For Website Showcase)
* **Endpoint:** `GET /api/v1/public-booking/room-categories`
* **Query Parameter:** `companyCode` (string)
* **Response Data (`Array of WebRoomCategoryDto`):**
  * `roomTypeId` (int32) — e.g., `1` = Executive Suite, `2` = Deluxe Couple, `3` = Standard Twin
  * `categoryName` (string)
  * `description` (string)
  * `basePricePerNight` (double)
  * `maxAdults` (int32)
  * `maxChildren` (int32)
  * `totalRooms` (int32)
  * `availableRoomsNow` (int32)
  * `thumbnailUrl` (string)
  * `imageUrls` (array of strings)
  * `amenities` (array of strings)

### 1.3 Search Real-Time Available Rooms (Overlap Checker)
* **Endpoint:** `GET /api/v1/public-booking/available-rooms`
* **Query Parameters:**
  * `checkIn` (string, `date-time`, e.g., `2026-11-01T14:00:00` or `2026-11-01`)
  * `checkOut` (string, `date-time`, e.g., `2026-11-03T12:00:00` or `2026-11-03`)
  * `roomTypeId` (int32, optional — filters by category)
  * `adults` (int32)
  * `companyCode` (string)
* **Response Data (`Array of WebAvailableRoomDto`):**
  * `roomId` (int32) — Specific physical room ID
  * `roomNumber` (string) — e.g., `"101"`, `"204"`
  * `roomTypeId` (int32)
  * `roomTypeName` (string)
  * `description` (string)
  * `floorName` (string)
  * `buildingName` (string)
  * `pricePerNight` (double)
  * `nights` (int32) — Calculated by API
  * `totalPrice` (double) — Calculated by API (`pricePerNight * nights`)
  * `maxAdults` (int32)
  * `maxChildren` (int32)
  * `thumbnailUrl` (string)
  * `imageUrls` (array of strings)
  * `amenities` (array of strings)

### 1.4 Create Online Reservation (Raw JSON — Primary Endpoint for WordPress)
* **Endpoint:** `POST /api/v1/public-booking/create-reservation-json`
  *(Note: `/api/v1/public-booking/create-reservation` also exists for `multipart/form-data` or `x-www-form-urlencoded`)*
* **Request Body (`WebBookingRequest` JSON):**
  * `companyCode` (string, default: `"GPH01"`)
  * `checkInDate` (string, `date-time`, **Required**, e.g., `"2026-11-01T14:00:00"`)
  * `checkOutDate` (string, `date-time`, **Required**, e.g., `"2026-11-03T12:00:00"`)
  * `roomTypeId` (int32, e.g., `2`)
  * `roomId` (int32, optional — **send `0` to auto-assign the best available room of `roomTypeId`**, or pass specific `roomId`)
  * `adults` (int32, default: `2`)
  * `children` (int32, default: `0`)
  * `specialRequests` (string, nullable — combine Bed Type, Arrival Time, Additional Guests, Checkboxes, and Customer Notes here)
  * `fullName` (string, **Required**)
  * `phone` (string, **Required**)
  * `email` (string, nullable)
  * `nationalIdOrPassport` (string, nullable)
  * `address` (string, nullable)
  * `promoCode` (string, nullable)
  * `discountAmount` (double, default: `0`)
  * `paymentOption` (string — Allowed values: `"PayAtHotel"`, `"Deposit"`, `"FullPayment"`)
  * `paymentMethod` (int32 Enum `1..6` — see Section 2 below)
  * `amountPaid` (double — `0` for PayAtHotel, or online paid amount in BDT)
  * `transactionReference` (string, nullable — Gateway TrxID, e.g., `"BKASH-TRX-987654"`)
  * `paymentRemarks` (string, nullable — e.g., `"Online payment via WooCommerce SSLCommerz"`)
* **Response Data (`WebBookingConfirmationDto`):**
  * `bookingId` (int32) — Internal database ID
  * `bookingNumber` (string) — Official Reservation Code (e.g., `"WEB-20261028-1234"`)
  * `companyCode` (string)
  * `hotelName` (string)
  * `registrationToken` (string) — Token for digital registration card & signature
  * `status` (string)
  * `guestName`, `guestPhone`, `guestEmail` (strings)
  * `roomNumber` (string) — Assigned physical room number
  * `roomType` (string)
  * `checkInDate`, `checkOutDate` (date-time)
  * `totalNights` (int32)
  * `ratePerNight`, `subTotal`, `discountAmount`, `totalAmount`, `paidAmount`, `dueAmount` (doubles)
  * `paymentStatus` (string)
  * `confirmationMessage` (string)
  * `createdAt` (date-time)

### 1.5 Track Existing Reservation (For Website "Track Booking" Page)
* **Endpoint:** `GET /api/v1/public-booking/track/{referenceOrPhone}`
* **Path Parameter:** `referenceOrPhone` (string, Required — accepts `bookingNumber` like `WEB-20261028-1234` OR Guest Mobile Phone like `01711223344`)
* **Response Data (`Array of WebBookingTrackDto`):**
  * Returns `bookingId`, `bookingNumber`, `status`, `guestName`, `phone`, `email`, `roomNumber`, `roomType`, `checkInDate`, `checkOutDate`, `totalNights`, `subTotal`, `discountAmount`, `totalAmount`, `paidAmount`, `dueAmount`, `paymentStatus`, `specialRequests`, `bookingDate`.

### 1.6 Cancel Reservation from Website
* **Endpoint:** `POST /api/v1/public-booking/cancel-reservation-json`
  *(Form-data alternative: `POST /api/v1/public-booking/cancel-reservation`)*
* **Request Body (`WebCancelReservationRequest` JSON):**
  * `bookingNumber` (string, **Required**, e.g., `"WEB-20261028-1672"`)
  * `phone` (string, **Required** — must match the phone number on the reservation)
  * `reason` (string, nullable, e.g., `"Change of travel plans"`)
* **Response:** `BooleanApiResponse` (`data: true/false`)

---

## 2. All Critical System Flags & Enumerations

### 2.1 `PaymentOption` (String Flag in `WebBookingRequest`)
* `"PayAtHotel"` — Guest books online with `amountPaid = 0` and pays at the reception desk during check-in.
* `"Deposit"` — Guest pays a partial advance deposit online (`0 < amountPaid < totalAmount`).
* `"FullPayment"` — Guest pays 100% of the booking amount online (`amountPaid == totalAmount`).

### 2.2 `PaymentMethod` (Integer Enum Flag: `1` to `6`)
Mapped from `CashierShift` and `PaymentCollectionSummaryDto` schemas:
* `1` = **Cash** (Use for `"PayAtHotel"` / COD)
* `2` = **Card** (Credit/Debit Card / POS / Online Card Gateway)
* `3` = **bKash** (MFS)
* `4` = **Nagad** (MFS)
* `5` = **Bank** (Bank Transfer / Cheque)
* `6` = **Other** (Other online gateways / SSLCommerz generic)

### 2.3 `RoomTypeId` (Default Room Category IDs)
* `1` = Executive Suite
* `2` = Deluxe Couple
* `3` = Standard Twin
*(Always fetch dynamically via `GET /api/v1/public-booking/room-categories`)*

### 2.4 `BookingStatus` (Integer Enum: `1` to `5`)
* `1` = Reserved / Pending Check-in
* `2` = Checked-In (In-House)
* `3` = Checked-Out
* `4` = Cancelled
* `5` = No-Show

### 2.5 `RoomStatus` (Integer Enum: `1` to `5`)
Mapped from `DashboardStats`:
* `1` = Available
* `2` = Occupied
* `3` = Reserved
* `4` = Dirty
* `5` = Maintenance

### 2.6 `HousekeepingStatus` (Integer Enum: `1` to `4`)
* `1` = Pending / Dirty, `2` = In Progress, `3` = Clean / Completed, `4` = Inspected / Out of Order

### 2.7 `FolioTransactionType` (Integer Enum: `1` to `10`)
* Represents charge categories on guest folios (Room Charge, Food & Beverage/POS, Laundry, MiniBar, Service Charge, Discount, etc.).

---

## 3. Additional Useful Endpoints (Digital Reg Card, Room Images & POS)

### 3.1 Digital Registration Card & Guest E-Signature
If the website offers online pre-check-in signature using the `registrationToken` returned during booking:
* **Get Reg Card by Token:** `GET /api/Bookings/reg-card/by-token/{token}` (Returns `RegistrationCardDto`)
* **Save Guest Digital Signature:** `POST /api/Bookings/save-signature`
  * Body: `{ "bookingId": 123, "signatureDataUrl": "data:image/png;base64,..." }`

### 3.2 Room Images Management (`RoomImages` Tag)
* **Get All Images of a Room:** `GET /api/v1/rooms/{roomId}/images` (Returns `imageUrl`, `thumbnailUrl`, `caption`, `isPrimary`, `displayOrder`)
* **Upload & Auto-WebP Optimize:** `POST /api/v1/rooms/{roomId}/images`
* **Set Primary Thumbnail:** `PUT /api/v1/rooms/{roomId}/images/{imageId}/primary`
* **Delete Image:** `DELETE /api/v1/rooms/{roomId}/images/{imageId}`

### 3.3 Internal ERP & POS Endpoints (Requires Bearer JWT via `POST /api/Auth/login`)
* **Authentication:** `POST /api/Auth/login` (`companyCode`, `username`, `password` -> returns JWT `token`).
* **POS Restaurant Menu (ERP Side):** `GET /api/POS/menu`, `POST /api/POS/order` (Note: Website food ordering is handled via WooCommerce as per project architecture, but can optionally sync or read from POS menu if needed in the future).
* **Setup & Rooms:** `GET /api/Setup/roomtypes`, `GET /api/Setup/amenities`, `GET /api/Rooms`, `GET /api/Rooms/matrix`.