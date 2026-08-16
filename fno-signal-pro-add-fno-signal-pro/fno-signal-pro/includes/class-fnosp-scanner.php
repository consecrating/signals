<?php
/**
 * Scanner — ranks a universe (indices + stocks) and returns today's top
 * BUY / SELL picks. Shared by the REST /scan endpoint and the daily digest cron.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Scanner {

	const CACHE_KEY = 'fnosp_scan_v2';

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Run the scan (cached unless $force).
	 *
	 * @param bool $force Skip cache.
	 * @return array
	 */
	public function run( $force = false ) {
		$ttl = max( 120, (int) $this->settings->get( 'cache_ttl', 60 ) * 3 );

		if ( ! $force ) {
			$cached = FnOSP_Cache::get( FNOSP_CACHE_PREFIX . self::CACHE_KEY );
			if ( false !== $cached ) {
				$cached['cached'] = true;
				return $cached;
			}
		}

		// Build the universe: optional indices + configured stock list.
		$universe = array();
		if ( (int) $this->settings->get( 'scan_include_indices', 1 ) === 1 ) {
			$indices  = array( 'NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY' );
			$universe = array_merge( $universe, $indices );
		}
		$universe = array_merge( $universe, (array) $this->settings->get( 'scan_universe', array() ) );
		$universe = array_slice( array_values( array_unique( array_map( 'strtoupper', $universe ) ) ), 0, 35 );

		$min_conf = (int) $this->settings->get( 'scan_min_confidence', 68 );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore
		}

		$free   = new FnOSP_Free_Data( $this->settings );
		$engine = FnOSP_Plugin::make_engine();
		$macro  = $free->get_macro();

		$index_set = array( 'NIFTY', 'NIFTY50', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY' );
		$rows      = array();
		$errors    = 0;

		foreach ( $universe as $sym ) {
			$snap = $free->get_snapshot_lite( $sym, $macro );
			if ( is_wp_error( $snap ) ) {
				$errors++;
				continue;
			}
			$r = $engine->evaluate( $snap, array( 'use_ai' => false, 'min_confidence' => $min_conf ) );
			$rows[] = array(
				'instrument' => $r['instrument'],
				'kind'       => in_array( $r['instrument'], $index_set, true ) ? 'Index' : 'Stock',
				'signal'     => $r['signal'],
				'confidence' => $r['confidence'],
				'trend'      => $r['trend_label'],
				'ltp'        => $r['ltp'],
				'setup'      => $r['setup'] ? array(
					'entry_low'  => $r['setup']['entry_low'],
					'entry_high' => $r['setup']['entry_high'],
					'stop_loss'  => $r['setup']['stop_loss'],
					'target1'    => $r['setup']['target1'],
					'target2'    => $r['setup']['target2'],
				) : null,
				'headline'   => isset( $r['layman_summary']['headline'] ) ? $r['layman_summary']['headline'] : '',
			);
		}

		$by_conf = function ( $a, $b ) {
			return $b['confidence'] - $a['confidence'];
		};
		$buys  = array_values( array_filter( $rows, function ( $x ) use ( $min_conf ) {
			return 'BUY' === $x['signal'] && $x['confidence'] >= $min_conf;
		} ) );
		$sells = array_values( array_filter( $rows, function ( $x ) use ( $min_conf ) {
			return 'SELL' === $x['signal'] && $x['confidence'] >= $min_conf;
		} ) );
		usort( $buys, $by_conf );
		usort( $sells, $by_conf );

		$result = array(
			'generated_at'   => gmdate( 'c' ),
			'scanned'        => count( $rows ),
			'errors'         => $errors,
			'min_confidence' => $min_conf,
			'macro'          => $macro,
			'market_open'    => class_exists( 'FnOSP_Scheduler' ) ? FnOSP_Scheduler::is_market_open() : null,
			'buy'            => $buys,
			'sell'           => $sells,
			'cached'         => false,
			'disclaimer'     => __( 'Educational scan, not investment advice. Verify before trading.', 'fno-signal-pro' ),
		);

		FnOSP_Cache::set( FNOSP_CACHE_PREFIX . self::CACHE_KEY, $result, $ttl );
		return $result;
	}
}
