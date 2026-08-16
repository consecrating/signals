<?php
/**
 * Admin area: menu, settings registration, dashboard, asset enqueue.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Admin {

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'F&O Signal Pro', 'fno-signal-pro' ),
			__( 'F&O Signals', 'fno-signal-pro' ),
			'manage_options',
			'fnosp-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-chart-line',
			58
		);

		add_submenu_page(
			'fnosp-dashboard',
			__( 'Dashboard', 'fno-signal-pro' ),
			__( 'Dashboard', 'fno-signal-pro' ),
			'manage_options',
			'fnosp-dashboard',
			array( $this, 'render_dashboard' )
		);

		add_submenu_page(
			'fnosp-dashboard',
			__( 'Settings', 'fno-signal-pro' ),
			__( 'Settings', 'fno-signal-pro' ),
			'manage_options',
			'fnosp-settings',
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Handle settings form submit.
	 */
	public function maybe_save_settings() {
		if ( ! isset( $_POST['fnosp_settings_nonce'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fnosp_settings_nonce'] ) ), 'fnosp_save_settings' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw = isset( $_POST['fnosp'] ) ? wp_unslash( $_POST['fnosp'] ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$this->settings->save( $raw );

		// Bust caches when settings change.
		FnOSP_Cache::flush_all();

		// Reschedule the daily digest with any new time/toggle.
		if ( class_exists( 'FnOSP_Scheduler' ) ) {
			FnOSP_Scheduler::reschedule_digest();
		}

		add_settings_error( 'fnosp', 'fnosp_saved', __( 'Settings saved.', 'fno-signal-pro' ), 'updated' );
		set_transient( 'fnosp_admin_notice', 'saved', 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=fnosp-settings&updated=1' ) );
		exit;
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'fnosp' ) ) {
			return;
		}

		wp_enqueue_style(
			'fnosp-admin',
			FNOSP_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			FNOSP_VERSION
		);

		// Live chart library (TradingView free embed). No API key required.
		wp_enqueue_script( 'fnosp-tradingview', 'https://s3.tradingview.com/tv.js', array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion

		wp_enqueue_script(
			'fnosp-admin',
			FNOSP_PLUGIN_URL . 'assets/js/admin.js',
			array(), // NO dependency on tradingview — chart is optional
			FNOSP_VERSION,
			true
		);

		wp_localize_script(
			'fnosp-admin',
			'FNOSP_ADMIN',
			array(
				'restUrl'  => esc_url_raw( rest_url( 'fnosp/v1/signal' ) ),
				'scanUrl'  => esc_url_raw( rest_url( 'fnosp/v1/scan' ) ),
				'tgTestUrl' => esc_url_raw( rest_url( 'fnosp/v1/telegram-test' ) ),
				'emailTestUrl' => esc_url_raw( rest_url( 'fnosp/v1/email-test' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'aiReady'  => $this->settings->ai_ready(),
				'chartTheme' => $this->settings->get( 'chart_theme', 'light' ),
				'showChart'  => (int) $this->settings->get( 'show_chart', 1 ),
				'i18n'     => array(
					'loading' => __( 'Analyzing market data...', 'fno-signal-pro' ),
					'error'   => __( 'Failed to generate signal.', 'fno-signal-pro' ),
					'tgSending' => __( 'Sending...', 'fno-signal-pro' ),
					'tgSent'    => __( 'Test message sent — check Telegram.', 'fno-signal-pro' ),
					'tgError'   => __( 'Test failed.', 'fno-signal-pro' ),
				),
			)
		);
	}

	public function render_dashboard() {
		$settings = $this->settings;
		require FNOSP_PLUGIN_DIR . 'admin/views/dashboard.php';
	}

	public function render_settings() {
		$settings = $this->settings;
		$values   = $this->settings->all();
		require FNOSP_PLUGIN_DIR . 'admin/views/settings.php';
	}
}
