<?php
/**
 * Settings / options manager.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Settings {

	/** @var array Cached settings. */
	private $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// Market data provider.
			'data_provider'      => 'free', // free (live only) | custom_rest.
			'data_api_url'       => '',
			'data_api_key'       => '',
			'nse_proxy'          => '',

			// AI analyst (Anthropic Claude).
			'ai_enabled'         => 0,
			'ai_provider'        => 'anthropic',
			'ai_api_key'         => '',
			'ai_model'           => 'claude-3-5-sonnet-20241022',
			'ai_max_tokens'      => 1200,
			'ai_endpoint'        => 'https://api.anthropic.com/v1/messages',
			'ai_version'         => '2023-06-01',

			// Engine behaviour.
			'confidence_min'     => 75,   // Only emit a trade above this %.
			'cache_ttl'          => 60,   // Seconds. Keeps response fast.
			'default_instrument' => 'NIFTY',
			'instruments'        => array( 'NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY', 'INFY', 'ACC', 'SUZLON' ),

			// Scanner ("Today's Top Picks") — universe of stocks to rank.
			'scan_universe'      => array( 'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN', 'AXISBANK', 'ITC', 'LT', 'TATAMOTORS', 'ACC', 'SUZLON', 'WIPRO', 'MARUTI', 'BAJFINANCE', 'HINDUNILVR', 'KOTAKBANK', 'SUNPHARMA' ),
			'scan_min_confidence' => 68,
			'scan_include_indices' => 1,

			// Daily "Top Picks" digest (Telegram + Email).
			'digest_enabled'     => 0,
			'digest_time'        => '09:00', // IST HH:MM.

			// Risk filters (Step 9).
			'rsi_buy_block'      => 85,
			'rsi_sell_block'     => 15,
			'vwap_dev_block'     => 4.0, // Percent deviation from VWAP that blocks a trade.

			// Frontend.
			'show_disclaimer'    => 1,
			'allow_public_rest'  => 0, // If 1, REST endpoint is public (rate-limited).
			'show_chart'         => 1, // Live TradingView chart on the dashboard.
			'chart_theme'        => 'light',

			// Telegram alerts.
			'telegram_enabled'         => 0,
			'telegram_token'           => '',
			'telegram_chat_id'         => '',
			'alert_min_confidence'     => 80,
			'alert_directions'         => array( 'BUY', 'SELL' ),
			'alert_instruments'        => array(),
			'alert_cooldown'           => 60,  // Minutes between repeat alerts (same direction).
			'alert_market_hours_only'  => 1,
			'alert_use_ai'             => 0,
			'alert_include_option'     => 1,  // Include an ATM option plan in alerts.
			'alert_option_dte'         => 7,

			// Email alerts.
			'email_enabled'            => 0,
			'email_recipients'         => '',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$saved       = get_option( FNOSP_OPTION_KEY, array() );
			$this->cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::default_settings() );
		}
		return $this->cache;
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default if not present.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Persist settings (sanitized).
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized settings that were saved.
	 */
	public function save( array $input ) {
		$clean = $this->sanitize( $input );
		update_option( FNOSP_OPTION_KEY, $clean );
		$this->cache = $clean;
		return $clean;
	}

	/**
	 * Sanitize a settings payload.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize( array $input ) {
		$defaults = self::default_settings();
		$out      = $this->all();

		$out['data_provider'] = in_array( ( $input['data_provider'] ?? '' ), array( 'demo', 'free', 'custom_rest' ), true )
			? $input['data_provider'] : $defaults['data_provider'];
		$out['data_api_url']  = esc_url_raw( $input['data_api_url'] ?? '' );
		$out['data_api_key']  = sanitize_text_field( $input['data_api_key'] ?? '' );
		$out['free_fallback_demo'] = empty( $input['free_fallback_demo'] ) ? 0 : 1;
		$out['nse_proxy']          = esc_url_raw( $input['nse_proxy'] ?? '' );

		$out['ai_enabled']  = empty( $input['ai_enabled'] ) ? 0 : 1;
		$out['ai_provider'] = sanitize_text_field( $input['ai_provider'] ?? 'anthropic' );
		$out['ai_api_key']  = sanitize_text_field( $input['ai_api_key'] ?? '' );
		$out['ai_model']    = sanitize_text_field( $input['ai_model'] ?? $defaults['ai_model'] );
		$out['ai_max_tokens'] = max( 256, min( 8192, absint( $input['ai_max_tokens'] ?? $defaults['ai_max_tokens'] ) ) );
		$out['ai_endpoint'] = esc_url_raw( $input['ai_endpoint'] ?? $defaults['ai_endpoint'] );
		$out['ai_version']  = sanitize_text_field( $input['ai_version'] ?? $defaults['ai_version'] );

		$out['confidence_min'] = max( 50, min( 99, absint( $input['confidence_min'] ?? $defaults['confidence_min'] ) ) );
		$out['cache_ttl']      = max( 0, min( 3600, absint( $input['cache_ttl'] ?? $defaults['cache_ttl'] ) ) );
		$out['default_instrument'] = sanitize_text_field( $input['default_instrument'] ?? $defaults['default_instrument'] );

		if ( ! empty( $input['instruments'] ) ) {
			$list = is_array( $input['instruments'] )
				? $input['instruments']
				: array_map( 'trim', explode( ',', (string) $input['instruments'] ) );
			$out['instruments'] = array_values( array_filter( array_map( 'sanitize_text_field', $list ) ) );
		}

		if ( isset( $input['scan_universe'] ) ) {
			$list = is_array( $input['scan_universe'] )
				? $input['scan_universe']
				: array_map( 'trim', explode( ',', (string) $input['scan_universe'] ) );
			$out['scan_universe'] = array_values( array_filter( array_map( function ( $v ) {
				return strtoupper( sanitize_text_field( $v ) );
			}, $list ) ) );
		}
		$out['scan_min_confidence'] = max( 50, min( 95, absint( $input['scan_min_confidence'] ?? 68 ) ) );
		$out['scan_include_indices'] = empty( $input['scan_include_indices'] ) ? 0 : 1;

		$out['digest_enabled'] = empty( $input['digest_enabled'] ) ? 0 : 1;
		$dt = isset( $input['digest_time'] ) ? trim( (string) $input['digest_time'] ) : '09:00';
		$out['digest_time'] = preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $dt ) ? $dt : '09:00';

		$out['rsi_buy_block']  = max( 50, min( 100, absint( $input['rsi_buy_block'] ?? $defaults['rsi_buy_block'] ) ) );
		$out['rsi_sell_block'] = max( 0, min( 50, absint( $input['rsi_sell_block'] ?? $defaults['rsi_sell_block'] ) ) );
		$out['vwap_dev_block'] = max( 0.5, min( 20, floatval( $input['vwap_dev_block'] ?? $defaults['vwap_dev_block'] ) ) );

		$out['show_disclaimer']   = empty( $input['show_disclaimer'] ) ? 0 : 1;
		$out['allow_public_rest'] = empty( $input['allow_public_rest'] ) ? 0 : 1;
		$out['show_chart']        = empty( $input['show_chart'] ) ? 0 : 1;
		$out['chart_theme']       = ( 'dark' === ( $input['chart_theme'] ?? '' ) ) ? 'dark' : 'light';

		// Telegram alerts.
		$out['telegram_enabled']  = empty( $input['telegram_enabled'] ) ? 0 : 1;
		$out['telegram_token']    = sanitize_text_field( $input['telegram_token'] ?? '' );
		$out['telegram_chat_id']  = sanitize_text_field( $input['telegram_chat_id'] ?? '' );
		$out['alert_min_confidence'] = max( 50, min( 99, absint( $input['alert_min_confidence'] ?? 80 ) ) );

		$dirs = array();
		if ( ! empty( $input['alert_directions'] ) && is_array( $input['alert_directions'] ) ) {
			foreach ( $input['alert_directions'] as $d ) {
				$d = strtoupper( sanitize_text_field( $d ) );
				if ( in_array( $d, array( 'BUY', 'SELL' ), true ) ) {
					$dirs[] = $d;
				}
			}
		}
		$out['alert_directions'] = ! empty( $dirs ) ? array_values( array_unique( $dirs ) ) : array( 'BUY', 'SELL' );

		if ( isset( $input['alert_instruments'] ) ) {
			$list = is_array( $input['alert_instruments'] )
				? $input['alert_instruments']
				: array_map( 'trim', explode( ',', (string) $input['alert_instruments'] ) );
			$out['alert_instruments'] = array_values( array_filter( array_map( 'sanitize_text_field', $list ) ) );
		}

		$out['alert_cooldown']          = max( 1, min( 1440, absint( $input['alert_cooldown'] ?? 60 ) ) );
		$out['alert_market_hours_only'] = empty( $input['alert_market_hours_only'] ) ? 0 : 1;
		$out['alert_use_ai']            = empty( $input['alert_use_ai'] ) ? 0 : 1;
		$out['alert_include_option']    = empty( $input['alert_include_option'] ) ? 0 : 1;
		$out['alert_option_dte']        = max( 1, min( 60, absint( $input['alert_option_dte'] ?? 7 ) ) );

		// Email alerts.
		$out['email_enabled']    = empty( $input['email_enabled'] ) ? 0 : 1;
		$out['email_recipients'] = sanitize_text_field( $input['email_recipients'] ?? '' );

		return $out;
	}

	/**
	 * Whether the AI analyst is usable (enabled + key present).
	 *
	 * @return bool
	 */
	public function ai_ready() {
		return (bool) $this->get( 'ai_enabled' ) && '' !== trim( (string) $this->get( 'ai_api_key' ) );
	}
}
