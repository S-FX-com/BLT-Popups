<?php
/**
 * Uninstall cleanup for BLT Popups.
 *
 * Runs only on plugin deletion (not deactivation). Removes the site-wide
 * active-popup pointer and every popup post together with its meta.
 *
 * @package Blt_Popups
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'blt_popups_active_id' );

$popups = get_posts(
	array(
		'post_type'      => 'blt_popup',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $popups as $popup_id ) {
	wp_delete_post( $popup_id, true );
}
