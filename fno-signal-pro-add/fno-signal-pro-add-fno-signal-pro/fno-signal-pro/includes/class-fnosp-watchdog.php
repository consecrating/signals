<?php
/**
 * Watchdog — Institutional-grade state machine for signal escalation.
 *
 * Unlike the basic 5-minute cron scheduler, the Watchdog maintains per-instrument
 * state and escalates scan frequency when a setup is building. This catches moves
 * that happen between standard cron ticks — the exact scenario where a PUT goes
 * from ₹15 → ₹70 in 90 seconds and a 5-min cron misses the entry.
 *
 * State machine:
 *   DORMANT       → No directional bias. Standard 5-min scan.
 *   WATCHING      → Weak bias detected (confidence 30-45%). Log, no alert.
 *   STALKING      → Moderate bias (45-60%). Rapid scan ON (60s). "Setup Building" alert.
 *   TRIGGER_ARMED → Trigger level identified. Sub-minute validation active.
 *   CONFIRMED     → ≥5/8 trigger conditions met. "CONFIRMED TRADE" fires instantly.
 *   ACTIVE_TRADE  → Position entered. P&L monitoring, trailing stops, target alerts.
 *   CLOSED        → All targets hit or stopped out. Cooldown before next signal.
 *
 * The key insight: we don't wait for the signal engine to cross 75% on a single
 * snapshot. Instead, we arm the trigger at 45-55% and use the Trigger Engine's
 * 8-condition microstructure validator to confirm in real-time.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Watchdog {

	const STATE_OPTION    = 'fnosp_watchdog_state_v2';
	const HISTORY_OPTION  = 'fnosp_watchdog_history';
	const CRON_RAPID      = 'fnosp_watchdog_rapid';
	const RAPID_INTERVAL  = 'fnosp_sixty_seconds';

	// State constants.
	const S_DORMANT       = 'DORMANT';
	const S_WATCHING      = 'WATCHING';
	const S_STALKING      = 'STALKING';
	const S_TRIGGER_ARMED = 'TRIGGER_ARMED';
	const S_CONFIRMED     = 'CONFIRMED';
	const S_ACTIVE_TRADE  = 'ACTIVE_TRADE';
	const S_CLOSED        = 'CLOSED';

	// Confidence thresholds for state transitions.
	const CONF_WATCHING   = 30;
	const CONF_STALKING   = 45;
	const CONF_ARMED      = 50; // Lower than old 75% — Trigger Engine validates the rest.

	// Timing.
	const STALKING_MAX_AGE   = 1800;  // 30 min max in STALKING without escalation → demote.
	const ARMED_MAX_AGE      = 900;   // 15 min max in ARMED without confirmation → demote.
	const CLOSED_COOLDOWN    = 600;   // 10 min cooldown after a trade closes.
	const ACTIVE_MAX_AGE     = 14400; // 4 hours max active trade (safety).

	/** @var FnOSP_Settings */
	private $settings;

	/** @var FnOSP_Trigger_Engine */
	private $trigger_engine;

	/** @var FnOSP_Entry_Optimizer */
	private $entry_optimizer;

	/** @var FnOSP_Risk_Shield */
	private $risk_shield;

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Inject dependencies (avoids circular construction).
	 */
	public function set_dependencies( FnOSP_Trigger_Engine $trigger, FnOSP_Entry_Optimizer $optimizer, FnOSP_Risk_Shield $shield ) {
		$this->trigger_engine  = $trigger;
		$this->entry_optimizer = $optimizer;
		$this->risk_shield     = $shield;
	}

	/**
	 * Register cron hooks.
	 */
	public function hooks() {
		add_filter( 'cron_schedules', array( $this, 'add_rapid_schedule' ) );
		add_action( self::CRON_RAPID, array( $this, 'rapid_tick' ) );
	}

	/**
	 * Register the 60-second rapid-scan interval.
	 */
	public function add_rapid_schedule( $schedules ) {
		if ( ! isset( $schedules[ self::RAPID_INTERVAL ] ) ) {
			$schedules[ self::RAPID_INTERVAL ] = array(
				'interval' => 60,
				'display'  => __( 'Every 60 seconds (Watchdog Rapid)', 'fno-signal-pro' ),
			);
		}
		return $schedules;
	}

	/**
	 * Activate rapid scanning (called when first instrument escalates to STALKING).
	 */
	public function activate_rapid_scan() {
		if ( ! wp_next_scheduled( self::CRON_RAPID ) ) {
			wp_schedule_event( time() + 10, self::RAPID_INTERVAL, self::CRON_RAPID );
		}
	}

	/**
	 * Deactivate rapid scanning (called when no instruments need it).
	 */
	public function deactivate_rapid_scan() {
		$ts = wp_next_scheduled( self::CRON_RAPID );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_RAPID );
			$ts = wp_next_scheduled( self::CRON_RAPID );
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// STATE MANAGEMENT
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Get the full watchdog state map (all instruments).
	 *
	 * @return array Keyed by instrument symbol.
	 */
	public function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Persist the full state map.
	 */
	private function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Get state for a single instrument.
	 */
	public function get_instrument_state( $instrument ) {
		$state = $this->get_state();
		return isset( $state[ $instrument ] ) ? $state[ $instrument ] : $this->default_state( $instrument );
	}

	/**
	 * Default state for a new/reset instrument.
	 */
	private function default_state( $instrument ) {
		return array(
			'instrument'      => $instrument,
			'state'           => self::S_DORMANT,
			'direction'       => null,       // BUY or SELL.
			'confidence'      => 0,
			'trigger_level'   => null,       // The price level that confirms.
			'trigger_type'    => null,       // 'below' or 'above'.
			'gex_flip'        => null,       // Gamma exposure flip level.
			'gex_regime'      => null,       // 'positive' or 'negative'.
			'entry_plan'      => null,       // From Entry Optimizer.
			'risk_plan'       => null,       // From Risk Shield.
			'trade'           => null,       // Active trade details.
			'escalated_at'    => null,       // When state last changed.
			'last_scan'       => null,       // Timestamp of last data fetch.
			'scan_count'      => 0,          // Consecutive scans in this state.
			'conditions_met'  => array(),    // Which trigger conditions are satisfied.
			'snapshots'       => array(),    // Recent snapshot history (for velocity calc).
			'alerts_sent'     => array(),    // Track which alerts were sent.
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// MAIN TICK — Called every 60 seconds (rapid) or 5 min (normal).
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Rapid tick — processes only escalated instruments (STALKING+).
	 */
	public function rapid_tick() {
		if ( ! FnOSP_Scheduler::is_market_open() ) {
			return;
		}

		$state       = $this->get_state();
		$escalated   = array();
		$any_active  = false;

		foreach ( $state as $sym => $inst_state ) {
			$s = $inst_state['state'];
			if ( in_array( $s, array( self::S_STALKING, self::S_TRIGGER_ARMED, self::S_ACTIVE_TRADE ), true ) ) {
				$escalated[ $sym ] = $inst_state;
				$any_active = true;
			}
		}

		if ( empty( $escalated ) ) {
			// No instruments need rapid scanning — deactivate to save resources.
			$this->deactivate_rapid_scan();
			return;
		}

		$free   = new FnOSP_Free_Data( $this->settings );
		$macro  = $free->get_macro();

		foreach ( $escalated as $sym => $inst_state ) {
			$state[ $sym ] = $this->process_instrument( $sym, $inst_state, $free, $macro );
		}

		$this->save_state( $state );
	}

	/**
	 * Normal tick — called from the main 5-min scheduler. Processes ALL instruments.
	 * Escalates new setups, demotes stale ones.
	 */
	public function normal_tick() {
		if ( ! FnOSP_Scheduler::is_market_open() ) {
			return;
		}

		$instruments = (array) $this->settings->get( 'alert_instruments', array() );
		if ( empty( $instruments ) ) {
			$instruments = (array) $this->settings->get( 'instruments', array() );
		}

		$state = $this->get_state();
		$free  = new FnOSP_Free_Data( $this->settings );
		$macro = $free->get_macro();

		$needs_rapid = false;

		foreach ( $instruments as $instrument ) {
			$instrument = strtoupper( trim( $instrument ) );
			if ( '' === $instrument ) {
				continue;
			}

			$inst_state = isset( $state[ $instrument ] ) ? $state[ $instrument ] : $this->default_state( $instrument );

			// Skip instruments in CONFIRMED (just sent alert) or CLOSED (cooling down).
			if ( self::S_CONFIRMED === $inst_state['state'] ) {
				// Auto-transition to ACTIVE_TRADE after 60s.
				if ( time() - (int) $inst_state['escalated_at'] > 60 ) {
					$inst_state['state']        = self::S_ACTIVE_TRADE;
					$inst_state['escalated_at'] = time();
				}
				$state[ $instrument ] = $inst_state;
				continue;
			}
			if ( self::S_CLOSED === $inst_state['state'] ) {
				if ( time() - (int) $inst_state['escalated_at'] > self::CLOSED_COOLDOWN ) {
					$inst_state = $this->default_state( $instrument );
				}
				$state[ $instrument ] = $inst_state;
				continue;
			}

			$state[ $instrument ] = $this->process_instrument( $instrument, $inst_state, $free, $macro );

			if ( in_array( $state[ $instrument ]['state'], array( self::S_STALKING, self::S_TRIGGER_ARMED, self::S_ACTIVE_TRADE ), true ) ) {
				$needs_rapid = true;
			}
		}

		$this->save_state( $state );

		if ( $needs_rapid ) {
			$this->activate_rapid_scan();
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// PER-INSTRUMENT PROCESSING (The Brain)
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Process a single instrument through the state machine.
	 *
	 * @param string            $instrument Instrument symbol.
	 * @param array             $ist        Current instrument state.
	 * @param FnOSP_Free_Data   $free       Data provider.
	 * @param array             $macro      Macro snapshot.
	 * @return array Updated instrument state.
	 */
	private function process_instrument( $instrument, array $ist, FnOSP_Free_Data $free, array $macro ) {
		// Fetch fresh snapshot.
		$snapshot = $free->get_snapshot_lite( $instrument, $macro );
		if ( is_wp_error( $snapshot ) ) {
			return $ist; // Can't process without data.
		}

		// Run signal engine for directional bias + confidence.
		$engine = FnOSP_Plugin::make_engine();
		$result = $engine->evaluate( $snapshot, array(
			'use_ai'         => false,
			'min_confidence' => 0, // We handle confidence gating ourselves.
		) );

		$confidence = (int) $result['confidence'];
		$direction  = $result['signal']; // BUY, SELL, or NO TRADE (from risk veto only).
		$net_bias   = (float) $result['net_bias'];

		// If risk filter vetoed but we have directional bias, track the raw direction.
		if ( 'NO TRADE' === $direction && abs( $net_bias ) > 5 ) {
			$direction = $net_bias > 0 ? 'BUY' : 'SELL';
		}

		// Store snapshot for velocity calculations (keep last 10).
		$ist['snapshots'][] = array(
			'ts'         => time(),
			'ltp'        => $snapshot['ltp'],
			'confidence' => $confidence,
			'direction'  => $direction,
			'pcr'        => $snapshot['pcr'],
			'oi_ce'      => isset( $snapshot['call_oi_chg'] ) ? $snapshot['call_oi_chg'] : 0,
			'oi_pe'      => isset( $snapshot['put_oi_chg'] ) ? $snapshot['put_oi_chg'] : 0,
			'volume'     => $snapshot['volume'],
			'vwap'       => $snapshot['vwap'],
			'rsi'        => $snapshot['rsi'],
		);
		if ( count( $ist['snapshots'] ) > 10 ) {
			$ist['snapshots'] = array_slice( $ist['snapshots'], -10 );
		}

		$ist['last_scan']  = time();
		$ist['scan_count'] = ( $ist['scan_count'] ?? 0 ) + 1;
		$ist['confidence'] = $confidence;

		$now = time();

		// ─── STATE TRANSITIONS ───────────────────────────────────────────

		switch ( $ist['state'] ) {

			case self::S_DORMANT:
				if ( 'NO TRADE' === $direction && abs( $net_bias ) < 5 ) {
					break; // Stay dormant.
				}
				if ( $confidence >= self::CONF_STALKING ) {
					$ist = $this->transition_to_stalking( $ist, $direction, $result, $snapshot );
				} elseif ( $confidence >= self::CONF_WATCHING ) {
					$ist['state']        = self::S_WATCHING;
					$ist['direction']    = $direction;
					$ist['escalated_at'] = $now;
				}
				break;

			case self::S_WATCHING:
				// Demote if bias disappeared.
				if ( 'NO TRADE' === $direction && abs( $net_bias ) < 5 ) {
					$ist = $this->default_state( $instrument );
					break;
				}
				// Promote to STALKING if confidence climbed.
				if ( $confidence >= self::CONF_STALKING ) {
					$ist = $this->transition_to_stalking( $ist, $direction, $result, $snapshot );
				}
				// Direction flip → reset.
				if ( $ist['direction'] && $direction !== $ist['direction'] && $direction !== 'NO TRADE' ) {
					$ist = $this->default_state( $instrument );
					$ist['state']        = self::S_WATCHING;
					$ist['direction']    = $direction;
					$ist['escalated_at'] = $now;
				}
				break;

			case self::S_STALKING:
				// Timeout check — if stuck in STALKING too long, demote.
				if ( $now - (int) $ist['escalated_at'] > self::STALKING_MAX_AGE ) {
					$ist = $this->default_state( $instrument );
					break;
				}
				// Direction flip → reset.
				if ( $ist['direction'] && $direction !== $ist['direction'] && $direction !== 'NO TRADE' ) {
					$ist = $this->default_state( $instrument );
					break;
				}
				// Confidence dropped → demote.
				if ( $confidence < self::CONF_WATCHING ) {
					$ist = $this->default_state( $instrument );
					break;
				}
				// Ready to arm trigger?
				if ( $confidence >= self::CONF_ARMED && $ist['trigger_level'] ) {
					$ist['state']        = self::S_TRIGGER_ARMED;
					$ist['escalated_at'] = $now;
					$this->send_trigger_armed_alert( $ist, $snapshot );
				}
				// Even if not armed by confidence, check if trigger engine validates.
				if ( $this->trigger_engine && $ist['trigger_level'] ) {
					$conditions = $this->trigger_engine->validate( $snapshot, $ist );
					$ist['conditions_met'] = $conditions['met'];
					if ( $conditions['confirmed'] ) {
						$ist = $this->transition_to_confirmed( $ist, $result, $snapshot );
					}
				}
				break;

			case self::S_TRIGGER_ARMED:
				// Timeout check.
				if ( $now - (int) $ist['escalated_at'] > self::ARMED_MAX_AGE ) {
					// Didn't confirm in time → demote back to STALKING.
					$ist['state']        = self::S_STALKING;
					$ist['escalated_at'] = $now;
					break;
				}
				// Direction flip → reset.
				if ( $ist['direction'] && $direction !== $ist['direction'] && $direction !== 'NO TRADE' ) {
					$ist = $this->default_state( $instrument );
					break;
				}
				// Run trigger engine validation.
				if ( $this->trigger_engine ) {
					$conditions = $this->trigger_engine->validate( $snapshot, $ist );
					$ist['conditions_met'] = $conditions['met'];
					if ( $conditions['confirmed'] ) {
						$ist = $this->transition_to_confirmed( $ist, $result, $snapshot );
					}
				} else {
					// Fallback: simple trigger-level breach check.
					$breached = $this->simple_trigger_check( $ist, $snapshot );
					if ( $breached ) {
						$ist = $this->transition_to_confirmed( $ist, $result, $snapshot );
					}
				}
				break;

			case self::S_ACTIVE_TRADE:
				// Monitor the active trade.
				if ( $this->risk_shield && $ist['trade'] ) {
					$risk_action = $this->risk_shield->monitor( $ist['trade'], $snapshot, $ist );
					if ( 'EXIT' === $risk_action['action'] ) {
						$this->send_exit_alert( $ist, $risk_action, $snapshot );
						$ist['state']        = self::S_CLOSED;
						$ist['escalated_at'] = $now;
					} elseif ( 'MOVE_STOP' === $risk_action['action'] ) {
						$ist['trade']['stop_premium'] = $risk_action['new_stop'];
						$this->send_stop_moved_alert( $ist, $risk_action );
					} elseif ( 'TARGET_HIT' === $risk_action['action'] ) {
						$this->send_target_hit_alert( $ist, $risk_action, $snapshot );
						if ( $risk_action['all_targets_done'] ) {
							$ist['state']        = self::S_CLOSED;
							$ist['escalated_at'] = $now;
						}
					}
				}
				// Safety timeout.
				if ( $now - (int) $ist['escalated_at'] > self::ACTIVE_MAX_AGE ) {
					$ist['state']        = self::S_CLOSED;
					$ist['escalated_at'] = $now;
				}
				break;

			case self::S_CONFIRMED:
				// Brief state — auto-transitions to ACTIVE_TRADE on next tick.
				if ( $now - (int) $ist['escalated_at'] > 30 ) {
					$ist['state']        = self::S_ACTIVE_TRADE;
					$ist['escalated_at'] = $now;
				}
				break;

			case self::S_CLOSED:
				// Cooldown.
				if ( $now - (int) $ist['escalated_at'] > self::CLOSED_COOLDOWN ) {
					$ist = $this->default_state( $instrument );
				}
				break;
		}

		return $ist;
	}

	// ─────────────────────────────────────────────────────────────────────
	// STATE TRANSITION HELPERS
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Transition to STALKING — calculate trigger level and send "Setup Building" alert.
	 */
	private function transition_to_stalking( array $ist, $direction, array $result, array $snapshot ) {
		$ist['state']        = self::S_STALKING;
		$ist['direction']    = $direction;
		$ist['escalated_at'] = time();

		// Calculate trigger level.
		$ltp = (float) $snapshot['ltp'];
		$atr = max( 0.01, (float) $snapshot['atr'] );

		if ( 'SELL' === $direction ) {
			// For SELL: trigger when price breaks below a key support.
			// Use VWAP, or recent low, or a GEX flip level — whichever is closest below.
			$candidates = array(
				$snapshot['vwap'],
				$snapshot['ohlc']['low'],
				isset( $snapshot['prev_ohlc']['low'] ) ? $snapshot['prev_ohlc']['low'] : $ltp,
			);
			// GEX flip if available.
			if ( isset( $result['advanced']['support_resistance']['s1'] ) ) {
				$candidates[] = $result['advanced']['support_resistance']['s1'];
			}
			// Pick the closest level below LTP (but not too close — at least 0.1 ATR).
			$valid = array_filter( $candidates, function ( $c ) use ( $ltp, $atr ) {
				return $c < $ltp && ( $ltp - $c ) > ( $atr * 0.05 ) && ( $ltp - $c ) < ( $atr * 2 );
			} );
			if ( ! empty( $valid ) ) {
				// Closest to LTP = easiest to breach = fastest confirmation.
				$ist['trigger_level'] = round( max( $valid ), 2 );
			} else {
				// Fallback: LTP - 0.15 * ATR (tight trigger).
				$ist['trigger_level'] = round( $ltp - 0.15 * $atr, 2 );
			}
			$ist['trigger_type'] = 'below';
		} else {
			// For BUY: trigger when price breaks above resistance.
			$candidates = array(
				$snapshot['vwap'],
				$snapshot['ohlc']['high'],
				isset( $snapshot['prev_ohlc']['high'] ) ? $snapshot['prev_ohlc']['high'] : $ltp,
			);
			if ( isset( $result['advanced']['support_resistance']['r1'] ) ) {
				$candidates[] = $result['advanced']['support_resistance']['r1'];
			}
			$valid = array_filter( $candidates, function ( $c ) use ( $ltp, $atr ) {
				return $c > $ltp && ( $c - $ltp ) > ( $atr * 0.05 ) && ( $c - $ltp ) < ( $atr * 2 );
			} );
			if ( ! empty( $valid ) ) {
				$ist['trigger_level'] = round( min( $valid ), 2 );
			} else {
				$ist['trigger_level'] = round( $ltp + 0.15 * $atr, 2 );
			}
			$ist['trigger_type'] = 'above';
		}

		// GEX regime from snapshot (if computed by data provider).
		$ist['gex_regime'] = isset( $snapshot['gex_regime'] ) ? $snapshot['gex_regime'] : null;
		$ist['gex_flip']   = isset( $snapshot['gex_flip'] ) ? $snapshot['gex_flip'] : null;

		// Send "Setup Building" alert (only once per stalking episode).
		if ( ! in_array( 'stalking', $ist['alerts_sent'] ?? array(), true ) ) {
			$this->send_setup_building_alert( $ist, $result, $snapshot );
			$ist['alerts_sent'][] = 'stalking';
		}

		return $ist;
	}

	/**
	 * Transition to CONFIRMED — the money moment.
	 */
	private function transition_to_confirmed( array $ist, array $result, array $snapshot ) {
		$ist['state']        = self::S_CONFIRMED;
		$ist['escalated_at'] = time();

		// Run Entry Optimizer to get the best trade plan.
		$entry_plan = null;
		if ( $this->entry_optimizer ) {
			$entry_plan = $this->entry_optimizer->optimize( $snapshot, $ist, $result );
			$ist['entry_plan'] = $entry_plan;
		}

		// Run Risk Shield to get protection plan.
		$risk_plan = null;
		if ( $this->risk_shield ) {
			$risk_plan = $this->risk_shield->plan( $snapshot, $ist, $entry_plan );
			$ist['risk_plan'] = $risk_plan;
		}

		// Build trade record.
		$ist['trade'] = array(
			'instrument'     => $ist['instrument'],
			'direction'      => $ist['direction'],
			'entry_time'     => time(),
			'entry_spot'     => $snapshot['ltp'],
			'entry_plan'     => $entry_plan,
			'risk_plan'      => $risk_plan,
			'targets_hit'    => 0,
			'pnl_realized'   => 0,
			'status'         => 'OPEN',
		);

		// FIRE THE CONFIRMED ALERT — this is what the user was missing today.
		$this->send_confirmed_alert( $ist, $result, $snapshot, $entry_plan, $risk_plan );
		$ist['alerts_sent'][] = 'confirmed';

		return $ist;
	}

	/**
	 * Simple trigger-level breach check (fallback when Trigger Engine not loaded).
	 */
	private function simple_trigger_check( array $ist, array $snapshot ) {
		if ( ! $ist['trigger_level'] ) {
			return false;
		}

		$ltp = (float) $snapshot['ltp'];

		if ( 'below' === $ist['trigger_type'] ) {
			return $ltp <= $ist['trigger_level'];
		}
		if ( 'above' === $ist['trigger_type'] ) {
			return $ltp >= $ist['trigger_level'];
		}
		return false;
	}

	// ─────────────────────────────────────────────────────────────────────
	// ALERT DISPATCH
	// ─────────────────────────────────────────────────────────────────────

	/**
	 * Send "Setup Building" alert with trigger level and GEX context.
	 */
	private function send_setup_building_alert( array $ist, array $result, array $snapshot ) {
		$channels = $this->get_channels();
		if ( empty( $channels ) ) {
			return;
		}

		$instrument = $ist['instrument'];
		$direction  = $ist['direction'];
		$confidence = $ist['confidence'];
		$trigger    = $ist['trigger_level'];
		$trigger_t  = $ist['trigger_type'];
		$ltp        = number_format( (float) $snapshot['ltp'], 2 );
		$gex        = $ist['gex_regime'] ? ucfirst( $ist['gex_regime'] ) . ' Gamma' : 'N/A';
		$gex_flip   = $ist['gex_flip'] ? number_format( $ist['gex_flip'], 0 ) : 'N/A';

		// Likely strike for the option.
		$step       = $this->strike_step( $instrument, $snapshot['ltp'] );
		$atm        = round( $snapshot['ltp'] / $step ) * $step;
		$opt_type   = ( 'SELL' === $direction ) ? 'PE' : 'CE';
		$likely_strike = $atm . ' ' . $opt_type;

		// DTE.
		$dte = $this->days_to_next_expiry( $instrument );
		$expiry_date = gmdate( 'd M Y', time() + $dte * 86400 );

		$trigger_condition = ( 'below' === $trigger_t )
			? "Confirms if price holds below " . number_format( $trigger, 2 )
			: "Confirms if price breaks above " . number_format( $trigger, 2 );

		// Build messages.
		$subject = sprintf( '⏳ GET READY — SETUP BUILDING · %s %s', $instrument, strtoupper( $direction ) );

		$tg_msg = implode( "\n", array(
			'⏳ <b>GET READY — SETUP BUILDING</b>',
			'',
			sprintf( '<b>%s</b> <b>%s</b>', $instrument, $direction ),
			'',
			sprintf( 'Instrument: %s', $instrument ),
			sprintf( 'Current Spot: ₹%s', $ltp ),
			sprintf( 'Leaning: %s (%s) — NOT confirmed yet', $direction, $opt_type ),
			sprintf( 'Watch Trigger: %s', $trigger_condition ),
			sprintf( 'Likely Strike: %s · Exp %s', $likely_strike, $expiry_date ),
			sprintf( 'Confidence: %d%% (needs 60%%+ to fire)', $confidence ),
			sprintf( 'Dealer Regime (GEX): %s · flip %s', $gex, $gex_flip ),
			'',
			'🚨 <b>Important Notes</b>',
			'• This is an early heads-up — NO trade yet.',
			'• Get your capital and broker ready.',
			'• Only enter after it confirms (confidence 60%+).',
			'• Do not pre-empt the trigger.',
			'',
			'<i>Educational analysis, not investment advice.</i>',
			sprintf( '🕒 %s UTC', gmdate( 'Y-m-d H:i:s' ) ),
		) );

		$html_body = $this->build_setup_email_html( $instrument, $direction, $ltp, $trigger_condition, $likely_strike, $expiry_date, $confidence, $gex, $gex_flip );

		foreach ( $channels as $channel ) {
			if ( $channel instanceof FnOSP_Telegram ) {
				$channel->send( $tg_msg );
			} elseif ( $channel instanceof FnOSP_Email ) {
				$channel->send( $subject, $html_body );
			}
		}
	}

	/**
	 * Send "TRIGGER ARMED" alert — price approaching trigger, conditions building.
	 */
	private function send_trigger_armed_alert( array $ist, array $snapshot ) {
		$channels = $this->get_channels();
		if ( empty( $channels ) ) {
			return;
		}

		$conditions = $ist['conditions_met'] ?? array();
		$met_count  = count( $conditions );

		$tg_msg = implode( "\n", array(
			'🎯 <b>TRIGGER ARMED</b>',
			'',
			sprintf( '<b>%s %s</b>', $ist['instrument'], $ist['direction'] ),
			sprintf( 'Trigger: %s @ %s', $ist['trigger_type'], number_format( $ist['trigger_level'], 2 ) ),
			sprintf( 'Spot now: ₹%s', number_format( $snapshot['ltp'], 2 ) ),
			sprintf( 'Conditions met: %d/8', $met_count ),
			! empty( $conditions ) ? '✅ ' . implode( "\n✅ ", $conditions ) : '',
			'',
			'⚡ Confirmation imminent — be ready to act.',
			'',
			'<i>Educational, not advice.</i>',
		) );

		foreach ( $channels as $channel ) {
			if ( $channel instanceof FnOSP_Telegram ) {
				$channel->send( $tg_msg );
			}
		}
	}

	/**
	 * Send "CONFIRMED TRADE" alert — the critical instant alert.
	 */
	private function send_confirmed_alert( array $ist, array $result, array $snapshot, $entry_plan, $risk_plan ) {
		$channels = $this->get_channels();
		if ( empty( $channels ) ) {
			return;
		}

		$instrument = $ist['instrument'];
		$direction  = $ist['direction'];
		$ltp        = number_format( (float) $snapshot['ltp'], 2 );

		// Entry plan details.
		$strike       = $entry_plan ? $entry_plan['strike'] : 'ATM';
		$opt_type     = $entry_plan ? $entry_plan['opt_type'] : ( 'SELL' === $direction ? 'PE' : 'CE' );
		$max_premium  = $entry_plan ? '₹' . number_format( $entry_plan['max_premium'], 2 ) : 'Market';
		$position_pct = $entry_plan ? $entry_plan['position_pct'] . '%' : '2%';
		$strategy     = $entry_plan ? $entry_plan['strategy_name'] : 'Naked Long';

		// Risk plan details.
		$stop_premium   = $risk_plan ? '₹' . number_format( $risk_plan['stop_premium'], 2 ) : 'N/A';
		$t1_premium     = $risk_plan ? '₹' . number_format( $risk_plan['target1_premium'], 2 ) : 'N/A';
		$t2_premium     = $risk_plan ? '₹' . number_format( $risk_plan['target2_premium'], 2 ) : 'N/A';
		$t3_premium     = $risk_plan ? '₹' . number_format( $risk_plan['target3_premium'], 2 ) : 'N/A';
		$hedge          = $risk_plan && ! empty( $risk_plan['hedge'] ) ? $risk_plan['hedge'] : null;
		$time_exit      = $risk_plan ? $risk_plan['time_exit'] : '15:15 IST';
		$max_loss       = $risk_plan ? '₹' . number_format( $risk_plan['max_loss_per_lot'], 0 ) : 'N/A';

		$conditions_str = ! empty( $ist['conditions_met'] ) ? implode( ', ', $ist['conditions_met'] ) : 'Trigger breached';

		$emoji    = ( 'BUY' === $direction ) ? '🟢' : '🔴';
		$act_verb = ( 'BUY' === $direction ) ? 'BUY' : 'BUY'; // We always buy options.

		$subject = sprintf( '%s CONFIRMED — %s %s · ENTER NOW', $emoji, $instrument, $direction );

		$tg_msg = implode( "\n", array(
			sprintf( '%s <b>CONFIRMED TRADE — %s %s</b>', $emoji, $instrument, strtoupper( $direction ) ),
			'',
			'━━━━━━━━━━━━━━━━━━━━',
			sprintf( '📍 Spot: ₹%s', $ltp ),
			sprintf( '🎯 %s %s %s', $act_verb, $strike, $opt_type ),
			sprintf( '💰 Max Premium: %s', $max_premium ),
			sprintf( '📊 Strategy: %s', $strategy ),
			sprintf( '📐 Position: %s of capital', $position_pct ),
			'━━━━━━━━━━━━━━━━━━━━',
			'',
			'<b>🎯 Targets (Premium):</b>',
			sprintf( '  T1 (book ⅓): %s', $t1_premium ),
			sprintf( '  T2 (book ⅓): %s', $t2_premium ),
			sprintf( '  T3 (trail):  %s', $t3_premium ),
			'',
			sprintf( '🛑 Stop Premium: %s', $stop_premium ),
			sprintf( '⏰ Time Exit: %s', $time_exit ),
			sprintf( '💸 Max Loss/Lot: %s', $max_loss ),
			'',
			$hedge ? sprintf( '🛡️ Hedge: %s', $hedge ) : '',
			'',
			'<b>✅ Confirmed by:</b>',
			$conditions_str,
			'',
			'⚡ <b>ACT NOW — this is the confirmation you waited for.</b>',
			'',
			'<i>After T1 hit → stop moves to cost (ZERO LOSS).</i>',
			'<i>Educational analysis, not investment advice.</i>',
			sprintf( '🕒 %s UTC', gmdate( 'Y-m-d H:i:s' ) ),
		) );

		$html_body = $this->build_confirmed_email_html(
			$instrument, $direction, $ltp, $strike, $opt_type, $max_premium,
			$strategy, $position_pct, $stop_premium, $t1_premium, $t2_premium,
			$t3_premium, $time_exit, $max_loss, $hedge, $conditions_str
		);

		foreach ( $channels as $channel ) {
			if ( $channel instanceof FnOSP_Telegram ) {
				$channel->send( $tg_msg );
			} elseif ( $channel instanceof FnOSP_Email ) {
				$channel->send( $subject, $html_body );
			}
		}

		// Log to history.
		$this->log_trade( $ist, $snapshot, $entry_plan, $risk_plan );
	}

	/**
	 * Send target-hit alert.
	 */
	private function send_target_hit_alert( array $ist, array $risk_action, array $snapshot ) {
		$channels = $this->get_channels();
		$target_n = $risk_action['target_number'] ?? 1;
		$premium  = $risk_action['current_premium'] ?? 0;

		$tg_msg = implode( "\n", array(
			sprintf( '🎯 <b>TARGET %d HIT — %s %s</b>', $target_n, $ist['instrument'], $ist['direction'] ),
			'',
			sprintf( 'Premium now: ₹%s', number_format( $premium, 2 ) ),
			sprintf( 'Spot: ₹%s', number_format( $snapshot['ltp'], 2 ) ),
			'',
			$target_n === 1
				? '📌 Book ⅓ of position. Stop moved to COST (zero-loss on remainder).'
				: ( $target_n === 2
					? '📌 Book ⅓ more. Trail remaining with 20% premium drop stop.'
					: '📌 Final target! Exit all remaining or ride with tight trail.' ),
			'',
			'<i>Educational, not advice.</i>',
		) );

		foreach ( $channels as $channel ) {
			if ( $channel instanceof FnOSP_Telegram ) {
				$channel->send( $tg_msg );
			}
		}
	}

	/**
	 * Send stop-moved alert.
	 */
	private function send_stop_moved_alert( array $ist, array $risk_action ) {
		$channels = $this->get_channels();
		$new_stop = $risk_action['new_stop'] ?? 0;
		$reason   = $risk_action['reason'] ?? 'Target hit — protecting profits.';

		$tg_msg = implode( "\n", array(
			sprintf( '🛡️ <b>STOP MOVED — %s</b>', $ist['instrument'] ),
			sprintf( 'New stop premium: ₹%s', number_format( $new_stop, 2 ) ),
			sprintf( 'Reason: %s', $reason ),
		) );

		foreach ( $channels as $channel ) {
			if ( $channel instanceof FnOSP_Telegram ) {
				$channel->send( $tg_msg );
			}
		}
	}

	/**
	 * Send exit alert.
	 */
	private function send_exit_alert( array $ist, array $risk_action, array $snapshot ) {
		$channels = $this->get_channels();
		$reason   = $risk_action['reason'] ?? 'Risk Shield triggered.';
		$premium  = $risk_action['exit_premium'] ?? 0;

		$tg_msg = implode( "\n", array(
			sprintf( '🚪 <b>EXIT — %s %s</b>', $ist['instrument'], $ist['direction'] ),
			'',
			sprintf( 'Exit premium: ₹%s', number_format( $premium, 2 ) ),
			sprintf( 'Spot: ₹%s', number_format( $snapshot['ltp'], 2 ) ),
			sprintf( 'Reason: %s', $reason ),
			'',
			'<i>Trade closed. Cooling down before next signal.</i>',
		) );

		foreach ( $channels as $channel ) {
			if ( $channel instanceof FnOSP_Telegram ) {
				$channel->send( $tg_msg );
			}
		}
	}

	// ─────────────────────────────────────────────────────────────────────
	// EMAIL HTML BUILDERS
	// ─────────────────────────────────────────────────────────────────────

	private function build_setup_email_html( $instrument, $direction, $ltp, $trigger_condition, $likely_strike, $expiry, $confidence, $gex, $gex_flip ) {
		$color = 'SELL' === $direction ? '#b32424' : '#14794a';
		$bg    = '#0d1b2a';
		return '<div style="background:' . $bg . ';color:#fff;font-family:Arial,sans-serif">'
			. '<div style="background:#e8a300;color:#000;padding:14px 20px;font-size:16px;font-weight:700">⏳ GET READY — SETUP BUILDING</div>'
			. '<div style="padding:20px">'
			. '<div style="font-size:22px;font-weight:700;margin-bottom:12px">' . esc_html( $instrument ) . ' <span style="color:' . $color . '">' . esc_html( $direction ) . '</span></div>'
			. '<table style="width:100%;color:#ccc;font-size:14px;border-collapse:collapse">'
			. $this->email_row( 'Instrument', $instrument )
			. $this->email_row( 'Current Spot', '₹' . $ltp )
			. $this->email_row( 'Leaning', $direction . ' (' . ( 'SELL' === $direction ? 'PE' : 'CE' ) . ') — NOT confirmed yet', $color )
			. $this->email_row( 'Watch Trigger', $trigger_condition, '#4fc3f7' )
			. $this->email_row( 'Likely Strike', $likely_strike . ' · Exp ' . $expiry, '#4fc3f7' )
			. $this->email_row( 'Confidence', $confidence . '% (needs 60%+ to fire)' )
			. $this->email_row( 'Dealer Regime (GEX)', $gex . ' · flip ' . $gex_flip, '#81c784' )
			. '</table>'
			. '<div style="margin-top:16px;padding:12px;background:#1a2d42;border-left:4px solid #e8a300;border-radius:4px">'
			. '<div style="color:#e8a300;font-weight:700;margin-bottom:6px">🚨 Important Notes</div>'
			. '<ul style="margin:0;padding-left:16px;color:#aaa;font-size:13px">'
			. '<li>This is an early heads-up — NO trade yet.</li>'
			. '<li>Get your capital and broker ready.</li>'
			. '<li>Only enter after it confirms (confidence 60%+).</li>'
			. '<li>Do not pre-empt the trigger.</li>'
			. '</ul></div>'
			. '</div></div>';
	}

	private function build_confirmed_email_html( $instrument, $direction, $ltp, $strike, $opt_type, $max_premium, $strategy, $position_pct, $stop_premium, $t1, $t2, $t3, $time_exit, $max_loss, $hedge, $conditions ) {
		$color   = 'SELL' === $direction ? '#b32424' : '#14794a';
		$bg      = '#0d1b2a';
		$accent  = 'SELL' === $direction ? '#ef5350' : '#66bb6a';
		$emoji   = 'SELL' === $direction ? '🔴' : '🟢';

		$hedge_row = $hedge ? $this->email_row( '🛡️ Hedge', $hedge, '#81c784' ) : '';

		return '<div style="background:' . $bg . ';color:#fff;font-family:Arial,sans-serif">'
			. '<div style="background:' . $accent . ';color:#fff;padding:14px 20px;font-size:18px;font-weight:700">' . $emoji . ' CONFIRMED — ENTER NOW</div>'
			. '<div style="padding:20px">'
			. '<div style="font-size:24px;font-weight:700;margin-bottom:4px">' . esc_html( $instrument ) . ' <span style="color:' . $accent . '">' . esc_html( $direction ) . '</span></div>'
			. '<div style="font-size:13px;color:#888;margin-bottom:16px">Confirmed by: ' . esc_html( $conditions ) . '</div>'
			. '<table style="width:100%;color:#ccc;font-size:14px;border-collapse:collapse">'
			. $this->email_row( '📍 Spot', '₹' . $ltp )
			. $this->email_row( '🎯 Action', 'BUY ' . $strike . ' ' . $opt_type, $accent )
			. $this->email_row( '💰 Max Premium', $max_premium, '#fff' )
			. $this->email_row( '📊 Strategy', $strategy )
			. $this->email_row( '📐 Position', $position_pct . ' of capital' )
			. '</table>'
			. '<div style="margin:16px 0;border-top:1px solid #2a3a4a"></div>'
			. '<table style="width:100%;color:#ccc;font-size:14px;border-collapse:collapse">'
			. $this->email_row( '🎯 T1 (book ⅓)', $t1, '#66bb6a' )
			. $this->email_row( '🎯 T2 (book ⅓)', $t2, '#66bb6a' )
			. $this->email_row( '🎯 T3 (trail)', $t3, '#66bb6a' )
			. $this->email_row( '🛑 Stop Premium', $stop_premium, '#ef5350' )
			. $this->email_row( '⏰ Time Exit', $time_exit )
			. $this->email_row( '💸 Max Loss/Lot', $max_loss, '#ef5350' )
			. $hedge_row
			. '</table>'
			. '<div style="margin-top:16px;padding:12px;background:#1a3d1a;border-left:4px solid #66bb6a;border-radius:4px;font-size:13px;color:#b2dfb2">'
			. '⚡ <b>ACT NOW</b> — this is the confirmation you waited for.<br>'
			. 'After T1 hit → stop moves to cost (ZERO LOSS on remainder).'
			. '</div>'
			. '<div style="margin-top:12px;font-size:11px;color:#666">Educational analysis, not investment advice. F&amp;O involves substantial risk. ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC</div>'
			. '</div></div>';
	}

	private function email_row( $k, $v, $color = '#ccc' ) {
		return '<tr><td style="padding:5px 0;color:#888;width:140px;font-size:13px">' . esc_html( $k ) . '</td>'
			. '<td style="padding:5px 0;color:' . $color . ';font-weight:600">' . esc_html( $v ) . '</td></tr>';
	}

	// ─────────────────────────────────────────────────────────────────────
	// UTILITIES
	// ─────────────────────────────────────────────────────────────────────

	private function get_channels() {
		$channels = array();
		$tg = new FnOSP_Telegram( $this->settings );
		if ( $tg->ready() ) {
			$channels[] = $tg;
		}
		$email = new FnOSP_Email( $this->settings );
		if ( $email->ready() ) {
			$channels[] = $email;
		}
		return $channels;
	}

	private function strike_step( $instrument, $ltp ) {
		$map = array( 'NIFTY' => 50, 'BANKNIFTY' => 100, 'FINNIFTY' => 50, 'SENSEX' => 100, 'MIDCPNIFTY' => 25 );
		if ( isset( $map[ strtoupper( $instrument ) ] ) ) {
			return $map[ strtoupper( $instrument ) ];
		}
		if ( $ltp >= 2000 ) { return 20; }
		if ( $ltp >= 500 ) { return 10; }
		return 5;
	}

	private function days_to_next_expiry( $instrument ) {
		$expiries = array(
			'2026-06-30', '2026-07-28', '2026-08-25', '2026-09-29',
			'2026-10-29', '2026-11-26', '2026-12-29',
			'2027-01-28', '2027-02-25', '2027-03-30',
		);
		$now = time();
		foreach ( $expiries as $d ) {
			$ts = strtotime( $d . ' 15:30:00 +0530' );
			if ( $ts && $ts > $now ) {
				return max( 1, (int) ceil( ( $ts - $now ) / 86400 ) );
			}
		}
		return 7;
	}

	private function log_trade( array $ist, array $snapshot, $entry_plan, $risk_plan ) {
		$history = get_option( self::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		$history[] = array(
			'instrument'  => $ist['instrument'],
			'direction'   => $ist['direction'],
			'confirmed_at' => gmdate( 'c' ),
			'spot_at_confirm' => $snapshot['ltp'],
			'entry_plan'  => $entry_plan,
			'risk_plan'   => $risk_plan,
			'conditions'  => $ist['conditions_met'],
		);
		// Keep last 100 trades.
		if ( count( $history ) > 100 ) {
			$history = array_slice( $history, -100 );
		}
		update_option( self::HISTORY_OPTION, $history, false );
	}

	/**
	 * Get current state summary for REST API / dashboard.
	 */
	public function get_dashboard_summary() {
		$state = $this->get_state();
		$summary = array();
		foreach ( $state as $sym => $ist ) {
			if ( self::S_DORMANT === $ist['state'] ) {
				continue; // Don't clutter dashboard with dormant.
			}
			$summary[] = array(
				'instrument'     => $sym,
				'state'          => $ist['state'],
				'direction'      => $ist['direction'],
				'confidence'     => $ist['confidence'],
				'trigger_level'  => $ist['trigger_level'],
				'trigger_type'   => $ist['trigger_type'],
				'conditions_met' => count( $ist['conditions_met'] ?? array() ),
				'escalated_at'   => $ist['escalated_at'],
				'gex_regime'     => $ist['gex_regime'],
			);
		}
		return $summary;
	}
}
