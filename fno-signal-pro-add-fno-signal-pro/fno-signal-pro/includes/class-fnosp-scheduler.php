<?php
/**
 * Alert scheduler (WP-Cron).
 *
 * Periodically scans the watched instruments, and pushes a Telegram alert when
 * a signal crosses the alert confidence threshold. Includes market-hours gating
 * (NSE: Mon-Fri 09:15-15:30 IST) and per-instrument dedup so the same signal is
 * not re-sent until the direction changes or a cooldown elapses.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Scheduler {

	const CRON_HOOK     = 'fnosp_scan_event';
	const DIGEST_HOOK   = 'fnosp_digest_event';
	const SCHEDULE_SLUG = 'fnosp_five_minutes';
	const STATE_OPTION  = 'fnosp_alert_state';

	/** @var FnOSP_Settings */
	private $settings;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scan' ) );
		add_action( self::DIGEST_HOOK, array( $this, 'run_digest' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Register a 5-minute cron interval.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function add_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::SCHEDULE_SLUG ] ) ) {
			$schedules[ self::SCHEDULE_SLUG ] = array(
				'interval' => 300,
				'display'  => __( 'Every 5 minutes (F&O Signal Pro)', 'fno-signal-pro' ),
			);
		}
		return $schedules;
	}

	/**
	 * Make sure the cron event is scheduled (idempotent).
	 */
	public function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::SCHEDULE_SLUG, self::CRON_HOOK );
		}
		// Daily digest (only if enabled).
		if ( (int) $this->settings->get( 'digest_enabled', 0 ) === 1 ) {
			if ( ! wp_next_scheduled( self::DIGEST_HOOK ) ) {
				wp_schedule_event( self::next_digest_ts( (string) $this->settings->get( 'digest_time', '09:00' ) ), 'daily', self::DIGEST_HOOK );
			}
		} else {
			self::clear_digest();
		}
	}

	/**
	 * Reschedule the digest (call after settings change).
	 */
	public static function reschedule_digest() {
		self::clear_digest();
		$s = new FnOSP_Settings();
		if ( (int) $s->get( 'digest_enabled', 0 ) === 1 ) {
			wp_schedule_event( self::next_digest_ts( (string) $s->get( 'digest_time', '09:00' ) ), 'daily', self::DIGEST_HOOK );
		}
	}

	/**
	 * Next UTC timestamp for an IST HH:MM time.
	 *
	 * @param string $hhmm Time "HH:MM" in IST.
	 * @return int
	 */
	public static function next_digest_ts( $hhmm ) {
		$parts = explode( ':', $hhmm );
		$h     = isset( $parts[0] ) ? (int) $parts[0] : 9;
		$m     = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$off   = 19800; // IST = UTC + 5:30.
		$now   = time();
		$ist   = $now + $off;
		$mid   = $ist - ( $ist % 86400 ); // Start of IST day (shifted epoch).
		$target = $mid + $h * 3600 + $m * 60;
		if ( $target <= $ist ) {
			$target += 86400;
		}
		return $target - $off;
	}

	/**
	 * Clear the digest event.
	 */
	public static function clear_digest() {
		$ts = wp_next_scheduled( self::DIGEST_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::DIGEST_HOOK );
			$ts = wp_next_scheduled( self::DIGEST_HOOK );
		}
	}

	/**
	 * Daily digest cron: run the scan and send a Top Picks summary.
	 */
	public function run_digest() {
		if ( (int) $this->settings->get( 'digest_enabled', 0 ) !== 1 ) {
			return;
		}
		$channels = array();
		$telegram = new FnOSP_Telegram( $this->settings );
		if ( $telegram->ready() ) {
			$channels['tg'] = $telegram;
		}
		$email = new FnOSP_Email( $this->settings );
		if ( $email->ready() ) {
			$channels['email'] = $email;
		}
		if ( empty( $channels ) ) {
			return;
		}

		$scanner = new FnOSP_Scanner( $this->settings );
		$res     = $scanner->run( true );

		if ( isset( $channels['tg'] ) ) {
			$channels['tg']->send( $channels['tg']->format_digest( $res ) );
		}
		if ( isset( $channels['email'] ) ) {
			$channels['email']->send( __( 'F&O Signal Pro — Daily Top Picks', 'fno-signal-pro' ), $channels['email']->format_digest( $res ) );
		}
	}

	/**
	 * Clear the scheduled event (called on deactivation).
	 */
	public static function clear() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
			$ts = wp_next_scheduled( self::CRON_HOOK );
		}
		self::clear_digest();
	}

	/**
	 * The cron callback: scan instruments and alert.
	 */
	public function run_scan() {
		$channels = array();
		$telegram = new FnOSP_Telegram( $this->settings );
		if ( $telegram->ready() ) {
			$channels[] = $telegram;
		}
		$email = new FnOSP_Email( $this->settings );
		if ( $email->ready() ) {
			$channels[] = $email;
		}
		if ( empty( $channels ) ) {
			return;
		}

		if ( (int) $this->settings->get( 'alert_market_hours_only', 1 ) === 1 && ! self::is_market_open() ) {
			return;
		}

		$min_conf   = (int) $this->settings->get( 'alert_min_confidence', 80 );
		$directions = (array) $this->settings->get( 'alert_directions', array( 'BUY', 'SELL' ) );
		$use_ai     = (bool) $this->settings->get( 'alert_use_ai', 0 ) && $this->settings->ai_ready();
		$cooldown   = (int) $this->settings->get( 'alert_cooldown', 60 ) * 60; // minutes -> seconds.
		$incl_opt   = (int) $this->settings->get( 'alert_include_option', 1 ) === 1;
		$opt_dte    = (int) $this->settings->get( 'alert_option_dte', 7 );

		$instruments = (array) $this->settings->get( 'alert_instruments', array() );
		if ( empty( $instruments ) ) {
			$instruments = (array) $this->settings->get( 'instruments', array() );
		}

		$engine = FnOSP_Plugin::make_engine();
		$state  = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}

		foreach ( $instruments as $instrument ) {
			$instrument = strtoupper( trim( $instrument ) );
			if ( '' === $instrument ) {
				continue;
			}

			$result = $engine->generate( $instrument, array(
				'use_ai'      => $use_ai,
				'dte'         => $opt_dte,
			) );
			if ( is_wp_error( $result ) ) {
				continue;
			}

			$signal     = $result['signal'];
			$confidence = (int) $result['confidence'];

			if ( 'NO TRADE' === $signal || ! in_array( $signal, $directions, true ) || $confidence < $min_conf ) {
				continue;
			}

			if ( ! $this->should_alert( $state, $instrument, $signal, $cooldown ) ) {
				continue;
			}

			$delivered = false;
			foreach ( $channels as $channel ) {
				$sent = $channel->send_signal_alert( $result );
				if ( ! is_wp_error( $sent ) ) {
					$delivered = true;
				}
			}

			if ( $delivered ) {
				$state[ $instrument ] = array(
					'signal'     => $signal,
					'confidence' => $confidence,
					'ts'         => time(),
				);
			}
		}

		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Dedup rule: alert if no prior alert, direction changed, or cooldown elapsed.
	 *
	 * @param array  $state      State map.
	 * @param string $instrument Instrument.
	 * @param string $signal     Current direction.
	 * @param int    $cooldown   Cooldown seconds.
	 * @return bool
	 */
	private function should_alert( $state, $instrument, $signal, $cooldown ) {
		if ( empty( $state[ $instrument ] ) ) {
			return true;
		}
		$prev = $state[ $instrument ];
		if ( ( $prev['signal'] ?? '' ) !== $signal ) {
			return true; // Direction flip — always alert.
		}
		$age = time() - (int) ( $prev['ts'] ?? 0 );
		return $age >= $cooldown;
	}

	/**
	 * Whether the NSE cash/F&O market is open (Mon-Fri 09:15-15:30 IST).
	 * Note: does not account for trading holidays.
	 *
	 * @return bool
	 */
	public static function is_market_open() {
		// IST = UTC + 5:30.
		$ist     = time() + ( 5 * 3600 + 1800 );
		$dow     = (int) gmdate( 'N', $ist ); // 1 (Mon) .. 7 (Sun).
		if ( $dow >= 6 ) {
			return false;
		}
		$minutes = (int) gmdate( 'G', $ist ) * 60 + (int) gmdate( 'i', $ist );
		$open    = 9 * 60 + 15;  // 09:15.
		$close   = 15 * 60 + 30; // 15:30.
		return ( $minutes >= $open && $minutes <= $close );
	}
}
