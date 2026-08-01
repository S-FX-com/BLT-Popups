<?php
/**
 * Meta box UI, field rendering, and save/sanitize for the popup CPT.
 *
 * @package Blt_Popups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the single-column, grouped meta box on the popup editor and
 * sanitizes/persists every field on save. Activation performed here routes
 * through {@see BLT_Popups_Admin} so single-active enforcement stays in one
 * place.
 */
class BLT_Popups_Meta {

	const NONCE_ACTION = 'blt_popups_save_meta';
	const NONCE_NAME   = 'blt_popups_meta_nonce';

	/**
	 * Hook registration.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . BLT_POPUPS_CPT, array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public static function add_meta_box() {
		add_meta_box(
			'blt_popup_settings',
			__( 'Popup Settings', 'blt-popups' ),
			array( __CLASS__, 'render' ),
			BLT_POPUPS_CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Current popup.
	 * @return void
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$post_id = (int) $post->ID;
		$get     = function ( $field ) use ( $post_id ) {
			return BLT_Popups_CPT::get( $post_id, $field );
		};

		$image_id  = (int) $get( 'image_id' );
		$image_src = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';

		$dest_type       = $get( 'dest_type' );
		$dest_page_id    = (int) $get( 'dest_page_id' );
		$dest_page_title = $dest_page_id ? get_the_title( $dest_page_id ) : '';

		// Renders a numbered, collapsible section header (see the matching
		// closing </div></details> after each section's fields below).
		$section_header = function ( $number, $title, $description ) {
			printf(
				'<summary class="blt-popup-section-header"><span class="blt-popup-step-num">%1$d</span><span class="blt-popup-section-heading"><span class="blt-popup-section-title">%2$s</span><span class="blt-popup-section-desc">%3$s</span></span></summary>',
				(int) $number,
				esc_html( $title ),
				esc_html( $description )
			);
		};
		?>
		<div class="blt-popup-metabox">

			<details class="blt-popup-section" open>
				<?php $section_header( 1, __( 'Content', 'blt-popups' ), __( 'Add the image and destination for your popup.', 'blt-popups' ) ); ?>
				<div class="blt-popup-section-body">

					<p class="blt-popup-field">
						<label class="blt-popup-label"><?php esc_html_e( 'Popup image', 'blt-popups' ); ?></label>
						<span class="blt-popup-image-preview<?php echo $image_src ? '' : ' is-empty'; ?>">
							<?php if ( $image_src ) : ?>
								<img src="<?php echo esc_url( $image_src ); ?>" alt="" />
							<?php endif; ?>
						</span>
						<input type="hidden" name="blt_popup_image_id" id="blt_popup_image_id" value="<?php echo esc_attr( $image_id ); ?>" />
						<button type="button" class="button blt-popup-image-select"><?php esc_html_e( 'Select image', 'blt-popups' ); ?></button>
						<button type="button" class="button blt-popup-image-remove"<?php echo $image_id ? '' : ' style="display:none"'; ?>><?php esc_html_e( 'Remove', 'blt-popups' ); ?></button>
					</p>

					<p class="blt-popup-field">
						<span class="blt-popup-label"><?php esc_html_e( 'Destination type', 'blt-popups' ); ?></span>
						<div class="blt-popup-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Destination type', 'blt-popups' ); ?>">
							<label class="blt-popup-segmented-option">
								<input type="radio" name="blt_popup_dest_type" value="external" <?php checked( $dest_type, 'external' ); ?> />
								<span><?php esc_html_e( 'External', 'blt-popups' ); ?></span>
							</label>
							<label class="blt-popup-segmented-option">
								<input type="radio" name="blt_popup_dest_type" value="internal" <?php checked( $dest_type, 'internal' ); ?> />
								<span><?php esc_html_e( 'Internal', 'blt-popups' ); ?></span>
							</label>
						</div>
					</p>

					<p class="blt-popup-field blt-popup-dest" data-dest="external" <?php echo 'external' === $dest_type ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-label" for="blt_popup_dest_url"><?php esc_html_e( 'Destination URL', 'blt-popups' ); ?></label>
						<input type="url" class="widefat" name="blt_popup_dest_url" id="blt_popup_dest_url" value="<?php echo esc_attr( $get( 'dest_url' ) ); ?>" placeholder="https://" />
						<span class="description"><?php esc_html_e( 'Opens in a new browser tab.', 'blt-popups' ); ?></span>
					</p>

					<p class="blt-popup-field blt-popup-dest" data-dest="internal" <?php echo 'internal' === $dest_type ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-label" for="blt_popup_dest_page_search"><?php esc_html_e( 'Destination page', 'blt-popups' ); ?></label>
						<span class="blt-popup-page-search-wrap">
							<input type="text" class="widefat blt-popup-page-search" id="blt_popup_dest_page_search" value="<?php echo esc_attr( $dest_page_title ); ?>" placeholder="<?php esc_attr_e( 'Start typing a page name…', 'blt-popups' ); ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" />
							<input type="hidden" name="blt_popup_dest_page_id" id="blt_popup_dest_page_id" value="<?php echo esc_attr( $dest_page_id ); ?>" />
							<ul class="blt-popup-page-suggestions" role="listbox" hidden></ul>
						</span>
					</p>

					<p class="blt-popup-field blt-popup-dest" data-dest="internal" <?php echo 'internal' === $dest_type ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-switch">
							<input type="checkbox" name="blt_popup_dest_new_tab" value="1" <?php checked( (bool) $get( 'dest_new_tab' ) ); ?> />
							<span class="blt-popup-switch-track" aria-hidden="true"></span>
							<span class="blt-popup-switch-label"><?php esc_html_e( 'Open destination in a new tab', 'blt-popups' ); ?></span>
						</label>
					</p>

					<p class="blt-popup-field">
						<label class="blt-popup-switch">
							<input type="checkbox" name="blt_popup_cta_enabled" id="blt_popup_cta_enabled" value="1" <?php checked( (bool) $get( 'cta_enabled' ) ); ?> />
							<span class="blt-popup-switch-track" aria-hidden="true"></span>
							<span class="blt-popup-switch-label"><?php esc_html_e( 'Show a call-to-action button (in addition to the clickable image)', 'blt-popups' ); ?></span>
						</label>
					</p>

					<p class="blt-popup-field blt-popup-cta-text" <?php echo $get( 'cta_enabled' ) ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-label" for="blt_popup_cta_text"><?php esc_html_e( 'Button text', 'blt-popups' ); ?></label>
						<input type="text" class="widefat" name="blt_popup_cta_text" id="blt_popup_cta_text" value="<?php echo esc_attr( $get( 'cta_text' ) ); ?>" placeholder="<?php esc_attr_e( 'Learn more', 'blt-popups' ); ?>" />
					</p>
				</div>
			</details>

			<details class="blt-popup-section" open>
				<?php $section_header( 2, __( 'Appearance', 'blt-popups' ), __( 'Control the size, style and look of your popup.', 'blt-popups' ) ); ?>
				<div class="blt-popup-section-body">

					<div class="blt-popup-field-row">
						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_max_width_pct"><?php esc_html_e( 'Max width (% of viewport)', 'blt-popups' ); ?></label>
							<input type="number" min="10" max="100" step="1" name="blt_popup_max_width_pct" id="blt_popup_max_width_pct" value="<?php echo esc_attr( $get( 'max_width_pct' ) ); ?>" />
						</p>

						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_max_height_pct"><?php esc_html_e( 'Max height (% of viewport)', 'blt-popups' ); ?></label>
							<input type="number" min="10" max="100" step="1" name="blt_popup_max_height_pct" id="blt_popup_max_height_pct" value="<?php echo esc_attr( $get( 'max_height_pct' ) ); ?>" />
						</p>
					</div>

					<div class="blt-popup-field-row">
						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_overlay_color"><?php esc_html_e( 'Overlay color', 'blt-popups' ); ?></label>
							<input type="color" name="blt_popup_overlay_color" id="blt_popup_overlay_color" value="<?php echo esc_attr( $get( 'overlay_color' ) ); ?>" />
						</p>

						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_overlay_opacity"><?php esc_html_e( 'Overlay opacity (0–1)', 'blt-popups' ); ?></label>
							<input type="number" min="0" max="1" step="0.05" name="blt_popup_overlay_opacity" id="blt_popup_overlay_opacity" value="<?php echo esc_attr( $get( 'overlay_opacity' ) ); ?>" />
						</p>
					</div>
				</div>
			</details>

			<details class="blt-popup-section" open>
				<?php $section_header( 3, __( 'Schedule', 'blt-popups' ), __( 'Choose when your popup should appear.', 'blt-popups' ) ); ?>
				<div class="blt-popup-section-body">
					<p class="description"><?php esc_html_e( 'All optional. Leave dates blank to run indefinitely; leave times blank for all-day. Evaluated in the site timezone.', 'blt-popups' ); ?></p>

					<div class="blt-popup-field-row">
						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_start_date"><?php esc_html_e( 'Start date', 'blt-popups' ); ?></label>
							<input type="date" name="blt_popup_start_date" id="blt_popup_start_date" value="<?php echo esc_attr( $get( 'start_date' ) ); ?>" />
						</p>

						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_start_time"><?php esc_html_e( 'Start time', 'blt-popups' ); ?></label>
							<input type="time" name="blt_popup_start_time" id="blt_popup_start_time" value="<?php echo esc_attr( $get( 'start_time' ) ); ?>" />
						</p>
					</div>

					<div class="blt-popup-field-row">
						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_end_date"><?php esc_html_e( 'End date', 'blt-popups' ); ?></label>
							<input type="date" name="blt_popup_end_date" id="blt_popup_end_date" value="<?php echo esc_attr( $get( 'end_date' ) ); ?>" />
						</p>

						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_end_time"><?php esc_html_e( 'End time', 'blt-popups' ); ?></label>
							<input type="time" name="blt_popup_end_time" id="blt_popup_end_time" value="<?php echo esc_attr( $get( 'end_time' ) ); ?>" />
						</p>
					</div>
				</div>
			</details>

			<details class="blt-popup-section" open>
				<?php $section_header( 4, __( 'Targeting', 'blt-popups' ), __( 'Choose where your popup will be shown.', 'blt-popups' ) ); ?>
				<div class="blt-popup-section-body">

					<?php
					$target_mode  = $get( 'target_mode' );
					$target_value = $get( 'target_value' );
					$target_pages = is_array( $target_value ) ? array_map( 'intval', $target_value ) : array();
					$target_types = is_array( $target_value ) ? array_map( 'strval', $target_value ) : array();
					$target_url   = is_string( $target_value ) ? $target_value : '';
					?>

					<p class="blt-popup-field">
						<label class="blt-popup-label" for="blt_popup_target_mode"><?php esc_html_e( 'Show on', 'blt-popups' ); ?></label>
						<select name="blt_popup_target_mode" id="blt_popup_target_mode">
							<option value="all" <?php selected( $target_mode, 'all' ); ?>><?php esc_html_e( 'All pages', 'blt-popups' ); ?></option>
							<option value="home" <?php selected( $target_mode, 'home' ); ?>><?php esc_html_e( 'Homepage only', 'blt-popups' ); ?></option>
							<option value="pages" <?php selected( $target_mode, 'pages' ); ?>><?php esc_html_e( 'Specific pages', 'blt-popups' ); ?></option>
							<option value="post_types" <?php selected( $target_mode, 'post_types' ); ?>><?php esc_html_e( 'Specific post types', 'blt-popups' ); ?></option>
							<option value="url_pattern" <?php selected( $target_mode, 'url_pattern' ); ?>><?php esc_html_e( 'URL pattern', 'blt-popups' ); ?></option>
						</select>
					</p>

					<div class="blt-popup-target blt-popup-target-pages" data-mode="pages" <?php echo 'pages' === $target_mode ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-label"><?php esc_html_e( 'Select pages', 'blt-popups' ); ?></label>
						<select name="blt_popup_target_pages[]" multiple size="8" class="widefat">
							<?php
							$pages = get_pages( array( 'sort_column' => 'post_title' ) );
							foreach ( (array) $pages as $page ) {
								printf(
									'<option value="%d" %s>%s</option>',
									(int) $page->ID,
									selected( in_array( (int) $page->ID, $target_pages, true ), true, false ),
									esc_html( $page->post_title )
								);
							}
							?>
						</select>
					</div>

					<div class="blt-popup-target blt-popup-target-post_types" data-mode="post_types" <?php echo 'post_types' === $target_mode ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-label"><?php esc_html_e( 'Select post types', 'blt-popups' ); ?></label>
						<?php
						$post_types = get_post_types( array( 'public' => true ), 'objects' );
						foreach ( $post_types as $pt ) {
							if ( in_array( $pt->name, array( 'attachment', BLT_POPUPS_CPT ), true ) ) {
								continue;
							}
							printf(
								'<label class="blt-popup-inline"><input type="checkbox" name="blt_popup_target_post_types[]" value="%s" %s /> %s</label>',
								esc_attr( $pt->name ),
								checked( in_array( $pt->name, $target_types, true ), true, false ),
								esc_html( $pt->labels->singular_name )
							);
						}
						?>
					</div>

					<div class="blt-popup-target blt-popup-target-url_pattern" data-mode="url_pattern" <?php echo 'url_pattern' === $target_mode ? '' : 'style="display:none"'; ?>>
						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_url_match"><?php esc_html_e( 'Match type', 'blt-popups' ); ?></label>
							<select name="blt_popup_url_match" id="blt_popup_url_match">
								<option value="contains" <?php selected( $get( 'url_match' ), 'contains' ); ?>><?php esc_html_e( 'URL contains', 'blt-popups' ); ?></option>
								<option value="starts_with" <?php selected( $get( 'url_match' ), 'starts_with' ); ?>><?php esc_html_e( 'URL starts with', 'blt-popups' ); ?></option>
							</select>
						</p>
						<p class="blt-popup-field">
							<label class="blt-popup-label" for="blt_popup_target_url"><?php esc_html_e( 'Pattern', 'blt-popups' ); ?></label>
							<input type="text" class="widefat" name="blt_popup_target_url" id="blt_popup_target_url" value="<?php echo esc_attr( $target_url ); ?>" placeholder="/promo" />
						</p>
					</div>
				</div>
			</details>

			<details class="blt-popup-section" open>
				<?php $section_header( 5, __( 'Frequency', 'blt-popups' ), __( 'Control how often the popup appears.', 'blt-popups' ) ); ?>
				<div class="blt-popup-section-body">

					<p class="blt-popup-field">
						<label class="blt-popup-label" for="blt_popup_frequency"><?php esc_html_e( 'Show to each visitor', 'blt-popups' ); ?></label>
						<select name="blt_popup_frequency" id="blt_popup_frequency">
							<option value="always" <?php selected( $get( 'frequency' ), 'always' ); ?>><?php esc_html_e( 'Every page load', 'blt-popups' ); ?></option>
							<option value="session" <?php selected( $get( 'frequency' ), 'session' ); ?>><?php esc_html_e( 'Once per session', 'blt-popups' ); ?></option>
							<option value="daily" <?php selected( $get( 'frequency' ), 'daily' ); ?>><?php esc_html_e( 'Once per day', 'blt-popups' ); ?></option>
							<option value="every_n_days" <?php selected( $get( 'frequency' ), 'every_n_days' ); ?>><?php esc_html_e( 'Once every N days', 'blt-popups' ); ?></option>
							<option value="once" <?php selected( $get( 'frequency' ), 'once' ); ?>><?php esc_html_e( 'Once ever', 'blt-popups' ); ?></option>
						</select>
					</p>

					<p class="blt-popup-field blt-popup-frequency-days" <?php echo 'every_n_days' === $get( 'frequency' ) ? '' : 'style="display:none"'; ?>>
						<label class="blt-popup-label" for="blt_popup_frequency_days"><?php esc_html_e( 'Number of days', 'blt-popups' ); ?></label>
						<input type="number" min="1" max="365" step="1" name="blt_popup_frequency_days" id="blt_popup_frequency_days" value="<?php echo esc_attr( $get( 'frequency_days' ) ); ?>" />
					</p>
				</div>
			</details>

			<details class="blt-popup-section" open>
				<?php $section_header( 6, __( 'Status', 'blt-popups' ), __( 'Set the status of your popup.', 'blt-popups' ) ); ?>
				<div class="blt-popup-section-body">

					<?php
					$status       = $get( 'status' );
					$active_id    = BLT_Popups_Admin::get_active_id();
					$is_active    = ( $active_id === $post_id );
					// Making a popup live is a site-wide, visitor-facing action, so
					// it is restricted to admins (matching the preview bypass).
					$can_activate = current_user_can( 'manage_options' );
					?>

					<p class="blt-popup-field">
						<label class="blt-popup-label" for="blt_popup_status"><?php esc_html_e( 'Status', 'blt-popups' ); ?></label>
						<select name="blt_popup_status" id="blt_popup_status">
							<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'blt-popups' ); ?></option>
							<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'blt-popups' ); ?></option>
							<?php if ( $can_activate || $is_active ) : ?>
								<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active (live)', 'blt-popups' ); ?></option>
							<?php endif; ?>
						</select>
					</p>

					<?php if ( $is_active ) : ?>
						<p class="blt-popup-live-note"><?php esc_html_e( 'This popup is currently live on the site.', 'blt-popups' ); ?></p>
					<?php elseif ( ! $can_activate ) : ?>
						<p class="description"><?php esc_html_e( 'Only an administrator can make a popup live.', 'blt-popups' ); ?></p>
					<?php endif; ?>

					<?php if ( $can_activate ) : ?>
						<p class="blt-popup-actions">
							<button type="button" class="button button-primary blt-popup-activate-btn"><?php esc_html_e( 'Activate (make live)', 'blt-popups' ); ?></button>
						</p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Only one popup can be live at a time. Activating this one deactivates any other active popup.', 'blt-popups' ); ?></p>
				</div>
			</details>
		</div>
		<?php
	}

	/**
	 * Build the nonce-signed front-end bypass-preview URL for a popup.
	 *
	 * @param int $post_id Popup ID.
	 * @return string
	 */
	public static function preview_url( $post_id ) {
		return add_query_arg(
			array(
				'blt_preview' => (int) $post_id,
				'_blt_nonce'  => wp_create_nonce( 'blt_preview_' . (int) $post_id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Save + sanitize all fields.
	 *
	 * @param int     $post_id Popup ID.
	 * @param WP_Post $post    Popup object.
	 * @return void
	 */
	public static function save( $post_id, $post ) {
		// Guards: nonce, autosave, revision, capability, correct type.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( BLT_POPUPS_CPT !== $post->post_type ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = BLT_Popups_CPT::fields();

		// Scalar/simple fields sourced from same-named blt_popup_ inputs.
		$simple = array(
			'dest_type',
			'dest_url',
			'dest_page_id',
			'dest_new_tab',
			'cta_enabled',
			'cta_text',
			'max_width_pct',
			'max_height_pct',
			'overlay_color',
			'overlay_opacity',
			'start_date',
			'end_date',
			'start_time',
			'end_time',
			'url_match',
			'frequency',
			'frequency_days',
			'image_id',
		);

		foreach ( $simple as $field ) {
			$input = 'blt_popup_' . $field;
			$raw   = isset( $_POST[ $input ] ) ? wp_unslash( $_POST[ $input ] ) : null;
			$value = self::sanitize_field( $fields[ $field ]['type'], $raw, $fields[ $field ] );
			update_post_meta( $post_id, BLT_Popups_CPT::meta_key( $field ), $value );
		}

		// Targeting mode + value (value shape depends on mode).
		$mode = self::sanitize_field(
			'enum',
			isset( $_POST['blt_popup_target_mode'] ) ? wp_unslash( $_POST['blt_popup_target_mode'] ) : '',
			$fields['target_mode']
		);
		update_post_meta( $post_id, BLT_Popups_CPT::meta_key( 'target_mode' ), $mode );

		$target_value = self::collect_target_value( $mode );
		update_post_meta( $post_id, BLT_Popups_CPT::meta_key( 'target_value' ), $target_value );

		// Status + single-active enforcement.
		$status = self::sanitize_field(
			'enum',
			isset( $_POST['blt_popup_status'] ) ? wp_unslash( $_POST['blt_popup_status'] ) : '',
			$fields['status']
		);

		$already_active = ( BLT_Popups_Admin::get_active_id() === (int) $post_id );

		if ( BLT_Popups_CPT::STATUS_ACTIVE === $status ) {
			if ( current_user_can( 'manage_options' ) ) {
				// Routes through Admin so the option + sibling-deactivation stay
				// in one place. This also writes this post's status meta = active.
				BLT_Popups_Admin::set_active( $post_id );
			} elseif ( $already_active ) {
				// A non-admin saving the popup that is already live: keep it
				// live (don't silently deactivate), but don't let them change
				// the site-wide activation.
				update_post_meta( $post_id, BLT_Popups_CPT::meta_key( 'status' ), BLT_Popups_CPT::STATUS_ACTIVE );
			} else {
				// Making a popup live is a site-wide, visitor-facing action, so
				// it requires manage_options (matching the preview bypass). A
				// non-admin's "active" request is saved as inactive instead.
				update_post_meta( $post_id, BLT_Popups_CPT::meta_key( 'status' ), BLT_Popups_CPT::STATUS_INACTIVE );
			}
		} else {
			update_post_meta( $post_id, BLT_Popups_CPT::meta_key( 'status' ), $status );
			// If this popup was the live one and is being taken off active,
			// clear the site-wide pointer.
			if ( $already_active ) {
				BLT_Popups_Admin::clear_active( $post_id );
			}
		}
	}

	/**
	 * Collect + sanitize the targeting value for the given mode.
	 *
	 * @param string $mode Targeting mode.
	 * @return array|string
	 */
	private static function collect_target_value( $mode ) {
		switch ( $mode ) {
			case 'pages':
				$raw = isset( $_POST['blt_popup_target_pages'] ) ? (array) wp_unslash( $_POST['blt_popup_target_pages'] ) : array();
				return array_values( array_filter( array_map( 'absint', $raw ) ) );

			case 'post_types':
				$raw   = isset( $_POST['blt_popup_target_post_types'] ) ? (array) wp_unslash( $_POST['blt_popup_target_post_types'] ) : array();
				$valid = get_post_types( array( 'public' => true ) );
				$out   = array();
				foreach ( $raw as $slug ) {
					$slug = sanitize_key( $slug );
					if ( isset( $valid[ $slug ] ) && BLT_POPUPS_CPT !== $slug ) {
						$out[] = $slug;
					}
				}
				return array_values( array_unique( $out ) );

			case 'url_pattern':
				$raw = isset( $_POST['blt_popup_target_url'] ) ? wp_unslash( $_POST['blt_popup_target_url'] ) : '';
				return sanitize_text_field( $raw );

			default:
				// all / home carry no value.
				return array();
		}
	}

	/**
	 * Sanitize a single value according to its schema type.
	 *
	 * @param string $type  Schema type.
	 * @param mixed  $raw   Raw (unslashed) input.
	 * @param array  $field Schema entry (for min/max/values/default).
	 * @return mixed
	 */
	public static function sanitize_field( $type, $raw, $field ) {
		switch ( $type ) {
			case 'int':
				return max( 0, (int) $raw );

			case 'int_range':
				$n   = (int) $raw;
				$min = isset( $field['min'] ) ? (int) $field['min'] : 0;
				$max = isset( $field['max'] ) ? (int) $field['max'] : PHP_INT_MAX;
				if ( '' === (string) $raw || null === $raw ) {
					return (int) $field['default'];
				}
				return min( $max, max( $min, $n ) );

			case 'float_range':
				if ( '' === (string) $raw || null === $raw ) {
					return (float) $field['default'];
				}
				$f   = (float) $raw;
				$min = isset( $field['min'] ) ? (float) $field['min'] : 0.0;
				$max = isset( $field['max'] ) ? (float) $field['max'] : 1.0;
				return min( $max, max( $min, $f ) );

			case 'bool':
				return ( '1' === (string) $raw || 1 === $raw || true === $raw ) ? 1 : 0;

			case 'url':
				return esc_url_raw( trim( (string) $raw ) );

			case 'text':
				return sanitize_text_field( (string) $raw );

			case 'hex':
				$hex = sanitize_hex_color( (string) $raw );
				return $hex ? $hex : $field['default'];

			case 'date':
				return self::sanitize_date( (string) $raw );

			case 'time':
				return self::sanitize_time( (string) $raw );

			case 'enum':
				$val = sanitize_key( (string) $raw );
				return in_array( $val, $field['values'], true ) ? $val : $field['default'];

			default:
				return sanitize_text_field( (string) $raw );
		}
	}

	/**
	 * Validate a Y-m-d date string; empty when blank/invalid.
	 *
	 * @param string $value Raw date.
	 * @return string
	 */
	private static function sanitize_date( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * Validate an H:i time string; empty when blank/invalid.
	 *
	 * @param string $value Raw time.
	 * @return string
	 */
	private static function sanitize_time( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$t = DateTime::createFromFormat( 'H:i', $value );
		return ( $t && $t->format( 'H:i' ) === $value ) ? $value : '';
	}
}
