<?php
/**
 * Front-end eligibility evaluation, asset enqueue, and preview bypass.
 *
 * @package Blt_Popups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Decides — cheaply and cache-safely — whether the front-end even needs the
 * popup assets, enqueues them when so, and exposes the shared eligibility
 * helpers that the REST endpoint reuses as the authoritative check.
 *
 * Caching model (see spec §9): the PHP enqueue gate uses only the two stable,
 * cache-safe signals — "is a popup active?" and "does its page targeting
 * match this URL?" — so it is safe to bake into cached HTML. The volatile
 * signals (date/time window, per-visitor frequency) are deferred: date/time
 * is re-checked fresh, uncached, by the REST endpoint; frequency lives in a
 * per-visitor cookie evaluated in JS. That keeps a popup with an elapsed end
 * date from lingering on cached pages.
 */
class BLT_Popups_Render {

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * Enqueue the front-end assets when a popup could plausibly show, or when
	 * a signed admin preview is requested.
	 *
	 * @return void
	 */
	public static function maybe_enqueue() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return;
		}

		// --- Preview bypass (§10.2): nonce-gated, admin-only, ignores status,
		// schedule and frequency so the popup can be seen in real context. ---
		$preview_id = self::requested_preview_id();
		if ( $preview_id ) {
			$config = self::build_config( $preview_id );
			if ( $config ) {
				self::enqueue_assets();
				self::localize(
					array(
						'previewMode' => true,
						'preview'     => $config,
					)
				);
			}
			return;
		}

		// --- Normal path. ---
		$active_id = BLT_Popups_Admin::get_active_id();
		if ( ! $active_id ) {
			return;
		}
		if ( 'publish' !== get_post_status( $active_id ) ) {
			return;
		}
		// Cache-safe coarse gate: page targeting only (stable per-URL).
		if ( ! self::targeting_matches( $active_id, self::context_from_query() ) ) {
			return;
		}
		// Must have a usable image to be worth loading anything.
		if ( ! self::build_config( $active_id ) ) {
			return;
		}

		self::enqueue_assets();
		// The active popup's identity, frequency, schedule and targeting are
		// resolved authoritatively (and uncached) by the REST endpoint — never
		// baked in here — so a switched/rescheduled popup can't go stale on a
		// cached page.
		self::localize(
			array(
				'previewMode' => false,
				'restActive'  => esc_url_raw( rest_url( BLT_POPUPS_REST_NS . '/active' ) ),
				'restTrack'   => esc_url_raw( rest_url( BLT_POPUPS_REST_NS . '/track' ) ),
				'restNonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Register + enqueue the CSS/JS pair.
	 *
	 * @return void
	 */
	private static function enqueue_assets() {
		wp_enqueue_style(
			'blt-popups-frontend',
			BLT_POPUPS_URL . 'assets/css/blt-popups-frontend.css',
			array(),
			BLT_POPUPS_VERSION
		);
		wp_enqueue_script(
			'blt-popups-frontend',
			BLT_POPUPS_URL . 'assets/js/blt-popups-frontend.js',
			array(),
			BLT_POPUPS_VERSION,
			true
		);
	}

	/**
	 * Localize the front-end config onto the enqueued script.
	 *
	 * @param array $data Config overrides/additions.
	 * @return void
	 */
	private static function localize( array $data ) {
		// Note: no per-visitor value (e.g. logged-in state) is localized here —
		// it would be frozen into cached HTML under a full-page cache. Admin
		// exclusion from analytics is handled server-side in the (uncached)
		// /track endpoint instead.
		$base = array(
			'cookiePrefix' => 'blt_popup_seen_',
			'previewMode'  => false,
			// Client-side fallback strings must come from PHP to stay
			// translatable; hardcoding them in JS bypasses the text domain.
			'i18n'         => array(
				'ctaFallback' => __( 'Learn more', 'blt-popups' ),
				'close'       => __( 'Close', 'blt-popups' ),
				'dialogLabel' => __( 'Promotion', 'blt-popups' ),
			),
		);
		wp_localize_script( 'blt-popups-frontend', 'bltPopups', array_merge( $base, $data ) );
	}

	/**
	 * The popup ID requested for preview via a valid signed URL, or 0.
	 *
	 * @return int
	 */
	public static function requested_preview_id() {
		if ( ! isset( $_GET['blt_preview'], $_GET['_blt_nonce'] ) ) {
			return 0;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return 0;
		}
		$id    = absint( wp_unslash( $_GET['blt_preview'] ) );
		$nonce = sanitize_text_field( wp_unslash( $_GET['_blt_nonce'] ) );
		if ( ! $id || ! wp_verify_nonce( $nonce, 'blt_preview_' . $id ) ) {
			return 0;
		}
		if ( get_post_type( $id ) !== BLT_POPUPS_CPT ) {
			return 0;
		}
		return $id;
	}

	/* --------------------------------------------------------------------- *
	 * Shared eligibility helpers (reused by the REST endpoint).
	 * --------------------------------------------------------------------- */

	/**
	 * Build the client-facing config for a popup, or null when it has no
	 * usable image (nothing to render).
	 *
	 * @param int $post_id Popup ID.
	 * @return array|null
	 */
	public static function build_config( $post_id ) {
		$post_id  = (int) $post_id;
		$image_id = (int) BLT_Popups_CPT::get( $post_id, 'image_id' );
		$src      = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
		if ( ! $src ) {
			return null;
		}

		$srcset = $image_id ? wp_get_attachment_image_srcset( $image_id, 'full' ) : '';
		$meta   = $image_id ? wp_get_attachment_metadata( $image_id ) : array();
		$alt    = $image_id ? trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) : '';

		list( $dest_url, $new_tab ) = self::resolve_destination( $post_id );

		return array(
			'id'             => $post_id,
			'image'          => array(
				'src'    => $src,
				'srcset' => $srcset ? $srcset : '',
				'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
				'alt'    => $alt,
			),
			'destUrl'        => $dest_url,
			'newTab'         => $new_tab,
			'cta'            => array(
				'enabled'   => (bool) BLT_Popups_CPT::get( $post_id, 'cta_enabled' ),
				'text'      => (string) BLT_Popups_CPT::get( $post_id, 'cta_text' ),
				'style'     => (string) BLT_Popups_CPT::get( $post_id, 'cta_style' ),
				'variant'   => (string) BLT_Popups_CPT::get( $post_id, 'cta_variant' ),
				'bgColor'   => (string) BLT_Popups_CPT::get( $post_id, 'cta_bg_color' ),
				'textColor' => (string) BLT_Popups_CPT::get( $post_id, 'cta_text_color' ),
			),
			'maxWidthPct'    => (int) BLT_Popups_CPT::get( $post_id, 'max_width_pct' ),
			'maxHeightPct'   => (int) BLT_Popups_CPT::get( $post_id, 'max_height_pct' ),
			'overlayColor'   => (string) BLT_Popups_CPT::get( $post_id, 'overlay_color' ),
			'overlayOpacity' => (float) BLT_Popups_CPT::get( $post_id, 'overlay_opacity' ),
			'animation'      => (string) BLT_Popups_CPT::get( $post_id, 'animation' ),
			// Frequency travels with the config so the client caps against the
			// popup actually served (fresh from REST), not a value baked into
			// possibly-stale cached HTML.
			'frequency'      => (string) BLT_Popups_CPT::get( $post_id, 'frequency' ),
			'frequencyDays'  => (int) BLT_Popups_CPT::get( $post_id, 'frequency_days' ),
		);
	}

	/**
	 * Resolve a popup's click-through URL and new-tab flag from its
	 * destination type.
	 *
	 * Internal destinations resolve to a live page's permalink (falling back
	 * to no destination if the page was trashed/unpublished since it was
	 * chosen) and respect the saved new-tab preference. External destinations
	 * use the stored URL as-is and always open in a new window, so a popup
	 * click never navigates the visitor away from the site.
	 *
	 * @param int $post_id Popup ID.
	 * @return array{0:string,1:bool} [ dest_url, new_tab ]
	 */
	private static function resolve_destination( $post_id ) {
		if ( 'internal' === BLT_Popups_CPT::get( $post_id, 'dest_type' ) ) {
			$page_id  = (int) BLT_Popups_CPT::get( $post_id, 'dest_page_id' );
			$dest_url = ( $page_id && 'page' === get_post_type( $page_id ) && 'publish' === get_post_status( $page_id ) )
				? (string) get_permalink( $page_id )
				: '';
			return array( $dest_url, (bool) BLT_Popups_CPT::get( $post_id, 'dest_new_tab' ) );
		}

		return array( (string) BLT_Popups_CPT::get( $post_id, 'dest_url' ), true );
	}

	/**
	 * Whether the popup's schedule window includes "now" (site timezone).
	 *
	 * Dates bound the campaign period (inclusive); times bound the hours
	 * within each active day. Any blank field is unbounded on that side.
	 *
	 * @param int $post_id Popup ID.
	 * @return bool
	 */
	public static function is_within_schedule( $post_id ) {
		$start_date = (string) BLT_Popups_CPT::get( $post_id, 'start_date' );
		$end_date   = (string) BLT_Popups_CPT::get( $post_id, 'end_date' );
		$start_time = (string) BLT_Popups_CPT::get( $post_id, 'start_time' );
		$end_time   = (string) BLT_Popups_CPT::get( $post_id, 'end_time' );

		$now      = current_datetime(); // DateTimeImmutable in the site timezone.
		$today    = $now->format( 'Y-m-d' );
		$now_time = $now->format( 'H:i' );

		// Zero-padded Y-m-d and H:i compare correctly as strings.
		if ( '' !== $start_date && $today < $start_date ) {
			return false;
		}
		if ( '' !== $end_date && $today > $end_date ) {
			return false;
		}
		if ( '' !== $start_time && $now_time < $start_time ) {
			return false;
		}
		if ( '' !== $end_time && $now_time > $end_time ) {
			return false;
		}
		return true;
	}

	/**
	 * Core targeting matcher.
	 *
	 * @param int   $post_id Popup ID.
	 * @param array $ctx     Resolved context: is_home(bool), post_id(int),
	 *                       post_type(string), path(string).
	 * @return bool
	 */
	public static function targeting_matches( $post_id, array $ctx ) {
		$mode  = BLT_Popups_CPT::get( $post_id, 'target_mode' );
		$value = BLT_Popups_CPT::get( $post_id, 'target_value' );

		switch ( $mode ) {
			case 'all':
				return true;

			case 'home':
				return ! empty( $ctx['is_home'] );

			case 'pages':
				$ids = is_array( $value ) ? array_map( 'intval', $value ) : array();
				return in_array( (int) $ctx['post_id'], $ids, true );

			case 'post_types':
				$types = is_array( $value ) ? array_map( 'strval', $value ) : array();
				return in_array( (string) $ctx['post_type'], $types, true );

			case 'url_pattern':
				$needle = is_string( $value ) ? trim( $value ) : '';
				if ( '' === $needle ) {
					return false;
				}
				// URLs are lowercase by convention but the pattern is typed by
				// hand, so match case-insensitively rather than failing silently
				// on "/Promo".
				$hay    = strtolower( (string) $ctx['path'] );
				$needle = strtolower( $needle );
				$match  = BLT_Popups_CPT::get( $post_id, 'url_match' );
				if ( 'starts_with' === $match ) {
					// A path always starts with "/", so tolerate a pattern typed
					// without it ("promo") instead of never matching.
					if ( 0 !== strpos( $needle, '/' ) ) {
						$needle = '/' . $needle;
					}
					return 0 === strpos( $hay, $needle );
				}
				return false !== strpos( $hay, $needle );

			default:
				return false;
		}
	}

	/**
	 * Targeting context from the current main query (real page context).
	 *
	 * @return array
	 */
	public static function context_from_query() {
		$queried_id = (int) get_queried_object_id();
		return array(
			// Front page only (spec §8), matching context_from_url()'s
			// path_is_home() — is_home() would also match a separate blog
			// posts index and diverge from the REST-side check.
			'is_home'   => is_front_page(),
			'post_id'   => $queried_id,
			'post_type' => $queried_id ? (string) get_post_type( $queried_id ) : '',
			'path'      => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		);
	}

	/**
	 * Targeting context resolved from a URL/path supplied by the client
	 * (used by the REST endpoint, which lacks the page's main query).
	 *
	 * @param string $url  Absolute URL of the page the visitor is on.
	 * @param string $path Path (+query) of that page.
	 * @return array
	 */
	public static function context_from_url( $url, $path ) {
		$post_id = $url ? (int) url_to_postid( $url ) : 0;
		return array(
			'is_home'   => self::path_is_home( $path ),
			'post_id'   => $post_id,
			'post_type' => $post_id ? (string) get_post_type( $post_id ) : '',
			'path'      => (string) $path,
		);
	}

	/**
	 * Whether a path points at the site front page.
	 *
	 * @param string $path Path (may include query).
	 * @return bool
	 */
	private static function path_is_home( $path ) {
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$path = '/' . trim( $path, '/' );
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = '/' . trim( $home, '/' );
		return ( $path === $home );
	}
}
