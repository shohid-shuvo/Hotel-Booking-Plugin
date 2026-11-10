<?php
/**
 * Room Synchronization Service.
 *
 * @package ARC\HotelCore\Rooms
 */

namespace ARC\HotelCore\Rooms;

use ARC\HotelCore\Api\Api_Client;
use ARC\HotelCore\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Synchronizes room categories from ASP.NET API to arc_room CPT.
 */
class Room_Sync {

	/**
	 * Hook sync actions.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'arc_sync_rooms_cron', [ $this, 'sync_all' ] );
		add_action( 'wp_ajax_arc_sync_rooms_now', [ $this, 'ajax_sync_now' ] );
		add_action( 'admin_bar_menu', [ $this, 'add_admin_bar_sync' ], 100 );
	}

	/**
	 * Run full synchronization of all hotel room categories from API.
	 *
	 * Uses the configured companyCode (or passed argument) to fetch
	 * public room categories and mirror them into WordPress arc_room posts.
	 *
	 * @param string $target_company_code Optional specific tenant code.
	 * @return array Sync statistics.
	 */
	public static function sync_all( string $target_company_code = '' ): array {
		$company_code = ! empty( $target_company_code ) ? sanitize_text_field( $target_company_code ) : Settings::get( 'company_code' );

		if ( empty( $company_code ) ) {
			return [
				'success' => false,
				'message' => __( 'No Company Code configured. Please enter a valid Company Code in Hotel Settings.', 'arc-hotel-core' ),
				'created' => 0,
				'updated' => 0,
				'drafted' => 0,
			];
		}

		$client = new Api_Client();

		// 1. Fetch Room Categories directly for this company code
		$cats_res = $client->get(
			'api/v1/public-booking/room-categories',
			[ 'companyCode' => $company_code ],
			[
				'use_cache' => false,
				'cache_ttl' => Settings::get( 'cache_ttl_rooms', 600 ),
			]
		);

		if ( ! $cats_res->ok() ) {
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: 1: Company Code, 2: Error message */
					__( 'Failed to fetch room categories for company [%1$s]: %2$s', 'arc-hotel-core' ),
					esc_html( $company_code ),
					esc_html( $cats_res->message() )
				),
				'created' => 0,
				'updated' => 0,
				'drafted' => 0,
			];
		}

		$raw_categories = $cats_res->data();
		if ( empty( $raw_categories ) || ! is_array( $raw_categories ) ) {
			return [
				'success' => false,
				'message' => sprintf(
					/* translators: %s: Company Code */
					__( 'No room categories found on the hotel server for company [%s].', 'arc-hotel-core' ),
					esc_html( $company_code )
				),
				'created' => 0,
				'updated' => 0,
				'drafted' => 0,
			];
		}

		$created              = 0;
		$updated              = 0;
		$drafted              = 0;
		$synced_room_type_ids = [];
		$current_time         = current_time( 'mysql' );

		foreach ( $raw_categories as $cat ) {
			$room_type_id = isset( $cat['roomTypeId'] ) ? (int) $cat['roomTypeId'] : 0;
			if ( $room_type_id <= 0 ) {
				continue;
			}

			$synced_room_type_ids[] = $room_type_id;

			$name         = sanitize_text_field( $cat['categoryName'] ?? sprintf( __( 'Room Type #%d', 'arc-hotel-core' ), $room_type_id ) );
			$desc         = wp_kses_post( $cat['description'] ?? '' );
			$price        = (float) ( $cat['basePricePerNight'] ?? 0 );
			$adults       = isset( $cat['maxAdults'] ) ? max( 1, (int) $cat['maxAdults'] ) : 2;
			$children     = isset( $cat['maxChildren'] ) ? (int) $cat['maxChildren'] : 0;
			$total_rooms  = isset( $cat['totalRooms'] ) ? (int) $cat['totalRooms'] : 0;
			$available    = isset( $cat['availableRoomsNow'] ) ? (int) $cat['availableRoomsNow'] : 0;
			$thumbnail    = esc_url_raw( $cat['thumbnailUrl'] ?? '' );
			$images       = isset( $cat['imageUrls'] ) && is_array( $cat['imageUrls'] ) ? array_map( 'esc_url_raw', $cat['imageUrls'] ) : [];
			$amenities    = isset( $cat['amenities'] ) && is_array( $cat['amenities'] ) ? array_map( 'sanitize_text_field', $cat['amenities'] ) : [];

			// If thumbnail is empty but gallery has images, fallback to the first image
			if ( empty( $thumbnail ) && ! empty( $images ) ) {
				$thumbnail = $images[0];
			}

			// Unique composite key per tenant & room category
			$unique_key = $company_code . '_' . $room_type_id;

			// Find existing post by _arc_room_type_id AND _arc_company_code (or _arc_unique_key)
			$query = new \WP_Query( [
				'post_type'      => Room_Post_Type::POST_TYPE,
				'post_status'    => [ 'publish', 'draft' ],
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'OR',
					[
						'key'     => '_arc_unique_key',
						'value'   => $unique_key,
						'compare' => '=',
					],
					[
						'relation' => 'AND',
						[
							'key'     => '_arc_room_type_id',
							'value'   => $room_type_id,
							'compare' => '=',
						],
						[
							'key'     => '_arc_company_code',
							'value'   => $company_code,
							'compare' => '=',
						],
					],
				],
			] );

			$post_id = ! empty( $query->posts ) ? (int) $query->posts[0] : 0;

			if ( $post_id > 0 ) {
				$post_data = [
					'ID'          => $post_id,
					'post_title'  => $name,
					'post_status' => 'publish',
				];

				$existing_post = get_post( $post_id );
				// Only populate description if current WordPress content is empty and API provided one
				if ( empty( trim( $existing_post->post_content ?? '' ) ) && ! empty( $desc ) ) {
					$post_data['post_content'] = $desc;
				}

				wp_update_post( $post_data );
				$updated++;
			} else {
				$default_desc = ! empty( $desc ) ? $desc : sprintf(
					/* translators: %s: Room category name */
					__( 'Experience luxury and comfort in our %s. Configured with modern furnishings, top hospitality amenities, and 24/7 room service.', 'arc-hotel-core' ),
					$name
				);

				$post_id = wp_insert_post( [
					'post_type'    => Room_Post_Type::POST_TYPE,
					'post_title'   => $name,
					'post_content' => $default_desc,
					'post_status'  => 'publish',
				] );
				$created++;
			}

			if ( $post_id && ! is_wp_error( $post_id ) ) {
				// Assign taxonomy
				wp_set_object_terms( $post_id, $name, Room_Post_Type::TAXONOMY, false );

				update_post_meta( $post_id, '_arc_unique_key', $unique_key );
				update_post_meta( $post_id, '_arc_unique_id', $room_type_id );
				update_post_meta( $post_id, '_arc_company_code', $company_code );
				update_post_meta( $post_id, '_arc_room_type_id', $room_type_id );
				update_post_meta( $post_id, '_arc_room_type_name', $name );
				update_post_meta( $post_id, '_arc_from_price', $price );
				update_post_meta( $post_id, '_arc_base_price', $price );
				update_post_meta( $post_id, '_arc_max_adults', $adults );
				update_post_meta( $post_id, '_arc_max_children', $children );
				update_post_meta( $post_id, '_arc_total_rooms', $total_rooms );
				update_post_meta( $post_id, '_arc_available_now', $available );
				update_post_meta( $post_id, '_arc_thumbnail_url', $thumbnail );
				update_post_meta( $post_id, '_arc_image_urls', $images );
				update_post_meta( $post_id, '_arc_amenities', $amenities );
				update_post_meta( $post_id, '_arc_synced_at', $current_time );

				// Sideload featured image if URL changed or missing
				if ( ! empty( $thumbnail ) ) {
					$new_hash      = md5( $thumbnail );
					$existing_hash = get_post_meta( $post_id, '_arc_thumb_url_hash', true );

					if ( empty( $existing_hash ) || $existing_hash !== $new_hash || ! has_post_thumbnail( $post_id ) ) {
						$attachment_id = self::sideload_image( $thumbnail, $post_id, $name );
						if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {
							set_post_thumbnail( $post_id, $attachment_id );
							update_post_meta( $post_id, '_arc_thumb_url_hash', $new_hash );
						}
					}
				}
			}
		}

		// Draft orphaned rooms or rooms from a previous company code
		$all_published = get_posts( [
			'post_type'      => Room_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		foreach ( $all_published as $p_id ) {
			$post_company_code = (string) get_post_meta( $p_id, '_arc_company_code', true );
			$post_type_id      = (int) get_post_meta( $p_id, '_arc_room_type_id', true );

			// If the post belongs to a different company code, or its type ID is no longer active in this company, draft it
			if ( ( ! empty( $post_company_code ) && $post_company_code !== $company_code ) || ! in_array( $post_type_id, $synced_room_type_ids, true ) ) {
				wp_update_post( [
					'ID'          => $p_id,
					'post_status' => 'draft',
				] );
				$drafted++;
			}
		}

		// Also fetch fresh hotel-info profile
		Settings::get_hotel_info( true );

		// Refresh rewrite rules to guarantee that room URLs never 404
		flush_rewrite_rules( false );

		return [
			'success'      => true,
			'company_code' => $company_code,
			'message'      => sprintf(
				/* translators: 1: Count of rooms, 2: Company code */
				__( 'Successfully synchronized %1$d room categories for hotel company [%2$s].', 'arc-hotel-core' ),
				count( $synced_room_type_ids ),
				$company_code
			),
			'created'      => $created,
			'updated'      => $updated,
			'drafted'      => $drafted,
			'total_synced' => count( $synced_room_type_ids ),
		];
	}

	/**
	 * AJAX handler for manual sync now.
	 *
	 * @return void
	 */
	public function ajax_sync_now(): void {
		check_ajax_referer( 'arc_sync_rooms_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'arc-hotel-core' ) ] );
		}

		$company_code = isset( $_POST['company_code'] ) ? sanitize_text_field( wp_unslash( $_POST['company_code'] ) ) : '';
		$stats        = self::sync_all( $company_code );

		if ( $stats['success'] ) {
			wp_send_json_success( $stats );
		} else {
			wp_send_json_error( $stats );
		}
	}

	/**
	 * Add Sync shortcut button in Admin Toolbar.
	 *
	 * @param \WP_Admin_Bar $wp_admin_bar Admin bar object.
	 * @return void
	 */
	public function add_admin_bar_sync( \WP_Admin_Bar $wp_admin_bar ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'arc-sync-rooms',
			'title' => '<span class="ab-icon dashicons dashicons-update"></span> ' . __( 'Sync Hotel Rooms', 'arc-hotel-core' ),
			'href'  => admin_url( 'admin.php?page=arc-hotel-settings' ),
		] );
	}

	/**
	 * Sideload image from URL to WordPress Media Library with hash deduplication.
	 *
	 * @param string $url     Remote image URL.
	 * @param int    $post_id Associated post ID.
	 * @param string $desc    Description/title for attachment.
	 * @return int|\WP_Error Attachment ID on success, WP_Error on failure.
	 */
	public static function sideload_image( string $url, int $post_id, string $desc = '' ): int|\WP_Error {
		if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return new \WP_Error( 'invalid_url', 'Invalid image URL' );
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		if ( ! function_exists( 'download_url' ) ) {
			return new \WP_Error( 'missing_func', 'download_url function not available' );
		}

		// Download temporary file with 15s timeout
		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$clean_name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( empty( $clean_name ) ) {
			$clean_name = 'room-' . $post_id . '.webp';
		}

		// Ensure valid file extension
		if ( ! pathinfo( $clean_name, PATHINFO_EXTENSION ) ) {
			$clean_name .= '.webp';
		}

		$file_array = [
			'name'     => $clean_name,
			'tmp_name' => $tmp,
		];

		$attachment_id = media_handle_sideload( $file_array, $post_id, $desc );

		// Clean up tmp file if sideload failed
		if ( is_wp_error( $attachment_id ) && file_exists( $tmp ) ) {
			@unlink( $tmp );
		}

		return $attachment_id;
	}
}
