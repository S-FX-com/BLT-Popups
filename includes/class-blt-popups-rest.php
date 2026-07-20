<?php
/**
 * REST endpoints: the cache-safe active-popup feed and impression/click counts.
 *
 * @package Blt_Popups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes two public routes under the blt-popups/v1 namespace:
 *
 *  - GET  /active  the authoritative, uncached config for the popup eligible
 *                  on the caller's page right now (status + targeting +
 *                  date/time re-checked server-side), or {popup:null}.
 *  - POST /track   increments the impression/click counter for the live popup.
 *
 * These live under wp-json (not admin-ajax) for cleaner CDN behaviour; the
 * response carries no-store headers and the client adds a cache-buster so a
 * cache-everything edge rule can't pin a stale answer.
 */
class BLT_Popups_REST {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			BLT_POPUPS_REST_NS,
			'/active',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'get_active' ),
				'args'                => array(
					'url'  => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'path' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			BLT_POPUPS_REST_NS,
			'/track',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'track' ),
				'args'                => array(
					'id'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'type' => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'impression', 'click' ),
					),
				),
			)
		);
	}

	/**
	 * Return the eligible active popup's config for the caller's page.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function get_active( WP_REST_Request $request ) {
		$response = new WP_REST_Response( array( 'popup' => null ) );
		// Never let a page/edge cache pin this answer.
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		$active_id = BLT_Popups_Admin::get_active_id();
		if ( ! $active_id ) {
			return $response;
		}
		if ( get_post_type( $active_id ) !== BLT_POPUPS_CPT ) {
			return $response;
		}
		if ( BLT_Popups_CPT::get( $active_id, 'status' ) !== BLT_Popups_CPT::STATUS_ACTIVE ) {
			return $response;
		}

		$url  = (string) $request->get_param( 'url' );
		$path = (string) $request->get_param( 'path' );
		$ctx  = BLT_Popups_Render::context_from_url( $url, $path );

		if ( ! BLT_Popups_Render::targeting_matches( $active_id, $ctx ) ) {
			return $response;
		}
		if ( ! BLT_Popups_Render::is_within_schedule( $active_id ) ) {
			return $response;
		}

		$config = BLT_Popups_Render::build_config( $active_id );
		if ( ! $config ) {
			return $response;
		}

		$response->set_data( array( 'popup' => $config ) );
		return $response;
	}

	/**
	 * Increment an impression or click counter for the live popup.
	 *
	 * Admins are excluded from counts (§16 Q1) — this also keeps preview and
	 * routine admin browsing out of the numbers. Only the currently-active
	 * popup can be counted, so a spoofed ID can't inflate an arbitrary post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function track( WP_REST_Request $request ) {
		$id   = (int) $request->get_param( 'id' );
		$type = (string) $request->get_param( 'type' );

		$response = new WP_REST_Response( array( 'counted' => false ) );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

		// Exclude admins (preview + internal browsing) from the numbers.
		if ( current_user_can( 'manage_options' ) ) {
			return $response;
		}

		// Only the live popup is countable.
		if ( ! $id || BLT_Popups_Admin::get_active_id() !== $id ) {
			return $response;
		}
		if ( get_post_type( $id ) !== BLT_POPUPS_CPT ) {
			return $response;
		}

		$field   = ( 'click' === $type ) ? 'clicks' : 'impressions';
		$key     = BLT_Popups_CPT::meta_key( $field );
		$current = (int) get_post_meta( $id, $key, true );
		update_post_meta( $id, $key, $current + 1 );

		$response->set_data(
			array(
				'counted' => true,
				'type'    => $field,
			)
		);
		return $response;
	}
}
