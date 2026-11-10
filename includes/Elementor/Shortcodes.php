<?php
/**
 * Shortcodes Registry.
 *
 * @package ARC\HotelCore\Elementor
 */

namespace ARC\HotelCore\Elementor;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Settings\Settings;
use ARC\HotelCore\Rooms\Room_Post_Type;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers all shortcodes for hotel showcase, search, results, and tracking.
 */
class Shortcodes {

	/**
	 * Register all plugin shortcodes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_shortcode( 'arc_hotel_info', [ $this, 'render_hotel_info' ] );
		add_shortcode( 'arc_rooms', [ $this, 'render_rooms_grid' ] );
		add_shortcode( 'arc_search', [ $this, 'render_search_bar' ] );
		add_shortcode( 'arc_results', [ $this, 'render_search_results' ] );
		add_shortcode( 'arc_room_booking', [ $this, 'render_single_room_booking' ] );
		add_shortcode( 'arc_track', [ $this, 'render_track_booking' ] );
	}

	/**
	 * [arc_hotel_info field="phone|email|address|hotelName|times"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_hotel_info( array $atts = [] ): string {
		$atts = shortcode_atts( [
			'field' => 'hotelName',
		], $atts, 'arc_hotel_info' );

		$info  = Settings::get_hotel_info();
		$field = strtolower( sanitize_key( $atts['field'] ) );

		return match ( $field ) {
			'hotelname', 'name' => esc_html( $info['hotelName'] ?? '' ),
			'phone'             => esc_html( $info['phone'] ?? '' ),
			'email'             => esc_html( $info['email'] ?? '' ),
			'address'           => esc_html( $info['address'] ?? '' ),
			'contactperson'     => esc_html( $info['contactPerson'] ?? '' ),
			'checkin_time'      => esc_html( $info['standardCheckInTime'] ?? '14:00' ),
			'checkout_time'     => esc_html( $info['standardCheckOutTime'] ?? '12:00' ),
			'times'             => sprintf(
				/* translators: 1: Check-in time, 2: Check-out time */
				esc_html__( 'Check-in: %1$s | Check-out: %2$s', 'arc-hotel-core' ),
				esc_html( $info['standardCheckInTime'] ?? '14:00' ),
				esc_html( $info['standardCheckOutTime'] ?? '12:00' )
			),
			default             => esc_html( $info[ $atts['field'] ] ?? '' ),
		};
	}

	/**
	 * [arc_rooms columns="3"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_rooms_grid( array $atts = [] ): string {
		$atts = shortcode_atts( [
			'columns'  => 3,
			'limit'    => -1,
			'category' => '',
		], $atts, 'arc_rooms' );

		$columns      = max( 1, min( 4, absint( $atts['columns'] ) ) );
		$limit        = (int) $atts['limit'];
		$company_code = Settings::get( 'company_code' );

		$query_args = [
			'post_type'      => Room_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		];

		if ( ! empty( $company_code ) ) {
			$query_args['meta_query'] = [
				[
					'key'     => '_arc_company_code',
					'value'   => $company_code,
					'compare' => '=',
				],
			];
		}

		if ( ! empty( $atts['category'] ) ) {
			$query_args['tax_query'] = [
				[
					'taxonomy' => Room_Post_Type::TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_title( $atts['category'] ),
				],
			];
		}

		$posts = get_posts( $query_args );

		if ( empty( $posts ) ) {
			return '<p class="arc-no-rooms">' . esc_html__( 'No rooms available at the moment. Please check back later.', 'arc-hotel-core' ) . '</p>';
		}

		ob_start();
		?>
		<div class="arc-rooms-grid arc-grid-cols-<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $posts as $post ) :
				$post_id    = $post->ID;
				$thumb_url  = get_the_post_thumbnail_url( $post_id, 'large' ) ?: get_post_meta( $post_id, '_arc_thumbnail_url', true );
				$from_price = (float) get_post_meta( $post_id, '_arc_from_price', true );
				$adults     = (int) get_post_meta( $post_id, '_arc_max_adults', true );
				$children   = (int) get_post_meta( $post_id, '_arc_max_children', true );
				$floor_name = get_post_meta( $post_id, '_arc_floor_name', true );
				$amenities  = get_post_meta( $post_id, '_arc_amenities', true );
				if ( ! is_array( $amenities ) && ! empty( $amenities ) ) {
					$amenities = maybe_unserialize( $amenities );
				}
				$amenities  = is_array( $amenities ) ? array_slice( $amenities, 0, 4 ) : [];
				$permalink  = get_permalink( $post_id );
				$terms      = get_the_terms( $post_id, Room_Post_Type::TAXONOMY );
				$cat_name   = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->name : '';
				?>
				<div class="arc-room-card">
					<?php if ( ! empty( $thumb_url ) ) : ?>
						<div class="arc-room-thumb">
							<a href="<?php echo esc_url( $permalink ); ?>">
								<img src="<?php echo esc_url( $thumb_url ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" loading="lazy" />
							</a>
							<?php if ( ! empty( $cat_name ) ) : ?>
								<span class="arc-room-badge" style="position: absolute; top: 12px; left: 12px; background: rgba(15,23,42,0.85); color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">
									<?php echo esc_html( $cat_name ); ?>
								</span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
					<div class="arc-room-details">
						<h3 class="arc-room-title">
							<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( get_the_title( $post_id ) ); ?></a>
						</h3>
						<div class="arc-room-capacity">
							<span>&#128100; <?php echo esc_html( $adults ); ?> <?php esc_html_e( 'Adults', 'arc-hotel-core' ); ?></span>
							<?php if ( $children > 0 ) : ?>
								<span> &bull; &#128102; <?php echo esc_html( $children ); ?> <?php esc_html_e( 'Children', 'arc-hotel-core' ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $floor_name ) ) : ?>
								<span> &bull; &#127970; <?php echo esc_html( $floor_name ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( ! empty( $amenities ) ) : ?>
							<div class="arc-room-amenities">
								<?php foreach ( $amenities as $am ) : ?>
									<span class="arc-amenity-pill"><?php echo esc_html( $am ); ?></span>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
						<div class="arc-room-footer">
							<div class="arc-room-price">
								<span class="arc-from"><?php esc_html_e( 'From', 'arc-hotel-core' ); ?></span>
								<strong class="arc-price-amount"><?php echo esc_html( Settings::format_price( $from_price ) ); ?></strong>
								<span class="arc-per-night">/<?php esc_html_e( 'night', 'arc-hotel-core' ); ?></span>
							</div>
							<a href="<?php echo esc_url( $permalink ); ?>" class="arc-btn arc-btn-primary">
								<?php esc_html_e( 'View Details', 'arc-hotel-core' ); ?>
							</a>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [arc_search]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_search_bar( array $atts = [] ): string {
		$today      = current_time( 'Y-m-d' );
		$tomorrow   = gmdate( 'Y-m-d', strtotime( '+1 day', current_time( 'timestamp' ) ) );
		$target_url = esc_url( home_url( '/room-search-results/' ) );

		$def_adults   = Settings::get( 'default_adults', 2 );
		$def_children = Settings::get( 'default_children', 0 );

		// Pre-populate if currently searching
		$check_in  = isset( $_GET['check_in'] ) ? sanitize_text_field( wp_unslash( $_GET['check_in'] ) ) : $today;
		$check_out = isset( $_GET['check_out'] ) ? sanitize_text_field( wp_unslash( $_GET['check_out'] ) ) : $tomorrow;
		$adults    = isset( $_GET['adults'] ) ? max( 1, absint( $_GET['adults'] ) ) : $def_adults;
		$children  = isset( $_GET['children'] ) ? absint( $_GET['children'] ) : $def_children;

		ob_start();
		?>
		<form method="get" action="<?php echo esc_url( $target_url ); ?>" class="arc-search-form">
			<div class="arc-search-field">
				<label for="arc_check_in"><?php esc_html_e( 'Check-in Date', 'arc-hotel-core' ); ?></label>
				<input type="date" id="arc_check_in" name="check_in" value="<?php echo esc_attr( $check_in ); ?>" min="<?php echo esc_attr( $today ); ?>" required />
			</div>
			<div class="arc-search-field">
				<label for="arc_check_out"><?php esc_html_e( 'Check-out Date', 'arc-hotel-core' ); ?></label>
				<input type="date" id="arc_check_out" name="check_out" value="<?php echo esc_attr( $check_out ); ?>" min="<?php echo esc_attr( $tomorrow ); ?>" required />
			</div>
			<div class="arc-search-field arc-field-adults">
				<label for="arc_adults"><?php esc_html_e( 'Adults', 'arc-hotel-core' ); ?></label>
				<select id="arc_adults" name="adults">
					<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
						<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $adults, $i ); ?>><?php echo esc_html( $i ); ?></option>
					<?php endfor; ?>
				</select>
			</div>
			<div class="arc-search-field arc-field-children">
				<label for="arc_children"><?php esc_html_e( 'Children', 'arc-hotel-core' ); ?></label>
				<select id="arc_children" name="children">
					<?php for ( $c = 0; $c <= 6; $c++ ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $children, $c ); ?>><?php echo esc_html( $c ); ?></option>
					<?php endfor; ?>
				</select>
			</div>
			<div class="arc-search-field arc-field-submit">
				<button type="submit" class="arc-btn arc-btn-primary arc-search-submit">
					<?php esc_html_e( 'Check Availability', 'arc-hotel-core' ); ?>
				</button>
			</div>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * [arc_results] - Search Results Page Controller & View
	 *
	 * @return string HTML output.
	 */
	public function render_search_results(): string {
		if ( empty( $_GET['check_in'] ) || empty( $_GET['check_out'] ) ) {
			return '<div class="arc-search-prompt">' .
				'<p>' . esc_html__( 'Please select your check-in and check-out dates above to search for available rooms.', 'arc-hotel-core' ) . '</p>' .
				$this->render_search_bar() .
			'</div>';
		}

		$check_in     = sanitize_text_field( wp_unslash( $_GET['check_in'] ) );
		$check_out    = sanitize_text_field( wp_unslash( $_GET['check_out'] ) );
		$adults       = isset( $_GET['adults'] ) ? max( 1, absint( $_GET['adults'] ) ) : 2;
		$children     = isset( $_GET['children'] ) ? absint( $_GET['children'] ) : 0;
		$company_code = Settings::get( 'company_code' );

		$client = new Api_Client();
		$result = $client->get(
			'api/v1/public-booking/available-rooms',
			[
				'companyCode' => $company_code,
				'checkIn'     => $check_in . 'T14:00:00',
				'checkOut'    => $check_out . 'T12:00:00',
				'adults'      => $adults,
			],
			[ 'use_cache' => false ]
		);

		if ( ! $result->ok() ) {
			return '<div class="arc-notice arc-notice-error"><p>' . esc_html( $result->message() ) . '</p></div>';
		}

		$rooms = $result->data();
		if ( empty( $rooms ) || ! is_array( $rooms ) ) {
			return '<div class="arc-notice arc-notice-info">' .
				'<h3>' . esc_html__( 'No Rooms Available', 'arc-hotel-core' ) . '</h3>' .
				'<p>' . esc_html__( 'Unfortunately, there are no rooms available for your selected dates and guest count. Please try different dates.', 'arc-hotel-core' ) . '</p>' .
				$this->render_search_bar() .
			'</div>';
		}

		// Group available rooms by roomTypeId and filter by capacity
		$grouped = [];
		foreach ( $rooms as $room ) {
			$type_id      = (int) ( $room['roomTypeId'] ?? 0 );
			$max_adults   = (int) ( $room['maxAdults'] ?? 2 );
			$max_children = (int) ( $room['maxChildren'] ?? 0 );

			// Client-side capacity verification (API does not filter children)
			if ( $adults > $max_adults || $children > $max_children ) {
				continue;
			}

			if ( ! isset( $grouped[ $type_id ] ) ) {
				$thumb     = esc_url_raw( $room['thumbnailUrl'] ?? '' );
				$desc      = sanitize_text_field( $room['description'] ?? '' );
				$amenities = (array) ( $room['amenities'] ?? [] );

				// Normalization fallback from synced post if API row lacks thumb/desc
				if ( empty( $thumb ) || empty( $desc ) || empty( $amenities ) ) {
					$synced_posts = get_posts( [
						'post_type'      => Room_Post_Type::POST_TYPE,
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'meta_query'     => [
							[
								'key'     => '_arc_room_type_id',
								'value'   => $type_id,
								'compare' => '=',
							],
						],
					] );

					if ( ! empty( $synced_posts ) ) {
						$pid = $synced_posts[0];
						if ( empty( $thumb ) ) {
							$thumb = get_the_post_thumbnail_url( $pid, 'large' ) ?: get_post_meta( $pid, '_arc_thumbnail_url', true );
						}
						if ( empty( $desc ) ) {
							$p_obj = get_post( $pid );
							$desc  = wp_strip_all_tags( $p_obj->post_content ?? '' );
						}
						if ( empty( $amenities ) ) {
							$amenities = (array) get_post_meta( $pid, '_arc_amenities', true );
						}
					}
				}

				$grouped[ $type_id ] = [
					'roomTypeId'     => $type_id,
					'roomTypeName'   => $room['roomTypeName'] ?? '',
					'description'    => $desc,
					'thumbnailUrl'   => $thumb,
					'pricePerNight'  => (float) ( $room['pricePerNight'] ?? 0 ),
					'nights'         => (int) ( $room['nights'] ?? 1 ),
					'totalPrice'     => (float) ( $room['totalPrice'] ?? 0 ),
					'maxAdults'      => $max_adults,
					'maxChildren'    => $max_children,
					'amenities'      => $amenities,
					'availableCount' => 1,
					'sampleRoomId'   => (int) ( $room['roomId'] ?? 0 ),
				];
			} else {
				$grouped[ $type_id ]['availableCount']++;
			}
		}

		if ( empty( $grouped ) ) {
			return '<div class="arc-notice arc-notice-info">' .
				'<h3>' . esc_html__( 'No Suitable Rooms Found', 'arc-hotel-core' ) . '</h3>' .
				'<p>' . esc_html__( 'We could not find rooms matching your required adult and children count. Please consider booking multiple rooms or adjusting guest numbers.', 'arc-hotel-core' ) . '</p>' .
				$this->render_search_bar() .
			'</div>';
		}

		ob_start();
		?>
		<div class="arc-search-summary">
			<h2><?php esc_html_e( 'Available Rooms & Suites', 'arc-hotel-core' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: Check-in, 2: Check-out, 3: Adults count, 4: Children count */
					esc_html__( 'Stay from %1$s to %2$s &bull; %3$d Adults, %4$d Children', 'arc-hotel-core' ),
					'<strong>' . esc_html( $check_in ) . '</strong>',
					'<strong>' . esc_html( $check_out ) . '</strong>',
					absint( $adults ),
					absint( $children )
				);
				?>
			</p>
		</div>

		<div class="arc-results-list">
			<?php foreach ( $grouped as $group ) : ?>
				<div class="arc-result-card">
					<?php if ( ! empty( $group['thumbnailUrl'] ) ) : ?>
						<div class="arc-result-thumb">
							<img src="<?php echo esc_url( $group['thumbnailUrl'] ); ?>" alt="<?php echo esc_attr( $group['roomTypeName'] ); ?>" loading="lazy" />
						</div>
					<?php endif; ?>
					<div class="arc-result-info">
						<h3><?php echo esc_html( $group['roomTypeName'] ); ?></h3>
						<?php if ( ! empty( $group['description'] ) ) : ?>
							<p class="arc-result-desc"><?php echo esc_html( $group['description'] ); ?></p>
						<?php endif; ?>
						<div class="arc-result-amenities">
							<?php foreach ( array_slice( $group['amenities'], 0, 5 ) as $amenity ) : ?>
								<span class="arc-amenity-pill"><?php echo esc_html( $amenity ); ?></span>
							<?php endforeach; ?>
						</div>
						<div class="arc-rooms-left" style="color: #059669; font-weight: 600; font-size: 13px; margin-top: 8px;">
							&#10004; <?php printf( esc_html__( '%d rooms currently available for your dates', 'arc-hotel-core' ), absint( $group['availableCount'] ) ); ?>
						</div>
					</div>
					<div class="arc-result-pricing">
						<div class="arc-rate-per-night">
							<strong><?php echo esc_html( Settings::format_price( $group['pricePerNight'] ) ); ?></strong>
							<span>/<?php esc_html_e( 'night', 'arc-hotel-core' ); ?></span>
						</div>
						<div class="arc-stay-total">
							<?php
							printf(
								/* translators: 1: Total price, 2: Number of nights */
								esc_html__( 'Total: %1$s (%2$d nights)', 'arc-hotel-core' ),
								'<strong>' . esc_html( Settings::format_price( $group['totalPrice'] ) ) . '</strong>',
								absint( $group['nights'] )
							);
							?>
						</div>
						<form method="post" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="arc-book-now-form">
							<?php wp_nonce_field( 'arc_add_room_to_cart', 'arc_book_nonce' ); ?>
							<input type="hidden" name="arc_action" value="book_room" />
							<input type="hidden" name="room_type_id" value="<?php echo esc_attr( $group['roomTypeId'] ); ?>" />
							<input type="hidden" name="room_id" value="<?php echo esc_attr( $group['sampleRoomId'] ); ?>" />
							<input type="hidden" name="check_in" value="<?php echo esc_attr( $check_in ); ?>" />
							<input type="hidden" name="check_out" value="<?php echo esc_attr( $check_out ); ?>" />
							<input type="hidden" name="adults" value="<?php echo esc_attr( $adults ); ?>" />
							<input type="hidden" name="children" value="<?php echo esc_attr( $children ); ?>" />
							<button type="submit" class="arc-btn arc-btn-primary arc-btn-block">
								<?php esc_html_e( 'Book Now', 'arc-hotel-core' ); ?>
							</button>
						</form>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [arc_room_booking] Single Room Post Availability Box
	 *
	 * @return string HTML output.
	 */
	public function render_single_room_booking(): string {
		global $post;
		if ( ! $post || Room_Post_Type::POST_TYPE !== $post->post_type ) {
			return '';
		}

		$room_type_id = (int) get_post_meta( $post->ID, '_arc_room_type_id', true );
		$from_price   = (float) get_post_meta( $post->ID, '_arc_from_price', true );

		ob_start();
		?>
		<div class="arc-single-booking-box">
			<div class="arc-box-header">
				<span class="arc-from"><?php esc_html_e( 'Starting from', 'arc-hotel-core' ); ?></span>
				<h3><?php echo esc_html( Settings::format_price( $from_price ) ); ?> <span>/<?php esc_html_e( 'night', 'arc-hotel-core' ); ?></span></h3>
			</div>
			<?php echo $this->render_search_bar(); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [arc_track] Track & Cancel Booking Form
	 *
	 * @return string HTML output.
	 */
	public function render_track_booking(): string {
		ob_start();
		?>
		<div class="arc-track-wrap">
			<div class="arc-track-form-box">
				<h2><?php esc_html_e( 'Track or Cancel Your Booking', 'arc-hotel-core' ); ?></h2>
				<p><?php esc_html_e( 'Enter your official Booking Number and the Mobile Phone number used during reservation.', 'arc-hotel-core' ); ?></p>
				<form id="arc-track-form" method="post" action="">
					<?php wp_nonce_field( 'arc_track_booking_action', 'arc_track_nonce' ); ?>
					<div class="arc-form-group">
						<label for="arc_track_number"><?php esc_html_e( 'Booking Number', 'arc-hotel-core' ); ?> *</label>
						<input type="text" id="arc_track_number" name="booking_number" placeholder="e.g. WEB-20261101-7891" required />
					</div>
					<div class="arc-form-group">
						<label for="arc_track_phone"><?php esc_html_e( 'Guest Mobile Number', 'arc-hotel-core' ); ?> *</label>
						<input type="tel" id="arc_track_phone" name="guest_phone" placeholder="e.g. 01700000000" required />
					</div>
					<button type="submit" class="arc-btn arc-btn-primary"><?php esc_html_e( 'Find Reservation', 'arc-hotel-core' ); ?></button>
				</form>
				<div id="arc-track-result" style="margin-top: 20px; display: none;"></div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
