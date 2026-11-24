# Active WordPress Custom Snippets - Aristocrats Inn

**Live website:** https://hotelaristocratsinn.com/

**Replacement prepared:** 2026-11-22

**Checkout snippet version:** 2.0.0

This document contains the replacement for the exact conditional checkout snippet confirmed by the site owner. Preparation and local testing do not mean it has been installed in the live WordPress admin.

## 1. Replace the conditional checkout snippet

The standalone, copyable source is [aristocrats-conditional-checkout.php](./snippets/aristocrats-conditional-checkout.php). Its code is also included below.

1. Export or copy the current **Aristocrats - Professional Conditional Checkout** snippet before replacing it.
2. Deactivate that old checkout snippet. Deactivate any older copies of the same snippet that define `arc_cfg`, `arc_cart_has`, or `arc_get_room_booking_data`.
3. Add one PHP snippet named **Aristocrats - Professional Conditional Checkout v2**. Paste the replacement code **without the opening `<?php` tag** and select **Run Everywhere**. Do not use frontend-only mode: WooCommerce checkout AJAX must load these helpers too.
4. Activate that one replacement. Keep **Aristocrats Hotel Core**, WooCommerce, payment gateways and the separate revisions-limit snippet active.
5. Clear the site/CDN page cache. Test a restaurant checkout and a room checkout with extra guests and special requests. Check the order's room type, physical room ID, dates, rate, nights and requests.

Do not keep the old and new checkout snippets active together. The replacement detects an existing `arc_cfg()` and stops its own registration with an admin notice instead of silently using the old helpers.

No separate restaurant, room form, Elementor template, payment gateway or plugin module needs to be disabled for this replacement. The old snippet's cart guard, guest fields, fare summary, raw debug footer, custom confirmation, admin details and email output are all part of the old snippet; deactivating that one snippet disables them together.

## 2. What changed

- Removed obsolete third-party hotel product detection and CSS references. Only custom room metadata and the `hotel-rooms` WooCommerce category identify room products.
- Preserved the full server-side `arc_room` / `_arc_booking` arrays. Room type, physical room ID, API rate, total, nights, category name, thumbnail and additional metadata are no longer reduced to four guest/date fields.
- Adapted both existing cart formats when adding/restoring cart data, and supplied the `room_id` / `api_room_id` aliases required by the current core. Conflicting positive room IDs block checkout.
- Saved order metadata at priority `20`, after the core's priority-`10` handler. Selected requests and guest preferences survive core saving, and cleared preferences do not retain old values.
- Matched the current core's required additional-adult names and used its `arc_special_note` field. Kept early/late arrival, airport pickup, extra bed and decoration requests, alongside quiet room and high floor.
- Used the selected category name and API thumbnail in the review card instead of the generic hidden container product's name and image.
- Read hotel name, address, phone and check-in/out times through `Settings::get_hotel_info()`, which uses the existing server-side cached API client. The browser receives no API URL, tenant code or credentials.
- Displayed only explicitly saved cancellation policy text. The live hotel-info schema does not provide stars, logo or cancellation policy; unspecified values remain hidden.
- Kept the existing simple food checkout fields. Hotel checkout CSS is loaded only for a room checkout and scoped with `form.checkout:has(.arc-booking-card)`; current browsers support this selector.
- Used the core's cart guard and `arc_clear_cart_nonce`. The snippet's fallback handler runs only if the core is inactive, and hotel checkout is blocked without the core.
- Left official booking confirmations, thank-you polling, emails and admin booking details to the core, preventing duplicate output. Removed the raw cart debug footer.
- Did not recalculate API prices or nights, change coupons/taxes/fees/gateway totals, create reservations or retry API writes from this snippet.

## 3. API evidence and remaining plugin work

The hotel profile uses **GET `/api/v1/public-booking/hotel-info`**, query `companyCode`. Verified fields: `hotelName`, `address`, `phone`, `standardCheckInTime`, `standardCheckOutTime` in `components.schemas.WebHotelInfoDto` of `agent-instructions/hotel-all-Api-End-point.json`.

Availability, price authority, cart price overrides, checkout overlap checks and reservation creation continue to use the existing core classes. This snippet fixes their checkout data handoff; it does not establish backend idempotency or atomic inventory locking.

These plugin-phase issues are addressed in the locally prepared 1.1.0 update. See [plugin-update-1.1.0.md](./plugin-update-1.1.0.md) for installation, verification and remaining backend/staging limits. Keep this v2 checkout snippet active during the plugin update.

## 4. Verification

```text
php -l documentsForAllMDfile/snippets/aristocrats-conditional-checkout.php
php -l tests/test-checkout-snippet.php
php tests/test-checkout-snippet.php --plugin-first
php tests/test-checkout-snippet.php --snippet-first
```

Both loading orders passed 40 checks each using the real core checkout fields, checkout validator, settings, API client and JSON fixtures. WordPress hooks/session/order storage are stubbed; no live network write, gateway payment or live browser checkout was performed. The tests verify rich booking metadata, aliases, request preservation, required guests/terms, food scoping, selected-room display, unchanged financial values and duplicate-copy protection.

## 5. Full replacement checkout code

```php
<?php
/**
 * Aristocrats - Professional Conditional Checkout v2.0.0.
 *
 * Replace the previous checkout snippet. In Code Snippets/WPCode, omit the
 * opening PHP tag and select the PHP / Run Everywhere mode.
 * Requires the classic WooCommerce checkout and arc-hotel-core for bookings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

// Stop before registering hooks if a previous copy is still active.
if ( function_exists( 'arc_cfg' ) ) {
	add_action( 'admin_notices', function () {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Aristocrats checkout v2 was not loaded. Disable the previous conditional checkout snippet, then activate one copy of v2.', 'arc-hotel-core' ) . '</p></div>';
		}
	} );
	return;
} else {

/** Check at callback time so plugin/snippet loading order does not matter. */
function arc_checkout_core_active() {
	return defined( 'ARC_PLUGIN_FILE' ) && class_exists( '\ARC\HotelCore\Plugin' );
}

/** Read the cached hotel profile through the existing server-side API client. */
function arc_cfg() {
	static $config = null;
	if ( null === $config ) {
		$config = array(
			'hotel_name'    => get_bloginfo( 'name' ),
			'hotel_address' => '',
			'stars'         => 0,
			'checkin_time'  => '14:00',
			'checkout_time' => '12:00',
			'support_phone' => '',
			'terms_url'     => '',
			'cancellation'  => '',
			'policies'      => array(),
		);
		if ( arc_checkout_core_active() ) {
			$info = \ARC\HotelCore\Settings\Settings::get_hotel_info();
			foreach ( array(
				'hotelName' => 'hotel_name', 'address' => 'hotel_address',
				'phone' => 'support_phone', 'standardCheckInTime' => 'checkin_time',
				'standardCheckOutTime' => 'checkout_time',
			) as $api_key => $config_key ) {
				if ( isset( $info[ $api_key ] ) && '' !== trim( (string) $info[ $api_key ] ) ) {
					$config[ $config_key ] = sanitize_text_field( $info[ $api_key ] );
				}
			}
			// The live hotel-info DTO has no cancellation policy or star rating.
			// Only display an explicitly saved policy, never manufacture one.
			$saved = get_option( \ARC\HotelCore\Settings\Settings::OPTION_KEY, array() );
			if ( is_array( $saved ) && ! empty( $saved['cancellation_policy_text'] ) ) {
				$config['cancellation'] = sanitize_textarea_field( $saved['cancellation_policy_text'] );
			}
		}
	}
	$filtered = apply_filters( 'arc_config', $config );
	return is_array( $filtered ) ? array_replace( $config, $filtered ) : $config;
}

function arc_is_room_product( $product_id ) {
	$product_id = (int) $product_id;
	return 'yes' === get_post_meta( $product_id, '_is_hotel_room', true )
		|| has_term( 'hotel-rooms', 'product_cat', $product_id );
}

function arc_checkout_is_room_item( $item ) {
	return ! empty( $item['arc_room'] ) || ! empty( $item['_arc_booking'] )
		|| arc_is_room_product( $item['product_id'] ?? 0 );
}

function arc_cart_has( $type ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		$is_room = arc_checkout_is_room_item( $item );
		if ( ( 'room' === $type && $is_room ) || ( 'food' === $type && ! $is_room ) ) {
			return true;
		}
	}
	return false;
}

function arc_first_room_item() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return null;
	}
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( arc_checkout_is_room_item( $item ) ) {
			return $item;
		}
	}
	return null;
}

/** Preserve the API quote and all server-side metadata in both booking paths. */
function arc_get_room_booking_data( $item ) {
	$stored = isset( $item['_arc_booking'] ) && is_array( $item['_arc_booking'] ) ? $item['_arc_booking'] : array();
	$room   = isset( $item['arc_room'] ) && is_array( $item['arc_room'] ) ? $item['arc_room'] : array();
	$data   = array_replace( $stored, $room );
	$filtered = apply_filters( 'arc_room_booking_data', $data, $item );
	if ( is_array( $filtered ) ) {
		$data = array_replace( $data, $filtered );
	}
	$data['api_room_id'] = (int) ( $data['api_room_id'] ?? 0 );
	$data['room_id']     = (int) ( $data['room_id'] ?? 0 );
	if ( $data['api_room_id'] <= 0 && $data['room_id'] > 0 ) {
		$data['api_room_id'] = $data['room_id'];
	} elseif ( $data['room_id'] <= 0 && $data['api_room_id'] > 0 ) {
		$data['room_id'] = $data['api_room_id'];
	}
	$data['adults']   = (int) ( $data['adults'] ?? 1 );
	$data['children'] = (int) ( $data['children'] ?? 0 );
	return $data;
}

/** Adapt both existing cart formats before the core reads their booking data. */
function arc_checkout_normalize_cart_item( $item ) {
	if ( ! empty( $item['arc_room'] ) || ! empty( $item['_arc_booking'] ) ) {
		$booking = arc_get_room_booking_data( $item );
		$item['arc_room']     = $booking;
		$item['_arc_booking'] = $booking;
	}
	return $item;
}

add_filter( 'woocommerce_add_cart_item_data', 'arc_checkout_normalize_cart_item', 20 );
add_filter( 'woocommerce_get_cart_item_from_session', 'arc_checkout_normalize_cart_item', 20 );

/** Nights and rates are API values; never derive them from cart discounts. */
function arc_nights( $booking ) {
	return max( 0, (int) ( $booking['nights'] ?? 0 ) );
}

function arc_plural( $count, $word ) {
	$count = (int) $count;
	$plural = 'Child' === $word ? 'Children' : $word . 's';
	return $count . ' ' . ( 1 === $count ? $word : $plural );
}

function arc_checkout_date( $date, $format = 'd M, Y' ) {
	$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, wp_timezone() );
	return $parsed && $parsed->format( 'Y-m-d' ) === $date ? $parsed->format( $format ) : '';
}

function arc_request_options() {
	return array(
		'arc_req_quiet'         => 'Quiet Room',
		'arc_req_high_floor'    => 'High Floor',
		'arc_req_airport'       => 'Airport Pickup Required',
		'arc_req_early_checkin' => 'Early Check-in',
		'arc_req_late_checkout'=> 'Late Check-out',
		'arc_req_extra_bed'     => 'Extra Bed',
		'arc_req_decoration'    => 'Decorations in Room',
	);
}

/** The plugin owns cart guards and its nonce-protected clear-cart handler. */
add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id ) {
	if ( arc_checkout_core_active() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $passed;
	}
	$conflict = arc_is_room_product( $product_id ) ? arc_cart_has( 'food' ) : arc_cart_has( 'room' );
	if ( $conflict ) {
		$url = wp_nonce_url( add_query_arg( 'arc_clear_cart', '1', wc_get_cart_url() ), 'arc_clear_cart_nonce' );
		wc_add_notice( esc_html__( 'Room reservations and restaurant items require separate orders.', 'arc-hotel-core' ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Clear Cart', 'arc-hotel-core' ) . '</a>', 'error' );
		return false;
	}
	return $passed;
}, 20, 2 );

add_action( 'template_redirect', function () {
	if ( arc_checkout_core_active() || '1' !== ( $_GET['arc_clear_cart'] ?? '' ) ) {
		return;
	}
	$nonce = isset( $_GET['_wpnonce'] ) && is_scalar( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'arc_clear_cart_nonce' ) ) {
		wc_add_notice( __( 'Invalid session link.', 'arc-hotel-core' ), 'error' );
		return;
	}
	if ( function_exists( 'WC' ) && WC()->cart ) {
		WC()->cart->empty_cart();
		wc_add_notice( __( 'Cart cleared successfully.', 'arc-hotel-core' ), 'success' );
	}
	wp_safe_redirect( wc_get_cart_url() );
	exit;
} );

/** Keep the existing simple food checkout; hotel fields apply to room carts. */
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( ! arc_cart_has( 'room' ) && ! arc_cart_has( 'food' ) ) {
		return $fields;
	}
	unset( $fields['billing']['billing_last_name'], $fields['billing']['billing_company'],
		$fields['billing']['billing_address_2'], $fields['billing']['billing_postcode'], $fields['billing']['billing_city'] );
	$fields['billing']['billing_first_name']['label'] = __( 'Full Name', 'arc-hotel-core' );
	$fields['billing']['billing_first_name']['class'] = array( 'form-row-wide' );
	$fields['billing']['billing_phone']['class'] = array( 'form-row-first' );
	$fields['billing']['billing_phone']['priority'] = 20;
	$fields['billing']['billing_phone']['required'] = true;
	$fields['billing']['billing_email']['class'] = array( 'form-row-last' );
	$fields['billing']['billing_email']['priority'] = 30;
	if ( ! arc_cart_has( 'room' ) ) {
		$fields['billing']['billing_state']['label'] = __( 'District', 'arc-hotel-core' );
		$fields['billing']['billing_state']['required'] = true;
		$fields['billing']['billing_state']['priority'] = 40;
		$fields['billing']['billing_address_1']['label'] = __( 'Delivery Address', 'arc-hotel-core' );
		$fields['billing']['billing_address_1']['placeholder'] = __( 'House / road / area', 'arc-hotel-core' );
		$fields['billing']['billing_address_1']['priority'] = 50;
		return $fields;
	}
	unset( $fields['billing']['billing_address_1'], $fields['billing']['billing_state'], $fields['billing']['billing_country'], $fields['order']['order_comments'] );
	$fields['shipping'] = array();
	$fields['billing']['billing_first_name']['label'] = __( 'Primary Guest - Full Name', 'arc-hotel-core' );
	$fields['billing']['billing_phone']['label'] = __( 'Mobile Number', 'arc-hotel-core' );
	$fields['billing']['billing_phone']['placeholder'] = '+8801XXXXXXXXX';
	$fields['billing']['billing_email']['required'] = true;
	$fields['billing']['arc_id_number'] = array(
		'type' => 'text', 'label' => __( 'NID / Passport Number', 'arc-hotel-core' ), 'required' => true,
		'class' => array( 'form-row-wide' ), 'priority' => 35,
	);
	$booking = arc_get_room_booking_data( arc_first_room_item() ?? array() );
	for ( $i = 2; $i <= min( 8, $booking['adults'] ); $i++ ) {
		$fields['billing'][ 'arc_guest_' . $i ] = array(
			'type' => 'text', 'label' => sprintf( __( 'Adult Guest %d - Full Name', 'arc-hotel-core' ), $i ),
			'required' => true, 'class' => array( 'form-row-wide' ), 'priority' => 36 + $i,
		);
	}
	$priority = 10;
	foreach ( arc_request_options() as $key => $label ) {
		$fields['order'][ $key ] = array( 'type' => 'checkbox', 'label' => $label, 'required' => false, 'class' => array( 'arc-check' ), 'priority' => $priority++ );
	}
	$fields['order']['arc_bed_type'] = array(
		'type' => 'radio', 'label' => __( 'Bed Preference', 'arc-hotel-core' ), 'required' => false,
		'options' => array( 'Large Double Bed' => 'Large Double / King Bed', 'Twin Beds' => 'Twin Beds' ),
		'class' => array( 'arc-wide' ), 'priority' => 30,
	);
	$fields['order']['arc_arrival_time'] = array(
		'type' => 'select', 'label' => __( 'Estimated Arrival Time', 'arc-hotel-core' ), 'required' => false,
		'options' => array( '' => 'Select an arrival time', 'Before 12 PM' => 'Before 12 PM', '12 PM - 2 PM' => '12 PM - 2 PM', '2 PM - 6 PM' => '2 PM - 6 PM', '6 PM - 10 PM' => '6 PM - 10 PM', 'After 10 PM' => 'After 10 PM' ),
		'class' => array( 'arc-wide' ), 'priority' => 31,
	);
	$fields['order']['arc_special_note'] = array(
		'type' => 'textarea', 'label' => __( 'Special Requests / Notes', 'arc-hotel-core' ), 'required' => false,
		'class' => array( 'arc-wide' ), 'priority' => 40,
	);
	$config = arc_cfg();
	$terms = esc_html__( 'I agree to the Terms & Conditions and Cancellation Policy.', 'arc-hotel-core' );
	if ( ! empty( $config['terms_url'] ) ) {
		$terms = sprintf( __( 'I agree to the <a href="%s" target="_blank" rel="noopener">Terms & Conditions</a> and Cancellation Policy.', 'arc-hotel-core' ), esc_url( $config['terms_url'] ) );
	}
	$fields['order']['arc_terms'] = array( 'type' => 'checkbox', 'label' => $terms, 'required' => true, 'class' => array( 'arc-wide', 'arc-terms' ), 'priority' => 50 );
	return $fields;
}, 20 );

/** Validate the existing server-side quote without changing prices or payments. */
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( ! arc_cart_has( 'room' ) ) {
		return;
	}
	if ( arc_cart_has( 'food' ) ) {
		$errors->add( 'arc_mixed', __( 'Room reservations and restaurant items require separate orders.', 'arc-hotel-core' ) );
		return;
	}
	if ( ! arc_checkout_core_active() ) {
		$errors->add( 'arc_core_missing', __( 'Online room reservations are temporarily unavailable. Please contact the hotel.', 'arc-hotel-core' ) );
		return;
	}
	$booking = arc_get_room_booking_data( arc_first_room_item() ?? array() );
	$in  = arc_checkout_date( $booking['check_in'] ?? '', 'Y-m-d' );
	$out = arc_checkout_date( $booking['check_out'] ?? '', 'Y-m-d' );
	if ( ! $in || ! $out || $in < current_time( 'Y-m-d' ) || $out <= $in
		|| (int) ( $booking['room_type_id'] ?? 0 ) <= 0 || arc_nights( $booking ) <= 0
		|| (float) ( $booking['total_price'] ?? 0 ) <= 0 || (float) ( $booking['rate_per_night'] ?? 0 ) <= 0
		|| $booking['adults'] < 1 || $booking['adults'] > 8 || $booking['children'] < 0
		|| ( $booking['api_room_id'] > 0 && $booking['room_id'] > 0 && $booking['api_room_id'] !== $booking['room_id'] ) ) {
		$errors->add( 'arc_booking_data', __( 'Your room selection is incomplete or expired. Please select your room and dates again.', 'arc-hotel-core' ) );
	}
	if ( empty( $data['arc_terms'] ) ) {
		$errors->add( 'arc_terms', __( 'Please accept the Terms & Conditions and Cancellation Policy.', 'arc-hotel-core' ) );
	}
	// The core already validates ID, phone, extra guest names and live availability.
}, 20, 2 );

/** Run after the core's priority-10 save, preserving the complete API quote. */
add_action( 'woocommerce_checkout_create_order', function ( $order, $data ) {
	if ( ! arc_cart_has( 'room' ) ) {
		$order->update_meta_data( '_arc_order_type', 'food' );
		return;
	}
	$item = arc_first_room_item();
	if ( ! $item ) {
		return;
	}
	$existing = $order->get_meta( '_arc_booking' );
	$booking  = array_replace( is_array( $existing ) ? $existing : array(), arc_get_room_booking_data( $item ) );
	$order->update_meta_data( '_arc_order_type', 'room' );
	$order->update_meta_data( '_arc_booking', $booking );
	$order->update_meta_data( '_arc_id_number', sanitize_text_field( $data['arc_id_number'] ?? '' ) );
	$guests = array();
	for ( $i = 2; $i <= min( 8, $booking['adults'] ); $i++ ) {
		$name = sanitize_text_field( $data[ 'arc_guest_' . $i ] ?? '' );
		if ( '' !== $name ) {
			$guests[ $i ] = $name;
		}
	}
	$order->update_meta_data( '_arc_guests', $guests );
	$options = arc_request_options();
	$requests = array();
	foreach ( (array) $order->get_meta( '_arc_requests' ) as $request ) {
		if ( is_scalar( $request ) && ! in_array( $request, $options, true ) ) {
			$requests[] = sanitize_textarea_field( $request );
		}
	}
	foreach ( $options as $key => $label ) {
		if ( ! empty( $data[ $key ] ) ) {
			$requests[] = $label;
		}
	}
	$note = sanitize_textarea_field( $data['arc_special_note'] ?? '' );
	if ( '' !== $note ) {
		$requests[] = $note;
	}
	$order->update_meta_data( '_arc_requests', array_values( array_unique( array_filter( $requests, static fn( $request ) => '' !== $request ) ) ) );
	$order->update_meta_data( '_arc_bed_type', sanitize_text_field( $data['arc_bed_type'] ?? '' ) );
	$order->update_meta_data( '_arc_arrival', sanitize_text_field( $data['arc_arrival_time'] ?? '' ) );
	// WooCommerce saves the order after this hook. Do not reset sync status here.
}, 20, 2 );

add_action( 'woocommerce_checkout_before_customer_details', function () {
	$item = arc_first_room_item();
	if ( ! $item ) {
		return;
	}
	$config = arc_cfg();
	$booking = arc_get_room_booking_data( $item );
	$name = $booking['room_type_name'] ?? __( 'Room Reservation', 'arc-hotel-core' );
	$thumb = $booking['thumbnail_url'] ?? '';
	$guests = arc_plural( $booking['adults'], 'Adult' );
	if ( $booking['children'] > 0 ) {
		$guests .= ', ' . arc_plural( $booking['children'], 'Child' );
	}
	?>
	<div class="arc-booking-card">
		<h3><?php esc_html_e( 'Review Your Booking', 'arc-hotel-core' ); ?></h3>
		<div class="arc-hotel"><strong><?php echo esc_html( $config['hotel_name'] ); ?></strong>
			<?php if ( ! empty( $config['stars'] ) ) : ?><span class="arc-stars"><?php echo esc_html( str_repeat( '★', min( 5, max( 0, (int) $config['stars'] ) ) ) ); ?></span><?php endif; ?>
			<?php if ( ! empty( $config['hotel_address'] ) ) : ?><p class="arc-muted"><?php echo esc_html( $config['hotel_address'] ); ?></p><?php endif; ?>
		</div>
		<div class="arc-room">
			<?php if ( $thumb ) : ?><div class="arc-room__img"><img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $name ); ?>"></div><?php endif; ?>
			<div><h4><?php echo esc_html( $name ); ?></h4><span class="arc-muted"><?php echo esc_html( $guests ); ?></span></div>
		</div>
		<div class="arc-dates">
			<div><small><?php echo esc_html( sprintf( __( 'Check-in (%s)', 'arc-hotel-core' ), $config['checkin_time'] ) ); ?></small><strong><?php echo esc_html( arc_checkout_date( $booking['check_in'] ?? '' ) ); ?></strong></div>
			<div class="arc-nights"><?php echo esc_html( arc_plural( arc_nights( $booking ), 'Night' ) ); ?></div>
			<div><small><?php echo esc_html( sprintf( __( 'Check-out (%s)', 'arc-hotel-core' ), $config['checkout_time'] ) ); ?></small><strong><?php echo esc_html( arc_checkout_date( $booking['check_out'] ?? '' ) ); ?></strong></div>
		</div>
		<?php if ( ! empty( $config['cancellation'] ) ) : ?><p class="arc-cancel"><?php echo esc_html( $config['cancellation'] ); ?></p><?php endif; ?>
	</div>
	<?php
} );

add_action( 'woocommerce_review_order_before_order_total', function () {
	$item = arc_first_room_item();
	if ( ! $item ) {
		return;
	}
	$booking = arc_get_room_booking_data( $item );
	if ( arc_nights( $booking ) > 1 && isset( $booking['rate_per_night'] ) ) {
		echo '<tr class="arc-row"><th>' . esc_html__( 'Room rate per night', 'arc-hotel-core' ) . '</th><td>' . wc_price( (float) $booking['rate_per_night'] ) . '</td></tr>';
	}
} );

add_action( 'woocommerce_checkout_after_customer_details', function () {
	if ( ! arc_cart_has( 'room' ) ) {
		return;
	}
	$config = arc_cfg();
	$items = array(
		'Check-in & Check-out' => sprintf( 'Check-in from %s. Check-out by %s.', $config['checkin_time'], $config['checkout_time'] ),
		'Identification' => 'Please bring the valid NID or passport used for this reservation.',
		'Additional Requests' => 'Requests are subject to availability. The hotel will confirm any additional charges.',
	);
	foreach ( (array) $config['policies'] as $title => $text ) {
		if ( is_scalar( $text ) && '' !== trim( (string) $text ) ) {
			$items[ $title ] = $text;
		}
	}
	if ( ! empty( $config['cancellation'] ) ) {
		$items['Cancellation Policy'] = $config['cancellation'];
	}
	if ( ! empty( $config['support_phone'] ) ) {
		$items['Hotel Support'] = $config['support_phone'];
	}
	echo '<div class="arc-info"><div class="arc-info__head">' . esc_html__( 'Important Information', 'arc-hotel-core' ) . '</div><div class="arc-info__body">';
	foreach ( $items as $title => $text ) {
		echo '<h5>' . esc_html( $title ) . '</h5><p>' . esc_html( $text ) . '</p>';
	}
	echo '</div></div>';
} );

add_filter( 'woocommerce_order_button_text', function ( $text ) {
	return arc_cart_has( 'room' ) ? __( 'Confirm Booking', 'arc-hotel-core' ) : $text;
} );

add_filter( 'gettext', function ( $translated, $text, $domain ) {
	if ( 'woocommerce' !== $domain || is_admin() || ! is_checkout() || ! arc_cart_has( 'room' ) ) {
		return $translated;
	}
	$labels = array( 'Billing details' => 'Guest Details', 'Additional information' => 'Additional Requests' );
	return $labels[ $text ] ?? $translated;
}, 10, 3 );

add_filter( 'woocommerce_gateway_title', function ( $title, $id ) {
	return 'cod' === $id && arc_cart_has( 'room' ) ? __( 'Pay at Hotel', 'arc-hotel-core' ) : $title;
}, 10, 2 );

add_filter( 'woocommerce_gateway_description', function ( $description, $id ) {
	return 'cod' === $id && arc_cart_has( 'room' ) ? __( 'Pay at the hotel reception during check-in.', 'arc-hotel-core' ) : $description;
}, 10, 2 );

/** Room-only styles; each selector is limited to the room checkout form. */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || ! arc_cart_has( 'room' ) ) {
		return;
	}
	wp_register_style( 'arc-checkout', false, array(), '2.0.0' );
	wp_enqueue_style( 'arc-checkout' );
	wp_add_inline_style( 'arc-checkout', '
form.checkout:has(.arc-booking-card){display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:24px;align-items:start}
form.checkout:has(.arc-booking-card) #customer_details{grid-column:1;width:100%}
form.checkout:has(.arc-booking-card) #customer_details .col-1,form.checkout:has(.arc-booking-card) #customer_details .col-2{float:none;width:100%}
form.checkout:has(.arc-booking-card) .arc-info,form.checkout:has(.arc-booking-card) .arc-booking-card{grid-column:1}
form.checkout:has(.arc-booking-card) #order_review_heading,form.checkout:has(.arc-booking-card) .woocommerce-shipping-fields{display:none}
form.checkout:has(.arc-booking-card) #order_review{grid-column:2;grid-row:1 / span 3;position:sticky;top:20px;background:#fff;padding:20px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
form.checkout:has(.arc-booking-card) #order_review::before{content:"Fare Summary";display:block;font-size:18px;font-weight:600;margin-bottom:14px}
form.checkout:has(.arc-booking-card) #order_review table.shop_table{border:0;margin:0 0 16px;width:100%}
form.checkout:has(.arc-booking-card) #order_review table.shop_table thead{display:none}
form.checkout:has(.arc-booking-card) #order_review table.shop_table th,form.checkout:has(.arc-booking-card) #order_review table.shop_table td{border:0;padding:10px 0;background:none}
form.checkout:has(.arc-booking-card) #order_review table.shop_table tr.order-total{background:#0b0f6b;color:#fff;font-size:16px}
form.checkout:has(.arc-booking-card) #order_review table.shop_table tr.order-total th,form.checkout:has(.arc-booking-card) #order_review table.shop_table tr.order-total td{color:#fff;padding:14px 10px}
form.checkout:has(.arc-booking-card) #payment{background:none!important;border-radius:0}
form.checkout:has(.arc-booking-card) #payment ul.payment_methods{border:0;padding:0;margin:0}
form.checkout:has(.arc-booking-card) #payment ul.payment_methods label{background:none!important;color:#101828;font-weight:600}
form.checkout:has(.arc-booking-card) #payment div.payment_box{background:#eef3ff;border-radius:6px;color:#344054}
form.checkout:has(.arc-booking-card) #payment div.payment_box::before{display:none}
form.checkout:has(.arc-booking-card) .woocommerce-billing-fields,form.checkout:has(.arc-booking-card) .woocommerce-additional-fields,form.checkout:has(.arc-booking-card) .arc-booking-card,form.checkout:has(.arc-booking-card) .arc-info{background:#fff;border-radius:10px;padding:20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
form.checkout:has(.arc-booking-card) h3{font-size:18px;margin:0 0 14px}
form.checkout:has(.arc-booking-card) .form-row input.input-text,form.checkout:has(.arc-booking-card) .form-row textarea,form.checkout:has(.arc-booking-card) .form-row select{border:1px solid #d5dbe3;border-radius:6px;padding:12px;background:#fafbfc}
form.checkout:has(.arc-booking-card) .form-row label{font-size:13px;color:#667085}
form.checkout:has(.arc-booking-card) .woocommerce-additional-fields__field-wrapper{display:grid;grid-template-columns:1fr 1fr;gap:10px}
form.checkout:has(.arc-booking-card) .woocommerce-additional-fields__field-wrapper .form-row{float:none!important;width:100%!important;margin:0!important;padding:0!important}
form.checkout:has(.arc-booking-card) .woocommerce-additional-fields__field-wrapper .arc-wide{grid-column:1 / -1}
form.checkout:has(.arc-booking-card) .woocommerce-additional-fields__field-wrapper .arc-check{border:1px solid #d5dbe3;border-radius:6px;padding:12px 14px!important;background:#fafbfc}
form.checkout:has(.arc-booking-card) .arc-terms label{font-size:13px}
form.checkout:has(.arc-booking-card) .arc-hotel{margin-bottom:12px}
form.checkout:has(.arc-booking-card) .arc-stars{color:#f5a623;margin-left:8px;letter-spacing:1px}
form.checkout:has(.arc-booking-card) .arc-room{display:flex;gap:14px;align-items:center;margin-bottom:14px}
form.checkout:has(.arc-booking-card) .arc-room__img img{width:80px;height:64px;object-fit:cover;border-radius:6px}
form.checkout:has(.arc-booking-card) .arc-room h4{margin:0 0 4px;font-size:16px}
form.checkout:has(.arc-booking-card) .arc-muted{color:#667085;font-size:13px}
form.checkout:has(.arc-booking-card) .arc-dates{display:flex;justify-content:space-between;align-items:center;gap:12px;background:#eef3ff;border-radius:8px;padding:14px}
form.checkout:has(.arc-booking-card) .arc-dates small,form.checkout:has(.arc-booking-card) .arc-dates strong{display:block}
form.checkout:has(.arc-booking-card) .arc-nights{border:1px solid #cbd5e1;background:#fff;border-radius:4px;padding:2px 10px;font-size:12px}
form.checkout:has(.arc-booking-card) .arc-cancel{margin:12px 0 0;font-size:13px;color:#027a48}
form.checkout:has(.arc-booking-card) .arc-info{padding:0;overflow:hidden}
form.checkout:has(.arc-booking-card) .arc-info__head{background:#dc3545;color:#fff;font-weight:600;padding:14px 20px}
form.checkout:has(.arc-booking-card) .arc-info__body{padding:6px 20px 16px}
form.checkout:has(.arc-booking-card) .arc-info__body h5{margin:14px 0 4px;font-size:14px}
form.checkout:has(.arc-booking-card) .arc-info__body p{margin:0;font-size:13px;color:#475467}
form.checkout:has(.arc-booking-card) #arc_bed_type_field .woocommerce-input-wrapper{display:flex;flex-wrap:wrap;gap:10px}
form.checkout:has(.arc-booking-card) #arc_bed_type_field label.radio{display:inline-block;padding:10px 12px;cursor:pointer}
form.checkout:has(.arc-booking-card) #place_order{width:100%;background:#fcc800;color:#111;font-weight:700;border-radius:6px;padding:16px}
form.checkout:has(.arc-booking-card) #place_order:hover{background:#e6b700}
@media(max-width:900px){form.checkout:has(.arc-booking-card){grid-template-columns:1fr}form.checkout:has(.arc-booking-card) #order_review{grid-column:1;grid-row:auto;position:static}form.checkout:has(.arc-booking-card) .woocommerce-additional-fields__field-wrapper{grid-template-columns:1fr}}
@media(max-width:480px){form.checkout:has(.arc-booking-card) .arc-dates{flex-wrap:wrap}form.checkout:has(.arc-booking-card) .arc-booking-card,form.checkout:has(.arc-booking-card) .woocommerce-billing-fields{padding:14px}}
	' );
} );

// Official reservation confirmations, emails and admin details remain in the core.
}
```

## 6. Keep the separate revisions-limit snippet

Keep one copy of **WordPress Post/Page Revisions Limit** active. It is independent of checkout and needs no replacement for this task.

```php
<?php
/** Limit WordPress post/page revisions to three. */
if ( ! defined( 'WP_POST_REVISIONS' ) ) {
    define( 'WP_POST_REVISIONS', 3 );
}
```
