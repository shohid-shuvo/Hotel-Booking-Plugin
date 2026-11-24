<?php
/**
 * Room Custom Post Type Registration.
 *
 * @package ARC\HotelCore\Rooms
 */

namespace ARC\HotelCore\Rooms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers arc_room CPT and its meta fields.
 */
class Room_Post_Type {

	/**
	 * CPT Slug.
	 */
	const POST_TYPE = 'arc_room';

	/**
	 * Taxonomy Slug.
	 */
	const TAXONOMY = 'arc_room_category';

	/**
	 * Initialize hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', [ $this, 'register_post_type' ], 5 );
		add_action( 'init', [ $this, 'register_taxonomy' ], 5 );
		add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );
		add_filter( 'template_include', [ $this, 'template_loader' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'register_admin_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_admin_columns' ], 10, 2 );
	}

	/**
	 * Register arc_room Custom Post Type.
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$labels = [
			'name'               => _x( 'Hotel Rooms', 'post type general name', 'arc-hotel-core' ),
			'singular_name'      => _x( 'Hotel Room', 'post type singular name', 'arc-hotel-core' ),
			'menu_name'          => _x( 'Hotel Management', 'admin menu', 'arc-hotel-core' ),
			'name_admin_bar'     => _x( 'Hotel Room', 'add new on admin bar', 'arc-hotel-core' ),
			'add_new'            => _x( 'Add New Room', 'room', 'arc-hotel-core' ),
			'add_new_item'       => __( 'Add New Hotel Room', 'arc-hotel-core' ),
			'new_item'           => __( 'New Hotel Room', 'arc-hotel-core' ),
			'edit_item'          => __( 'Edit Hotel Room', 'arc-hotel-core' ),
			'view_item'          => __( 'View Hotel Room', 'arc-hotel-core' ),
			'all_items'          => __( 'All Rooms', 'arc-hotel-core' ),
			'search_items'       => __( 'Search Hotel Rooms', 'arc-hotel-core' ),
			'not_found'          => __( 'No hotel rooms found.', 'arc-hotel-core' ),
			'not_found_in_trash' => __( 'No hotel rooms found in Trash.', 'arc-hotel-core' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'rooms', 'with_front' => false ],
			'capability_type'    => 'post',
			'has_archive'        => 'rooms',
			'hierarchical'       => false,
			'menu_position'      => 26,
			'menu_icon'          => 'dashicons-building',
			'show_in_rest'       => true,
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'elementor' ],
		];

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register arc_room_category Taxonomy.
	 *
	 * @return void
	 */
	public function register_taxonomy(): void {
		$labels = [
			'name'              => _x( 'Room Categories', 'taxonomy general name', 'arc-hotel-core' ),
			'singular_name'     => _x( 'Room Category', 'taxonomy singular name', 'arc-hotel-core' ),
			'search_items'      => __( 'Search Room Categories', 'arc-hotel-core' ),
			'all_items'         => __( 'All Categories', 'arc-hotel-core' ),
			'parent_item'       => __( 'Parent Category', 'arc-hotel-core' ),
			'parent_item_colon' => __( 'Parent Category:', 'arc-hotel-core' ),
			'edit_item'         => __( 'Edit Category', 'arc-hotel-core' ),
			'update_item'       => __( 'Update Category', 'arc-hotel-core' ),
			'add_new_item'      => __( 'Add New Category', 'arc-hotel-core' ),
			'new_item_name'     => __( 'New Category Name', 'arc-hotel-core' ),
			'menu_name'         => __( 'Categories', 'arc-hotel-core' ),
		];

		$args = [
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'room-category', 'with_front' => false ],
			'default_term'      => [
				'name' => 'Uncategorized',
				'slug' => 'uncategorized',
			],
		];

		register_taxonomy( self::TAXONOMY, [ self::POST_TYPE ], $args );
	}

	/**
	 * Register custom columns in hotel room list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function register_admin_columns( array $columns ): array {
		$new_columns = [];
		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;
			if ( 'title' === $key ) {
				$new_columns['arc_room_num'] = __( 'Room #', 'arc-hotel-core' );
				$new_columns['arc_floor']    = __( 'Floor / Wing', 'arc-hotel-core' );
				$new_columns['arc_price']    = __( 'Price/Night', 'arc-hotel-core' );
				$new_columns['arc_capacity'] = __( 'Capacity', 'arc-hotel-core' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_columns( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'arc_room_num':
				$num = get_post_meta( $post_id, '_arc_room_number', true );
				echo esc_html( $num ?: '—' );
				break;
			case 'arc_floor':
				$floor = get_post_meta( $post_id, '_arc_floor_name', true );
				$bld   = get_post_meta( $post_id, '_arc_building_name', true );
				$parts = array_filter( [ $floor, $bld ] );
				echo esc_html( ! empty( $parts ) ? implode( ', ', $parts ) : '—' );
				break;
			case 'arc_price':
				$price = get_post_meta( $post_id, '_arc_from_price', true );
				echo esc_html( $price ? number_format( (float) $price ) . ' BDT' : '—' );
				break;
			case 'arc_capacity':
				$adults   = get_post_meta( $post_id, '_arc_max_adults', true );
				$children = get_post_meta( $post_id, '_arc_max_children', true );
				printf( esc_html__( '%1$d A / %2$d C', 'arc-hotel-core' ), absint( $adults ), absint( $children ) );
				break;
		}
	}

	/**
	 * Register meta box for API-synced room metadata.
	 *
	 * @return void
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'arc_room_api_details',
			__( 'Room Specifications & Sync Details', 'arc-hotel-core' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render room meta box.
	 *
	 * @param \WP_Post $post Current post.
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$room_id      = get_post_meta( $post->ID, '_arc_room_id', true );
		$room_number  = get_post_meta( $post->ID, '_arc_room_number', true );
		$room_type_id = get_post_meta( $post->ID, '_arc_room_type_id', true );
		$floor_name   = get_post_meta( $post->ID, '_arc_floor_name', true );
		$bld_name     = get_post_meta( $post->ID, '_arc_building_name', true );
		$from_price   = get_post_meta( $post->ID, '_arc_from_price', true );
		$max_adults   = get_post_meta( $post->ID, '_arc_max_adults', true );
		$max_children = get_post_meta( $post->ID, '_arc_max_children', true );
		$synced_at    = get_post_meta( $post->ID, '_arc_synced_at', true );
		$amenities    = get_post_meta( $post->ID, '_arc_amenities', true );

		if ( ! is_array( $amenities ) && ! empty( $amenities ) ) {
			$amenities = maybe_unserialize( $amenities );
		}
		if ( ! is_array( $amenities ) ) {
			$amenities = [];
		}
		?>
		<div style="padding: 10px 0;">
			<p>
				<em><?php esc_html_e( 'These specifications are synchronized directly from the central Hotel Management System.', 'arc-hotel-core' ); ?></em>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'Room Number:', 'arc-hotel-core' ); ?></strong></th>
					<td><strong style="font-size: 16px; color: #2271b1;"><?php echo esc_html( $room_number ?: 'N/A' ); ?></strong> (ID: <code><?php echo esc_html( $room_id ?: 'Not Synced' ); ?></code>)</td>
				</tr>
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'Floor & Building:', 'arc-hotel-core' ); ?></strong></th>
					<td><?php echo esc_html( implode( ' &bull; ', array_filter( [ $floor_name, $bld_name ] ) ) ?: '—' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'Category ID:', 'arc-hotel-core' ); ?></strong></th>
					<td><code><?php echo esc_html( $room_type_id ?: 'Not Synced' ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'Base "From" Price:', 'arc-hotel-core' ); ?></strong></th>
					<td><strong><?php echo esc_html( $from_price ? number_format( (float) $from_price, 2 ) . ' BDT' : 'N/A' ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'Capacity:', 'arc-hotel-core' ); ?></strong></th>
					<td>
						<?php
						printf(
							/* translators: 1: Adults count, 2: Children count */
							esc_html__( '%1$d Adults, %2$d Children', 'arc-hotel-core' ),
							absint( $max_adults ),
							absint( $max_children )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'API Amenities:', 'arc-hotel-core' ); ?></strong></th>
					<td>
						<?php if ( ! empty( $amenities ) ) : ?>
							<ul style="margin: 0; list-style: disc; padding-left: 20px;">
								<?php foreach ( $amenities as $amenity ) : ?>
									<li><?php echo esc_html( $amenity ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<em><?php esc_html_e( 'No amenities listed.', 'arc-hotel-core' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><strong><?php esc_html_e( 'Last Synchronized:', 'arc-hotel-core' ); ?></strong></th>
					<td><?php echo esc_html( $synced_at ?: 'Never' ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Auto-flush rewrite rules and ensure Elementor CPT support.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrites(): void {
		if ( ! get_option( 'arc_permalinks_flushed_v3' ) ) {
			flush_rewrite_rules( false );
			update_option( 'arc_permalinks_flushed_v3', '1' );
		}

		// Ensure Elementor CPT support
		$elementor_cpt = get_option( 'elementor_cpt_support', [ 'post', 'page' ] );
		if ( is_array( $elementor_cpt ) && ! in_array( self::POST_TYPE, $elementor_cpt, true ) ) {
			$elementor_cpt[] = self::POST_TYPE;
			update_option( 'elementor_cpt_support', $elementor_cpt );
		}
	}

	/**
	 * Template loader fallback for single hotel room and room archive.
	 *
	 * Gives precedence to Elementor Pro Theme Builder and theme templates,
	 * but ensures a valid fallback template is loaded if none exists.
	 *
	 * @param string $template Path to current template.
	 * @return string Resolved template path.
	 */
	public function template_loader( string $template ): string {
		// If an Elementor canvas, fullwidth or theme builder template was already resolved, do not override it.
		if ( ! empty( $template ) && false !== strpos( $template, 'elementor' ) ) {
			return $template;
		}

		// Check if Elementor Pro Theme Builder has an active document assigned for this location.
		if ( class_exists( '\ElementorPro\Plugin' ) ) {
			$theme_builder = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' );
			if ( $theme_builder && method_exists( $theme_builder, 'get_conditions_manager' ) ) {
				$conditions_manager = $theme_builder->get_conditions_manager();
				if ( is_singular( self::POST_TYPE ) ) {
					$documents = $conditions_manager->get_documents_for_location( 'single' );
					if ( ! empty( $documents ) ) {
						return $template;
					}
				} elseif ( is_post_type_archive( self::POST_TYPE ) ) {
					$documents = $conditions_manager->get_documents_for_location( 'archive' );
					if ( ! empty( $documents ) ) {
						return $template;
					}
				}
			}
		}

		if ( is_singular( self::POST_TYPE ) ) {
			$theme_template = locate_template( [ 'single-arc_room.php' ] );
			if ( ! empty( $theme_template ) ) {
				return $theme_template;
			}

			$plugin_template = ARC_PLUGIN_DIR . 'templates/single-arc_room.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		} elseif ( is_post_type_archive( self::POST_TYPE ) ) {
			$theme_template = locate_template( [ 'archive-arc_room.php' ] );
			if ( ! empty( $theme_template ) ) {
				return $theme_template;
			}

			$plugin_template = ARC_PLUGIN_DIR . 'templates/archive-arc_room.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}

		return $template;
	}
}
