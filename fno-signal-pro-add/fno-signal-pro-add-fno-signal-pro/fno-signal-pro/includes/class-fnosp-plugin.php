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

	/** @var FnOSP_Watchdog */
	public $watchdog;

	/** @var FnOSP_Trigger_Engine */
	public $trigger_engine;

	/** @var FnOSP_Entry_Optimizer */
	public $entry_optimizer;

	/** @var FnOSP_Risk_Shield */
	public $risk_shield;

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

		// Advanced confirmation engine (Watchdog + Trigger + Entry + Risk).
		$this->trigger_engine  = new FnOSP_Trigger_Engine( $this->settings );
		$this->entry_optimizer = new FnOSP_Entry_Optimizer( $this->settings );
		$this->risk_shield     = new FnOSP_Risk_Shield( $this->settings );
		$this->watchdog        = new FnOSP_Watchdog( $this->settings );
		$this->watchdog->set_dependencies( $this->trigger_engine, $this->entry_optimizer, $this->risk_shield );

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
		$this->watchdog->hooks();

		// Wire watchdog into the scheduler's 5-min tick.
		add_action( FnOSP_Scheduler::CRON_HOOK, array( $this->watchdog, 'normal_tick' ), 5 );

		// Watchdog REST endpoints.
		add_action( 'rest_api_init', array( $this, 'register_watchdog_routes' ) );

		add_filter( 'plugin_action_links_' . FNOSP_PLUGIN_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Register Watchdog REST API routes.
	 */
	public function register_watchdog_routes() {
		// GET /fnosp/v1/watchdog — dashboard state summary.
		register_rest_route(
			'fnosp/v1',
			'/watchdog',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_watchdog_state' ),
				'permission_callback' => '__return_true',
			)
		);

		// POST /fnosp/v1/watchdog/reset — reset all watchdog states.
		register_rest_route(
			'fnosp/v1',
			'/watchdog/reset',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_watchdog_reset' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// GET /fnosp/v1/watchdog/history — trade history.
		register_rest_route(
			'fnosp/v1',
			'/watchdog/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_watchdog_history' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * REST: Get watchdog dashboard state.
	 */
	public function rest_watchdog_state() {
		return rest_ensure_response( array(
			'ok'      => true,
			'states'  => $this->watchdog->get_dashboard_summary(),
			'rapid'   => (bool) wp_next_scheduled( FnOSP_Watchdog::CRON_RAPID ),
			'time'    => gmdate( 'c' ),
		) );
	}

	/**
	 * REST: Reset all watchdog states (admin only).
	 */
	public function rest_watchdog_reset() {
		delete_option( FnOSP_Watchdog::STATE_OPTION );
		$this->watchdog->deactivate_rapid_scan();
		return rest_ensure_response( array( 'ok' => true, 'message' => 'Watchdog state reset.' ) );
	}

	/**
	 * REST: Trade history.
	 */
	public function rest_watchdog_history() {
		$history = get_option( FnOSP_Watchdog::HISTORY_OPTION, array() );
		return rest_ensure_response( array(
			'ok'      => true,
			'trades'  => is_array( $history ) ? array_reverse( $history ) : array(),
		) );
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

	/**
	 * Get the watchdog instance (for external use).
	 *
	 * @return FnOSP_Watchdog
	 */
	public static function watchdog() {
		return self::instance()->watchdog;
	}
}
