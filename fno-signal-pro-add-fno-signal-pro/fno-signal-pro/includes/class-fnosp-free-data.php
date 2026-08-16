<?php
/**
 * Free, no-API-key live data adapter.
 *
 * Fetches real OHLCV candles from a public chart endpoint (no key required),
 * computes the full technical indicator set in PHP, and makes a best-effort
 * attempt at NSE option-chain analytics (PCR / OI change / max pain). When any
 * source is unavailable the corresponding fields fall back to neutral defaults
 * so the engine still produces a (more conservative) signal.
 *
 * Data sources used (public, keyless):
 *   - Yahoo Finance chart API for OHLCV candles.
 *   - NSE option-chain JSON (best-effort; often blocked from servers).
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Free_Data {

	/** @var FnOSP_Settings */
	private $settings;

	/** @var string|null Cached NSE cookie for this request. */
	private $nse_cookie = null;

	/** @var bool Once NSE is detected unreachable, skip further NSE calls this request. */
	private $nse_blocked = false;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Map our instrument symbols to Yahoo Finance symbols.
	 *
	 * @return array
	 */
	public static function symbol_map() {
		return array(
			'NIFTY'      => '^NSEI',
			'NIFTY50'    => '^NSEI',
			'BANKNIFTY'  => '^NSEBANK',
			'FINNIFTY'   => 'NIFTY_FIN_SERVICE.NS',
			'SENSEX'     => '^BSESN',
			'MIDCPNIFTY' => '^NSEMDCP50',
			// Common stock aliases -> Yahoo NSE symbols.
			'INFY'         => 'INFY.NS',
			'INFOSYS'      => 'INFY.NS',
			'ACC'          => 'ACC.NS',
			'SUZLON'       => 'SUZLON.NS',
			'SUZLONENERGY' => 'SUZLON.NS',
		);
	}

	/**
	 * NSE option-chain index symbols (for PCR / OI / max pain).
	 *
	 * @return array
	 */
	private static function nse_oc_map() {
		return array(
			'NIFTY'      => array( 'type' => 'indices', 'symbol' => 'NIFTY' ),
			'BANKNIFTY'  => array( 'type' => 'indices', 'symbol' => 'BANKNIFTY' ),
			'FINNIFTY'   => array( 'type' => 'indices', 'symbol' => 'FINNIFTY' ),
			'MIDCPNIFTY' => array( 'type' => 'indices', 'symbol' => 'MIDCPNIFTY' ),
		);
	}

	/**
	 * Resolve a Yahoo symbol for any instrument (indices mapped, else assume NSE equity).
	 *
	 * @param string $instrument Instrument.
	 * @return string
	 */
	public function resolve_symbol( $instrument ) {
		$instrument = strtoupper( $instrument );
		$map        = self::symbol_map();
		if ( isset( $map[ $instrument ] ) ) {
			return $map[ $instrument ];
		}
		// Treat unknown symbols as NSE-listed equities (e.g. RELIANCE -> RELIANCE.NS).
		if ( false === strpos( $instrument, '.' ) ) {
			return $instrument . '.NS';
		}
		return $instrument;
	}

	/**
	 * Build a normalized snapshot from free sources.
	 *
	 * @param string $instrument Instrument.
	 * @return array|WP_Error
	 */
	public function get_snapshot( $instrument, $opts = array() ) {
		$instrument = strtoupper( sanitize_text_field( $instrument ) );
		$symbol     = $this->resolve_symbol( $instrument );
		$lite       = ! empty( $opts['lite'] );

		$intraday = $this->fetch_chart( $symbol, '15m', '1mo' );
		if ( is_wp_error( $intraday ) ) {
			return $intraday;
		}
		if ( empty( $intraday['close'] ) || count( $intraday['close'] ) < 30 ) {
			return new WP_Error( 'fnosp_free_thin', __( 'Free data source returned too few candles to analyze.', 'fno-signal-pro' ) );
		}

		// Daily candles for previous-day OHLC. Skipped in lite (scanner) mode to halve HTTP calls.
		$daily = $lite ? array() : $this->fetch_chart( $symbol, '1d', '1mo' );

		return $this->build_snapshot( $instrument, $intraday, $daily, is_array( $opts ) ? $opts : array() );
	}

	/**
	 * Fast snapshot for scanning: only price/indicators are fetched per symbol;
	 * option chain & news are neutral; macro (VIX/FII-DII/breadth) is injected
	 * from a shared fetch so we don't hammer NSE once per symbol.
	 *
	 * @param string $instrument Instrument.
	 * @param array  $macro      Shared macro values (from get_macro()).
	 * @return array|WP_Error
	 */
	public function get_snapshot_lite( $instrument, $macro = array() ) {
		return $this->get_snapshot( $instrument, array( 'lite' => true, 'macro' => $macro ) );
	}

	/**
	 * Fetch shared market-wide macro values once (for the scanner).
	 *
	 * @return array
	 */
	public function get_macro() {
		$vix     = $this->fetch_india_vix();
		$flows   = $this->fetch_fii_dii();
		$breadth = $this->fetch_market_breadth( 'NIFTY' );
		return array(
			'vix'             => $vix['ok'] ? $vix['value'] : 13.0,
			'fii_net'         => $flows['ok'] ? $flows['fii_net'] : 0.0,
			'dii_net'         => $flows['ok'] ? $flows['dii_net'] : 0.0,
			'adv_decline'     => $breadth['ok'] ? $breadth['adv_decline'] : 1.0,
			'sector_strength' => $breadth['ok'] ? $breadth['sector_strength'] : 0,
		);
	}

	/**
	 * Fetch candles from the Yahoo chart API.
	 *
	 * @param string $symbol   Yahoo symbol.
	 * @param string $interval Interval (e.g. 15m, 1d).
	 * @param string $range    Range (e.g. 1mo, 1y).
	 * @return array|WP_Error  { ts[], open[], high[], low[], close[], volume[], meta{} }
	 */
	private function fetch_chart( $symbol, $interval, $range ) {
		$base = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode( $symbol );
		$url  = add_query_arg(
			array(
				'interval'       => $interval,
				'range'          => $range,
				'includePrePost' => 'false',
			),
			$base
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'Mozilla/5.0 (compatible; FnOSignalPro/1.0; +https://wordpress.org)',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'fnosp_free_http', sprintf( /* translators: %d: status */ __( 'Free data source returned HTTP %d. Try again shortly or use a custom feed.', 'fno-signal-pro' ), $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['chart']['result'][0] ) ) {
			$msg = isset( $body['chart']['error']['description'] ) ? $body['chart']['error']['description'] : __( 'Unexpected response from free data source.', 'fno-signal-pro' );
			return new WP_Error( 'fnosp_free_shape', $msg );
		}

		$res   = $body['chart']['result'][0];
		$quote = isset( $res['indicators']['quote'][0] ) ? $res['indicators']['quote'][0] : array();
		$ts    = isset( $res['timestamp'] ) ? $res['timestamp'] : array();

		// Filter out null candles (Yahoo includes gaps).
		$open = $high = $low = $close = $vol = $stamps = array();
		$count = count( $ts );
		for ( $i = 0; $i < $count; $i++ ) {
			$c = isset( $quote['close'][ $i ] ) ? $quote['close'][ $i ] : null;
			$o = isset( $quote['open'][ $i ] ) ? $quote['open'][ $i ] : null;
			$h = isset( $quote['high'][ $i ] ) ? $quote['high'][ $i ] : null;
			$l = isset( $quote['low'][ $i ] ) ? $quote['low'][ $i ] : null;
			if ( null === $c || null === $o || null === $h || null === $l ) {
				continue;
			}
			$stamps[] = (int) $ts[ $i ];
			$open[]   = (float) $o;
			$high[]   = (float) $h;
			$low[]    = (float) $l;
			$close[]  = (float) $c;
			$vol[]    = isset( $quote['volume'][ $i ] ) ? (float) $quote['volume'][ $i ] : 0.0;
		}

		return array(
			'ts'     => $stamps,
			'open'   => $open,
			'high'   => $high,
			'low'    => $low,
			'close'  => $close,
			'volume' => $vol,
			'meta'   => isset( $res['meta'] ) ? $res['meta'] : array(),
		);
	}

	/**
	 * Compute the full normalized snapshot from candle arrays.
	 *
	 * @param string $instrument Instrument.
	 * @param array  $intra      Intraday candle bundle.
	 * @param array|WP_Error $daily Daily candle bundle.
	 * @return array
	 */
	private function build_snapshot( $instrument, $intra, $daily, $opts = array() ) {
		$lite  = ! empty( $opts['lite'] );
		$macro = isset( $opts['macro'] ) && is_array( $opts['macro'] ) ? $opts['macro'] : array();

		$closes = $intra['close'];
		$highs  = $intra['high'];
		$lows   = $intra['low'];
		$vols   = $intra['volume'];
		$ts     = $intra['ts'];

		$ltp = end( $closes );

		// Indicators.
		$ema9   = FnOSP_Indicators::ema( $closes, 9 );
		$ema21  = FnOSP_Indicators::ema( $closes, 21 );
		$ema50  = FnOSP_Indicators::ema( $closes, 50 );
		$ema200 = FnOSP_Indicators::ema( $closes, 200 );
		$rsi    = FnOSP_Indicators::rsi( $closes, 14 );
		$macd   = FnOSP_Indicators::macd( $closes );
		$atr    = FnOSP_Indicators::atr( $highs, $lows, $closes, 14 );
		$bb     = FnOSP_Indicators::bollinger( $closes, 20, 2 );
		$st     = FnOSP_Indicators::supertrend( $highs, $lows, $closes, 10, 3 );

		// Session VWAP + today's OHLC: isolate candles from the latest session day.
		$session = $this->session_slice( $ts, $highs, $lows, $closes, $vols );
		$vwap    = FnOSP_Indicators::vwap( $session['high'], $session['low'], $session['close'], $session['volume'] );
		$day_open = $session['close'] ? $session['open_price'] : reset( $closes );
		$day_high = $session['high'] ? max( $session['high'] ) : max( $highs );
		$day_low  = $session['low'] ? min( $session['low'] ) : min( $lows );
		$day_vol  = array_sum( $session['volume'] );
		$avg_vol  = $this->avg_session_volume( $ts, $vols );

		// Previous-day OHLC from daily candles.
		$prev = $this->prev_day_ohlc( $daily, $ltp );

		// Option chain: skip in lite (scanner) mode for speed.
		$oc = $lite
			? array( 'ok' => false, 'pcr' => 1.0, 'max_pain' => 0.0, 'iv' => 14.0, 'vix' => 13.0, 'call_oi_chg' => 0, 'put_oi_chg' => 0, 'reason' => 'lite mode' )
			: $this->fetch_option_chain( $instrument, $ltp );

		// Macro (VIX, FII/DII, breadth): use shared values in lite mode, else fetch.
		if ( ! empty( $macro ) ) {
			$vix     = array( 'ok' => isset( $macro['vix'] ), 'value' => isset( $macro['vix'] ) ? (float) $macro['vix'] : 13.0 );
			$flows   = array( 'ok' => isset( $macro['fii_net'] ), 'fii_net' => (float) ( $macro['fii_net'] ?? 0 ), 'dii_net' => (float) ( $macro['dii_net'] ?? 0 ) );
			$breadth = array( 'ok' => isset( $macro['adv_decline'] ), 'adv_decline' => (float) ( $macro['adv_decline'] ?? 1.0 ), 'sector_strength' => (int) ( $macro['sector_strength'] ?? 0 ), 'advances' => 0, 'declines' => 0 );
		} elseif ( $lite ) {
			$vix     = array( 'ok' => false, 'value' => 13.0 );
			$flows   = array( 'ok' => false, 'fii_net' => 0.0, 'dii_net' => 0.0 );
			$breadth = array( 'ok' => false, 'adv_decline' => 1.0, 'sector_strength' => 0, 'advances' => 0, 'declines' => 0 );
		} else {
			$vix     = $this->fetch_india_vix();
			$flows   = $this->fetch_fii_dii();
			$breadth = $this->fetch_market_breadth( $instrument );
		}

		// News sentiment: skip in lite mode.
		$news = $lite
			? array( 'ok' => false, 'score' => 0.0, 'headlines' => 0, 'reason' => 'lite mode' )
			: $this->fetch_news_sentiment( $instrument );

		return array(
			'instrument'   => $instrument,
			'timestamp'    => time(),
			'source'       => ( $lite ? 'free-lite' : 'free' ) . ( $oc['ok'] ? '+oc' : '' ),
			'ltp'          => round( $ltp, 2 ),
			'ohlc'         => array(
				'open'  => round( $day_open, 2 ),
				'high'  => round( $day_high, 2 ),
				'low'   => round( $day_low, 2 ),
				'close' => round( $ltp, 2 ),
			),
			'prev_ohlc'    => $prev,
			'volume'       => (float) $day_vol,
			'avg_volume'   => (float) max( 1, $avg_vol ),
			'vwap'         => $vwap > 0 ? $vwap : round( $ltp, 2 ),
			'rsi'          => $rsi['latest'],
			'rsi_prev'     => $rsi['prev'],
			'macd'         => $macd['macd'],
			'macd_signal'  => $macd['signal'],
			'macd_hist'    => $macd['hist'],
			'supertrend'   => $st,
			'ema9'         => round( $ema9, 2 ),
			'ema21'        => round( $ema21, 2 ),
			'ema50'        => round( $ema50, 2 ),
			'ema200'       => round( $ema200, 2 ),
			'atr'          => $atr > 0 ? $atr : round( max( 1, $ltp * 0.004 ), 2 ),
			'bb_upper'     => $bb['upper'],
			'bb_mid'       => $bb['mid'],
			'bb_lower'     => $bb['lower'],
			'pcr'          => $oc['pcr'],
			'max_pain'     => $oc['max_pain'] > 0 ? $oc['max_pain'] : round( $ltp ),
			'iv'           => $oc['iv'],
			'vix'          => $vix['ok'] ? $vix['value'] : $oc['vix'],
			'call_oi_chg'  => $oc['call_oi_chg'],
			'put_oi_chg'   => $oc['put_oi_chg'],
			// FII/DII best-effort (NSE) — neutral when unavailable.
			'fii_net'      => $flows['fii_net'],
			'dii_net'      => $flows['dii_net'],
			'adv_decline'  => $breadth['adv_decline'],
			'sector_strength' => $breadth['sector_strength'],
			'news_sentiment'  => $news['score'],
			'lot_size'     => $this->default_lot_size( $instrument ),
			'data_notes'   => $this->build_notes( $oc, $vix, $flows, $breadth, $news ),
		);
	}

	/**
	 * Isolate the most recent trading day's candles.
	 *
	 * @return array { open[], high[], low[], close[], volume[], open_price }
	 */
	private function session_slice( $ts, $highs, $lows, $closes, $vols ) {
		$n = count( $ts );
		if ( 0 === $n ) {
			return array(
				'high'       => array(),
				'low'        => array(),
				'close'      => array(),
				'volume'     => array(),
				'open_price' => 0.0,
			);
		}
		$last_day = gmdate( 'Y-m-d', $ts[ $n - 1 ] );
		$h = $l = $c = $v = array();
		$open_price = null;
		for ( $i = 0; $i < $n; $i++ ) {
			if ( gmdate( 'Y-m-d', $ts[ $i ] ) === $last_day ) {
				if ( null === $open_price ) {
					$open_price = $closes[ $i ];
				}
				$h[] = $highs[ $i ];
				$l[] = $lows[ $i ];
				$c[] = $closes[ $i ];
				$v[] = $vols[ $i ];
			}
		}
		return array(
			'high'       => $h,
			'low'        => $l,
			'close'      => $c,
			'volume'     => $v,
			'open_price' => null === $open_price ? end( $closes ) : $open_price,
		);
	}

	/**
	 * Average per-session volume across the loaded window (for confirmation ratio).
	 */
	private function avg_session_volume( $ts, $vols ) {
		$by_day = array();
		$n      = count( $ts );
		for ( $i = 0; $i < $n; $i++ ) {
			$d              = gmdate( 'Y-m-d', $ts[ $i ] );
			$by_day[ $d ]   = ( isset( $by_day[ $d ] ) ? $by_day[ $d ] : 0 ) + $vols[ $i ];
		}
		if ( empty( $by_day ) ) {
			return 0.0;
		}
		return array_sum( $by_day ) / count( $by_day );
	}

	/**
	 * Previous trading day's OHLC from daily candles.
	 */
	private function prev_day_ohlc( $daily, $ltp ) {
		if ( is_wp_error( $daily ) || empty( $daily['close'] ) ) {
			return array(
				'open'  => round( $ltp, 2 ),
				'high'  => round( $ltp, 2 ),
				'low'   => round( $ltp, 2 ),
				'close' => round( $ltp, 2 ),
			);
		}
		$cnt = count( $daily['close'] );
		// Use the second-to-last daily candle as "previous day" (last is today).
		$idx = $cnt >= 2 ? $cnt - 2 : $cnt - 1;
		return array(
			'open'  => round( $daily['open'][ $idx ], 2 ),
			'high'  => round( $daily['high'][ $idx ], 2 ),
			'low'   => round( $daily['low'][ $idx ], 2 ),
			'close' => round( $daily['close'][ $idx ], 2 ),
		);
	}

	/**
	 * Wrap an NSE target URL through the configured proxy, if any.
	 * Supports a {url} placeholder, a trailing '=' (append encoded), or plain prefix.
	 *
	 * @param string $target Target NSE URL.
	 * @return string
	 */
	private function proxied_url( $target ) {
		$proxy = trim( (string) $this->settings->get( 'nse_proxy', '' ) );
		if ( '' === $proxy ) {
			return $target;
		}
		if ( false !== strpos( $proxy, '{url}' ) ) {
			return str_replace( '{url}', rawurlencode( $target ), $proxy );
		}
		if ( '=' === substr( $proxy, -1 ) ) {
			return $proxy . rawurlencode( $target );
		}
		return rtrim( $proxy, '/' ) . '/' . ltrim( $target, '/' );
	}

	/**
	 * Whether a proxy is configured.
	 *
	 * @return bool
	 */
	private function has_proxy() {
		return '' !== trim( (string) $this->settings->get( 'nse_proxy', '' ) );
	}

	/**
	 * Fetch India VIX (keyless) via the chart API. Symbol: ^INDIAVIX.
	 *
	 * @return array { ok:bool, value:float }
	 */
	private function fetch_india_vix() {
		$chart = $this->fetch_chart( '^INDIAVIX', '1d', '5d' );
		if ( is_wp_error( $chart ) || empty( $chart['close'] ) ) {
			// Try the meta regularMarketPrice as a secondary path.
			return array(
				'ok'    => false,
				'value' => 13.0,
			);
		}
		$value = end( $chart['close'] );
		if ( $value <= 0 && ! empty( $chart['meta']['regularMarketPrice'] ) ) {
			$value = (float) $chart['meta']['regularMarketPrice'];
		}
		return array(
			'ok'    => $value > 0,
			'value' => $value > 0 ? round( $value, 2 ) : 13.0,
		);
	}

	/**
	 * Best-effort FII/DII net cash figures (Rs Cr) from NSE. Neutral on failure.
	 *
	 * @return array { ok:bool, fii_net:float, dii_net:float, reason:string }
	 */
	private function fetch_fii_dii() {
		$default = array(
			'ok'      => false,
			'fii_net' => 0.0,
			'dii_net' => 0.0,
			'reason'  => 'FII/DII source unreachable; neutral default used.',
		);
		if ( $this->nse_blocked ) {
			return $default;
		}

		$cookies = $this->prime_nse_cookies();
		$args    = array(
			'timeout' => 6,
			'headers' => array(
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
				'Referer'         => 'https://www.nseindia.com/reports/fii-dii',
			),
		);
		if ( $cookies ) {
			$args['headers']['Cookie'] = $cookies;
		}

		$url      = $this->proxied_url( 'https://www.nseindia.com/api/fiidiiTradeReact' );
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$this->nse_blocked = true;
			return $default;
		}
		$rows = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			return $default;
		}

		$fii = 0.0;
		$dii = 0.0;
		foreach ( $rows as $row ) {
			$cat = strtoupper( (string) ( $row['category'] ?? '' ) );
			$net = isset( $row['netValue'] ) ? (float) $row['netValue'] : 0.0;
			if ( false !== strpos( $cat, 'FII' ) || false !== strpos( $cat, 'FPI' ) ) {
				$fii += $net;
			} elseif ( false !== strpos( $cat, 'DII' ) ) {
				$dii += $net;
			}
		}

		return array(
			'ok'      => true,
			'fii_net' => round( $fii, 1 ),
			'dii_net' => round( $dii, 1 ),
			'reason'  => 'FII/DII loaded.',
		);
	}

	/**
	 * Best-effort market breadth + sector strength from NSE allIndices.
	 * Returns advance/decline ratio and a sector-strength proxy (-100..100).
	 *
	 * @param string $instrument Instrument.
	 * @return array { ok, adv_decline, sector_strength, advances, declines, reason }
	 */
	private function fetch_market_breadth( $instrument ) {
		$default = array(
			'ok'              => false,
			'adv_decline'     => 1.0,
			'sector_strength' => 0,
			'advances'        => 0,
			'declines'        => 0,
			'reason'          => 'Breadth source unreachable; neutral default used.',
		);
		if ( $this->nse_blocked ) {
			return $default;
		}

		$index_name = $this->nse_index_name( $instrument );

		$cookies = $this->prime_nse_cookies();
		$args    = array(
			'timeout' => 6,
			'headers' => array(
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
				'Referer'         => 'https://www.nseindia.com/',
			),
		);
		if ( $cookies ) {
			$args['headers']['Cookie'] = $cookies;
		}

		$response = wp_remote_get( $this->proxied_url( 'https://www.nseindia.com/api/allIndices' ), $args );
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$this->nse_blocked = true;
			return $default;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return $default;
		}

		foreach ( $body['data'] as $row ) {
			$name = strtoupper( trim( (string) ( $row['index'] ?? '' ) ) );
			if ( $name !== strtoupper( $index_name ) ) {
				continue;
			}
			$adv = (int) ( $row['advances'] ?? 0 );
			$dec = (int) ( $row['declines'] ?? 0 );
			$pch = isset( $row['percentChange'] ) ? (float) $row['percentChange'] : 0.0;

			$ratio  = $dec > 0 ? round( $adv / $dec, 2 ) : ( $adv > 0 ? 2.0 : 1.0 );
			$sector = (int) max( -100, min( 100, round( $pch * 25 ) ) );

			return array(
				'ok'              => true,
				'adv_decline'     => $ratio > 0 ? $ratio : 1.0,
				'sector_strength' => $sector,
				'advances'        => $adv,
				'declines'        => $dec,
				'reason'          => 'Breadth & sector strength loaded.',
			);
		}

		return $default;
	}

	/**
	 * Map instrument to its NSE index name (as used in allIndices).
	 *
	 * @param string $instrument Instrument.
	 * @return string
	 */
	private function nse_index_name( $instrument ) {
		$map = array(
			'NIFTY'      => 'NIFTY 50',
			'BANKNIFTY'  => 'NIFTY BANK',
			'FINNIFTY'   => 'NIFTY FINANCIAL SERVICES',
			'MIDCPNIFTY' => 'NIFTY MIDCAP SELECT',
			'SENSEX'     => 'NIFTY 50', // BSE index not on NSE feed; use NIFTY 50 as market proxy.
		);
		return isset( $map[ strtoupper( $instrument ) ] ) ? $map[ strtoupper( $instrument ) ] : 'NIFTY 50';
	}

	/**
	 * Keyless news sentiment via Google News RSS + a finance lexicon.
	 * Returns a score in [-1..1].
	 *
	 * @param string $instrument Instrument.
	 * @return array { ok, score, headlines, reason }
	 */
	private function fetch_news_sentiment( $instrument ) {
		$default = array(
			'ok'        => false,
			'score'     => 0.0,
			'headlines' => 0,
			'reason'    => 'News source unreachable; neutral default used.',
		);

		$query = $this->news_query( $instrument );
		$url   = add_query_arg(
			array(
				'q'    => $query,
				'hl'   => 'en-IN',
				'gl'   => 'IN',
				'ceid' => 'IN:en',
			),
			'https://news.google.com/rss/search'
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 6,
				'headers' => array(
					'Accept'     => 'application/rss+xml, application/xml, text/xml',
					'User-Agent' => 'Mozilla/5.0 (compatible; FnOSignalPro/1.0)',
				),
			)
		);
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return $default;
		}

		$xml = wp_remote_retrieve_body( $response );
		if ( empty( $xml ) ) {
			return $default;
		}

		// Extract item titles (skip the channel title).
		if ( ! preg_match_all( '#<item>.*?<title>(.*?)</title>#si', $xml, $m ) || empty( $m[1] ) ) {
			return $default;
		}
		$titles = array_slice( $m[1], 0, 25 );
		$score  = $this->score_headlines( $titles );

		return array(
			'ok'        => true,
			'score'     => $score,
			'headlines' => count( $titles ),
			'reason'    => 'News sentiment computed from ' . count( $titles ) . ' headlines.',
		);
	}

	/**
	 * Build the news search query for an instrument.
	 */
	private function news_query( $instrument ) {
		$map = array(
			'NIFTY'      => 'Nifty 50 stock market India',
			'BANKNIFTY'  => 'Bank Nifty banking stocks India',
			'FINNIFTY'   => 'Nifty Financial Services India',
			'SENSEX'     => 'Sensex stock market India',
			'MIDCPNIFTY' => 'Nifty Midcap India',
			'INFY'       => 'Infosys share price news',
			'INFOSYS'    => 'Infosys share price news',
			'ACC'        => 'ACC Ltd cement share price news',
			'SUZLON'     => 'Suzlon Energy share price news',
		);
		if ( isset( $map[ strtoupper( $instrument ) ] ) ) {
			return $map[ strtoupper( $instrument ) ];
		}
		return $instrument . ' share price NSE';
	}

	/**
	 * Lexicon-based sentiment over a set of headlines. Returns [-1..1].
	 *
	 * @param string[] $titles Headlines.
	 * @return float
	 */
	private function score_headlines( array $titles ) {
		$positive = array( 'rally', 'rallies', 'surge', 'surges', 'gain', 'gains', 'jump', 'jumps', 'rise', 'rises', 'rising', 'soar', 'soars', 'bullish', 'record', 'high', 'beat', 'beats', 'profit', 'profits', 'upgrade', 'strong', 'strength', 'boost', 'recover', 'recovery', 'rebound', 'optimism', 'outperform', 'buy', 'top', 'advance', 'advances', 'up' );
		$negative = array( 'fall', 'falls', 'drop', 'drops', 'plunge', 'plunges', 'slump', 'slumps', 'crash', 'crashes', 'down', 'bearish', 'loss', 'losses', 'cut', 'cuts', 'weak', 'weakness', 'decline', 'declines', 'selloff', 'sell-off', 'fear', 'fears', 'fraud', 'slip', 'slips', 'tumble', 'tumbles', 'sink', 'sinks', 'worry', 'worries', 'downgrade', 'underperform', 'sell', 'pressure', 'concern', 'concerns' );

		$pos = 0;
		$neg = 0;
		foreach ( $titles as $title ) {
			$text  = strtolower( html_entity_decode( wp_strip_all_tags( $title ) ) );
			$words = preg_split( '/[^a-z\-]+/', $text );
			foreach ( $words as $w ) {
				if ( '' === $w ) {
					continue;
				}
				if ( in_array( $w, $positive, true ) ) {
					$pos++;
				} elseif ( in_array( $w, $negative, true ) ) {
					$neg++;
				}
			}
		}

		$total = $pos + $neg;
		if ( 0 === $total ) {
			return 0.0;
		}
		return round( ( $pos - $neg ) / $total, 2 );
	}

	/**
	 * Best-effort NSE option-chain analytics. Returns neutral defaults on failure.
	 *
	 * @param string $instrument Instrument.
	 * @param float  $ltp        Last price.
	 * @return array
	 */
	private function fetch_option_chain( $instrument, $ltp ) {
		$default = array(
			'ok'          => false,
			'pcr'         => 1.0,
			'max_pain'    => 0.0,
			'iv'          => 14.0,
			'vix'         => 13.0,
			'call_oi_chg' => 0,
			'put_oi_chg'  => 0,
			'reason'      => '',
		);

		$map = self::nse_oc_map();
		if ( ! isset( $map[ strtoupper( $instrument ) ] ) ) {
			$default['reason'] = 'Option chain not available for this symbol (index options only).';
			return $default;
		}
		if ( $this->nse_blocked ) {
			$default['reason'] = 'NSE unreachable this request; neutral defaults used.';
			return $default;
		}
		$sym = $map[ strtoupper( $instrument ) ]['symbol'];

		// NSE requires a session cookie. Prime it, then call the API.
		$cookies = $this->prime_nse_cookies();
		$args    = array(
			'timeout' => 6,
			'headers' => array(
				'Accept'          => 'application/json, text/plain, */*',
				'Accept-Language' => 'en-US,en;q=0.9',
				'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
				'Referer'         => 'https://www.nseindia.com/option-chain',
			),
		);
		if ( $cookies ) {
			$args['headers']['Cookie'] = $cookies;
		}

		$url      = $this->proxied_url( 'https://www.nseindia.com/api/option-chain-indices?symbol=' . rawurlencode( $sym ) );
		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$this->nse_blocked = true;
			$default['reason'] = 'Live option-chain source unreachable from server; options score uses neutral defaults.';
			return $default;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['records']['data'] ) ) {
			$default['reason'] = 'Option-chain response empty; using neutral defaults.';
			return $default;
		}

		$tot_ce_oi   = 0;
		$tot_pe_oi   = 0;
		$tot_ce_chg  = 0;
		$tot_pe_chg  = 0;
		$strike_oi   = array();
		$iv_samples  = array();

		foreach ( $body['records']['data'] as $row ) {
			$strike = isset( $row['strikePrice'] ) ? (float) $row['strikePrice'] : 0;
			if ( isset( $row['CE'] ) ) {
				$ce          = $row['CE'];
				$tot_ce_oi  += (int) ( $ce['openInterest'] ?? 0 );
				$tot_ce_chg += (int) ( $ce['changeinOpenInterest'] ?? 0 );
				if ( ! empty( $ce['impliedVolatility'] ) ) {
					$iv_samples[] = (float) $ce['impliedVolatility'];
				}
				$strike_oi[ $strike ]['ce'] = (int) ( $ce['openInterest'] ?? 0 );
			}
			if ( isset( $row['PE'] ) ) {
				$pe          = $row['PE'];
				$tot_pe_oi  += (int) ( $pe['openInterest'] ?? 0 );
				$tot_pe_chg += (int) ( $pe['changeinOpenInterest'] ?? 0 );
				if ( ! empty( $pe['impliedVolatility'] ) ) {
					$iv_samples[] = (float) $pe['impliedVolatility'];
				}
				$strike_oi[ $strike ]['pe'] = (int) ( $pe['openInterest'] ?? 0 );
			}
		}

		$pcr      = $tot_ce_oi > 0 ? round( $tot_pe_oi / $tot_ce_oi, 2 ) : 1.0;
		$max_pain = $this->compute_max_pain( $strike_oi );
		$iv       = ! empty( $iv_samples ) ? round( array_sum( $iv_samples ) / count( $iv_samples ), 1 ) : 14.0;

		return array(
			'ok'          => true,
			'pcr'         => $pcr,
			'max_pain'    => $max_pain,
			'iv'          => $iv,
			'vix'         => $default['vix'], // India VIX needs a separate source.
			'call_oi_chg' => $tot_ce_chg,
			'put_oi_chg'  => $tot_pe_chg,
			'reason'      => 'Live option chain loaded.',
		);
	}

	/**
	 * Prime NSE cookies by hitting the homepage first.
	 *
	 * @return string Cookie header string, or '' on failure.
	 */
	private function prime_nse_cookies() {
		if ( $this->nse_blocked ) {
			return '';
		}
		if ( null !== $this->nse_cookie ) {
			return $this->nse_cookie; // Cached for this request (avoids re-priming per call).
		}
		$this->nse_cookie = ''; // Default to empty so we don't retry on failure.
		$resp = wp_remote_get(
			$this->proxied_url( 'https://www.nseindia.com/option-chain' ),
			array(
				'timeout' => 6,
				'headers' => array(
					'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36',
					'Accept'          => 'text/html,application/xhtml+xml',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			$this->nse_blocked = true;
			return '';
		}
		$cookies = wp_remote_retrieve_header( $resp, 'set-cookie' );
		if ( empty( $cookies ) ) {
			return '';
		}
		$parts = is_array( $cookies ) ? $cookies : array( $cookies );
		$pairs = array();
		foreach ( $parts as $c ) {
			$pairs[] = strtok( $c, ';' );
		}
		$this->nse_cookie = implode( '; ', array_filter( $pairs ) );
		return $this->nse_cookie;
	}

	/**
	 * Compute max pain: strike where total option writer payout is minimized.
	 *
	 * @param array $strike_oi Map strike => [ce, pe].
	 * @return float
	 */
	private function compute_max_pain( $strike_oi ) {
		if ( empty( $strike_oi ) ) {
			return 0.0;
		}
		$strikes = array_keys( $strike_oi );
		sort( $strikes );

		$best_strike = $strikes[0];
		$best_pain   = PHP_FLOAT_MAX;

		foreach ( $strikes as $expiry ) {
			$pain = 0.0;
			foreach ( $strike_oi as $k => $oi ) {
				$ce = isset( $oi['ce'] ) ? $oi['ce'] : 0;
				$pe = isset( $oi['pe'] ) ? $oi['pe'] : 0;
				// CE writers lose when expiry > strike; PE writers lose when expiry < strike.
				if ( $expiry > $k ) {
					$pain += ( $expiry - $k ) * $ce;
				}
				if ( $expiry < $k ) {
					$pain += ( $k - $expiry ) * $pe;
				}
			}
			if ( $pain < $best_pain ) {
				$best_pain   = $pain;
				$best_strike = $expiry;
			}
		}
		return (float) $best_strike;
	}

	/**
	 * Notes describing data completeness for transparency.
	 *
	 * @param array $oc    Option-chain result.
	 * @param array $vix   India VIX result.
	 * @param array $flows FII/DII result.
	 * @return array
	 */
	private function build_notes( $oc, $vix = array(), $flows = array(), $breadth = array(), $news = array() ) {
		$notes   = array();
		$notes[] = 'Price/OHLC/indicators: live (free source).';
		$notes[] = $oc['ok']
			? 'Option chain (PCR/OI/Max Pain): live.'
			: 'Option chain: unavailable from server — neutral defaults used (' . $oc['reason'] . ').';
		$notes[] = ( ! empty( $vix['ok'] ) )
			? 'India VIX: live.'
			: 'India VIX: unavailable — neutral default used.';
		$notes[] = ( ! empty( $flows['ok'] ) )
			? 'FII/DII flows: live.'
			: 'FII/DII flows: unavailable — neutral default used' . ( $this->has_proxy() ? '.' : ' (set an NSE proxy in Settings to enable).' );
		$notes[] = ( ! empty( $breadth['ok'] ) )
			? sprintf( 'Market breadth & sector strength: live (%d adv / %d dec).', (int) $breadth['advances'], (int) $breadth['declines'] )
			: 'Market breadth & sector strength: unavailable — neutral default used' . ( $this->has_proxy() ? '.' : ' (set an NSE proxy in Settings to enable).' );
		$notes[] = ( ! empty( $news['ok'] ) )
			? sprintf( 'News sentiment: live (%d headlines).', (int) $news['headlines'] )
			: 'News sentiment: unavailable — neutral default used.';
		return $notes;
	}

	private function default_lot_size( $instrument ) {
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
