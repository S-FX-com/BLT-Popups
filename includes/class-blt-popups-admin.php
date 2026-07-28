<?php
/**
 * Admin UI: single-active enforcement, list columns, live badge, editor assets.
 *
 * @package Blt_Popups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the "only one popup is live" invariant (via the
 * BLT_POPUPS_ACTIVE_OPTION option, the single source of truth) plus the CPT
 * list-table presentation and editor asset enqueue.
 */
class BLT_Popups_Admin {

	/**
	 * Hook registration (admin only).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'manage_' . BLT_POPUPS_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . BLT_POPUPS_CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'live_badge' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );

		// Keep the pointer honest when the live popup is trashed or deleted.
		add_action( 'wp_trash_post', array( __CLASS__, 'maybe_clear_on_removal' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'maybe_clear_on_removal' ) );
	}

	/* --------------------------------------------------------------------- *
	 * Single-active enforcement (the invariant lives here).
	 * --------------------------------------------------------------------- */

	/**
	 * The currently-live popup ID, or 0 when none.
	 *
	 * @return int
	 */
	public static function get_active_id() {
		return (int) get_option( BLT_POPUPS_ACTIVE_OPTION, 0 );
	}

	/**
	 * Make a popup live: flip every other active popup to inactive, mark this
	 * one active, and record it as the single site-wide active popup.
	 *
	 * @param int $post_id Popup ID to activate.
	 * @return void
	 */
	public static function set_active( $post_id ) {
		$post_id = (int) $post_id;

		// Demote any other popup currently flagged active.
		$others = get_posts(
			array(
				'post_type'      => BLT_POPUPS_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => array( $post_id ),
				'meta_key'       => BLT_Popups_CPT::meta_key( 'status' ),
				'meta_value'     => BLT_Popups_CPT::STATUS_ACTIVE,
			)
		);
		foreach ( $others as $other_id ) {
			update_post_meta( $other_id, BLT_Popups_CPT::meta_key( 'status' ), BLT_Popups_CPT::STATUS_INACTIVE );
		}

		update_post_meta( $post_id, BLT_Popups_CPT::meta_key( 'status' ), BLT_Popups_CPT::STATUS_ACTIVE );
		update_option( BLT_POPUPS_ACTIVE_OPTION, $post_id );
	}

	/**
	 * Clear the site-wide pointer when the given popup is the live one.
	 * Does not change status meta (the caller owns that).
	 *
	 * @param int $post_id Popup ID.
	 * @return void
	 */
	public static function clear_active( $post_id ) {
		if ( self::get_active_id() === (int) $post_id ) {
			delete_option( BLT_POPUPS_ACTIVE_OPTION );
		}
	}

	/**
	 * On trash/delete of the live popup, drop the pointer so nothing tries to
	 * serve a missing post.
	 *
	 * @param int $post_id Post being removed.
	 * @return void
	 */
	public static function maybe_clear_on_removal( $post_id ) {
		if ( get_post_type( $post_id ) !== BLT_POPUPS_CPT ) {
			return;
		}
		self::clear_active( (int) $post_id );
	}

	/* --------------------------------------------------------------------- *
	 * List table.
	 * --------------------------------------------------------------------- */

	/**
	 * Define custom columns (keep title + date, insert ours between).
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new['blt_status']      = __( 'Status', 'blt-popups' );
				$new['blt_schedule']    = __( 'Schedule', 'blt-popups' );
				$new['blt_targeting']   = __( 'Targeting', 'blt-popups' );
				$new['blt_impressions'] = __( 'Impressions', 'blt-popups' );
				$new['blt_clicks']      = __( 'Clicks', 'blt-popups' );
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	/**
	 * Render a custom column.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Popup ID.
	 * @return void
	 */
	public static function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'blt_status':
				$status    = BLT_Popups_CPT::get( $post_id, 'status' );
				$is_active = ( self::get_active_id() === (int) $post_id ) && ( BLT_Popups_CPT::STATUS_ACTIVE === $status );
				$label     = $is_active ? __( 'Active', 'blt-popups' ) : ucfirst( (string) $status );
				$class     = $is_active ? 'active' : $status;
				printf(
					'<span class="blt-popup-badge blt-popup-badge-%s">%s</span>',
					esc_attr( $class ),
					esc_html( $label )
				);
				break;

			case 'blt_schedule':
				echo esc_html( self::schedule_summary( $post_id ) );
				break;

			case 'blt_targeting':
				echo esc_html( self::targeting_summary( $post_id ) );
				break;

			case 'blt_impressions':
				echo (int) BLT_Popups_CPT::get( $post_id, 'impressions' );
				break;

			case 'blt_clicks':
				echo (int) BLT_Popups_CPT::get( $post_id, 'clicks' );
				break;
		}
	}

	/**
	 * Human summary of a popup's schedule.
	 *
	 * @param int $post_id Popup ID.
	 * @return string
	 */
	public static function schedule_summary( $post_id ) {
		$start_date = BLT_Popups_CPT::get( $post_id, 'start_date' );
		$end_date   = BLT_Popups_CPT::get( $post_id, 'end_date' );
		$start_time = BLT_Popups_CPT::get( $post_id, 'start_time' );
		$end_time   = BLT_Popups_CPT::get( $post_id, 'end_time' );

		if ( ! $start_date && ! $end_date && ! $start_time && ! $end_time ) {
			return __( 'Always', 'blt-popups' );
		}

		$from = trim( $start_date . ' ' . $start_time );
		$to   = trim( $end_date . ' ' . $end_time );

		if ( $from && $to ) {
			/* translators: 1: start, 2: end. */
			return sprintf( __( '%1$s → %2$s', 'blt-popups' ), $from, $to );
		}
		if ( $from ) {
			/* translators: %s: start. */
			return sprintf( __( 'From %s', 'blt-popups' ), $from );
		}
		/* translators: %s: end. */
		return sprintf( __( 'Until %s', 'blt-popups' ), $to );
	}

	/**
	 * Human summary of a popup's targeting.
	 *
	 * @param int $post_id Popup ID.
	 * @return string
	 */
	public static function targeting_summary( $post_id ) {
		$mode  = BLT_Popups_CPT::get( $post_id, 'target_mode' );
		$value = BLT_Popups_CPT::get( $post_id, 'target_value' );

		switch ( $mode ) {
			case 'home':
				return __( 'Homepage only', 'blt-popups' );

			case 'pages':
				$count = is_array( $value ) ? count( $value ) : 0;
				/* translators: %d: number of pages. */
				return sprintf( _n( '%d page', '%d pages', $count, 'blt-popups' ), $count );

			case 'post_types':
				$list = is_array( $value ) ? implode( ', ', $value ) : '';
				/* translators: %s: comma-separated post types. */
				return $list ? sprintf( __( 'Post types: %s', 'blt-popups' ), $list ) : __( 'Post types', 'blt-popups' );

			case 'url_pattern':
				$match = BLT_Popups_CPT::get( $post_id, 'url_match' );
				$verb  = ( 'starts_with' === $match ) ? __( 'starts with', 'blt-popups' ) : __( 'contains', 'blt-popups' );
				/* translators: 1: match verb, 2: pattern. */
				return sprintf( __( 'URL %1$s "%2$s"', 'blt-popups' ), $verb, is_string( $value ) ? $value : '' );

			case 'all':
			default:
				return __( 'All pages', 'blt-popups' );
		}
	}

	/* --------------------------------------------------------------------- *
	 * Live badge + assets.
	 * --------------------------------------------------------------------- */

	/**
	 * Persistent notice on the popup list screen naming the live popup.
	 *
	 * @return void
	 */
	public static function live_badge() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . BLT_POPUPS_CPT !== $screen->id ) {
			return;
		}

		$active_id = self::get_active_id();
		if ( $active_id && get_post_status( $active_id ) ) {
			$title = get_the_title( $active_id );
			$link  = get_edit_post_link( $active_id );
			printf(
				'<div class="notice notice-info blt-popup-live-notice"><p><span class="blt-popup-badge blt-popup-badge-active">%s</span> %s <a href="%s"><strong>%s</strong></a></p></div>',
				esc_html__( 'Live', 'blt-popups' ),
				esc_html__( 'Currently active popup:', 'blt-popups' ),
				esc_url( (string) $link ),
				esc_html( $title )
			);
		} else {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'No popup is currently live. Activate one from its editor to show it on the site.', 'blt-popups' )
			);
		}
	}

	/**
	 * Enqueue editor assets (media picker, admin UI, and the front-end CSS so
	 * the in-admin preview matches the live rendering).
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || BLT_POPUPS_CPT !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'blt-popups-frontend',
			BLT_POPUPS_URL . 'assets/css/blt-popups-frontend.css',
			array(),
			BLT_POPUPS_VERSION
		);

		wp_enqueue_style(
			'blt-popups-admin',
			BLT_POPUPS_URL . 'assets/css/blt-popups-admin.css',
			array( 'blt-popups-frontend' ),
			BLT_POPUPS_VERSION
		);

		wp_enqueue_script(
			'blt-popups-admin',
			BLT_POPUPS_URL . 'assets/js/blt-popups-admin.js',
			array(),
			BLT_POPUPS_VERSION,
			true
		);

		$active_id = self::get_active_id();
		wp_localize_script(
			'blt-popups-admin',
			'bltPopupsAdmin',
			array(
				'mediaTitle'   => __( 'Select popup image', 'blt-popups' ),
				'mediaButton'  => __( 'Use this image', 'blt-popups' ),
				'activeId'     => $active_id,
				'activeTitle'  => $active_id ? get_the_title( $active_id ) : '',
				'currentId'    => (int) get_the_ID(),
				'i18n'         => array(
					'confirmReplace' => __( 'This will deactivate the currently live popup "%s". Continue?', 'blt-popups' ),
					'confirmActivate' => __( 'Make this popup live on the site now?', 'blt-popups' ),
					'noImage'        => __( 'Select an image first to preview the popup.', 'blt-popups' ),
					'close'          => __( 'Close', 'blt-popups' ),
					'ctaFallback'    => __( 'Learn more', 'blt-popups' ),
				),
			)
		);
	}
}
