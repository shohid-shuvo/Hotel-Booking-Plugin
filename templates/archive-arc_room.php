<?php
/**
 * Archive Template for Hotel Rooms.
 *
 * @package ARC\HotelCore
 */

get_header();

if ( ! function_exists( 'elementor_theme_do_location' ) || ! elementor_theme_do_location( 'archive' ) ) :
	?>

	<main id="primary" class="site-main arc-room-archive-container">
		<header class="page-header arc-archive-header">
			<h1 class="page-title"><?php esc_html_e( 'Our Luxury Rooms & Suites', 'arc-hotel-core' ); ?></h1>
			<p class="arc-archive-desc">
				<?php
				$hotel_info = \ARC\HotelCore\Settings\Settings::get_hotel_info();
				printf(
					/* translators: %s: Hotel Name */
					esc_html__( 'Experience unparalleled comfort and hospitality at %s.', 'arc-hotel-core' ),
					esc_html( $hotel_info['hotelName'] ?? 'our hotel' )
				);
				?>
			</p>
		</header>

		<div class="arc-archive-search-bar" style="margin-bottom: 40px;">
			<?php echo do_shortcode( '[arc_search]' ); ?>
		</div>

		<div class="arc-archive-grid">
			<?php echo do_shortcode( '[arc_rooms columns="3"]' ); ?>
		</div>
	</main>

	<?php
endif;

get_footer();
