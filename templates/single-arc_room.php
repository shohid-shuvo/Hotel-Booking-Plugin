<?php
/**
 * Single Room Template.
 *
 * @package ARC\HotelCore
 */

get_header();

while ( have_posts() ) :
	the_post();
	$post_id    = get_the_ID();
	$from_price = (float) get_post_meta( $post_id, '_arc_from_price', true );
	$adults     = (int) get_post_meta( $post_id, '_arc_max_adults', true );
	$children   = (int) get_post_meta( $post_id, '_arc_max_children', true );
	$amenities  = get_post_meta( $post_id, '_arc_amenities', true );
	if ( ! is_array( $amenities ) && ! empty( $amenities ) ) {
		$amenities = maybe_unserialize( $amenities );
	}
	$amenities  = is_array( $amenities ) ? $amenities : [];
	$gallery    = get_post_meta( $post_id, '_arc_image_urls', true );
	$gallery    = is_array( $gallery ) ? $gallery : [];
	$terms      = get_the_terms( $post_id, \ARC\HotelCore\Rooms\Room_Post_Type::TAXONOMY );
	$cat_name   = ! empty( $terms ) && ! is_wp_error( $terms ) ? $terms[0]->name : '';
	$floor_name = get_post_meta( $post_id, '_arc_floor_name', true );
	$room_num   = get_post_meta( $post_id, '_arc_room_number', true );
	?>

	<main id="primary" class="site-main arc-single-room-container">
		<article id="post-<?php the_ID(); ?>" <?php post_class( 'arc-room-article' ); ?>>
			<div class="arc-room-hero">
				<h1 class="arc-room-title"><?php the_title(); ?></h1>
				<div class="arc-room-meta-pills">
					<?php if ( ! empty( $cat_name ) ) : ?>
						<span class="arc-pill arc-pill-cat" style="background: #2271b1; color: #fff; font-weight: 600;"><?php echo esc_html( $cat_name ); ?></span>
					<?php endif; ?>
					<span class="arc-pill">&#128100; <?php echo esc_html( $adults ); ?> <?php esc_html_e( 'Adults', 'arc-hotel-core' ); ?></span>
					<?php if ( $children > 0 ) : ?>
						<span class="arc-pill">&#128102; <?php echo esc_html( $children ); ?> <?php esc_html_e( 'Children', 'arc-hotel-core' ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $floor_name ) ) : ?>
						<span class="arc-pill">&#127970; <?php echo esc_html( $floor_name ); ?></span>
					<?php endif; ?>
					<span class="arc-pill arc-pill-price"><?php esc_html_e( 'From', 'arc-hotel-core' ); ?> <strong><?php echo esc_html( \ARC\HotelCore\Settings\Settings::format_price( $from_price ) ); ?></strong> /<?php esc_html_e( 'night', 'arc-hotel-core' ); ?></span>
				</div>
			</div>

			<?php if ( has_post_thumbnail() || ! empty( $gallery ) ) : ?>
				<div class="arc-room-gallery-wrap">
					<?php if ( has_post_thumbnail() ) : ?>
						<div class="arc-gallery-main">
							<?php the_post_thumbnail( 'full' ); ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $gallery ) ) : ?>
						<div class="arc-gallery-thumbnails">
							<?php foreach ( $gallery as $img_url ) : ?>
								<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy" />
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="arc-room-content-grid" style="display: flex; gap: 40px; margin-top: 30px;">
				<div class="arc-room-main-info" style="flex: 2;">
					<h2><?php esc_html_e( 'Overview & Description', 'arc-hotel-core' ); ?></h2>
					<div class="entry-content">
						<?php the_content(); ?>
					</div>

					<?php if ( ! empty( $amenities ) ) : ?>
						<div class="arc-room-amenities-section" style="margin-top: 30px;">
							<h3><?php esc_html_e( 'Room Amenities', 'arc-hotel-core' ); ?></h3>
							<ul class="arc-amenities-list">
								<?php foreach ( $amenities as $am ) : ?>
									<li>&#10004; <?php echo esc_html( $am ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>

				<div class="arc-room-sidebar-booking" style="flex: 1;">
					<div class="arc-sticky-booking-widget">
						<?php echo do_shortcode( '[arc_room_booking]' ); ?>
					</div>
				</div>
			</div>
		</article>
	</main>

	<?php
endwhile;

get_footer();
