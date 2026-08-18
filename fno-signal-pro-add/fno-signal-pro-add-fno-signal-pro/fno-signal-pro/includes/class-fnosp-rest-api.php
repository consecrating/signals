<?php
/**
 * REST API endpoints.
 *
 * Routes (namespace: fnosp/v1):
 *   GET  /signal?instrument=NIFTY&ai=1   -> generate a signal (cached)
 *   GET  /instruments                    -> configured instrument list
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Rest_Api {

	const NS = 'fnosp/v1';

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct() {
		$this->settings = new FnOSP_Settings();
	}

	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NS,
			'/signal',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_signal' ),
				'permission_callback' => '__return_true', // Always allow (admin check not needed for signal reads).
				'args'                => array(
					'instrument' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'ai'         => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
					'nocache'    => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
					'strike'     => array(
						'type'     => 'number',
						'required' => false,
					),
					'opt_type'   => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'dte'        => array(
						'type'     => 'integer',
						'required' => false,
					),
					'expiry'     => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'premium'    => array(
						'type'     => 'number',
						'required' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/instruments',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_instruments' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/scan',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'scan' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'nocache' => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => function () {
					return rest_ensure_response( array(
						'ok'       => true,
						'plugin'   => 'fno-signal-pro',
						'version'  => FNOSP_VERSION,
						'provider' => ( new FnOSP_Settings() )->get( 'data_provider', 'demo' ),
						'php'      => PHP_VERSION,
						'time'     => gmdate( 'c' ),
					) );
				},
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/telegram-test',			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'telegram_test' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/email-test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'email_test' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * POST /email-test — send a test email.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function email_test() {
		$email = new FnOSP_Email( $this->settings );
		if ( ! $email->ready() ) {
			return new WP_Error( 'fnosp_email_not_ready', __( 'Enable email alerts (recipients default to the site admin email), then save settings first.', 'fno-signal-pro' ), array( 'status' => 400 ) );
		}
		$res = $email->test();
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				'sent'    => $res['sent'],
				'message' => __( 'Test email sent. Check your inbox (and spam folder).', 'fno-signal-pro' ),
			)
		);
	}

	/**
	 * GET /scan — rank the configured stock universe and return today's top BUY/SELL picks.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan( WP_REST_Request $req ) {
		$nocache = (bool) $req->get_param( 'nocache' );
		$scanner = new FnOSP_Scanner( $this->settings );
		$result  = $scanner->run( $nocache );
		return rest_ensure_response( $result );
	}

	/**
	 * POST /telegram-test — send a test Telegram message.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function telegram_test() {
		$telegram = new FnOSP_Telegram( $this->settings );
		if ( ! $telegram->ready() ) {
			return new WP_Error( 'fnosp_tg_not_ready', __( 'Enable Telegram alerts and set both the bot token and chat id, then save settings first.', 'fno-signal-pro' ), array( 'status' => 400 ) );
		}
		$res = $telegram->test();
		if ( is_wp_error( $res ) ) {
			return new WP_Error( $res->get_error_code(), $res->get_error_message(), array( 'status' => 502 ) );
		}
		return rest_ensure_response(
			array(
				'ok'      => true,
				'sent'    => $res['sent'],
				'failed'  => $res['failed'],
				'message' => __( 'Test message sent. Check your Telegram chat.', 'fno-signal-pro' ),
			)
		);
	}

	/**
	 * Permission gate. Public access optional (admin toggle), otherwise requires login.
	 *
	 * @return bool
	 */
	public function permission() {
		if ( (int) $this->settings->get( 'allow_public_rest', 0 ) === 1 ) {
			return true;
		}
		return is_user_logged_in();
	}

	/**
	 * GET /signal handler.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_signal( WP_REST_Request $req ) {
		$instrument = $req->get_param( 'instrument' );
		if ( empty( $instrument ) ) {
			$instrument = $this->settings->get( 'default_instrument', 'NIFTY' );
		}
		$instrument = strtoupper( $instrument );

		// Validate against configured instruments unless it's a custom symbol allowed.
		$allowed = (array) $this->settings->get( 'instruments', array() );
		$use_ai  = (bool) $req->get_param( 'ai' ) && $this->settings->ai_ready();
		$nocache = (bool) $req->get_param( 'nocache' );

		// Optional per-strike option plan.
		$strike   = $req->get_param( 'strike' );
		$opt_type = $req->get_param( 'opt_type' );
		$dte      = $req->get_param( 'dte' );
		$expiry   = $req->get_param( 'expiry' );
		$premium  = $req->get_param( 'premium' );
		$has_opt  = ! empty( $strike ) && (float) $strike > 0;

		// If an expiry date (YYYY-MM-DD) is given, derive days-to-expiry from it.
		if ( ! empty( $expiry ) ) {
			$exp_ts = strtotime( $expiry );
			if ( false !== $exp_ts ) {
				$days = (int) ceil( ( $exp_ts - time() ) / DAY_IN_SECONDS );
				$dte  = max( 1, $days );
			}
		}

		$opt_suffix = $has_opt ? ( '|' . (float) $strike . ( $opt_type ? strtoupper( $opt_type ) : 'CE' ) . '|' . (int) $dte . '|' . (float) $premium ) : '';
		$cache_key = FnOSP_Cache::key( $instrument . $opt_suffix, $use_ai );
		$ttl       = (int) $this->settings->get( 'cache_ttl', 60 );

		if ( ! $nocache && $ttl > 0 ) {
			$cached = FnOSP_Cache::get( $cache_key );
			if ( false !== $cached ) {
				$cached['cached'] = true;
				return rest_ensure_response( $cached );
			}
		}

		$engine   = FnOSP_Plugin::make_engine();

		// Extend time limit for the data fetch.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 60 ); // phpcs:ignore
		}

		$gen_opts = array( 'use_ai' => $use_ai );
		if ( $has_opt ) {
			$gen_opts['strike']   = (float) $strike;
			$gen_opts['opt_type'] = $opt_type ? strtoupper( $opt_type ) : 'CE';
			$gen_opts['dte']      = $dte ? max( 1, (int) $dte ) : 7;
			if ( ! empty( $premium ) && (float) $premium > 0 ) {
				$gen_opts['premium'] = (float) $premium;
			}
		}
		$result = $engine->generate( $instrument, $gen_opts );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$result['cached']         = false;
		$result['allowed_symbol'] = in_array( $instrument, array_map( 'strtoupper', $allowed ), true );

		if ( $ttl > 0 ) {
			FnOSP_Cache::set( $cache_key, $result, $ttl );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * GET /instruments handler.
	 *
	 * @return WP_REST_Response
	 */
	public function get_instruments() {
		return rest_ensure_response(
			array(
				'default'     => $this->settings->get( 'default_instrument', 'NIFTY' ),
				'instruments' => array_values( (array) $this->settings->get( 'instruments', array() ) ),
				'ai_ready'    => $this->settings->ai_ready(),
			)
		);
	}
}
