<?php
/**
 * Review Your Booking Checkout Card (OTA Style).
 *
 * @package ARC\HotelCore
 */

use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $room_item ) ) {
	return;
}

$hotel_info   = Settings::get_hotel_info();
$policy_text  = Settings::get( 'cancellation_policy_text' );
$checkin_time = $hotel_info['standardCheckInTime'] ?? '14:00';
$checkout_time= $hotel_info['standardCheckOutTime'] ?? '12:00';
?>

<div class="arc-review-booking-card">
	<div class="arc-card-header">
		<div class="arc-hotel-brand">
			<span class="arc-stars">&#9733;&#9733;&#9733;&#9733;&#9734;</span>
			<h2 class="arc-hotel-name"><?php echo esc_html( $hotel_info['hotelName'] ?? __( 'Hotel Reservation', 'arc-hotel-core' ) ); ?></h2>
		</div>
		<span class="arc-badge-stay"><?php echo esc_html( $room_item['nights'] ); ?> <?php echo esc_html( _n( 'Night', 'Nights', $room_item['nights'], 'arc-hotel-core' ) ); ?></span>
	</div>

	<div class="arc-card-body">
		<?php if ( ! empty( $room_item['thumbnail_url'] ) ) : ?>
			<div class="arc-card-thumbnail">
				<img src="<?php echo esc_url( $room_item['thumbnail_url'] ); ?>" alt="<?php echo esc_attr( $room_item['room_type_name'] ); ?>" />
			</div>
		<?php endif; ?>

		<div class="arc-card-summary">
			<h3 class="arc-room-title"><?php echo esc_html( $room_item['room_type_name'] ); ?></h3>

			<div class="arc-dates-grid">
				<div class="arc-date-col">
					<span class="arc-label"><?php esc_html_e( 'Check-in', 'arc-hotel-core' ); ?></span>
					<strong class="arc-date-val"><?php echo esc_html( $room_item['check_in'] ); ?></strong>
					<span class="arc-time-val"><?php printf( esc_html__( 'From %s', 'arc-hotel-core' ), esc_html( $checkin_time ) ); ?></span>
				</div>
				<div class="arc-date-arrow">&#8594;</div>
				<div class="arc-date-col">
					<span class="arc-label"><?php esc_html_e( 'Check-out', 'arc-hotel-core' ); ?></span>
					<strong class="arc-date-val"><?php echo esc_html( $room_item['check_out'] ); ?></strong>
					<span class="arc-time-val"><?php printf( esc_html__( 'Until %s', 'arc-hotel-core' ), esc_html( $checkout_time ) ); ?></span>
				</div>
			</div>

			<div class="arc-guests-line">
				<span>&#128100; <?php echo esc_html( $room_item['adults'] ); ?> <?php esc_html_e( 'Adults', 'arc-hotel-core' ); ?></span>
				<?php if ( ! empty( $room_item['children'] ) ) : ?>
					<span> &bull; &#128102; <?php echo esc_html( $room_item['children'] ); ?> <?php esc_html_e( 'Children', 'arc-hotel-core' ); ?></span>
				<?php endif; ?>
			</div>

			<div class="arc-price-breakdown">
				<div class="arc-price-row">
					<span><?php esc_html_e( 'Rate per night:', 'arc-hotel-core' ); ?></span>
					<span><?php echo esc_html( Settings::format_price( (float) $room_item['rate_per_night'] ) ); ?></span>
				</div>
				<div class="arc-price-row arc-price-total">
					<span><strong><?php esc_html_e( 'Total Room Fare:', 'arc-hotel-core' ); ?></strong></span>
					<strong class="arc-highlight"><?php echo esc_html( Settings::format_price( (float) $room_item['total_price'] ) ); ?></strong>
				</div>
			</div>
		</div>
	</div>

	<?php if ( ! empty( $policy_text ) ) : ?>
		<div class="arc-card-policy">
			<span class="arc-policy-icon">&#9432;</span>
			<span class="arc-policy-text"><?php echo esc_html( $policy_text ); ?></span>
		</div>
	<?php endif; ?>
</div>
