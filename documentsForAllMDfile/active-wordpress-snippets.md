# Active WordPress Custom Snippets (WPCode / Code Snippets)

This document contains all custom PHP snippets currently active on the client's WordPress site (`sdlwordpress.wuaze.com`) via the **Code Snippets** or **WPCode** plugin. Any AI assistant or developer working on this codebase should refer to this file to understand the active checkout customization and avoid redundant or conflicting code.

---

## 1. Aristocrats – Professional Conditional Checkout

### Purpose & Capabilities
* **Cart Conflict Guard:** Prevents mixing restaurant food orders with hotel room bookings in the same WooCommerce cart.
* **Conditional Checkout Fields:**
  * **Food Orders:** Displays standard basic delivery fields (Name, Phone, District, Address).
  * **Hotel Room Orders:** Transforms checkout into an OTA-style hotel booking experience (Primary Guest Name, NID/Passport, Mobile, Email, Additional Adult Guest Names dynamically based on adults count, Bed Type selection, Arrival Time estimation, Special Requests checkboxes, Terms & Cancellation policy checkbox).
* **Important Information Policy Box:** Renders check-in/out policies and hotel rules under customer details.
* **Review Your Booking Card:** Displays hotel name, star rating, address, room image, check-in/check-out dates, and number of nights.
* **Fare Summary:** Displays per-night room rate.
* **Order Meta Saving:** Stores `_arc_order_type`, `_arc_id_number`, `_arc_booking`, `_arc_guests`, `_arc_requests`, `_arc_bed_type`, and `_arc_arrival`.
* **Admin & Email Notifications:** Injects full hotel booking details in admin order screens and customer/admin emails (with NID masked for customers).
* **Custom Booking Confirmation:** Replaces standard order-received with an OTA-styled "Booking Received" card on `woocommerce_thankyou`.
* **Integrated Button:** Replaces "Place Order" with `Confirm Booking` (`#place_order`).

### Integration with `arc-hotel-core` Plugin
* The snippet uses helper `arc_is_room_product()` which recognizes products with `_is_hotel_room = 'yes'` or category `hotel-rooms` (which `arc-hotel-core` creates).
* In `arc-hotel-core`, `Checkout_Fields.php` detects `function_exists('arc_cfg')` to yield and avoid duplicate cards or duplicate checkout fields.
* The plugin picks up the saved order data and synchronizes it directly with the ASP.NET central hotel API (`api/v1/public-booking/create-reservation-json`).

### Full Snippet Code
```php
<?php
/**
 * Aristocrats – Professional Conditional Checkout (Code Snippets এ পেস্ট করো, শুরুতে <?php লাগবে না)
 * আগের স্নিপেট বন্ধ/মুছে দিয়ে শুধু এটা রাখো।
 *
 * ফিচার:
 *  - ফুড ↔ রুম কার্ট কনফ্লিক্ট রুল
 *  - ফুড চেকআউট: বেসিক ফিল্ড | রুম চেকআউট: OTA-স্টাইল বুকিং চেকআউট
 *  - প্রতি গেস্টের নাম, NID/Passport, Additional Requests (চেকবক্স), Bed type, Arrival time
 *  - Terms চেকবক্স, Important Information বক্স, Secure/Support নোট
 *  - Order confirmation কার্ড + ইমেইলে বুকিং ডিটেইলস, অ্যাডমিন অর্ডারে সব তথ্য
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* =========================================================
 * 0. CONFIG — এখানে ক্লায়েন্টের তথ্য বসাও (ফাঁকা থাকলে পেজে দেখাবে না)
 * ======================================================= */
function arc_cfg() {
	return apply_filters( 'arc_config', array(
		'hotel_name'    => 'Aristocrats',
		'hotel_address' => '',            // যেমন: 'Cox\'s Bazar, Bangladesh'
		'stars'         => 0,             // 0 দিলে স্টার দেখাবে না
		'checkin_time'  => '14:00',
		'checkout_time' => '11:00',
		'support_phone' => '',            // যেমন: '+880 1XXX-XXXXXX'
		'terms_url'     => '',            // Terms পেজের URL
		'cancellation'  => '',            // যেমন: 'চেক-ইনের ৪৮ ঘণ্টা আগে পর্যন্ত ফ্রি ক্যান্সেলেশন'
		'require_guest_names' => false,   // true দিলে অতিরিক্ত গেস্টের নাম বাধ্যতামূলক
		// Important Information: শিরোনাম => লেখা (ফাঁকা হলে দেখাবে না) — ক্লায়েন্টের পলিসি লিখো
		'policies'      => array(
			'Child Policy'                     => '',
			'Early Check-in & Late Check-out'  => '',
			'Arms & Pets Policy'               => '',
			'Outside Food Policy'              => '',
		),
	) );
}

/* =========================================================
 * 1. Helpers
 * ======================================================= */
function arc_is_room_product( $product_id ) {
	$product_id = (int) $product_id;
	if ( get_post_type( $product_id ) === 'hb_room' ) return true;          // WP Hotel Booking
	if ( get_post_meta( $product_id, '_is_hotel_room', true ) === 'yes' ) return true;
	return has_term( 'hotel-rooms', 'product_cat', $product_id );
}

function arc_cart_has( $type ) { // 'room' | 'food'
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) return false;
	foreach ( WC()->cart->get_cart() as $item ) {
		$room = arc_is_room_product( $item['product_id'] );
		if ( $type === 'room' && $room )  return true;
		if ( $type === 'food' && ! $room ) return true;
	}
	return false;
}

function arc_plural( $n, $word ) {
	return (int) $n . ' ' . $word . ( (int) $n === 1 ? '' : 's' );
}

function arc_to_ts( $v ) {
	if ( is_numeric( $v ) ) { $v = (int) $v; return $v > 100000 ? $v : 0; }
	$t = strtotime( (string) $v );
	return $t ? $t : 0;
}

/** কার্ট আইটেমের ভেতরে (nested array সহ) regex মেলানো key খুঁজে প্রথম scalar মান ফেরত দেয় */
function arc_scan( $data, $regex, $depth = 0 ) {
	if ( ! is_array( $data ) || $depth > 3 ) return null;
	foreach ( $data as $k => $v ) {
		if ( $k === 'data' ) continue; // WC_Product অবজেক্ট বাদ
		if ( is_string( $k ) && preg_match( $regex, $k ) && is_scalar( $v ) && $v !== '' ) return $v;
	}
	foreach ( $data as $k => $v ) {
		if ( $k === 'data' ) continue;
		if ( is_array( $v ) ) {
			$found = arc_scan( $v, $regex, $depth + 1 );
			if ( $found !== null ) return $found;
		}
	}
	return null;
}

/**
 * রুম কার্ট আইটেম থেকে তারিখ/গেস্ট ডেটা।
 * WP Hotel Booking এর আসল key (debug আউটপুট থেকে নিশ্চিত): check_in_date, check_out_date, adult_qty, child_qty
 * না মিললে fallback হিসেবে আন্দাজে খোঁজে। filter দিয়ে override করা যাবে।
 */
function arc_get_room_booking_data( $item ) {
	$in  = arc_to_ts( $item['check_in_date']  ?? arc_scan( $item, '/(check[_-]?in|arriv|start[_-]?date|^_?from)/i' ) );
	$out = arc_to_ts( $item['check_out_date'] ?? arc_scan( $item, '/(check[_-]?out|depart|end[_-]?date|^_?to$)/i' ) );
	$ad  = $item['adult_qty'] ?? arc_scan( $item, '/^_?(adults?|adults?_qty|number_of_adults)$/i' );
	$ch  = $item['child_qty'] ?? arc_scan( $item, '/^_?(child(ren)?|child_qty|children_qty|number_of_children)$/i' );

	$data = array(
		'check_in'  => $in  ? gmdate( 'Y-m-d', $in )  : '',
		'check_out' => $out ? gmdate( 'Y-m-d', $out ) : '',
		'adults'    => max( 1, (int) $ad ),
		'children'  => max( 0, (int) $ch ),
	);
	return apply_filters( 'arc_room_booking_data', $data, $item );
}

function arc_nights( $b ) {
	if ( empty( $b['check_in'] ) || empty( $b['check_out'] ) ) return 0;
	return max( 1, (int) round( ( strtotime( $b['check_out'] ) - strtotime( $b['check_in'] ) ) / DAY_IN_SECONDS ) );
}

function arc_first_room_item() {
	if ( ! WC()->cart ) return null;
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( arc_is_room_product( $item['product_id'] ) ) return $item;
	}
	return null;
}

function arc_mask( $s ) {
	$s = (string) $s;
	return strlen( $s ) > 4 ? str_repeat( '•', strlen( $s ) - 3 ) . substr( $s, -3 ) : $s;
}

/* অতিরিক্ত অনুরোধের চেকবক্স: key => লেবেল */
function arc_request_options() {
	return array(
		'arc_req_early_checkin' => 'Early Check-in',
		'arc_req_late_checkout' => 'Late Check-out',
		'arc_req_airport'       => 'Airport Transfer',
		'arc_req_extra_bed'     => 'Extra Bed',
		'arc_req_high_floor'    => 'Room on a Higher Floor',
		'arc_req_decoration'    => 'Decorations in Room',
	);
}

/* ---------------- DEBUG (শুধু admin): checkout পেজে ?arc_debug=1 ---------------- */
add_action( 'wp_footer', function () {
	if ( ! current_user_can( 'manage_options' ) || empty( $_GET['arc_debug'] ) || ! WC()->cart ) return;
	echo '<pre style="background:#111;color:#0f0;padding:16px;overflow:auto;font-size:12px;white-space:pre-wrap">';
	foreach ( WC()->cart->get_cart() as $item ) {
		$resolved = arc_is_room_product( $item['product_id'] ) ? arc_get_room_booking_data( $item ) : array();
		$raw = $item; unset( $raw['data'] );
		echo esc_html( 'post_type: ' . get_post_type( $item['product_id'] ) . "\nRESOLVED: " . print_r( $resolved, true ) . "\nRAW: " . print_r( $raw, true ) );
	}
	echo '</pre>';
} );

/* =========================================================
 * 2. Cart conflict (food ↔ room)
 * ======================================================= */
add_filter( 'woocommerce_add_to_cart_validation', function ( $passed, $product_id ) {
	if ( ! WC()->cart || WC()->cart->is_empty() ) return $passed;
	$adding_room = arc_is_room_product( $product_id );
	$conflict    = $adding_room ? arc_cart_has( 'food' ) : arc_cart_has( 'room' );
	if ( $conflict ) {
		$clear_url = wp_nonce_url( add_query_arg( 'arc_clear_cart', '1', wc_get_cart_url() ), 'arc_clear_cart' );
		$msg = $adding_room
			? 'আপনার কার্টে খাবার আছে। রুম বুক করতে হলে আগে কার্ট খালি করুন।'
			: 'আপনার কার্টে রুম বুকিং আছে। খাবার অর্ডার করতে হলে আগে কার্ট খালি করুন।';
		wc_add_notice( $msg . ' <a class="button" href="' . esc_url( $clear_url ) . '">কার্ট খালি করুন</a>', 'error' );
		return false;
	}
	return $passed;
}, 10, 2 );

add_action( 'template_redirect', function () {
	if ( isset( $_GET['arc_clear_cart'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'arc_clear_cart' ) ) {
		WC()->cart->empty_cart();
		wc_add_notice( 'কার্ট খালি করা হয়েছে। এবার নতুন করে যোগ করুন।', 'success' );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
} );

add_action( 'woocommerce_check_cart_items', function () {
	if ( arc_cart_has( 'room' ) && arc_cart_has( 'food' ) ) {
		$clear_url = wp_nonce_url( add_query_arg( 'arc_clear_cart', '1', wc_get_cart_url() ), 'arc_clear_cart' );
		wc_add_notice( 'রুম ও খাবার একসাথে অর্ডার করা যায় না। <a href="' . esc_url( $clear_url ) . '">কার্ট খালি করে</a> আলাদাভাবে অর্ডার করুন।', 'error' );
	}
} );

/* =========================================================
 * 3. Conditional checkout fields
 * ======================================================= */
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {

	unset( $fields['billing']['billing_last_name'], $fields['billing']['billing_company'],
	       $fields['billing']['billing_address_2'], $fields['billing']['billing_postcode'],
	       $fields['billing']['billing_city'] );

	$fields['billing']['billing_first_name']['label']    = 'পূর্ণ নাম';
	$fields['billing']['billing_first_name']['class']    = array( 'form-row-wide' );
	$fields['billing']['billing_phone']['class']         = array( 'form-row-first' );
	$fields['billing']['billing_phone']['priority']      = 20;
	$fields['billing']['billing_email']['class']         = array( 'form-row-last' );
	$fields['billing']['billing_email']['priority']      = 30;

	/* ---------- FOOD ---------- */
	if ( arc_cart_has( 'food' ) && ! arc_cart_has( 'room' ) ) {
		$fields['billing']['billing_phone']['required'] = true;
		$fields['billing']['billing_state']['label']    = 'জেলা (District)';
		$fields['billing']['billing_state']['required'] = true;
		$fields['billing']['billing_state']['priority'] = 40;
		$fields['billing']['billing_address_1']['label']       = 'ঠিকানা';
		$fields['billing']['billing_address_1']['placeholder'] = 'বাসা/রোড/এলাকা';
		$fields['billing']['billing_address_1']['priority']    = 50;
		return $fields;
	}

	/* ---------- ROOM ---------- */
	if ( arc_cart_has( 'room' ) ) {
		$cfg = arc_cfg();
		unset( $fields['billing']['billing_address_1'], $fields['billing']['billing_state'], $fields['billing']['billing_country'] );

		$fields['billing']['billing_first_name']['label']       = 'Guest 1 (Primary Guest) — Full Name';
		$fields['billing']['billing_first_name']['placeholder'] = 'NID/Passport অনুযায়ী নাম';
		$fields['billing']['billing_phone']['required']         = true;
		$fields['billing']['billing_phone']['label']            = 'Mobile Number';
		$fields['billing']['billing_phone']['placeholder']      = '+8801XXXXXXXXX';
		$fields['billing']['billing_email']['label']            = 'Email (কনফার্মেশন এখানে যাবে)';

		$fields['billing']['arc_id_number'] = array(
			'type' => 'text', 'label' => 'NID / Passport Number', 'required' => true,
			'placeholder' => 'চেক-ইনের সময় এই আইডি দেখাতে হবে',
			'class' => array( 'form-row-wide' ), 'priority' => 35,
		);

		// অতিরিক্ত গেস্টের নাম (adults সংখ্যা অনুযায়ী)
		$item = arc_first_room_item();
		if ( $item ) {
			$b = arc_get_room_booking_data( $item );
			for ( $i = 2; $i <= min( 8, (int) $b['adults'] ); $i++ ) {
				$fields['billing'][ 'arc_guest_' . $i ] = array(
					'type' => 'text', 'label' => 'Guest ' . $i . ' (Adult) — Full Name',
					'required' => (bool) $cfg['require_guest_names'],
					'class' => array( 'form-row-wide' ), 'priority' => 36 + $i,
				);
			}
		}

		// Additional Requests (চেকবক্স)
		$p = 10;
		foreach ( arc_request_options() as $key => $label ) {
			$fields['order'][ $key ] = array(
				'type' => 'checkbox', 'label' => $label, 'required' => false,
				'class' => array( 'arc-check' ), 'priority' => $p++,
			);
		}
		$fields['order']['arc_bed_type'] = array(
			'type' => 'radio', 'label' => 'Bed Type', 'required' => false,
			'options' => array( 'Large Bed' => 'Large Bed', 'Twin Beds' => 'Twin Beds' ),
			'class' => array( 'arc-wide' ), 'priority' => 30,
		);
		$fields['order']['arc_arrival_time'] = array(
			'type' => 'select', 'label' => 'Estimated Arrival Time', 'required' => false,
			'options' => array(
				'' => 'নির্বাচন করুন', 'Before 12 PM' => 'Before 12 PM', '12 PM – 2 PM' => '12 PM – 2 PM',
				'2 PM – 6 PM' => '2 PM – 6 PM', '6 PM – 10 PM' => '6 PM – 10 PM', 'After 10 PM' => 'After 10 PM',
			),
			'class' => array( 'arc-wide' ), 'priority' => 31,
		);
		$fields['order']['order_comments']['label']       = 'Special Requests';
		$fields['order']['order_comments']['placeholder'] = 'প্রপার্টিকে জানাতে চান এমন কিছু লিখুন (যেমন: ফ্লাইট নম্বর, বিশেষ প্রয়োজন)';
		$fields['order']['order_comments']['class']       = array( 'arc-wide' );
		$fields['order']['order_comments']['priority']    = 40;

		$terms_label = 'আমি Terms & Conditions ও Cancellation Policy মেনে নিচ্ছি';
		if ( ! empty( $cfg['terms_url'] ) ) {
			$terms_label = 'আমি <a href="' . esc_url( $cfg['terms_url'] ) . '" target="_blank" rel="noopener">Terms & Conditions</a> ও Cancellation Policy মেনে নিচ্ছি';
		}
		$fields['order']['arc_terms'] = array(
			'type' => 'checkbox', 'label' => $terms_label, 'required' => true,
			'class' => array( 'arc-wide', 'arc-terms' ), 'priority' => 50,
		);
	}
	return $fields;
}, 20 );

/* Validation */
add_action( 'woocommerce_after_checkout_validation', function ( $data, $errors ) {
	if ( arc_cart_has( 'room' ) && arc_cart_has( 'food' ) ) {
		$errors->add( 'arc_mixed', 'রুম ও খাবার একই অর্ডারে দেওয়া যাবে না।' );
		return;
	}
	if ( ! arc_cart_has( 'room' ) ) return;

	$id = preg_replace( '/\s+/', '', $data['arc_id_number'] ?? '' );
	if ( ! preg_match( '/^(\d{10}|\d{13}|\d{17}|[A-Za-z]{1,2}\d{6,8})$/', $id ) ) {
		$errors->add( 'arc_id', 'সঠিক NID বা পাসপোর্ট নম্বর দিন।' );
	}
	$digits = preg_replace( '/\D/', '', $data['billing_phone'] ?? '' );
	if ( strlen( $digits ) < 8 || strlen( $digits ) > 15 ) {
		$errors->add( 'arc_phone', 'সঠিক মোবাইল নম্বর দিন।' );
	}
	// Terms চেকবক্স WooCommerce নিজেই required হিসেবে যাচাই করে (field-এ required => true)
}, 10, 2 );

/* =========================================================
 * 4. Order meta সেভ
 * ======================================================= */
add_action( 'woocommerce_checkout_create_order', function ( $order, $data ) {
	if ( ! arc_cart_has( 'room' ) ) {
		$order->update_meta_data( '_arc_order_type', 'food' );
		return;
	}
	$order->update_meta_data( '_arc_order_type', 'room' );
	$order->update_meta_data( '_arc_id_number', sanitize_text_field( $data['arc_id_number'] ?? '' ) );

	$item = arc_first_room_item();
	if ( $item ) $order->update_meta_data( '_arc_booking', arc_get_room_booking_data( $item ) );

	$guests = array();
	for ( $i = 2; $i <= 8; $i++ ) {
		if ( ! empty( $data[ 'arc_guest_' . $i ] ) ) $guests[ $i ] = sanitize_text_field( $data[ 'arc_guest_' . $i ] );
	}
	$order->update_meta_data( '_arc_guests', $guests );

	$reqs = array();
	foreach ( arc_request_options() as $key => $label ) {
		if ( ! empty( $data[ $key ] ) ) $reqs[] = $label;
	}
	$order->update_meta_data( '_arc_requests', $reqs );
	$order->update_meta_data( '_arc_bed_type', sanitize_text_field( $data['arc_bed_type'] ?? '' ) );
	$order->update_meta_data( '_arc_arrival', sanitize_text_field( $data['arc_arrival_time'] ?? '' ) );
}, 10, 2 );

/** বুকিং ডিটেইলসের সারি (label => value) — admin / email / thank-you তিন জায়গাতেই ব্যবহার */
function arc_booking_rows( $order, $mask_id = false ) {
	$rows = array();
	if ( $order->get_meta( '_arc_order_type' ) !== 'room' ) return $rows;

	$b = $order->get_meta( '_arc_booking' );
	if ( is_array( $b ) ) {
		$n = arc_nights( $b );
		$rows['Check-in']  = $b['check_in']  ? date_i18n( 'D, d M Y', strtotime( $b['check_in'] ) )  : '—';
		$rows['Check-out'] = $b['check_out'] ? date_i18n( 'D, d M Y', strtotime( $b['check_out'] ) ) : '—';
		if ( $n ) $rows['Nights'] = (string) $n;
		$g = arc_plural( $b['adults'], 'Adult' );
		if ( ! empty( $b['children'] ) ) $g .= ', ' . arc_plural( $b['children'], 'Child' ) ;
		$rows['Guests'] = str_replace( 'Childs', 'Children', $g );
	}
	$rows['Primary Guest'] = trim( $order->get_billing_first_name() );
	foreach ( (array) $order->get_meta( '_arc_guests' ) as $i => $name ) $rows[ 'Guest ' . $i ] = $name;
	if ( $id = $order->get_meta( '_arc_id_number' ) ) $rows['NID / Passport'] = $mask_id ? arc_mask( $id ) : $id;
	if ( $t = $order->get_meta( '_arc_bed_type' ) )   $rows['Bed Type'] = $t;
	if ( $t = $order->get_meta( '_arc_arrival' ) )    $rows['Arrival Time'] = $t;
	if ( $r = (array) $order->get_meta( '_arc_requests' ) ) { $r = array_filter( $r ); if ( $r ) $rows['Requests'] = implode( ', ', $r ); }
	if ( $note = $order->get_customer_note() ) $rows['Special Requests'] = $note;
	return $rows;
}

/* Admin order পেজ */
add_action( 'woocommerce_admin_order_data_after_billing_address', function ( $order ) {
	$rows = arc_booking_rows( $order, false );
	if ( ! $rows ) return;
	echo '<div style="margin-top:12px"><strong>Hotel Booking Details</strong>';
	foreach ( $rows as $l => $v ) echo '<p style="margin:2px 0"><em>' . esc_html( $l ) . ':</em> ' . esc_html( $v ) . '</p>';
	echo '</div>';
} );

/* ইমেইল: গ্রাহকের ইমেইলে NID masked, admin এ পূর্ণ */
add_action( 'woocommerce_email_after_order_table', function ( $order, $sent_to_admin, $plain_text ) {
	$rows = arc_booking_rows( $order, ! $sent_to_admin );
	if ( ! $rows ) return;
	if ( $plain_text ) {
		echo "\n== Booking Details ==\n";
		foreach ( $rows as $l => $v ) echo $l . ': ' . $v . "\n";
		return;
	}
	echo '<h2 style="margin:24px 0 8px">Booking Details</h2><table cellspacing="0" cellpadding="6" style="width:100%;border:1px solid #e5e5e5" border="1">';
	foreach ( $rows as $l => $v ) {
		echo '<tr><th style="text-align:left;width:35%">' . esc_html( $l ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
	}
	echo '</table>';
}, 10, 3 );

/* Thank-you / Confirmation কার্ড */
add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order || $order->get_meta( '_arc_order_type' ) !== 'room' ) return;
	$cfg  = arc_cfg();
	$rows = arc_booking_rows( $order, true );
	echo '<div class="arc-confirm"><div class="arc-confirm__head"><span class="arc-tick">✓</span><div><h2>Booking Received</h2><p>Booking ID: <strong>#' . esc_html( $order->get_order_number() ) . '</strong></p></div></div>';
	echo '<h3>' . esc_html( $cfg['hotel_name'] ) . '</h3><table class="arc-confirm__table">';
	foreach ( $rows as $l => $v ) echo '<tr><th>' . esc_html( $l ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
	echo '</table><p class="arc-muted">কনফার্মেশন ইমেইল ' . esc_html( $order->get_billing_email() ) . ' এ পাঠানো হয়েছে।';
	if ( $cfg['support_phone'] ) echo ' সহায়তা: ' . esc_html( $cfg['support_phone'] );
	echo '</p></div>';
}, 5 );

/* =========================================================
 * 5. Checkout UI (শুধু রুম কার্ট)
 * ======================================================= */
/* 5a. Review Your Booking কার্ড */
add_action( 'woocommerce_checkout_before_customer_details', function () {
	if ( ! arc_cart_has( 'room' ) ) return;
	$cfg = arc_cfg();

	echo '<div class="arc-booking-card"><h3>Review Your Booking</h3>';
	foreach ( WC()->cart->get_cart() as $item ) {
		if ( ! arc_is_room_product( $item['product_id'] ) ) continue;
		$p = $item['data'];
		$b = arc_get_room_booking_data( $item );
		$n = arc_nights( $b );
		$guests = arc_plural( $b['adults'], 'Adult' );
		if ( $b['children'] ) $guests .= ', ' . arc_plural( $b['children'], 'Child' );
		$guests = str_replace( 'Childs', 'Children', $guests );
		?>
		<div class="arc-hotel">
			<strong><?php echo esc_html( $cfg['hotel_name'] ); ?></strong>
			<?php if ( (int) $cfg['stars'] > 0 ) echo '<span class="arc-stars">' . str_repeat( '★', min( 5, (int) $cfg['stars'] ) ) . '</span>'; ?>
			<?php if ( $cfg['hotel_address'] ) echo '<div class="arc-muted">' . esc_html( $cfg['hotel_address'] ) . '</div>'; ?>
		</div>
		<div class="arc-room">
			<div class="arc-room__img"><?php echo $p->get_image( 'thumbnail' ); ?></div>
			<div class="arc-room__info">
				<h4><?php echo esc_html( $p->get_name() ); ?></h4>
				<span class="arc-muted"><?php echo esc_html( $guests ); ?> | <?php echo esc_html( arc_plural( $item['quantity'], 'Room' ) ); ?></span>
			</div>
		</div>
		<div class="arc-dates">
			<div><small>Check In (<?php echo esc_html( $cfg['checkin_time'] ); ?>)</small>
				<strong><?php echo esc_html( $b['check_in'] ? date_i18n( 'd M, Y', strtotime( $b['check_in'] ) ) : '-' ); ?></strong>
				<small><?php echo esc_html( $b['check_in'] ? date_i18n( 'l', strtotime( $b['check_in'] ) ) : '' ); ?></small></div>
			<div class="arc-nights"><?php echo $n ? esc_html( arc_plural( $n, 'Night' ) ) : ''; ?></div>
			<div style="text-align:right"><small>Check Out (<?php echo esc_html( $cfg['checkout_time'] ); ?>)</small>
				<strong><?php echo esc_html( $b['check_out'] ? date_i18n( 'd M, Y', strtotime( $b['check_out'] ) ) : '-' ); ?></strong>
				<small><?php echo esc_html( $b['check_out'] ? date_i18n( 'l', strtotime( $b['check_out'] ) ) : '' ); ?></small></div>
		</div>
		<?php
		if ( $cfg['cancellation'] ) echo '<p class="arc-cancel">' . esc_html( $cfg['cancellation'] ) . '</p>';
	}
	echo '</div>';
} );

/* 5b. Fare Summary: প্রতি রাতের দাম */
add_action( 'woocommerce_review_order_before_order_total', function () {
	if ( ! arc_cart_has( 'room' ) ) return;
	$item = arc_first_room_item();
	if ( ! $item ) return;
	$n = arc_nights( arc_get_room_booking_data( $item ) );
	if ( $n < 2 ) return; // ১ রাত হলে Subtotal-এর সাথে একই, তাই দেখানো হয় না
	$per = $item['line_total'] / $n / max( 1, (int) $item['quantity'] );
	echo '<tr class="arc-row"><th>Room rate (' . esc_html( arc_plural( $n, 'night' ) ) . ')</th><td>' . wc_price( $per ) . ' / night</td></tr>';
} );

/* 5c. Important Information বক্স (বাম কলামের নিচে) */
add_action( 'woocommerce_checkout_after_customer_details', function () {
	if ( ! arc_cart_has( 'room' ) ) return;
	$cfg = arc_cfg();
	$items = array(
		'Check-in & Check-out' => 'Check-in ' . $cfg['checkin_time'] . ' থেকে, Check-out ' . $cfg['checkout_time'] . ' এর মধ্যে।',
		'Identification'       => 'চেক-ইনের সময় সব গেস্টকে বৈধ NID বা পাসপোর্ট দেখাতে হবে।',
	);
	foreach ( (array) $cfg['policies'] as $t => $txt ) {
		if ( trim( (string) $txt ) !== '' ) $items[ $t ] = $txt;
	}
	echo '<div class="arc-info"><div class="arc-info__head">Important Information</div><div class="arc-info__body">';
	foreach ( $items as $t => $txt ) echo '<h5>' . esc_html( $t ) . '</h5><p>' . esc_html( $txt ) . '</p>';
	echo '</div></div>';
} );

/* 5d. বাটনের নিচে secure/support নোট */
add_action( 'woocommerce_review_order_after_submit', function () {
	if ( ! arc_cart_has( 'room' ) ) return;
	$cfg = arc_cfg();
	echo '<p class="arc-secure">🔒 নিরাপদ বুকিং — আপনার তথ্য সুরক্ষিত';
	if ( $cfg['support_phone'] ) echo '<br>সহায়তা: ' . esc_html( $cfg['support_phone'] );
	echo '</p>';
} );

/* 5e. টেক্সট */
add_filter( 'woocommerce_order_button_text', function ( $t ) { return arc_cart_has( 'room' ) ? 'Confirm Booking' : $t; } );

add_filter( 'gettext', function ( $translated, $text, $domain ) {
	if ( $domain !== 'woocommerce' || is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() || ! arc_cart_has( 'room' ) ) return $translated;
	$map = array( 'Billing details' => 'Guest Details', 'Additional information' => 'Additional Requests' );
	return $map[ $text ] ?? $translated;
}, 10, 3 );

add_filter( 'woocommerce_gateway_title', function ( $title, $id ) {
	return ( $id === 'cod' && arc_cart_has( 'room' ) ) ? 'Pay at Hotel' : $title;
}, 10, 2 );

add_filter( 'woocommerce_gateway_description', function ( $desc, $id ) {
	return ( $id === 'cod' && arc_cart_has( 'room' ) ) ? 'চেক-ইনের সময় হোটেলে মূল্য পরিশোধ করুন।' : $desc;
}, 10, 2 );

/* Additional Requests সেকশনের নিচে নোট */
add_action( 'woocommerce_after_order_notes', function () {
	if ( arc_cart_has( 'room' ) ) echo '<p class="arc-muted" style="margin:8px 0 0">অনুরোধ প্রপার্টিতে পাঠানো হবে; পূরণ করা প্রাপ্যতা সাপেক্ষে।</p>';
} );

/* =========================================================
 * 6. CSS
 * ======================================================= */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_checkout() ) return;
	wp_register_style( 'arc-checkout', false );
	wp_enqueue_style( 'arc-checkout' );
	wp_add_inline_style( 'arc-checkout', '
	form.checkout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(0,1fr);gap:24px;align-items:start}
	form.checkout #customer_details{grid-column:1;width:100%}
	form.checkout #customer_details .col-1,form.checkout #customer_details .col-2{float:none;width:100%}
	form.checkout .arc-info,form.checkout .arc-booking-card{grid-column:1}
	form.checkout #order_review_heading,form.checkout .woocommerce-shipping-fields{display:none}
	form.checkout #order_review{grid-column:2;grid-row:1 / span 3;position:sticky;top:20px;background:#fff;padding:20px;border-radius:10px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
	form.checkout #order_review::before{content:"Fare Summary";display:block;font-size:18px;font-weight:600;margin-bottom:14px}
	#order_review table.shop_table{border:0;margin:0 0 16px;width:100%}
	#order_review table.shop_table thead{display:none}
	#order_review table.shop_table tr{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #eef0f3}
	#order_review table.shop_table th,#order_review table.shop_table td{display:block;border:0;padding:0;background:none}
	#order_review table.shop_table tfoot tr{font-size:14px;color:#475467}
	#order_review table.shop_table tr.order-total{background:#0b0f6b;color:#fff;border-radius:6px;padding:14px 16px;margin-top:10px;border:0;font-size:16px}
	#order_review table.shop_table tr.order-total th,#order_review table.shop_table tr.order-total td,#order_review table.shop_table tr.order-total strong{color:#fff}
	#order_review .hb_wc_cart_room_item .product-name{font-weight:600}
	#order_review .hb_wc_cart_room_item .date,#order_review .hb_wc_cart_room_item .details{font-weight:400;font-size:13px;color:#667085;margin-top:4px}
	#order_review .hb_wc_cart_room_item .date span:first-child,#order_review .hb_wc_cart_room_item .details span:first-child{font-weight:600;color:#344054}
	#payment{background:none!important;border-radius:0}
	#payment ul.payment_methods{border:0;padding:0;margin:0}
	#payment ul.payment_methods li{list-style:none}
	#payment ul.payment_methods label{background:none!important;color:#101828;font-weight:600}
	.woocommerce-checkout #payment div.payment_box{background:#eef3ff;border-radius:6px;color:#344054}
	.woocommerce-checkout #payment div.payment_box::before{display:none}
	.woocommerce-terms-and-conditions-wrapper{margin:14px 0;font-size:13px;color:#667085}
	.woocommerce-terms-and-conditions-wrapper p{margin:0}
	.arc-secure{text-align:center;font-size:12px;color:#667085;margin:12px 0 0}
	.woocommerce-billing-fields,.woocommerce-additional-fields,.arc-booking-card,.arc-info{background:#fff;border-radius:10px;padding:20px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}
	.woocommerce-billing-fields h3,.woocommerce-additional-fields h3,.arc-booking-card h3{font-size:18px;margin:0 0 14px}
	.woocommerce form .form-row input.input-text,.woocommerce form .form-row textarea,.woocommerce form .form-row select{border:1px solid #d5dbe3;border-radius:6px;padding:12px;background:#fafbfc}
	.woocommerce form .form-row label{font-size:13px;color:#667085}
	.woocommerce-additional-fields__field-wrapper{display:grid;grid-template-columns:1fr 1fr;gap:10px}
	.woocommerce-additional-fields__field-wrapper .form-row{float:none!important;width:100%!important;margin:0!important;padding:0!important}
	.woocommerce-additional-fields__field-wrapper .arc-wide{grid-column:1 / -1}
	.woocommerce-additional-fields__field-wrapper .arc-check{border:1px solid #d5dbe3;border-radius:6px;padding:12px 14px!important;background:#fafbfc}
	.woocommerce-additional-fields__field-wrapper .arc-check label{margin:0;display:flex;gap:8px;align-items:center;color:#101828;font-size:14px}
	.woocommerce-additional-fields__field-wrapper .arc-terms{border:0;background:none;padding:4px 0!important}
	.woocommerce-additional-fields__field-wrapper .arc-terms label{font-size:13px}
	.arc-hotel{margin-bottom:12px}
	.arc-hotel strong{font-size:15px}
	.arc-stars{color:#f5a623;margin-left:8px;letter-spacing:1px}
	.arc-room{display:flex;gap:14px;align-items:center;margin-bottom:14px}
	.arc-room__img img{width:80px;height:64px;object-fit:cover;border-radius:6px}
	.arc-room__info h4{margin:0 0 4px;font-size:16px}
	.arc-muted{color:#667085;font-size:13px}
	.arc-dates{display:flex;justify-content:space-between;align-items:center;background:#eef3ff;border-radius:8px;padding:14px}
	.arc-dates small{display:block;color:#667085}
	.arc-dates strong{display:block;font-size:15px}
	.arc-nights{border:1px solid #cbd5e1;background:#fff;border-radius:4px;padding:2px 10px;font-size:12px}
	.arc-nights:empty{display:none}
	.arc-cancel{margin:12px 0 0;font-size:13px;color:#027a48}
	.arc-info{padding:0;overflow:hidden}
	.arc-info__head{background:#dc3545;color:#fff;font-weight:600;padding:14px 20px}
	.arc-info__body{padding:6px 20px 16px}
	.arc-info__body h5{margin:14px 0 4px;font-size:14px}
	.arc-info__body p{margin:0;font-size:13px;color:#475467}
	#arc_bed_type_field .woocommerce-input-wrapper{display:flex;flex-wrap:wrap;gap:10px}
	#arc_bed_type_field input[type=radio]{position:absolute;opacity:0;width:0;height:0}
	#arc_bed_type_field label.radio{display:inline-block;margin:0;padding:10px 18px;border:1px solid #d5dbe3;border-radius:6px;background:#fafbfc;color:#101828;cursor:pointer;font-size:14px}
	#arc_bed_type_field input[type=radio]:checked + label.radio{border-color:#0b0f6b;background:#eef3ff;color:#0b0f6b;font-weight:600}
	#place_order{width:100%;background:#fcc800;color:#111;font-weight:700;border-radius:6px;padding:16px}
	#place_order:hover{background:#e6b700}
	@media(max-width:900px){form.checkout{grid-template-columns:1fr}
	form.checkout #order_review{grid-column:1;grid-row:auto;position:static}
	.woocommerce-additional-fields__field-wrapper{grid-template-columns:1fr}}
	' );
	// রুম কার্টে WooCommerce এর স্বয়ংক্রিয় "(optional)" লেখা লুকানো
	if ( arc_cart_has( 'room' ) ) {
		wp_add_inline_style( 'arc-checkout', 'form.checkout .optional{display:none}' );
	}
} );

/* Confirmation পেজের CSS */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) return;
	wp_register_style( 'arc-confirm', false );
	wp_enqueue_style( 'arc-confirm' );
	wp_add_inline_style( 'arc-confirm', '
	.arc-confirm{background:#fff;border-radius:10px;padding:24px;margin:0 0 24px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
	.arc-confirm__head{display:flex;gap:14px;align-items:center;margin-bottom:12px}
	.arc-confirm__head h2{margin:0;font-size:22px}
	.arc-confirm__head p{margin:2px 0 0}
	.arc-tick{display:inline-flex;width:44px;height:44px;border-radius:50%;background:#12b76a;color:#fff;align-items:center;justify-content:center;font-size:22px}
	.arc-confirm h3{margin:8px 0;font-size:17px}
	.arc-confirm__table{width:100%;border-collapse:collapse}
	.arc-confirm__table th,.arc-confirm__table td{padding:8px 0;border-bottom:1px solid #eef0f3;text-align:left;font-size:14px}
	.arc-confirm__table th{width:35%;color:#667085;font-weight:500}
	.arc-muted{color:#667085;font-size:13px}
	' );
} );
```

---

## 2. WordPress Post/Page Revisions Limit

### Purpose
Limits WordPress revisions for posts and pages to a maximum of 3 to prevent database bloating on free/shared hosting (`wuaze.com`).

### Full Snippet Code
```php
<?php
/**
 * Limit WordPress Post/Page Revisions to Maximum 3
 */
if ( ! defined( 'WP_POST_REVISIONS' ) ) {
    define( 'WP_POST_REVISIONS', 3 );
}
```
