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
 * Owns the "only one popup is live" invariant — built entirely on WordPress's
 * native publish/draft post status, which is the single source of truth (a
 * popup is live if and only if its post_status is 'publish') — plus the CPT
 * list-table presentation, the quick-toggle switch, and editor asset enqueue.
 */
class BLT_Popups_Admin {

	const TOGGLE_NONCE_ACTION = 'blt_popups_toggle_status';
	const MIGRATED_OPTION     = 'blt_popups_status_migrated';

	/**
	 * Hook registration (admin only).
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'manage_' . BLT_POPUPS_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . BLT_POPUPS_CPT . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'live_badge' ) );
		add_action( 'admin_notices', array( __CLASS__, 'duplicate_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_side_meta_boxes' ) );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title_strip' ) );
		add_action( 'post_submitbox_misc_actions', array( __CLASS__, 'publish_box_note' ) );

		// Duplicate action: list-table row link + the handler it points at.
		add_filter( 'post_row_actions', array( __CLASS__, 'add_duplicate_row_action' ), 10, 2 );
		add_action( 'admin_action_blt_popups_duplicate', array( __CLASS__, 'handle_duplicate' ) );

		// The single-active invariant, enforced wherever post_status can
		// change: the editor's Publish/Draft box, Quick Edit, Bulk Edit, and
		// our own list-table toggle below.
		add_action( 'transition_post_status', array( __CLASS__, 'handle_status_transition' ), 10, 3 );
		// Only an administrator may make a popup live (matches the preview
		// bypass's capability check) — enforced at save time regardless of
		// which UI path attempted the publish.
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'guard_publish_capability' ), 10, 2 );

		// Quick-toggle switch in the list table.
		add_action( 'wp_ajax_blt_popups_toggle_status', array( __CLASS__, 'ajax_toggle_status' ) );

		// One-time migration from the old custom "status" meta field to
		// native post_status, for sites upgrading from an earlier version.
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_legacy_status' ) );
	}

	/**
	 * The nonce-signed URL for duplicating a popup via {@see handle_duplicate()}.
	 *
	 * @param int $post_id Popup to duplicate.
	 * @return string
	 */
	public static function duplicate_url( $post_id ) {
		return wp_nonce_url(
			admin_url( 'admin.php?action=blt_popups_duplicate&post=' . (int) $post_id ),
			'blt_popups_duplicate_' . (int) $post_id
		);
	}

	/* --------------------------------------------------------------------- *
	 * Single-active enforcement (the invariant lives here).
	 *
	 * "Live" simply means post_status === 'publish': there is no separate
	 * pointer/option to keep in sync, so the post itself is always the
	 * single source of truth, however its status was changed (the editor's
	 * Publish box, Quick Edit, Bulk Edit, or the list-table toggle).
	 * --------------------------------------------------------------------- */

	/**
	 * The currently-live popup ID, or 0 when none.
	 *
	 * @return int
	 */
	public static function get_active_id() {
		$ids = get_posts(
			array(
				'post_type'      => BLT_POPUPS_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Keep the "only one live popup" invariant whenever a popup's post_status
	 * changes, regardless of which UI path changed it.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The popup.
	 * @return void
	 */
	public static function handle_status_transition( $new_status, $old_status, $post ) {
		if ( BLT_POPUPS_CPT !== $post->post_type || $new_status === $old_status ) {
			return;
		}

		if ( 'publish' === $new_status ) {
			self::demote_other_live_popups( (int) $post->ID );
		}
	}

	/**
	 * Publishing a popup makes it *the* live popup, so every other popup
	 * currently marked live is demoted to draft.
	 *
	 * @param int $post_id Popup being made live.
	 * @return void
	 */
	private static function demote_other_live_popups( $post_id ) {
		$others = get_posts(
			array(
				'post_type'      => BLT_POPUPS_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'exclude'        => array( $post_id ),
			)
		);
		foreach ( $others as $other_id ) {
			wp_update_post(
				array(
					'ID'          => $other_id,
					'post_status' => 'draft',
				)
			);
		}
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
				self::render_status_toggle( $post_id );
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
	 * Render the quick-toggle switch + status badge for a list-table row.
	 *
	 * The switch is a thin, AJAX-driven front for native post_status: flipping
	 * it on publishes the popup (making it live and demoting any other live
	 * popup); flipping it off drafts it. Making a popup live is admin-only
	 * (matching the preview bypass), so the switch is disabled for a draft
	 * popup when the current user can't publish it — but a live popup can
	 * always be switched off by anyone who can edit it.
	 *
	 * @param int $post_id Popup ID.
	 * @return void
	 */
	public static function render_status_toggle( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		$is_live  = ( 'publish' === $post->post_status );
		$label    = $is_live ? __( 'Live', 'blt-popups' ) : __( 'Draft', 'blt-popups' );
		$class    = $is_live ? 'active' : 'draft';
		// Trashed/pending/etc. rows get a disabled toggle — the switch is only
		// for the ordinary publish/draft flip, not for reviving a trashed post.
		$disabled = ! current_user_can( 'edit_post', $post_id )
			|| ( ! $is_live && ! current_user_can( 'manage_options' ) )
			|| ( ! in_array( $post->post_status, array( 'publish', 'draft', 'pending' ), true ) );
		?>
		<div class="blt-popup-status-cell" data-post-id="<?php echo (int) $post_id; ?>">
			<label class="blt-popup-switch blt-popup-list-toggle">
				<input
					type="checkbox"
					class="blt-popup-toggle-input"
					<?php checked( $is_live ); ?>
					<?php disabled( $disabled ); ?>
				/>
				<span class="blt-popup-switch-track" aria-hidden="true"></span>
				<span class="screen-reader-text"><?php esc_html_e( 'Make this popup live', 'blt-popups' ); ?></span>
			</label>
			<span class="blt-popup-badge blt-popup-badge-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
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
	 * Duplicate action.
	 * --------------------------------------------------------------------- */

	/**
	 * Add a "Duplicate" link to the list-table row actions.
	 *
	 * @param array   $actions Existing row actions.
	 * @param WP_Post $post    The row's post.
	 * @return array
	 */
	public static function add_duplicate_row_action( $actions, $post ) {
		if ( BLT_POPUPS_CPT !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$actions['blt_duplicate'] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::duplicate_url( $post->ID ) ),
			esc_html__( 'Duplicate', 'blt-popups' )
		);
		return $actions;
	}

	/**
	 * Duplicate a popup into a new draft carrying every schema field except
	 * impressions/clicks — a copy is never live (post_status is forced to
	 * 'draft' below) and starts its analytics at zero, same as any freshly
	 * created popup (both simply fall back to their schema defaults by not
	 * being written below).
	 *
	 * @return void
	 */
	public static function handle_duplicate() {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id || BLT_POPUPS_CPT !== get_post_type( $post_id ) ) {
			wp_die( esc_html__( 'Invalid popup.', 'blt-popups' ) );
		}

		check_admin_referer( 'blt_popups_duplicate_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this popup.', 'blt-popups' ) );
		}

		$source = get_post( $post_id );
		if ( ! $source ) {
			wp_die( esc_html__( 'Popup not found.', 'blt-popups' ) );
		}

		$new_id = wp_insert_post(
			array(
				'post_type'   => BLT_POPUPS_CPT,
				/* translators: %s: original popup title. */
				'post_title'  => sprintf( __( '%s (copy)', 'blt-popups' ), $source->post_title ),
				'post_status' => 'draft',
			),
			true
		);

		if ( is_wp_error( $new_id ) ) {
			wp_die( $new_id ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- wp_die() escapes WP_Error internally.
		}

		$skip = array( 'impressions', 'clicks' );
		foreach ( BLT_Popups_CPT::fields() as $field => $schema ) {
			if ( in_array( $field, $skip, true ) ) {
				continue;
			}
			update_post_meta( $new_id, BLT_Popups_CPT::meta_key( $field ), BLT_Popups_CPT::get( $post_id, $field ) );
		}

		wp_safe_redirect( add_query_arg( 'blt_duplicated', '1', get_edit_post_link( $new_id, 'raw' ) ) );
		exit;
	}

	/**
	 * One-time success notice after {@see handle_duplicate()} redirects to
	 * the new draft's editor.
	 *
	 * @return void
	 */
	public static function duplicate_notice() {
		if ( ! isset( $_GET['blt_duplicated'] ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || BLT_POPUPS_CPT !== $screen->post_type ) {
			return;
		}
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Popup duplicated. This copy is a draft — review it and activate when ready.', 'blt-popups' )
		);
	}

	/* --------------------------------------------------------------------- *
	 * Sidebar meta boxes (Live Preview + Popup Summary).
	 * --------------------------------------------------------------------- */

	/**
	 * Register the two 'side' context meta boxes. Registered at 'default'
	 * priority so both render below the native Publish box (added by core at
	 * 'core' priority) — Live Preview first, Summary beneath it.
	 *
	 * @return void
	 */
	public static function add_side_meta_boxes() {
		add_meta_box(
			'blt_popup_live_preview',
			__( 'Live Preview', 'blt-popups' ),
			array( __CLASS__, 'render_live_preview_box' ),
			BLT_POPUPS_CPT,
			'side',
			'default'
		);

		add_meta_box(
			'blt_popup_summary',
			__( 'Popup Summary', 'blt-popups' ),
			array( __CLASS__, 'render_summary_box' ),
			BLT_POPUPS_CPT,
			'side',
			'default'
		);
	}

	/**
	 * Render the Live Preview sidebar box. The frame itself is populated and
	 * kept in sync entirely by JS (same renderer the front end uses), so a
	 * change to the image, destination, sizing, overlay or CTA fields is
	 * reflected immediately without a page reload.
	 *
	 * @param WP_Post $post Current popup.
	 * @return void
	 */
	public static function render_live_preview_box( $post ) {
		$post_id = (int) $post->ID;
		?>
		<div class="blt-popup-live-preview">
			<div class="blt-popup-device-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Preview device', 'blt-popups' ); ?>">
				<button type="button" class="blt-popup-device-tab is-active" data-device="desktop" role="tab" aria-selected="true"><?php esc_html_e( 'Desktop', 'blt-popups' ); ?></button>
				<button type="button" class="blt-popup-device-tab" data-device="tablet" role="tab" aria-selected="false"><?php esc_html_e( 'Tablet', 'blt-popups' ); ?></button>
				<button type="button" class="blt-popup-device-tab" data-device="mobile" role="tab" aria-selected="false"><?php esc_html_e( 'Mobile', 'blt-popups' ); ?></button>
			</div>
			<div class="blt-popup-preview-frame" data-device="desktop">
				<p class="blt-popup-preview-empty"><?php esc_html_e( 'Select an image to preview your popup.', 'blt-popups' ); ?></p>
			</div>
			<?php if ( $post_id && 'auto-draft' !== $post->post_status ) : ?>
				<p class="blt-popup-preview-link">
					<a href="<?php echo esc_url( BLT_Popups_Meta::preview_url( $post_id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open preview in new tab ↗', 'blt-popups' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Popup Summary sidebar box: a read-only recap of the fields
	 * that most affect whether/how the popup shows, plus its lightweight
	 * analytics and post dates. Reuses the same summary helpers as the list
	 * table so the two never disagree.
	 *
	 * @param WP_Post $post Current popup.
	 * @return void
	 */
	public static function render_summary_box( $post ) {
		$post_id   = (int) $post->ID;
		$is_active = ( 'publish' === $post->post_status );
		$label     = $is_active ? __( 'Live', 'blt-popups' ) : __( 'Draft', 'blt-popups' );
		$class     = $is_active ? 'active' : 'draft';
		$is_saved  = ( $post_id && 'auto-draft' !== $post->post_status );
		?>
		<ul class="blt-popup-summary-list">
			<li>
				<span class="blt-popup-summary-label"><?php esc_html_e( 'Status', 'blt-popups' ); ?></span>
				<span class="blt-popup-badge blt-popup-badge-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
			</li>
			<li>
				<span class="blt-popup-summary-label"><?php esc_html_e( 'Schedule', 'blt-popups' ); ?></span>
				<span class="blt-popup-summary-value"><?php echo esc_html( self::schedule_summary( $post_id ) ); ?></span>
			</li>
			<li>
				<span class="blt-popup-summary-label"><?php esc_html_e( 'Targeting', 'blt-popups' ); ?></span>
				<span class="blt-popup-summary-value"><?php echo esc_html( self::targeting_summary( $post_id ) ); ?></span>
			</li>
			<li>
				<span class="blt-popup-summary-label"><?php esc_html_e( 'Impressions', 'blt-popups' ); ?></span>
				<span class="blt-popup-summary-value"><?php echo (int) BLT_Popups_CPT::get( $post_id, 'impressions' ); ?></span>
			</li>
			<li>
				<span class="blt-popup-summary-label"><?php esc_html_e( 'Clicks', 'blt-popups' ); ?></span>
				<span class="blt-popup-summary-value"><?php echo (int) BLT_Popups_CPT::get( $post_id, 'clicks' ); ?></span>
			</li>
			<?php if ( $is_saved ) : ?>
				<li>
					<span class="blt-popup-summary-label"><?php esc_html_e( 'Created', 'blt-popups' ); ?></span>
					<span class="blt-popup-summary-value"><?php echo esc_html( get_the_date( '', $post_id ) ); ?></span>
				</li>
				<li>
					<span class="blt-popup-summary-label"><?php esc_html_e( 'Last updated', 'blt-popups' ); ?></span>
					<span class="blt-popup-summary-value"><?php echo esc_html( get_the_modified_date( '', $post_id ) ); ?></span>
				</li>
			<?php endif; ?>
		</ul>
		<?php if ( $is_saved && current_user_can( 'edit_post', $post_id ) ) : ?>
			<p class="blt-popup-duplicate">
				<a href="<?php echo esc_url( self::duplicate_url( $post_id ) ); ?>">⧉ <?php esc_html_e( 'Duplicate this popup', 'blt-popups' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/* --------------------------------------------------------------------- *
	 * Title strip (status + last-updated, shown under the title field).
	 * --------------------------------------------------------------------- */

	/**
	 * Render a small status/last-updated strip right under the title field,
	 * via core's own edit_form_after_title hook — fires for every post type,
	 * so this guards to only render on popups.
	 *
	 * @param WP_Post $post Current post (any post type).
	 * @return void
	 */
	public static function after_title_strip( $post ) {
		if ( ! $post || BLT_POPUPS_CPT !== $post->post_type ) {
			return;
		}
		$post_id = (int) $post->ID;
		if ( ! $post_id || 'auto-draft' === $post->post_status ) {
			return;
		}

		$is_active = ( 'publish' === $post->post_status );
		$label     = $is_active ? __( 'Live', 'blt-popups' ) : __( 'Draft', 'blt-popups' );
		$class     = $is_active ? 'active' : 'draft';
		?>
		<div class="blt-popup-title-strip">
			<span class="blt-popup-badge blt-popup-badge-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span>
			<span class="blt-popup-title-strip-modified">
				<?php
				printf(
					/* translators: %s: last-modified date and time. */
					esc_html__( 'Last updated: %s', 'blt-popups' ),
					esc_html( get_the_modified_date( '', $post_id ) . ' ' . get_the_modified_time( '', $post_id ) )
				);
				?>
			</span>
		</div>
		<?php
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
				esc_html__( 'No popup is currently live. Toggle one on from the list below, or publish it from its editor.', 'blt-popups' )
			);
		}
	}

	/**
	 * A small note in the native Publish box explaining the admin-only
	 * restriction, for anyone who can edit a popup but can't make it live.
	 *
	 * @return void
	 */
	public static function publish_box_note() {
		$screen = get_current_screen();
		if ( ! $screen || BLT_POPUPS_CPT !== $screen->post_type ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="misc-pub-section blt-popup-publish-note">%s</div>',
			esc_html__( 'Only an administrator can make a popup live. You can still save your changes as a draft.', 'blt-popups' )
		);
	}

	/**
	 * Enqueue admin assets: the media picker/editor script on the editor
	 * screens, and the lighter list-table toggle script (+ its nonce) on the
	 * list screen. Both screens share the same CSS.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue( $hook ) {
		$is_editor = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		$is_list   = ( 'edit.php' === $hook );
		if ( ! $is_editor && ! $is_list ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || BLT_POPUPS_CPT !== $screen->post_type ) {
			return;
		}

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

		if ( $is_editor ) {
			wp_enqueue_media();
		}

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
				'mediaTitle'        => __( 'Select popup image', 'blt-popups' ),
				'mediaButton'       => __( 'Use this image', 'blt-popups' ),
				'activeId'          => $active_id,
				'activeTitle'       => $active_id ? get_the_title( $active_id ) : '',
				'currentId'         => (int) get_the_ID(),
				'currentPostStatus' => $is_editor && get_post() ? get_post()->post_status : '',
				'popupListUrl'      => esc_url_raw( admin_url( 'edit.php?post_type=' . BLT_POPUPS_CPT ) ),
				'ajaxUrl'           => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'toggleNonce'       => wp_create_nonce( self::TOGGLE_NONCE_ACTION ),
				// Core's own content-search endpoint (the same one the block
				// editor's link inserter uses) — no custom REST route needed.
				'restSearchUrl'     => esc_url_raw( rest_url( 'wp/v2/search' ) ),
				'restNonce'         => wp_create_nonce( 'wp_rest' ),
				'i18n'              => array(
					'confirmReplace'  => __( 'This will deactivate the currently live popup "%s". Continue?', 'blt-popups' ),
					'confirmActivate' => __( 'Make this popup live on the site now?', 'blt-popups' ),
					'toggleError'     => __( 'Could not update this popup. Please try again.', 'blt-popups' ),
					'live'            => __( 'Live', 'blt-popups' ),
					'draft'           => __( 'Draft', 'blt-popups' ),
					'ctaFallback'     => __( 'Learn more', 'blt-popups' ),
					'noResults'       => __( 'No matching pages found.', 'blt-popups' ),
					'searching'       => __( 'Searching…', 'blt-popups' ),
					'previewEmpty'    => __( 'Select an image to preview your popup.', 'blt-popups' ),
					'allPopups'       => __( 'All Popups', 'blt-popups' ),
					'saveDraft'       => __( 'Save Draft', 'blt-popups' ),
					'publish'         => __( 'Publish', 'blt-popups' ),
				),
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * Publish capability guard.
	 * --------------------------------------------------------------------- */

	/**
	 * Making a popup live is a site-wide, visitor-facing action, so it is
	 * restricted to administrators — enforced here at save time regardless of
	 * which UI path attempted the publish (editor, Quick Edit, Bulk Edit, or
	 * our own AJAX toggle, which also checks this before ever calling
	 * wp_update_post()).
	 *
	 * A non-admin editing a popup that is already live keeps it live (this
	 * filter only blocks newly *making* one live); it just can't flip a
	 * draft/inactive popup to publish.
	 *
	 * @param array $data    Slashed post data about to be saved.
	 * @param array $postarr Raw $_POST-derived post array (has the ID, if any).
	 * @return array
	 */
	public static function guard_publish_capability( $data, $postarr ) {
		if ( BLT_POPUPS_CPT !== $data['post_type'] || 'publish' !== $data['post_status'] ) {
			return $data;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			return $data;
		}

		$data['post_status'] = 'draft';
		return $data;
	}

	/* --------------------------------------------------------------------- *
	 * List-table quick toggle (AJAX).
	 * --------------------------------------------------------------------- */

	/**
	 * Handle the list-table toggle switch: publish (go live) or draft
	 * (go inactive) a popup, then report the resulting live popup back so the
	 * browser can update every affected row without a page reload.
	 *
	 * @return void
	 */
	public static function ajax_toggle_status() {
		check_ajax_referer( self::TOGGLE_NONCE_ACTION, 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || BLT_POPUPS_CPT !== get_post_type( $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid popup.', 'blt-popups' ) ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this popup.', 'blt-popups' ) ), 403 );
		}

		$activate = ! empty( $_POST['activate'] );

		if ( $activate && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Only an administrator can make a popup live.', 'blt-popups' ) ), 403 );
		}

		$result = wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => $activate ? 'publish' : 'draft',
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$active_id = self::get_active_id();
		wp_send_json_success(
			array(
				'postId'      => $post_id,
				'isLive'      => ( 'publish' === get_post_status( $post_id ) ),
				'activeId'    => $active_id,
				'activeTitle' => $active_id ? get_the_title( $active_id ) : '',
			)
		);
	}

	/* --------------------------------------------------------------------- *
	 * One-time migration from the old custom "status" meta field.
	 * --------------------------------------------------------------------- */

	/**
	 * Sites upgrading from a version where "live" was tracked in a custom
	 * `_blt_popup_status` meta field (values: draft/inactive/active) instead
	 * of native post_status. Runs once: carries the previously-active popup's
	 * liveness over to post_status = 'publish', drafts everything else
	 * (including any popup that happened to already be a native "Published"
	 * post without ever having been the plugin's active one — under the old
	 * model that flag meant nothing, so it must not suddenly go live now),
	 * and removes the legacy meta.
	 *
	 * @return void
	 */
	public static function maybe_migrate_legacy_status() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}

		$ids = get_posts(
			array(
				'post_type'      => BLT_POPUPS_CPT,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$legacy_key = BLT_Popups_CPT::meta_key( 'status' );
		foreach ( $ids as $id ) {
			$legacy_status = get_post_meta( $id, $legacy_key, true );
			$post_status   = get_post_status( $id );

			if ( 'active' === $legacy_status && 'publish' !== $post_status ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
			} elseif ( 'active' !== $legacy_status && 'publish' === $post_status ) {
				wp_update_post( array( 'ID' => $id, 'post_status' => 'draft' ) );
			}

			delete_post_meta( $id, $legacy_key );
		}

		update_option( self::MIGRATED_OPTION, 1 );
	}
}
