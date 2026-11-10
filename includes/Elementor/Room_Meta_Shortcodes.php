<?php
/**
 * Dynamic room shortcodes for Elementor templates.
 *
 * [arc_room_photos]    Gallery (main image + thumbnails) from synced `_arc_image_urls`.
 * [arc_room_features]  Capacity + amenities list from synced `_arc_max_*` / `_arc_amenities`.
 *
 * Names are intentionally different from the legacy snippet shortcodes
 * (arc_room_gallery / arc_room_amenities) so both can never collide.
 *
 * Install: includes/Elementor/Room_Meta_Shortcodes.php, then call
 * \ARC\HotelCore\Elementor\Room_Meta_Shortcodes::init(); from Plugin.php.
 */

namespace ARC\HotelCore\Elementor;

defined( 'ABSPATH' ) || exit;

class Room_Meta_Shortcodes {

	private static $css_printed = false;

	public static function init() {
		add_shortcode( 'arc_room_photos', array( __CLASS__, 'photos' ) );
		add_shortcode( 'arc_room_features', array( __CLASS__, 'features' ) );
	}

	/**
	 * Shortcode attribute first, then the current post; Elementor preview falls back to the first room.
	 */
	private static function post_id( $atts ) {
		$id = ! empty( $atts['room_id'] ) ? absint( $atts['room_id'] ) : (int) get_the_ID();
		if ( $id && 'arc_room' === get_post_type( $id ) ) {
			return $id;
		}
		$first = get_posts( array( 'post_type' => 'arc_room', 'numberposts' => 1, 'fields' => 'ids' ) );
		return $first ? (int) $first[0] : 0;
	}

	private static function meta_list( $post_id, $key ) {
		$val = get_post_meta( $post_id, $key, true );
		if ( ! is_array( $val ) && ! empty( $val ) ) {
			$val = maybe_unserialize( $val );
		}
		return is_array( $val ) ? array_values( array_filter( $val ) ) : array();
	}

	private static function css() {
		if ( self::$css_printed ) {
			return '';
		}
		self::$css_printed = true;
		return '<style>
.arc-photos__main{border-radius:8px;overflow:hidden;background:#f1f5f9;aspect-ratio:16/10}
.arc-photos__main img{width:100%;height:100%;object-fit:cover;display:block}
.arc-photos__thumbs{display:flex;gap:10px;margin-top:10px;overflow-x:auto}
.arc-photos__thumb{flex:0 0 96px;height:68px;padding:0;border:2px solid transparent;border-radius:6px;overflow:hidden;cursor:pointer;background:none;opacity:.75}
.arc-photos__thumb img{width:100%;height:100%;object-fit:cover;display:block}
.arc-photos__thumb.is-active{border-color:#c5a880;opacity:1}
.arc-features{list-style:none;margin:0;padding:0}
.arc-features li{display:flex;gap:10px;align-items:flex-start;padding:7px 0;color:#334155;font-size:14.5px}
.arc-features li:before{content:"\2714";color:#c5a880;font-weight:700}
.arc-features__cap{font-weight:600;color:#1e293b}
</style>';
	}

	public static function photos( $atts = array() ) {
		$atts    = shortcode_atts( array( 'room_id' => 0 ), $atts, 'arc_room_photos' );
		$post_id = self::post_id( $atts );
		if ( ! $post_id ) {
			return '';
		}

		$imgs = array_map( 'esc_url_raw', self::meta_list( $post_id, '_arc_image_urls' ) );
		if ( ! $imgs ) {
			$thumb = get_the_post_thumbnail_url( $post_id, 'large' );
			$imgs  = $thumb ? array( $thumb ) : array();
		}
		if ( ! $imgs ) {
			return '';
		}

		$uid  = wp_unique_id( 'arcphotos' );
		$html = self::css() . '<div class="arc-photos" id="' . esc_attr( $uid ) . '">';
		$html .= '<div class="arc-photos__main"><img src="' . esc_url( $imgs[0] ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '"></div>';

		if ( count( $imgs ) > 1 ) {
			$html .= '<div class="arc-photos__thumbs">';
			foreach ( $imgs as $i => $src ) {
				$html .= '<button type="button" class="arc-photos__thumb' . ( 0 === $i ? ' is-active' : '' ) . '" data-src="' . esc_url( $src ) . '" aria-label="' . esc_attr( sprintf( 'Photo %d', $i + 1 ) ) . '">';
				$html .= '<img src="' . esc_url( $src ) . '" alt="" loading="lazy"></button>';
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		// Thumbnail click swaps the main image (UX only, no data involved).
		$js = '(function(){var r=document.getElementById(' . wp_json_encode( $uid ) . ');if(!r){return;}'
			. 'var m=r.querySelector(".arc-photos__main img");'
			. 'r.querySelectorAll(".arc-photos__thumb").forEach(function(t){t.addEventListener("click",function(){'
			. 'm.src=t.getAttribute("data-src");'
			. 'r.querySelectorAll(".arc-photos__thumb").forEach(function(x){x.classList.remove("is-active");});'
			. 't.classList.add("is-active");});});})();';

		return $html . '<script>' . $js . '</script>';
	}

	public static function features( $atts = array() ) {
		$atts    = shortcode_atts( array( 'room_id' => 0 ), $atts, 'arc_room_features' );
		$post_id = self::post_id( $atts );
		if ( ! $post_id ) {
			return '';
		}

		$adults    = (int) get_post_meta( $post_id, '_arc_max_adults', true );
		$children  = (int) get_post_meta( $post_id, '_arc_max_children', true );
		$amenities = self::meta_list( $post_id, '_arc_amenities' );

		$html = self::css() . '<ul class="arc-features">';
		if ( $adults > 0 ) {
			$cap = sprintf( '%d %s', $adults, _n( 'Adult', 'Adults', $adults, 'arc-hotel-core' ) );
			if ( $children > 0 ) {
				$cap .= sprintf( ' + %d %s', $children, _n( 'Child', 'Children', $children, 'arc-hotel-core' ) );
			}
			$html .= '<li class="arc-features__cap">' . esc_html( $cap ) . '</li>';
		}
		foreach ( $amenities as $a ) {
			$html .= '<li>' . esc_html( (string) $a ) . '</li>';
		}
		return $html . '</ul>';
	}
}