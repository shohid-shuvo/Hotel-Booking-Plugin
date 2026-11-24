# Smart Hotel Management API (POS ERP Architecture)
**Base URL:** `https://hotelapi.dusrasoftltd.com`  
**Swagger UI:** `https://hotelapi.dusrasoftltd.com/swagger/index.html`  
**Version:** `v1` (OpenAPI 3.0.1)  
**Architecture:** Multi-Company SaaS Hotel Management API with Repository Pattern and Multi-Tenancy

---

## 1. AuditLogs
### `GET /api/AuditLogs`
* **Parameters:** None
* **Response (`200 OK`):** Array of `AuditLog`
  * `id` (`int32`), `createdAt` (`date-time`), `createdBy` (`string`), `updatedAt` (`date-time`), `updatedBy` (`string`), `isDeleted` (`boolean`), `tenantId` (`string`)
  * `username` (`string`), `action` (`string`), `entityName` (`string`), `entityKey` (`string`), `oldValue` (`string`), `newValue` (`string`), `timestamp` (`date-time`), `ipAddress` (`string`)

---

## 2. Auth
### `POST /api/Auth/login`
* **Request Body (`application/json` - `LoginRequest`):**
  * Authentication credentials payload
* **Response (`200 OK` - `AuthResponseApiResponse`):**
  * `succeeded` (`boolean`), `message` (`string`), `errors` (`string[]`)
  * `data` (`AuthResponse`): `token` (`string`), `expiration` (`date-time`), `tenantId` (`string`), `companyCode` (`string`), `companyName` (`string`), `userId` (`int32`), `username` (`string`), `fullName` (`string`), `role` (`string`)

---

## 3. Bookings
### `GET /api/Bookings`
* **Parameters:** None
* **Response (`200 OK`):** Array of `Booking` (`id`, `bookingNumber`, `tenantId`, `createdAt`, etc.)

### `GET /api/Bookings/{id}`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** `Booking`

### `POST /api/Bookings/walkin`
* **Request Body (`application/json`):** `WalkInCheckInRequest`
* **Response (`200 OK`):** `BookingApiResponse`

### `POST /api/Bookings/advance-reserve`
* **Request Body (`application/json` - `AdvanceReservationRequest`):**
  * **Required:** `fullName` (`string`), `phone` (`string`), `roomId` (`int32`), `checkInDate` (`date-time`), `checkOutDate` (`date-time`)
  * **Optional:** `email` (`string`), `nationalIdOrPassport` (`string`), `ratePerNight` (`double`), `advancePaid` (`double`), `paymentMethod` (`PaymentMethod`: `1`-`6`), `specialRequests` (`string`), `transactionReference` (`string`), `mfsSenderNumber` (`string`), `mfsTrxId` (`string`), `cardType` (`string`), `cardLast4` (`string`), `cardApprovalCode` (`string`), `cardPosMachine` (`string`), `bankName` (`string`), `bankAccountOrCheque` (`string`), `bankBranch` (`string`)
* **Response (`200 OK`):** `BookingApiResponse`

### `POST /api/Bookings/night-audit`
* **Parameters:** None
* **Response (`200 OK`):** `NightAuditResultApiResponse`

### `GET /api/Bookings/reg-card/by-token/{token}`
* **Parameters:** `token` (path, required, `string`)
* **Response (`200 OK` - `RegistrationCardDtoApiResponse`):**
  * `data` (`RegistrationCardDto`): `bookingId` (`int32`), `bookingNumber` (`string`), `registrationToken` (`string`), `guestName` (`string`), `phone` (`string`), `email` (`string`), `nationalIdOrPassport` (`string`), `nationality` (`string`), `address` (`string`), `roomNumber` (`string`), `roomType` (`string`), `checkInDate` (`date-time`), `checkOutDate` (`date-time`), `ratePerNight` (`double`), `signatureDataUrl` (`string`), `hotelName` (`string`), `hotelAddress` (`string`), `hotelPhone` (`string`), `checkInTime` (`string`), `checkOutTime` (`string`)

### `POST /api/Bookings/save-signature`
* **Request Body (`application/json` - `DigitalSignatureRequest`):**
  * `bookingId` (`int32`), `signatureDataUrl` (`string`)
* **Response (`200 OK`):** `BooleanApiResponse`

---

## 4. Dashboard
### `GET /api/Dashboard/stats`
* **Parameters:** None
* **Response (`200 OK` - `DashboardStats`):**
  * `totalRooms` (`int32`), `availableRooms` (`int32`), `occupiedRooms` (`int32`), `reservedRooms` (`int32`), `dirtyRooms` (`int32`), `maintenanceRooms` (`int32`), `occupancyRate` (`double`), `revenueToday` (`double`), `activeGuests` (`int32`), `adr` (`double`), `revPAR` (`double`)

---

## 5. ExchangeRates
### `GET /api/ExchangeRates`
* **Response (`200 OK`):** Array of `ExchangeRate` (`id`, `currencyCode`, `currencyName`, `symbol`, `rateToBDT`, `isActive`, `isBaseCurrency`)

### `POST /api/ExchangeRates`
* **Request Body (`application/json`):** `ExchangeRate`
* **Response (`200 OK`):** `ExchangeRateApiResponse`

---

## 6. Folios
### `GET /api/Folios/all`
* **Response (`200 OK`):** Array of `Folio`

### `GET /api/Folios/booking/{bookingId}`
* **Parameters:** `bookingId` (path, required, `int32`)
* **Response (`200 OK`):** `Folio`

### `POST /api/Folios/transactions`
* **Request Body (`application/json`):** `FolioTransaction`
* **Response (`200 OK`):** `FolioTransaction`

### `POST /api/Folios/payments`
* **Request Body (`application/json` - `Payment`):**
  * `id` (`int32`), `folioId` (`int32`), `amount` (`double`), `paymentMethod` (`PaymentMethod`: `1`-`6`), `transactionReference` (`string`), `paymentDate` (`date-time`), `receivedBy` (`string`)
* **Response (`200 OK`):** `Payment`

### `POST /api/Folios/post-charge`
* **Request Body (`application/json` - `PostChargeRequest`):**
  * **Required:** `bookingId` (`int32`), `description` (`string`)
  * **Optional:** `transactionType` (`FolioTransactionType`), `amount` (`double`, min `0.01`, max `1000000`)
* **Response (`200 OK`):** `FolioTransactionApiResponse`

### `POST /api/Folios/discount`
* **Request Body (`application/json` - `ApplyDiscountRequest`):**
  * `folioId` (`int32`), `discountType` (`string`), `discountValue` (`double`), `discountAmount` (`double`), `reason` (`string`)
* **Response (`200 OK`):** `FolioTransactionApiResponse`

### `GET /api/Folios/booking/{bookingId}/all-folios`
* **Parameters:** `bookingId` (path, required, `int32`)
* **Response (`200 OK`):** Array of `Folio`

### `POST /api/Folios/split`
* **Request Body (`application/json` - `CreateFolioRequest`):**
  * `bookingId` (`int32`), `folioType` (`string`), `folioName` (`string`)
* **Response (`200 OK`):** `FolioApiResponse`

### `POST /api/Folios/transfer-charge`
* **Request Body (`application/json` - `TransferFolioChargeRequest`):**
  * Charge transfer payload
* **Response (`200 OK`):** `BooleanApiResponse`

---

## 7. Guests
### `GET /api/Guests`
* **Response (`200 OK`):** Array of `Guest`

### `POST /api/Guests`
* **Request Body (`application/json`):** `Guest`
* **Response (`200 OK`):** `Guest`

### `PUT /api/Guests`
* **Request Body (`application/json`):** `Guest`
* **Response (`200 OK`):** OK

### `GET /api/Guests/{id}`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** `Guest`

### `GET /api/Guests/{id}/history`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** Array of `Booking`

### `POST /api/Guests/{id}/toggle-blacklist`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** `BooleanApiResponse`

---

## 8. Housekeeping
### `GET /api/Housekeeping/pending`
* **Response (`200 OK`):** Array of `HousekeepingTask`

### `POST /api/Housekeeping`
* **Request Body (`application/json`):** `HousekeepingTask`
* **Response (`200 OK`):** `HousekeepingTask`

### `PUT /api/Housekeeping/{id}/status`
* **Parameters:**
  * `id` (path, required, `int32`)
  * `status` (query, `HousekeepingStatus`)
  * `remarks` (query, `string`)
* **Response (`200 OK`):** OK

---

## 9. Inventory
### `GET /api/Inventory`
* **Response (`200 OK`):** Array of `InventoryItem`

### `POST /api/Inventory`
* **Request Body (`application/json`):** `InventoryItem`
* **Response (`200 OK`):** `InventoryItem`

### `GET /api/Inventory/logs`
* **Response (`200 OK`):** Array of `InventoryStockLog`

### `POST /api/Inventory/movement`
* **Request Body (`application/json`):** `StockMovementRequest`
* **Response (`200 OK`):** `InventoryStockLogApiResponse`

### `PUT /api/Inventory/{id}/stock`
* **Parameters:** `id` (path, required, `int32`), `quantity` (query, `int32`)
* **Response (`200 OK`):** OK

### `GET /api/Inventory/assets`
* **Response (`200 OK`):** Array of `RoomAsset` (`id`, `roomId`, `assetName` [required], `brand`, `serialNumber`, `purchaseDate`, `warrantyExpiry`, `status`)

### `POST /api/Inventory/assets`
* **Request Body (`application/json`):** `RoomAsset`
* **Response (`200 OK`):** `RoomAsset`

---

## 10. Keycards
### `POST /api/Keycards/issue`
* **Request Body (`application/json`):** `IssueKeycardRequest`
* **Response (`200 OK`):** `KeycardResultDto`

### `GET /api/Keycards/read/{cardUid}`
* **Parameters:** `cardUid` (path, required, `string`)
* **Response (`200 OK`):** `KeycardResultDto`

### `POST /api/Keycards/void/{roomNumber}`
* **Parameters:** `roomNumber` (path, required, `string`)
* **Response (`200 OK`):** `KeycardResultDto`

---

## 11. Maintenance
### `GET /api/Maintenance`
* **Response (`200 OK`):** Array of `MaintenanceTicket`

### `POST /api/Maintenance`
* **Request Body (`application/json`):** `MaintenanceTicket`
* **Response (`200 OK`):** `MaintenanceTicket`

### `PUT /api/Maintenance/{id}/resolve`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** OK

---

## 12. Ota
### `GET /api/Ota/reservations`
* **Response (`200 OK`):** Array of `OtaReservation` (`id`, `channelName`, `otaBookingReference`, `guestName`, `guestEmail`, `guestPhone`, `roomTypeCode`, `roomNumber`, `checkInDate`, `checkOutDate`, `totalAmount`, `commissionPercent`, `commissionAmount`, `status`, `rawPayload`)

### `POST /api/Ota/simulate`
* **Request Body (`application/json`):** `SimulateOtaBookingRequest`
* **Response (`200 OK`):** `BookingApiResponse`

### `POST /api/Ota/webhook/{channel}`
* **Parameters:** `channel` (path, required, `string`)
* **Request Body (`application/json`):** `SimulateOtaBookingRequest`
* **Response (`200 OK`):** `BookingApiResponse`

---

## 13. POS
### `GET /api/POS/menu`
* **Response (`200 OK`):** Array of `MenuItem`

### `POST /api/POS/menu`
* **Request Body (`application/json`):** `MenuItem`
* **Response (`200 OK`):** `MenuItem`

### `PUT /api/POS/menu`
* **Request Body (`application/json`):** `MenuItem`
* **Response (`200 OK`):** `BooleanApiResponse`

### `DELETE /api/POS/menu/{id}`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** `BooleanApiResponse`

### `POST /api/POS/order`
* **Request Body (`application/json` - `POSOrderRequest`):**
  * `bookingId` (`int32`), `roomNumber` (`string`), `orderDate` (`date-time`)
  * `items` (`POSOrderItemDto[]`): `menuItemId` (`int32`), `itemName` (`string`), `unitPrice` (`double`), `quantity` (`int32`), `total` (`double`, readOnly)
  * `subTotal` (`double`), `discountType` (`string`), `discountValue` (`double`), `discountAmount` (`double`), `discountReason` (`string`), `taxPercent` (`double`), `serviceChargePercent` (`double`), `grandTotal` (`double`)
* **Response (`200 OK`):** `FolioTransactionApiResponse`

### `GET /api/POS/room-history/{bookingId}`
* **Parameters:** `bookingId` (path, required, `int32`)
* **Response (`200 OK`):** Array of `FolioTransaction`

### `GET /api/POS/orders-by-date`
* **Parameters:** `date` (query, `date-time`)
* **Response (`200 OK`):** Array of `FolioTransaction`

---

## 14. PublicBooking *(Website Booking Engine)*

### `GET /api/v1/public-booking/hotel-info`
* **Summary:** 1. Get Hotel profile, policies, and contact information for the public website header/footer.
* **Parameters:**
  * `companyCode` (query, `string`) — e.g., `GPH01`
* **Response (`200 OK`):** `WebHotelInfoDtoApiResponse`

### `GET /api/v1/public-booking/room-categories`
* **Summary:** 2. Get list of Room Categories (Room Types) with pricing, capacities, and amenities for website showcase.
* **Parameters:**
  * `companyCode` (query, `string`)
* **Response (`200 OK`):** `WebRoomCategoryDtoListApiResponse`

### `GET /api/v1/public-booking/available-rooms`
* **Summary:** 3. Search Available Rooms by Date Range, Guest Count, and optional Category. Checks real-time calendar overlaps against active reservations.
* **Parameters:**
  * `checkIn` (query, `date-time`)
  * `checkOut` (query, `date-time`)
  * `roomTypeId` (query, `int32`)
  * `adults` (query, `int32`)
  * `companyCode` (query, `string`)
* **Response (`200 OK`):** `WebAvailableRoomDtoListApiResponse`

### `POST /api/v1/public-booking/create-reservation`
* **Summary:** 4. Create an Online Reservation (Form Input Boxes - User-Friendly with Pre-filled Demo Data for Swagger and Web Forms).
* **Request Body (`multipart/form-data` & `application/x-www-form-urlencoded`):**
  * **Required Fields:** `CheckInDate`, `CheckOutDate`, `FullName`, `Phone`
  * **All Form Flags:**
    * `CompanyCode` (`string`, default: `"GPH01"`)
    * `CheckInDate` (`date-time`, default: `"2026-11-01T14:00:00"`)
    * `CheckOutDate` (`date-time`, default: `"2026-11-03T12:00:00"`)
    * `RoomTypeId` (`int32`, default: `2` — `1`=Executive Suite, `2`=Deluxe Couple, `3`=Standard Twin)
    * `RoomId` (`int32`, default: `0` — leave `0` to auto-assign best room)
    * `Adults` (`int32`, default: `2`)
    * `Children` (`int32`, default: `0`)
    * `SpecialRequests` (`string`, default: `"High floor, quiet room, extra pillow"`)
    * `FullName` (`string`, default: `"Kazi Arifur Rahman"`)
    * `Phone` (`string`, default: `"01711223344"`)
    * `Email` (`string`, default: `"arif@example.com"`)
    * `NationalIdOrPassport` (`string`, default: `"19922692000000001"`)
    * `Address` (`string`, default: `"Gulshan-2, Dhaka"`)
    * `PromoCode` (`string`, default: `""`)
    * `DiscountAmount` (`double`, default: `0`)
    * `PaymentOption` (`string`, default: `"Deposit"` — `"PayAtHotel"`, `"Deposit"`, `"FullPayment"`)
    * `PaymentMethod` (`PaymentMethod`: `1`-`6`)
    * `AmountPaid` (`double`, default: `2000`)
    * `TransactionReference` (`string`, default: `"BKASH-TRX-987654"`)
    * `PaymentRemarks` (`string`, default: `"Advance deposit via bKash"`)
* **Response (`200 OK`):** `WebBookingConfirmationDtoApiResponse`

### `POST /api/v1/public-booking/create-reservation-json`
* **Summary:** 4b. Create an Online Reservation (Raw JSON - for External Website Frontend and Mobile Apps).
* **Request Body (`application/json` - `WebBookingRequest`):**
  * Same properties as `create-reservation` in camelCase (`companyCode`, `checkInDate`, `checkOutDate`, `roomTypeId`, `roomId`, `adults`, `children`, `specialRequests`, `fullName`, `phone`, `email`, `nationalIdOrPassport`, `address`, `promoCode`, `discountAmount`, `paymentOption`, `paymentMethod`, `amountPaid`, `transactionReference`, `paymentRemarks`).
* **Response (`200 OK`):** `WebBookingConfirmationDtoApiResponse`

### `GET /api/v1/public-booking/track/{referenceOrPhone}`
* **Summary:** 5. Track an existing reservation by Booking Number (e.g. `WEB-20261028-1234`) or Guest Phone number.
* **Parameters:**
  * `referenceOrPhone` (path, required, `string`)
* **Response (`200 OK` - `WebBookingTrackDtoListApiResponse`):**
  * `data` (`WebBookingTrackDto[]`): `bookingId`, `bookingNumber`, `companyCode`, `hotelName`, `status`, `guestName`, `phone`, `email`, `roomNumber`, `roomType`, `checkInDate`, `checkOutDate`, `totalNights`, `subTotal`, `discountAmount`, `totalAmount`, `paidAmount`, `dueAmount`, `paymentStatus`, `specialRequests`, `bookingDate`

### `POST /api/v1/public-booking/cancel-reservation`
* **Summary:** 6. Cancel a reservation (Form Input Boxes).
* **Request Body (`multipart/form-data` & `application/x-www-form-urlencoded`):**
  * **Required:** `BookingNumber` (`string`, default: `"WEB-20261028-1672"`), `Phone` (`string`, default: `"01711223344"`)
  * **Optional:** `Reason` (`string`, default: `"Change of travel plans"`)
* **Response (`200 OK`):** `BooleanApiResponse`

### `POST /api/v1/public-booking/cancel-reservation-json`
* **Summary:** 6b. Cancel a reservation (Raw JSON).
* **Request Body (`application/json` - `WebCancelReservationRequest`):**
  * **Required:** `bookingNumber` (`string`), `phone` (`string`)
  * **Optional:** `reason` (`string`)
* **Response (`200 OK`):** `BooleanApiResponse`

---

## 15. Reports
### `GET /api/Reports/manager-flash`
* **Parameters:** `date` (query, `date-time`)
* **Response (`200 OK`):** `ManagerFlashReportDto`

### `GET /api/Reports/police-station`
* **Parameters:** `date` (query, `date-time`)
* **Response (`200 OK`):** Array of `PoliceStationGuestRecordDto` (`guestName`, `phone`, `nationalIdOrPassport`, `nationality`, `address`, `roomNumber`, `checkInDate`, `checkOutDate`, `status`)

### `GET /api/Reports/payments`
* **Parameters:** `from` (query, `date-time`), `to` (query, `date-time`)
* **Response (`200 OK` - `PaymentCollectionSummaryDto`):** `cashAmount`, `cardAmount`, `bkashAmount`, `nagadAmount`, `bankAmount`, `totalCollected`, `totalCount`

### `GET /api/Reports/dues`
* **Response (`200 OK`):** Array of `DueReportItemDto` (`bookingId`, `bookingNumber`, `guestName`, `phone`, `roomNumber`, `checkInDate`, `checkOutDate`, `totalCharges`, `totalPaid`, `balanceDue`, `status`, `roomCharges`, `foodAndBeverageCharges`, `laundryCharges`, `miniBarCharges`, `serviceCharges`, `otherCharges`, `transactions[]`, `payments[]`)

### `GET /api/Reports/tax-vat`
* **Parameters:** `from` (query, `date-time`), `to` (query, `date-time`)
* **Response (`200 OK`):** `TaxVatSummaryDto`

---

## 16. RoomImages
### `GET /api/v1/rooms/{roomId}/images`
* **Summary:** 1. Get all images for a specific room.
* **Parameters:** `roomId` (path, required, `int32`)
* **Response (`200 OK` - `RoomImageDtoListApiResponse`):**
  * `data` (`RoomImageDto[]`): `id`, `roomId`, `roomTypeId`, `imageUrl`, `thumbnailUrl`, `caption`, `isPrimary`, `displayOrder`, `fileSizeBytes`, `createdAt`

### `POST /api/v1/rooms/{roomId}/images`
* **Summary:** 2. Upload and optimize one or more photos for a room (WebP conversion & FTP upload).
* **Parameters:** `roomId` (path, required, `int32`)
* **Request Body (`multipart/form-data`):** `files` (`binary[]`), `caption` (`string`), `isPrimary` (`boolean`, default: `false`)
* **Response (`200 OK`):** `UploadImageResponseDtoListApiResponse`

### `PUT /api/v1/rooms/{roomId}/images/{imageId}/primary`
* **Summary:** 3. Set a specific image as the Primary (thumbnail) image for a room.
* **Parameters:** `roomId` (path, required, `int32`), `imageId` (path, required, `int32`)
* **Response (`200 OK`):** `BooleanApiResponse`

### `DELETE /api/v1/rooms/{roomId}/images/{imageId}`
* **Summary:** 4. Delete an image from database and remote FTP server.
* **Parameters:** `roomId` (path, required, `int32`), `imageId` (path, required, `int32`)
* **Response (`200 OK`):** `BooleanApiResponse`

---

## 17. Rooms
### `GET /api/Rooms`
* **Response (`200 OK`):** Array of `Room`

### `POST /api/Rooms`
* **Request Body (`application/json`):** `Room`
* **Response (`200 OK`):** `Room`

### `PUT /api/Rooms`
* **Request Body (`application/json`):** `Room`
* **Response (`200 OK`):** OK

### `GET /api/Rooms/matrix`
* **Response (`200 OK`):** Array of `RoomMatrixItem` (`roomId`, `roomNumber`, `roomType`, `floorName`, `buildingName`, `status`)

### `GET /api/Rooms/{id}`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** `Room`

### `DELETE /api/Rooms/{id}`
* **Parameters:** `id` (path, required, `int32`)
* **Response (`200 OK`):** OK

### `POST /api/Rooms/with-amenities`
* **Request Body (`application/json` - `CreateRoomRequest`):**
  * **Required:** `roomNumber` (`string`)
  * **Optional:** `branchId` (`int32`), `floorId` (`int32`), `roomTypeId` (`int32`), `pricePerNight` (`double`), `notes` (`string`), `amenityIds` (`int32[]`)
* **Response (`200 OK`):** `RoomApiResponse`

### `PUT /api/Rooms/{id}/status`
* **Parameters:** `id` (path, required, `int32`), `status` (query, `RoomStatus`)
* **Response (`200 OK`):** OK

---

## 18. Setup
### `GET /api/Setup/branches` | `POST /api/Setup/branches` | `DELETE /api/Setup/branches/{id}`
* **Schema:** `Branch` (`id` path param on DELETE)

### `GET /api/Setup/floors` | `POST /api/Setup/floors` | `DELETE /api/Setup/floors/{id}`
* **Schema:** `Floor` (`id` path param on DELETE)

### `GET /api/Setup/roomtypes` | `POST /api/Setup/roomtypes` | `DELETE /api/Setup/roomtypes/{id}`
* **Schema:** `RoomType` (`id` path param on DELETE)

### `GET /api/Setup/amenities` | `POST /api/Setup/amenities` | `DELETE /api/Setup/amenities/{id}`
* **Schema:** `Amenity` (`id`, `name` [required], `icon`, `description`)

---

## 19. Shifts
### `GET /api/Shifts/summary`
* **Response (`200 OK` - `CashierShiftSummaryDto`):** `currentShift`, `openingFloat`, `cashPayments`, `cardPayments`, `bkashPayments`, `nagadPayments`, `bankPayments`, `totalExpectedCash`, `totalAllCollections`, `totalTransactionsCount`

### `GET /api/Shifts/current`
* **Response (`200 OK`):** `CashierShift` (`shiftName`, `cashierUserId`, `cashierName`, `openedAt`, `closedAt`, `openingFloat`, `cashCollected`, `cardCollected`, `bkashCollected`, `nagadCollected`, `bankCollected`, `otherCollected`, `totalSystemAmount`, `declaredCash`, `discrepancy`, `status`, `notes`, `handoverTo`)

### `POST /api/Shifts/open`
* **Request Body (`application/json` - `OpenShiftRequest`):** `shiftName` (`string`), `openingFloat` (`double`)
* **Response (`200 OK`):** `CashierShiftApiResponse`

### `POST /api/Shifts/close`
* **Request Body (`application/json` - `CloseShiftRequest`):** `shiftId` (`int32`), `declaredCash` (`double`), `handoverTo` (`string`), `notes` (`string`)
* **Response (`200 OK`):** `CashierShiftApiResponse`

### `GET /api/Shifts/history`
* **Response (`200 OK`):** Array of `CashierShift`

---

## 20. SmartHotel.API
### `GET /`
* **Response (`200 OK`):** Health Check / Root Endpoint

---

## 21. Tenants
### `GET /api/Tenants` | `POST /api/Tenants` | `PUT /api/Tenants`
* **Schema:** `Tenant`

---

## Common Enums & Reference Flags
* **`PaymentMethod` (`int32`):** `1` = Cash, `2` = Card, `3` = bKash, `4` = Nagad, `5` = Bank, `6` = Other
* **`PaymentOption` (`string`):** `"PayAtHotel"`, `"Deposit"`, `"FullPayment"`
* **`RoomTypeId` (`int32`):** `1` = Executive Suite, `2` = Deluxe Couple, `3` = Standard Twin