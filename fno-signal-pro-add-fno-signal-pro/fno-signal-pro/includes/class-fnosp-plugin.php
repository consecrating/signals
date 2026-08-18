<?php
/**
 * Main plugin orchestrator. Wires together all components.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FnOSP_Plugin {

	/** @var FnOSP_Plugin|null */
	private static $instance = null;

	/** @var FnOSP_Settings */
	public $settings;

	/** @var FnOSP_Admin */
	public $admin;

	/** @var FnOSP_Rest_Api */
	public $rest;

	/** @var FnOSP_Shortcode */
	public $shortcode;

	/** @var FnOSP_Scheduler */
	public $scheduler;

	/**
	 * Singleton accessor.
	 *
	 * @return FnOSP_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new FnOSP_Settings();
		$this->rest      = new FnOSP_Rest_Api();
		$this->shortcode = new FnOSP_Shortcode();
		$this->scheduler = new FnOSP_Scheduler( $this->settings );

		if ( is_admin() ) {
			$this->admin = new FnOSP_Admin( $this->settings );
		}
	}

	/**
	 * Register all hooks.
	 */
	public function run() {
		if ( is_admin() && $this->admin ) {
			$this->admin->hooks();
		}
		$this->rest->hooks();
		$this->shortcode->hooks();
		$this->scheduler->hooks();

		add_filter( 'plugin_action_links_' . FNOSP_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add a Settings link on the Plugins page.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url      = admin_url( 'admin.php?page=fnosp-settings' );
		$settings = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'fno-signal-pro' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Convenience factory for a fully wired signal engine.
	 *
	 * @return FnOSP_Signal_Engine
	 */
	public static function make_engine() {
		$settings      = new FnOSP_Settings();
		$data_provider = new FnOSP_Data_Provider( $settings );
		$ai            = new FnOSP_AI_Analyst( $settings );
		return new FnOSP_Signal_Engine( $settings, $data_provider, $ai );
	}
}
