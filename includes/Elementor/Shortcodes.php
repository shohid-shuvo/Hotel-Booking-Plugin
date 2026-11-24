<?php
/**
 * Shortcodes Registry.
 *
 * @package ARC\HotelCore\Elementor
 */

namespace ARC\HotelCore\Elementor;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Booking\Room_Quote;
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
		// add_shortcode( 'arc_room_booking', [ $this, 'render_single_room_booking' ] );
		add_shortcode( 'arc_track', [ $this, 'render_track_booking' ] );
		add_shortcode( 'arc_room_meta', [ $this, 'render_room_meta' ] );
		add_shortcode( 'arc_room_amenities', [ $this, 'render_room_amenities' ] );
		add_shortcode( 'arc_room_gallery', [ $this, 'render_room_gallery' ] );
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
	 * [arc_rooms columns="3" per_page="9"]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_rooms_grid( array $atts = [] ): string {
		$atts = shortcode_atts( [
			'columns'  => 3,
			'per_page' => 9,
			'limit'    => -1,
			'category' => '',
		], $atts, 'arc_rooms' );

		$columns      = max( 1, min( 4, absint( $atts['columns'] ) ) );
		$per_page     = min( PHP_INT_MAX, absint( $atts['per_page'] ) ) ?: 9;
		$limit        = (int) $atts['limit'];
		$company_code = Settings::get( 'company_code' );
		$current_page = max( 1, absint( get_query_var( 'paged', 1 ) ) );

		foreach ( [ 'arc_page', 'paged' ] as $page_arg ) {
			if ( isset( $_GET[ $page_arg ] ) && is_scalar( $_GET[ $page_arg ] ) ) {
				$current_page = max( 1, absint( wp_unslash( $_GET[ $page_arg ] ) ) );
				break;
			}
		}

		$query_args = [
			'post_type'      => Room_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $limit > 0 ? min( $per_page, $limit ) : $per_page,
			'paged'          => $current_page,
			'no_found_rows'  => false,
			'orderby'        => 'menu_order title ID',
			'order'          => 'ASC',
		];

		// Keep oversized page arguments from overflowing the SQL row offset.
		$query_args['paged'] = min( $current_page, intdiv( PHP_INT_MAX, $query_args['posts_per_page'] ) );

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

		$room_query = new \WP_Query( $query_args );

		// Empty out-of-range queries do not always populate found_posts.
		if ( empty( $room_query->posts ) && $current_page > 1 ) {
			$query_args['paged'] = 1;
			$room_query          = new \WP_Query( $query_args );
		}

		$total_rooms  = $limit > 0 ? min( (int) $room_query->found_posts, $limit ) : (int) $room_query->found_posts;
		$total_pages  = (int) ceil( $total_rooms / $per_page );
		$current_page = min( $current_page, max( 1, $total_pages ) );

		if ( $total_rooms > 0 && $query_args['paged'] !== $current_page ) {
			$query_args['paged'] = $current_page;
			$room_query          = new \WP_Query( $query_args );
		}

		// Preserve a positive legacy limit as a total cap, including the last page.
		$remaining_rooms = max( 0, $total_rooms - ( $current_page - 1 ) * $per_page );
		$posts           = array_slice( $room_query->posts, 0, min( $per_page, $remaining_rooms ) );

		if ( empty( $posts ) ) {
			return '<p class="arc-no-rooms">' . esc_html__( 'No rooms available at the moment. Please check back later.', 'arc-hotel-core' ) . '</p>';
		}

		$fallback_thumbnail = 'https://hotelaristocratsinn.com/wp-content/uploads/2026/10/card-image.webp';

		ob_start();
		?>
		<div class="arc-rooms-grid arc-grid-cols-<?php echo esc_attr( $columns ); ?>">
			<?php foreach ( $posts as $post ) :
				$post_id    = $post->ID;
				$thumb_url  = $fallback_thumbnail;
				foreach ( [ get_the_post_thumbnail_url( $post_id, 'large' ), get_post_meta( $post_id, '_arc_thumbnail_url', true ) ] as $candidate ) {
					$candidate = is_string( $candidate ) ? trim( $candidate ) : '';
					$scheme    = strtolower( (string) wp_parse_url( $candidate, PHP_URL_SCHEME ) );
					if (
						! filter_var( $candidate, FILTER_VALIDATE_URL )
						|| preg_match( '/[\x00-\x20\x7f<>"`]/', $candidate )
						|| ! in_array( $scheme, [ 'http', 'https' ], true )
					) {
						continue;
					}
					$thumb_url = esc_url_raw( $candidate, [ 'http', 'https' ] );
					if ( $thumb_url ) {
						break;
					}
					$thumb_url = $fallback_thumbnail;
				}
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
		<?php if ( $total_pages > 1 ) :
			$pagination_base = remove_query_arg( [ 'arc_page', 'paged' ], get_pagenum_link( 1, false ) );
			$pagination_base = str_replace(
				[ '?arc_page=999999999', '&arc_page=999999999' ],
				[ '?arc_page=%#%', '&arc_page=%#%' ],
				add_query_arg( 'arc_page', 999999999, $pagination_base )
			);
			$pagination      = paginate_links( [
				'base'         => $pagination_base,
				'format'       => '',
				'current'      => $current_page,
				'total'        => $total_pages,
				'type'         => 'list',
				'aria_current' => 'page',
				'prev_text'    => esc_html__( 'Previous', 'arc-hotel-core' ),
				'next_text'    => esc_html__( 'Next', 'arc-hotel-core' ),
				'end_size'     => 1,
				'mid_size'     => 2,
			] );
			?>
			<nav class="arc-rooms-pagination" aria-label="<?php echo esc_attr__( 'Room pagination', 'arc-hotel-core' ); ?>">
				<?php echo wp_kses_post( $pagination ); ?>
			</nav>
		<?php endif; ?>
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
					<?php for ( $i = 1; $i <= 8; $i++ ) : ?>
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

		$result = Room_Quote::search( $check_in, $check_out, $adults, $children );

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
							[ 'key' => '_arc_company_code', 'value' => $company_code ],
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
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_single_room_booking( array $atts = [] ): string {
		return Room_Booking_Form::render( $atts );
	}

	/**
	 * [arc_track] Track & Cancel Booking Form
	 *
	 * @return string HTML output.
	 */
	public function render_track_booking(): string {
		wp_enqueue_script( 'arc-track-booking', ARC_PLUGIN_URL . 'assets/js/track-booking.js', [ 'jquery' ], ARC_VERSION, true );
		$id = wp_unique_id( 'arc-track-' );
		ob_start();
		?>
		<div class="arc-track-wrap arc-track-form-box" data-nonces-url="<?php echo esc_url( rest_url( 'arc/v1/fresh-nonces' ) ); ?>" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-track-nonce="<?php echo esc_attr( wp_create_nonce( 'arc_track_booking_action' ) ); ?>" data-cancel-nonce="<?php echo esc_attr( wp_create_nonce( 'arc_cancel_booking_action' ) ); ?>">
			<h2><?php esc_html_e( 'Track Your Reservation', 'arc-hotel-core' ); ?></h2>
			<form class="arc-track-form" method="post">
				<p><label for="<?php echo esc_attr( $id ); ?>-ref"><?php esc_html_e( 'Booking Number', 'arc-hotel-core' ); ?></label><input id="<?php echo esc_attr( $id ); ?>-ref" name="booking_number" type="text" maxlength="120" required></p>
				<p><label for="<?php echo esc_attr( $id ); ?>-phone"><?php esc_html_e( 'Full Booking Phone Number', 'arc-hotel-core' ); ?></label><input id="<?php echo esc_attr( $id ); ?>-phone" name="guest_phone" type="tel" maxlength="30" required></p>
				<button type="submit" class="arc-btn arc-btn-primary"><?php esc_html_e( 'Find Reservation', 'arc-hotel-core' ); ?></button>
			</form>
			<div class="arc-track-result" role="status" aria-live="polite"></div>
			<div class="arc-cancel-panel" hidden>
				<p><?php esc_html_e( 'Cancellation does not issue an automatic payment refund. Contact the hotel about refunds and cancellation policy.', 'arc-hotel-core' ); ?></p>
				<form class="arc-cancel-form"><label for="<?php echo esc_attr( $id ); ?>-reason"><?php esc_html_e( 'Cancellation Reason', 'arc-hotel-core' ); ?></label><textarea id="<?php echo esc_attr( $id ); ?>-reason" name="reason" maxlength="500" required></textarea><button type="submit" class="arc-btn"><?php esc_html_e( 'Cancel Reservation', 'arc-hotel-core' ); ?></button></form>
			</div>
			<noscript><?php esc_html_e( 'Enable JavaScript to track or cancel online.', 'arc-hotel-core' ); ?></noscript>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Helper to resolve room post ID for shortcodes.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return int Post ID.
	 */
	protected function resolve_room_post_id( array $atts = [] ): int {
		$post_id = ! empty( $atts['room_id'] ) ? absint( $atts['room_id'] ) : (int) get_the_ID();
		if ( ! $post_id || Room_Post_Type::POST_TYPE !== get_post_type( $post_id ) ) {
			// Fallback for Elementor Theme Builder preview
			$fallback = get_posts( [
				'post_type'      => Room_Post_Type::POST_TYPE,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			] );
			$post_id = $fallback ? (int) $fallback[0] : 0;
		}
		return $post_id;
	}

	/**
	 * [arc_room_meta] Room specifications and pills (Adults, Children, Floor, Starting price).
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_room_meta( array $atts = [] ): string {
		$atts    = shortcode_atts( [ 'room_id' => 0 ], $atts, 'arc_room_meta' );
		$post_id = $this->resolve_room_post_id( $atts );
		if ( ! $post_id ) {
			return '';
		}

		$from_price = (float) get_post_meta( $post_id, '_arc_from_price', true );
		$adults     = (int) get_post_meta( $post_id, '_arc_max_adults', true );
		$children   = (int) get_post_meta( $post_id, '_arc_max_children', true );
		$floor_name = get_post_meta( $post_id, '_arc_floor_name', true );
		$terms      = get_the_terms( $post_id, Room_Post_Type::TAXONOMY );
		$cat_name   = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->name : '';

		ob_start();
		?>
		<div class="arc-room-meta-pills">
			<?php if ( ! empty( $cat_name ) ) : ?>
				<span class="arc-pill arc-pill-cat"><?php echo esc_html( $cat_name ); ?></span>
			<?php endif; ?>
			<span class="arc-pill">&#128100; <?php echo esc_html( $adults ?: 2 ); ?> <?php esc_html_e( 'Adults', 'arc-hotel-core' ); ?></span>
			<?php if ( $children > 0 ) : ?>
				<span class="arc-pill">&#128102; <?php echo esc_html( $children ); ?> <?php esc_html_e( 'Children', 'arc-hotel-core' ); ?></span>
			<?php endif; ?>
			<?php if ( ! empty( $floor_name ) ) : ?>
				<span class="arc-pill">&#127970; <?php echo esc_html( $floor_name ); ?></span>
			<?php endif; ?>
			<?php if ( $from_price > 0 ) : ?>
				<span class="arc-pill arc-pill-price"><?php esc_html_e( 'From', 'arc-hotel-core' ); ?> <strong><?php echo esc_html( Settings::format_price( $from_price ) ); ?></strong> /<?php esc_html_e( 'night', 'arc-hotel-core' ); ?></span>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [arc_room_amenities] Dynamic amenities list from API.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_room_amenities( array $atts = [] ): string {
		$atts    = shortcode_atts( [ 'room_id' => 0 ], $atts, 'arc_room_amenities' );
		$post_id = $this->resolve_room_post_id( $atts );
		if ( ! $post_id ) {
			return '';
		}

		$amenities = get_post_meta( $post_id, '_arc_amenities', true );
		if ( ! is_array( $amenities ) && ! empty( $amenities ) ) {
			$amenities = maybe_unserialize( $amenities );
		}
		$amenities = is_array( $amenities ) ? array_filter( $amenities ) : [];

		if ( empty( $amenities ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="arc-room-amenities-section">
			<ul class="arc-amenities-grid">
				<?php foreach ( $amenities as $am ) : ?>
					<li>&#10004; <?php echo esc_html( $am ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [arc_room_gallery] Dynamic room gallery thumbnails from API.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_room_gallery( array $atts = [] ): string {
		$atts    = shortcode_atts( [ 'room_id' => 0 ], $atts, 'arc_room_gallery' );
		$post_id = $this->resolve_room_post_id( $atts );
		if ( ! $post_id ) {
			return '';
		}

		$gallery = get_post_meta( $post_id, '_arc_image_urls', true );
		if ( ! is_array( $gallery ) && ! empty( $gallery ) ) {
			$gallery = maybe_unserialize( $gallery );
		}
		$gallery = is_array( $gallery ) ? array_filter( $gallery ) : [];

		if ( empty( $gallery ) ) {
			return '';
		}

		ob_start();
		?>
		<div class="arc-gallery-thumbnails">
			<?php foreach ( $gallery as $img_url ) : ?>
				<a href="<?php echo esc_url( $img_url ); ?>" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" loading="lazy" />
				</a>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
