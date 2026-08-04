<?php
/**
 * Plugin Name:       BLT Popups
 * Plugin URI:        https://s-fx.com/plugins/blt-popups/
 * Description:       Lightweight, single-purpose image popups — scheduled, targeted, and cache-safe. One active popup site-wide, unlimited saved. No page-builder or lightbox-library dependencies.
 * Version:           1.0.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            S-FX.com Small Business Solutions
 * Author URI:        https://s-fx.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blt-popups
 * Domain Path:       /languages
 *
 * @package Blt_Popups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------- Constants ----------
define( 'BLT_POPUPS_VERSION', '1.0.5' );
define( 'BLT_POPUPS_FILE', __FILE__ );
define( 'BLT_POPUPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLT_POPUPS_URL', plugin_dir_url( __FILE__ ) );
define( 'BLT_POPUPS_CPT', 'blt_popup' );
define( 'BLT_POPUPS_META_PREFIX', '_blt_popup_' );
define( 'BLT_POPUPS_REST_NS', 'blt-popups/v1' );

// ---------- Update checker ----------
// Self-hosted updates served from GitHub release assets (the CI-built zip
// with a stable blt-popups/ top-level folder, produced by
// .github/workflows/release.yml) — never from source zipballs, whose folder
// name includes the commit hash and would break the install path.
//
// The repository is public, so update checks work with no credentials. A
// GitHub token is optional; when the BLT_POPUPS_GITHUB_TOKEN wp-config
// constant is defined it is used only to raise the API rate limit
// (60->5000 req/hr) and to keep working if the repo is ever made private.
require_once BLT_POPUPS_DIR . 'includes/lib/plugin-update-checker/plugin-update-checker.php';

$blt_popups_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/S-FX-com/BLT-Popups/',
	__FILE__,
	'blt-popups'
);
$blt_popups_update_checker->setBranch( 'main' );
if ( defined( 'BLT_POPUPS_GITHUB_TOKEN' ) && is_string( BLT_POPUPS_GITHUB_TOKEN ) && '' !== BLT_POPUPS_GITHUB_TOKEN ) {
	$blt_popups_update_checker->setAuthentication( BLT_POPUPS_GITHUB_TOKEN );
}
// Only accept the CI-built zip asset; ignore checksums/source archives.
$blt_popups_update_checker->getVcsApi()->enableReleaseAssets( '/^blt-popups(-[\d.]+)?\.zip$/i' );

// ---------- Autoloader ----------
spl_autoload_register(
	function ( $class ) {
		$prefix = 'BLT_Popups_';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$relative = strtolower( str_replace( '_', '-', $relative ) );

		$map = array(
			'cpt'    => 'includes/class-blt-popups-cpt.php',
			'meta'   => 'includes/class-blt-popups-meta.php',
			'admin'  => 'includes/class-blt-popups-admin.php',
			'render' => 'includes/class-blt-popups-render.php',
			'rest'   => 'includes/class-blt-popups-rest.php',
		);

		if ( isset( $map[ $relative ] ) ) {
			$file = BLT_POPUPS_DIR . $map[ $relative ];
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
);

// ---------- Activation / Deactivation ----------
register_activation_hook(
	__FILE__,
	function () {
		BLT_Popups_CPT::register();
		flush_rewrite_rules();
	}
);
register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);

// ---------- i18n ----------
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'blt-popups', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}
);

// ---------- Boot ----------
add_action(
	'plugins_loaded',
	function () {
		// CPT + meta box (front-end and admin both need the CPT registered).
		BLT_Popups_CPT::init();
		BLT_Popups_Meta::init();

		// REST endpoints (active-popup config + impression/click tracking).
		BLT_Popups_REST::init();

		// Admin UI: list columns, activate/deactivate, live-badge, editor assets.
		if ( is_admin() ) {
			BLT_Popups_Admin::init();
		}

		// Front-end eligibility + enqueue (skips wp-admin internally).
		BLT_Popups_Render::init();
	}
);
