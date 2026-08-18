<?php
/**
 * Market data provider.
 *
 * Responsible for producing a normalized "market snapshot" array that the
 * signal engine consumes. Supports two modes:
 *   - demo:        Deterministic-ish synthetic data (no external calls). Lets the
 *                  plugin work out of the box for testing/preview.
 *   - custom_rest: Fetches from a user-configured REST endpoint that returns the
 *                  expected JSON schema (documented in readme.txt).
 *
 * The normalized snapshot schema is the single contract used everywhere.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Data_Provider {

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get a normalized market snapshot for an instrument.
	 *
	 * @param string $instrument Instrument symbol.
	 * @return array|WP_Error
	 */
	public function get_snapshot( $instrument ) {
		$instrument = strtoupper( sanitize_text_field( $instrument ) );
		$provider   = $this->settings->get( 'data_provider' );

		if ( 'custom_rest' === $provider ) {
			$remote = $this->fetch_remote( $instrument );
			if ( is_wp_error( $remote ) ) {
				return $remote;
			}
			return $this->normalize( $remote, $instrument );
		}

		// Free live data (default). No fallback to demo — show error if unavailable.
		$free     = new FnOSP_Free_Data( $this->settings );
		$snapshot = $free->get_snapshot( $instrument );
		if ( is_wp_error( $snapshot ) ) {
			return new WP_Error( 'fnosp_no_data', sprintf(
				/* translators: %s: instrument */
				__( 'Could not fetch live data for %s. Market may be closed, or your host cannot reach the data source. Try again during market hours (9:15 AM – 3:30 PM IST).', 'fno-signal-pro' ),
				$instrument
			) );
		}
		return $snapshot;
	}

	/**
	 * Fetch raw data from the configured REST endpoint.
	 *
	 * @param string $instrument Instrument.
	 * @return array|WP_Error
	 */
	private function fetch_remote( $instrument ) {
		$url = $this->settings->get( 'data_api_url' );
		if ( empty( $url ) ) {
			return new WP_Error( 'fnosp_no_url', __( 'No market data API URL configured.', 'fno-signal-pro' ) );
		}

		$request_url = add_query_arg(
			array( 'instrument' => rawurlencode( $instrument ) ),
			$url
		);

		$args = array(
			'timeout' => 12,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);
		$key = $this->settings->get( 'data_api_key' );
		if ( ! empty( $key ) ) {
			$args['headers']['Authorization'] = 'Bearer ' . $key;
			$args['headers']['X-API-Key']     = $key;
		}

		$response = wp_remote_get( $request_url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'fnosp_http', sprintf( /* translators: %d: HTTP status */ __( 'Data API returned HTTP %d.', 'fno-signal-pro' ), $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'fnosp_bad_json', __( 'Data API returned invalid JSON.', 'fno-signal-pro' ) );
		}

		return $body;
	}

	/**
	 * Normalize raw provider data into the canonical snapshot schema.
	 * Missing fields are filled defensively so the engine never crashes.
	 *
	 * @param array  $raw        Raw payload.
	 * @param string $instrument Instrument.
	 * @return array
	 */
	public function normalize( array $raw, $instrument ) {
		$f = function ( $key, $default = 0 ) use ( $raw ) {
			return isset( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ? (float) $raw[ $key ] : $default;
		};

		$ltp = $f( 'ltp', $f( 'close', 0 ) );

		$snapshot = array(
			'instrument'   => $instrument,
			'timestamp'    => time(),
			'source'       => 'custom_rest',
			'ltp'          => $ltp,
			'ohlc'         => array(
				'open'  => $f( 'open', $ltp ),
				'high'  => $f( 'high', $ltp ),
				'low'   => $f( 'low', $ltp ),
				'close' => $f( 'close', $ltp ),
			),
			'prev_ohlc'    => array(
				'open'  => isset( $raw['prev_open'] ) ? (float) $raw['prev_open'] : $ltp,
				'high'  => isset( $raw['prev_high'] ) ? (float) $raw['prev_high'] : $ltp,
				'low'   => isset( $raw['prev_low'] ) ? (float) $raw['prev_low'] : $ltp,
				'close' => isset( $raw['prev_close'] ) ? (float) $raw['prev_close'] : $ltp,
			),
			'volume'       => $f( 'volume', 0 ),
			'avg_volume'   => $f( 'avg_volume', $f( 'volume', 0 ) ),
			'vwap'         => $f( 'vwap', $ltp ),
			'rsi'          => $f( 'rsi', 50 ),
			'rsi_prev'     => isset( $raw['rsi_prev'] ) ? (float) $raw['rsi_prev'] : $f( 'rsi', 50 ),
			'macd'         => $f( 'macd', 0 ),
			'macd_signal'  => $f( 'macd_signal', 0 ),
			'macd_hist'    => isset( $raw['macd_hist'] ) ? (float) $raw['macd_hist'] : ( $f( 'macd', 0 ) - $f( 'macd_signal', 0 ) ),
			'supertrend'   => isset( $raw['supertrend'] ) ? strtolower( sanitize_text_field( $raw['supertrend'] ) ) : 'neutral',
			'ema9'         => $f( 'ema9', $ltp ),
			'ema21'        => $f( 'ema21', $ltp ),
			'ema50'        => $f( 'ema50', $ltp ),
			'ema200'       => $f( 'ema200', $ltp ),
			'atr'          => $f( 'atr', max( 1, $ltp * 0.006 ) ),
			'bb_upper'     => $f( 'bb_upper', $ltp * 1.01 ),
			'bb_mid'       => $f( 'bb_mid', $ltp ),
			'bb_lower'     => $f( 'bb_lower', $ltp * 0.99 ),
			'pcr'          => $f( 'pcr', 1.0 ),
			'max_pain'     => $f( 'max_pain', $ltp ),
			'iv'           => $f( 'iv', 14 ),
			'vix'          => $f( 'vix', 13 ),
			'call_oi_chg'  => $f( 'call_oi_chg', 0 ),
			'put_oi_chg'   => $f( 'put_oi_chg', 0 ),
			'fii_net'      => $f( 'fii_net', 0 ),
			'dii_net'      => $f( 'dii_net', 0 ),
			'adv_decline'  => $f( 'adv_decline', 1.0 ), // advancers / decliners ratio.
			'sector_strength' => $f( 'sector_strength', 0 ), // -100..100.
			'news_sentiment'  => $f( 'news_sentiment', 0 ),  // -1..1.
			'lot_size'     => isset( $raw['lot_size'] ) ? (int) $raw['lot_size'] : self::default_lot_size( $instrument ),
		);

		return $snapshot;
	}

	/**
	 * Generate a plausible synthetic snapshot for demo/preview.
	 * Uses the day-of-year + instrument as a seed so values are stable for a day.
	 *
	 * @param string $instrument Instrument.
	 * @return array
	 */
	public function demo_snapshot( $instrument ) {
		$base = self::demo_base_price( $instrument );

		// Deterministic pseudo-random within the day for stable previews.
		$seed = (int) gmdate( 'z' ) + crc32( $instrument );
		mt_srand( $seed );
		$rf = function ( $min, $max ) {
			return $min + ( mt_rand( 0, 10000 ) / 10000 ) * ( $max - $min );
		};

		$drift  = $rf( -0.012, 0.018 ); // Slight bullish bias for demo.
		$ltp    = round( $base * ( 1 + $drift ), 2 );
		$atr    = round( $base * $rf( 0.004, 0.009 ), 2 );
		$open   = round( $ltp - $rf( -1, 1 ) * $atr, 2 );
		$high   = round( max( $open, $ltp ) + $rf( 0.1, 0.8 ) * $atr, 2 );
		$low    = round( min( $open, $ltp ) - $rf( 0.1, 0.8 ) * $atr, 2 );
		$vwap   = round( ( $open + $high + $low + $ltp ) / 4, 2 );
		$rsi    = round( $rf( 38, 72 ), 1 );
		$macd   = round( $rf( -8, 14 ), 2 );
		$sig    = round( $macd - $rf( -4, 6 ), 2 );

		$ema9   = round( $ltp - $rf( -0.4, 0.6 ) * $atr, 2 );
		$ema21  = round( $ema9 - $rf( -0.2, 0.7 ) * $atr, 2 );
		$ema50  = round( $ema21 - $rf( -0.1, 0.9 ) * $atr, 2 );
		$ema200 = round( $base * ( 1 - $rf( 0.005, 0.03 ) ), 2 );

		// Build the full snapshot while still seeded so every field is
		// deterministic for the day; THEN restore global randomness.
		$snapshot = array(
			'instrument'   => $instrument,
			'timestamp'    => time(),
			'source'       => 'demo',
			'ltp'          => $ltp,
			'ohlc'         => array(
				'open'  => $open,
				'high'  => $high,
				'low'   => $low,
				'close' => $ltp,
			),
			'prev_ohlc'    => array(
				'open'  => round( $open * 0.998, 2 ),
				'high'  => round( $high * 0.997, 2 ),
				'low'   => round( $low * 0.996, 2 ),
				'close' => round( $open * 0.999, 2 ),
			),
			'volume'       => (int) round( $rf( 0.9, 1.8 ) * 1000000 ),
			'avg_volume'   => 1000000,
			'vwap'         => $vwap,
			'rsi'          => $rsi,
			'rsi_prev'     => round( $rsi - $rf( -6, 6 ), 1 ),
			'macd'         => $macd,
			'macd_signal'  => $sig,
			'macd_hist'    => round( $macd - $sig, 2 ),
			'supertrend'   => ( $ltp > $ema21 ) ? 'bullish' : 'bearish',
			'ema9'         => $ema9,
			'ema21'        => $ema21,
			'ema50'        => $ema50,
			'ema200'       => $ema200,
			'atr'          => $atr,
			'bb_upper'     => round( $vwap + 2 * $atr, 2 ),
			'bb_mid'       => $vwap,
			'bb_lower'     => round( $vwap - 2 * $atr, 2 ),
			'pcr'          => round( $rf( 0.7, 1.4 ), 2 ),
			'max_pain'     => round( $ltp * ( 1 + $rf( -0.01, 0.01 ) ), 0 ),
			'iv'           => round( $rf( 11, 19 ), 1 ),
			'vix'          => round( $rf( 11, 17 ), 1 ),
			'call_oi_chg'  => (int) round( $rf( -150000, 150000 ) ),
			'put_oi_chg'   => (int) round( $rf( -150000, 180000 ) ),
			'fii_net'      => round( $rf( -1500, 2200 ), 1 ),
			'dii_net'      => round( $rf( -1200, 1800 ), 1 ),
			'adv_decline'  => round( $rf( 0.7, 1.8 ), 2 ),
			'sector_strength' => round( $rf( -40, 60 ) ),
			'news_sentiment'  => round( $rf( -0.4, 0.6 ), 2 ),
			'lot_size'     => self::default_lot_size( $instrument ),
		);

		mt_srand(); // Restore global randomness for the rest of the request.

		return $snapshot;
	}

	/**
	 * Indicative demo base prices.
	 *
	 * @param string $instrument Instrument.
	 * @return float
	 */
	private static function demo_base_price( $instrument ) {
		$map = array(
			'NIFTY'     => 24100,
			'BANKNIFTY' => 57900,
			'FINNIFTY'  => 23200,
			'SENSEX'    => 77500,
			'MIDCPNIFTY'=> 12200,
		);
		return isset( $map[ $instrument ] ) ? (float) $map[ $instrument ] : 1000.0;
	}

	/**
	 * Indicative lot sizes (user should override via custom provider).
	 *
	 * @param string $instrument Instrument.
	 * @return int
	 */
	private static function default_lot_size( $instrument ) {
		$map = array(
			'NIFTY'      => 25,
			'BANKNIFTY'  => 15,
			'FINNIFTY'   => 25,
			'SENSEX'     => 10,
			'MIDCPNIFTY' => 50,
		);
		return isset( $map[ $instrument ] ) ? $map[ $instrument ] : 1;
	}
}
