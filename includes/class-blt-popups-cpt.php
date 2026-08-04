<?php
/**
 * Custom post type registration and the shared field schema.
 *
 * @package Blt_Popups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the `blt_popup` CPT and exposes the canonical meta-field schema
 * (keys, types, defaults, allowed enum values) used by the meta, admin, REST
 * and render classes so those stay in lockstep.
 */
class BLT_Popups_CPT {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the custom post type.
	 *
	 * `public => false` keeps popups off the front-end as standalone URLs;
	 * they are surfaced only through the site-wide popup mechanism.
	 *
	 * @return void
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Popups', 'blt-popups' ),
			'singular_name'      => __( 'Popup', 'blt-popups' ),
			'menu_name'          => __( 'BLT Popups', 'blt-popups' ),
			'add_new'            => __( 'Add New', 'blt-popups' ),
			'add_new_item'       => __( 'Add New Popup', 'blt-popups' ),
			'edit_item'          => __( 'Edit Popup', 'blt-popups' ),
			'new_item'           => __( 'New Popup', 'blt-popups' ),
			'view_item'          => __( 'View Popup', 'blt-popups' ),
			'search_items'       => __( 'Search Popups', 'blt-popups' ),
			'not_found'          => __( 'No popups found', 'blt-popups' ),
			'not_found_in_trash' => __( 'No popups found in Trash', 'blt-popups' ),
			'all_items'          => __( 'All Popups', 'blt-popups' ),
		);

		register_post_type(
			BLT_POPUPS_CPT,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-cover-image',
				'menu_position'       => 25,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				// Title only — content is edited entirely through the meta box.
				'supports'            => array( 'title' ),
			)
		);
	}

	/**
	 * The canonical meta-field schema.
	 *
	 * Each entry: type (for sanitize routing), default, and (for enums) the
	 * allowed values. Keys here are unprefixed; storage keys get the
	 * BLT_POPUPS_META_PREFIX applied via {@see self::meta_key()}.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function fields() {
		return array(
			'image_id'        => array(
				'type'    => 'int',
				'default' => 0,
			),
			'dest_type'       => array(
				'type'    => 'enum',
				'default' => 'external',
				'values'  => array( 'external', 'internal' ),
			),
			'dest_url'        => array(
				'type'    => 'url',
				'default' => '',
			),
			'dest_page_id'    => array(
				'type'    => 'int',
				'default' => 0,
			),
			'dest_new_tab'    => array(
				'type'    => 'bool',
				'default' => false,
			),
			'cta_enabled'     => array(
				'type'    => 'bool',
				'default' => false,
			),
			'cta_text'        => array(
				'type'    => 'text',
				'default' => '',
			),
			'cta_style'       => array(
				'type'    => 'enum',
				'default' => 'automatic',
				'values'  => array( 'automatic', 'custom' ),
			),
			'cta_variant'     => array(
				'type'    => 'enum',
				'default' => 'primary',
				'values'  => array( 'primary', 'secondary' ),
			),
			'cta_bg_color'    => array(
				'type'    => 'hex',
				'default' => '#111111',
			),
			'cta_text_color'  => array(
				'type'    => 'hex',
				'default' => '#ffffff',
			),
			'max_width_pct'   => array(
				'type'    => 'int_range',
				'default' => 70,
				'min'     => 10,
				'max'     => 100,
			),
			'max_height_pct'  => array(
				'type'    => 'int_range',
				'default' => 80,
				'min'     => 10,
				'max'     => 100,
			),
			'overlay_color'   => array(
				'type'    => 'hex',
				'default' => '#000000',
			),
			'overlay_opacity' => array(
				'type'    => 'float_range',
				'default' => 0.6,
				'min'     => 0.0,
				'max'     => 1.0,
			),
			'animation'       => array(
				'type'    => 'enum',
				'default' => 'zoom',
				'values'  => array( 'none', 'fade', 'slide', 'zoom' ),
			),
			'start_date'      => array(
				'type'    => 'date',
				'default' => '',
			),
			'end_date'        => array(
				'type'    => 'date',
				'default' => '',
			),
			'start_time'      => array(
				'type'    => 'time',
				'default' => '',
			),
			'end_time'        => array(
				'type'    => 'time',
				'default' => '',
			),
			'target_mode'     => array(
				'type'    => 'enum',
				'default' => 'all',
				'values'  => array( 'all', 'home', 'pages', 'post_types', 'url_pattern' ),
			),
			'target_value'    => array(
				'type'    => 'target_value',
				'default' => array(),
			),
			'url_match'       => array(
				'type'    => 'enum',
				'default' => 'contains',
				'values'  => array( 'contains', 'starts_with' ),
			),
			'frequency'       => array(
				'type'    => 'enum',
				'default' => 'session',
				'values'  => array( 'always', 'session', 'daily', 'every_n_days', 'once' ),
			),
			'frequency_days'  => array(
				'type'    => 'int_range',
				'default' => 7,
				'min'     => 1,
				'max'     => 365,
			),
			// Whether a popup is active/live is native WordPress publish/draft
			// status (see BLT_Popups_Admin), not a schema field here.
			// Lightweight analytics (§11).
			'impressions'     => array(
				'type'    => 'int',
				'default' => 0,
			),
			'clicks'          => array(
				'type'    => 'int',
				'default' => 0,
			),
		);
	}

	/**
	 * Storage key for a schema field (applies the meta prefix).
	 *
	 * @param string $field Unprefixed field name.
	 * @return string
	 */
	public static function meta_key( $field ) {
		return BLT_POPUPS_META_PREFIX . $field;
	}

	/**
	 * Get a single field value for a popup, falling back to its schema
	 * default when unset.
	 *
	 * @param int    $post_id Popup post ID.
	 * @param string $field   Unprefixed field name.
	 * @return mixed
	 */
	public static function get( $post_id, $field ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $field ] ) ) {
			return null;
		}

		$key    = self::meta_key( $field );
		$exists = metadata_exists( 'post', $post_id, $key );
		if ( ! $exists ) {
			return $fields[ $field ]['default'];
		}

		return get_post_meta( $post_id, $key, true );
	}
}
