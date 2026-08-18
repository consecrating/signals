<?php
/**
 * Plugin Name:       F&O Signal Pro
 * Plugin URI:        https://example.com/fno-signal-pro
 * Description:       Institutional-grade Futures & Options trading signal engine for NIFTY, BANKNIFTY, FINNIFTY, SENSEX, MIDCPNIFTY, stock futures & equity options. Combines a 100-point quantitative scoring framework with optional AI (Anthropic Claude) narrative analysis. Fast, cached, REST-powered.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            F&O Signal Pro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fno-signal-pro
 *
 * DISCLAIMER: This plugin is a decision-support / educational tool. It does NOT
 * provide financial advice and cannot guarantee accuracy. Trading in F&O carries
 * substantial risk. Always do your own research.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'FNOSP_VERSION', '2.0.0' );
define( 'FNOSP_PLUGIN_FILE', __FILE__ );
define( 'FNOSP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FNOSP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FNOSP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'FNOSP_OPTION_KEY', 'fnosp_settings' );
define( 'FNOSP_CACHE_PREFIX', 'fnosp_sig_' );

// ---------------------------------------------------------------------------
// Simple PSR-style autoloader for plugin classes.
// Classes are named FnOSP_Something and live in includes/class-fnosp-something.php
// ---------------------------------------------------------------------------
spl_autoload_register(
	function ( $class ) {
		if ( strpos( $class, 'FnOSP_' ) !== 0 ) {
			return;
		}
		$slug = strtolower( str_replace( array( 'FnOSP_', '_' ), array( '', '-' ), $class ) );
		$paths = array(
			FNOSP_PLUGIN_DIR . 'includes/class-fnosp-' . $slug . '.php',
			FNOSP_PLUGIN_DIR . 'admin/class-fnosp-' . $slug . '.php',
		);
		foreach ( $paths as $path ) {
			if ( file_exists( $path ) ) {
				require_once $path;
				return;
			}
		}
	}
);

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------
register_activation_hook(
	__FILE__,
	function () {
		// Always overwrite with fresh defaults on activation to fix broken installs.
		update_option( FNOSP_OPTION_KEY, FnOSP_Settings::default_settings() );
		add_option( 'fnosp_db_version', FNOSP_VERSION );
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		// Clear all cached signals on deactivation.
		if ( class_exists( 'FnOSP_Cache' ) ) {
			FnOSP_Cache::flush_all();
		}
		// Clear the alert cron event.
		if ( class_exists( 'FnOSP_Scheduler' ) ) {
			FnOSP_Scheduler::clear();
		}
		flush_rewrite_rules();
	}
);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'fno-signal-pro', false, dirname( FNOSP_PLUGIN_BASENAME ) . '/languages' );
		FnOSP_Plugin::instance()->run();
	}
);
