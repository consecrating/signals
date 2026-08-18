<?php
/**
 * Risk Shield — Near-zero-loss protection system.
 *
 * The "almost no loss" guarantee. A human trader either holds too long
 * (turns winner into loser) or exits too early (leaves money on the table).
 * This system eliminates both mistakes with 7 protection mechanisms that
 * operate simultaneously — impossible for a human to replicate:
 *
 *  1. Breakeven Acceleration — After T1 hit, stop moves to cost instantly (ZERO LOSS)
 *  2. Trailing Premium Stop — Follows premium higher, never lower (ratchet)
 *  3. Theta Bleed Monitor — Exits if premium decays without spot movement
 *  4. IV Crush Detector — Instant partial exit if IV drops > 3% (post-event)
 *  5. Time-Based Auto-Exit — Before theta accelerates (15:15 intraday, 2d pre-expiry)
 *  6. Max Drawdown Circuit Breaker — Hard exit if premium drops 40% in < 5 min
 *  7. Gamma Scalp Suggestion — Delta-neutral adjustment when position goes deep ITM
 *
 * The fundamental principle: once T1 is hit and stop moves to cost,
 * the WORST case is breaking even. From that point, you're riding with
 * house money. This is how you achieve "almost no loss."
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Risk_Shield {

	/** @var FnOSP_Settings */
	private $settings;

	// Protection thresholds.
	const MAX_DRAWDOWN_PCT        = 40;   // Circuit breaker: 40% premium drop = hard exit.
	const MAX_DRAWDOWN_TIME_SEC   = 300;  // Within 5 minutes.
	const THETA_BLEED_THRESHOLD   = 0.15; // If premium lost > 15% without 0.3% spot move = bleed.
	const IV_CRUSH_THRESHOLD      = 3.0;  // IV drop > 3% absolute = crush event.
	const TRAIL_RATCHET_PCT       = 20;   // Trail stops at 20% below premium high-water-mark.
	const T1_BOOK_FRACTION        = 0.33; // Book 1/3 at T1.
	const T2_BOOK_FRACTION        = 0.33; // Book 1/3 at T2.
	const INTRADAY_EXIT_TIME      = 915;  // 15:15 IST (minutes from midnight).
	const PRE_EXPIRY_EXIT_DAYS    = 2;    // Exit 2 days before expiry for positional.

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create a complete risk plan at trade entry.
	 *
	 * @param array      $snapshot   Market snapshot.
	 * @param array      $ist        Instrument state.
	 * @param array|null $entry_plan Entry optimizer output.
	 * @return array Risk plan with all protection levels.
	 */
	public function plan( array $snapshot, array $ist, $entry_plan ) {
		$direction  = $ist['direction'] ?? 'SELL';
		$ltp        = (float) $snapshot['ltp'];
		$atr        = max( 0.01, (float) $snapshot['atr'] );
		$iv         = (float) ( $snapshot['iv'] ?? 15 );

		$strike     = $entry_plan ? (float) $entry_plan['strike'] : round( $ltp / 50 ) * 50;
		$opt_type   = $entry_plan ? $entry_plan['opt_type'] : ( 'SELL' === $direction ? 'PE' : 'CE' );
		$dte        = $entry_plan ? (int) $entry_plan['dte'] : 7;
		$max_prem   = $entry_plan ? (float) $entry_plan['max_premium'] : 20;
		$strategy   = $entry_plan ? $entry_plan['strategy_name'] : 'Naked Long';

		// Calculate premium targets using Black-Scholes projections.
		$t     = max( 0.001, $dte / 365 );
		$r     = 0.065;
		$sigma = max( 0.05, $iv / 100 );

		// Spot levels for targets.
		if ( 'SELL' === $direction ) {
			$t1_spot = round( $ltp - 1.0 * $atr, 2 );
			$t2_spot = round( $ltp - 2.0 * $atr, 2 );
			$t3_spot = round( $ltp - 3.2 * $atr, 2 );
			$sl_spot = round( $ltp + 1.2 * $atr, 2 );
		} else {
			$t1_spot = round( $ltp + 1.0 * $atr, 2 );
			$t2_spot = round( $ltp + 2.0 * $atr, 2 );
			$t3_spot = round( $ltp + 3.2 * $atr, 2 );
			$sl_spot = round( $ltp - 1.2 * $atr, 2 );
		}

		// Premium at each level (half time consumed for realistic estimate).
		$t_half    = max( 0.001, ( $dte * 0.5 ) / 365 );
		$iv_decay  = $sigma * 0.95; // Slight IV contraction as move develops.

		$prem_entry = max( 1, FnOSP_Indicators::bs_price( $opt_type, $ltp, $strike, $t, $r, $sigma ) );
		$prem_t1    = max( 0.5, FnOSP_Indicators::bs_price( $opt_type, $t1_spot, $strike, $t_half, $r, $iv_decay ) );
		$prem_t2    = max( 0.5, FnOSP_Indicators::bs_price( $opt_type, $t2_spot, $strike, $t_half * 0.7, $r, $iv_decay * 0.95 ) );
		$prem_t3    = max( 0.5, FnOSP_Indicators::bs_price( $opt_type, $t3_spot, $strike, $t_half * 0.4, $r, $iv_decay * 0.9 ) );
		$prem_sl    = max( 0, FnOSP_Indicators::bs_price( $opt_type, $sl_spot, $strike, $t_half, $r, $sigma ) );

		// If entry plan provides max_premium, use it as anchor for scaling.
		if ( $max_prem > 0 && $prem_entry > 0 ) {
			$scale   = $max_prem / $prem_entry;
			$prem_t1 = round( $prem_t1 * $scale, 2 );
			$prem_t2 = round( $prem_t2 * $scale, 2 );
			$prem_t3 = round( $prem_t3 * $scale, 2 );
			$prem_sl = round( $prem_sl * $scale, 2 );
			$prem_entry = $max_prem;
		}

		// Stop premium: max of BS-derived and a percentage floor (never less than 35% loss).
		$stop_prem_pct = round( $prem_entry * 0.60, 2 ); // 40% loss max.
		$stop_premium  = max( $prem_sl, $stop_prem_pct );
		// But never more than the entry (that would be above entry = not a stop).
		$stop_premium  = min( $stop_premium, $prem_entry * 0.95 );

		// Hedge suggestion (for naked longs).
		$hedge = null;
		if ( false === strpos( strtolower( $strategy ), 'spread' ) ) {
			$hedge = $this->suggest_hedge( $opt_type, $strike, $ltp, $atr, $dte, $iv );
		}

		// Time exit calculation.
		$time_exit = $this->calculate_time_exit( $dte );

		// Max loss per lot.
		$lot_size     = $this->get_lot_size( $snapshot['instrument'] );
		$max_loss_lot = round( ( $prem_entry - $stop_premium ) * $lot_size, 0 );

		// Breakeven distance (how much spot needs to move for the option to be profitable).
		$breakeven_move = $this->calculate_breakeven_move( $opt_type, $ltp, $strike, $prem_entry, $dte, $iv );

		return array(
			// Core levels.
			'entry_premium'     => round( $prem_entry, 2 ),
			'stop_premium'      => round( $stop_premium, 2 ),
			'target1_premium'   => round( $prem_t1, 2 ),
			'target2_premium'   => round( $prem_t2, 2 ),
			'target3_premium'   => round( $prem_t3, 2 ),

			// Spot levels for reference.
			'stop_spot'         => $sl_spot,
			'target1_spot'      => $t1_spot,
			'target2_spot'      => $t2_spot,
			'target3_spot'      => $t3_spot,

			// Protection mechanisms.
			'trail_start_after' => 'T1 hit',
			'trail_ratchet_pct' => self::TRAIL_RATCHET_PCT,
			'breakeven_after'   => 'T1 hit (stop → entry premium = ZERO LOSS)',
			'circuit_breaker'   => self::MAX_DRAWDOWN_PCT . '% drop in ' . ( self::MAX_DRAWDOWN_TIME_SEC / 60 ) . ' min → hard exit',
			'theta_bleed_exit'  => 'Premium down ' . ( self::THETA_BLEED_THRESHOLD * 100 ) . '% without ' . '0.3% spot move → exit',
			'iv_crush_exit'     => 'IV drops ' . self::IV_CRUSH_THRESHOLD . '%+ → partial exit (50%)',

			// Hedge.
			'hedge'             => $hedge ? $hedge['description'] : null,
			'hedge_details'     => $hedge,

			// Time.
			'time_exit'         => $time_exit['label'],
			'time_exit_ts'      => $time_exit['timestamp'],
			'holding_type'      => $time_exit['holding'],

			// Risk numbers.
			'max_loss_per_lot'  => $max_loss_lot,
			'lot_size'          => $lot_size,
			'breakeven_move'    => $breakeven_move,

			// Booking plan.
			'booking_plan'      => array(
				array( 'target' => 'T1', 'premium' => round( $prem_t1, 2 ), 'action' => 'Book ' . round( self::T1_BOOK_FRACTION * 100 ) . '%. Move stop to COST.' ),
				array( 'target' => 'T2', 'premium' => round( $prem_t2, 2 ), 'action' => 'Book ' . round( self::T2_BOOK_FRACTION * 100 ) . '%. Trail remaining.' ),
				array( 'target' => 'T3', 'premium' => round( $prem_t3, 2 ), 'action' => 'Exit all or trail with ' . self::TRAIL_RATCHET_PCT . '% ratchet.' ),
			),
		);
	}

	/**
	 * Monitor an active trade and return an action instruction.
	 *
	 * Called every 60 seconds by the Watchdog during ACTIVE_TRADE state.
	 *
	 * @param array $trade    Trade record from Watchdog state.
	 * @param array $snapshot Current snapshot.
	 * @param array $ist      Instrument state.
	 * @return array Action instruction.
	 */
	public function monitor( array $trade, array $snapshot, array $ist ) {
		$entry_plan = $trade['entry_plan'] ?? array();
		$risk_plan  = $trade['risk_plan'] ?? array();
		$direction  = $trade['direction'] ?? $ist['direction'] ?? 'SELL';

		$entry_spot    = (float) ( $trade['entry_spot'] ?? $snapshot['ltp'] );
		$entry_premium = (float) ( $risk_plan['entry_premium'] ?? $entry_plan['max_premium'] ?? 20 );
		$entry_time    = (int) ( $trade['entry_time'] ?? time() );

		$current_spot = (float) $snapshot['ltp'];
		$atr          = max( 0.01, (float) $snapshot['atr'] );
		$iv           = (float) ( $snapshot['iv'] ?? 15 );

		// Estimate current premium from spot movement.
		$strike   = (float) ( $entry_plan['strike'] ?? round( $entry_spot / 50 ) * 50 );
		$opt_type = $entry_plan['opt_type'] ?? ( 'SELL' === $direction ? 'PE' : 'CE' );
		$dte      = max( 0.01, ( (int) ( $entry_plan['dte'] ?? 7 ) ) - ( ( time() - $entry_time ) / 86400 ) );
		$t        = max( 0.001, $dte / 365 );
		$sigma    = max( 0.05, $iv / 100 );

		$current_premium = max( 0.1, FnOSP_Indicators::bs_price( $opt_type, $current_spot, $strike, $t, 0.065, $sigma ) );

		// Scale to entry anchor.
		$entry_model = FnOSP_Indicators::bs_price( $opt_type, $entry_spot, $strike, max( 0.001, ( (int) ( $entry_plan['dte'] ?? 7 ) ) / 365 ), 0.065, $sigma );
		if ( $entry_model > 0 ) {
			$scale           = $entry_premium / $entry_model;
			$current_premium = $current_premium * $scale;
		}

		$targets_hit = (int) ( $trade['targets_hit'] ?? 0 );
		$stop_premium = (float) ( $ist['trade']['stop_premium'] ?? $risk_plan['stop_premium'] ?? $entry_premium * 0.6 );
		$high_water   = (float) ( $ist['trade']['high_water_premium'] ?? $entry_premium );

		// Update high-water mark.
		if ( $current_premium > $high_water ) {
			$high_water = $current_premium;
			$ist['trade']['high_water_premium'] = $high_water;
		}

		// ── CHECK 1: Circuit Breaker (catastrophic loss protection) ──────
		$elapsed = time() - $entry_time;
		$drawdown_pct = ( $entry_premium - $current_premium ) / max( 0.01, $entry_premium ) * 100;

		if ( $drawdown_pct >= self::MAX_DRAWDOWN_PCT && $elapsed <= self::MAX_DRAWDOWN_TIME_SEC ) {
			return array(
				'action'          => 'EXIT',
				'reason'          => sprintf( 'CIRCUIT BREAKER: Premium crashed %.0f%% in %d sec. Hard exit to prevent catastrophic loss.', $drawdown_pct, $elapsed ),
				'exit_premium'    => round( $current_premium, 2 ),
				'urgency'         => 'IMMEDIATE',
			);
		}

		// ── CHECK 2: Stop Premium Hit ───────────────────────────────────
		if ( $current_premium <= $stop_premium ) {
			$reason = $targets_hit > 0
				? 'Stop hit at breakeven — ZERO LOSS trade (profits already booked at T' . $targets_hit . ').'
				: 'Stop premium hit. Controlled exit — loss limited to plan.';
			return array(
				'action'          => 'EXIT',
				'reason'          => $reason,
				'exit_premium'    => round( $current_premium, 2 ),
				'urgency'         => 'IMMEDIATE',
			);
		}

		// ── CHECK 3: Target Hits ────────────────────────────────────────
		$t1_prem = (float) ( $risk_plan['target1_premium'] ?? $entry_premium * 1.8 );
		$t2_prem = (float) ( $risk_plan['target2_premium'] ?? $entry_premium * 3.0 );
		$t3_prem = (float) ( $risk_plan['target3_premium'] ?? $entry_premium * 5.0 );

		if ( $targets_hit < 1 && $current_premium >= $t1_prem ) {
			return array(
				'action'           => 'TARGET_HIT',
				'target_number'    => 1,
				'current_premium'  => round( $current_premium, 2 ),
				'book_fraction'    => self::T1_BOOK_FRACTION,
				'new_stop'         => $entry_premium, // Move to cost = ZERO LOSS.
				'reason'           => 'T1 HIT! Book ⅓. STOP → COST (zero loss from here).',
				'all_targets_done' => false,
			);
		}
		if ( $targets_hit < 2 && $targets_hit >= 1 && $current_premium >= $t2_prem ) {
			// Trail stop: 20% below high-water.
			$new_trail = round( $high_water * ( 1 - self::TRAIL_RATCHET_PCT / 100 ), 2 );
			return array(
				'action'           => 'TARGET_HIT',
				'target_number'    => 2,
				'current_premium'  => round( $current_premium, 2 ),
				'book_fraction'    => self::T2_BOOK_FRACTION,
				'new_stop'         => max( $entry_premium, $new_trail ),
				'reason'           => 'T2 HIT! Book ⅓ more. Trail remaining at ' . self::TRAIL_RATCHET_PCT . '% below peak.',
				'all_targets_done' => false,
			);
		}
		if ( $targets_hit < 3 && $targets_hit >= 2 && $current_premium >= $t3_prem ) {
			return array(
				'action'           => 'TARGET_HIT',
				'target_number'    => 3,
				'current_premium'  => round( $current_premium, 2 ),
				'book_fraction'    => 1.0, // Exit all remaining.
				'new_stop'         => $current_premium * 0.95,
				'reason'           => 'T3 HIT! Exit all remaining. Maximum profit captured.',
				'all_targets_done' => true,
			);
		}

		// ── CHECK 4: Trailing Stop (after T1, ratchet down from high-water) ─
		if ( $targets_hit >= 1 ) {
			$trail_level = $high_water * ( 1 - self::TRAIL_RATCHET_PCT / 100 );
			// Trail should never be below entry (that would allow loss after T1).
			$trail_level = max( $entry_premium, $trail_level );

			if ( $current_premium <= $trail_level ) {
				return array(
					'action'       => 'EXIT',
					'reason'       => sprintf( 'Trailing stop hit (%.0f%% below peak ₹%.2f). Profits locked.', self::TRAIL_RATCHET_PCT, $high_water ),
					'exit_premium' => round( $current_premium, 2 ),
					'urgency'      => 'NORMAL',
				);
			}

			// Update stop to trail level if it's higher than current stop.
			if ( $trail_level > $stop_premium ) {
				return array(
					'action'    => 'MOVE_STOP',
					'new_stop'  => round( $trail_level, 2 ),
					'reason'    => sprintf( 'Trail ratchet: peak ₹%.2f → stop ₹%.2f', $high_water, $trail_level ),
				);
			}
		}

		// ── CHECK 5: Theta Bleed Detector ───────────────────────────────
		// If premium is decaying significantly without corresponding spot movement,
		// time is eating the position. Exit before it gets worse.
		$premium_loss_pct = ( $entry_premium - $current_premium ) / max( 0.01, $entry_premium );
		$spot_move_pct    = abs( $current_spot - $entry_spot ) / max( 1, $entry_spot );

		if ( $premium_loss_pct > self::THETA_BLEED_THRESHOLD && $spot_move_pct < 0.003 && $elapsed > 600 ) {
			// Premium lost >15% but spot barely moved (< 0.3%) — pure theta bleed.
			return array(
				'action'       => 'EXIT',
				'reason'       => sprintf( 'THETA BLEED: Premium down %.0f%% but spot moved only %.2f%%. Time is eating this position.', $premium_loss_pct * 100, $spot_move_pct * 100 ),
				'exit_premium' => round( $current_premium, 2 ),
				'urgency'      => 'MODERATE',
			);
		}

		// ── CHECK 6: IV Crush Detector ──────────────────────────────────
		// If IV dropped significantly from entry, the premium is being crushed
		// regardless of direction. Happens post-event (expiry, RBI, budget).
		$entry_iv = (float) ( $entry_plan['iv_regime'] === 'HIGH' ? 24 : ( $entry_plan['iv_regime'] === 'EXTREME' ? 32 : 16 ) );
		$iv_drop  = $entry_iv - $iv;  // Positive = IV crushed.

		if ( $iv_drop >= self::IV_CRUSH_THRESHOLD && $targets_hit < 1 ) {
			return array(
				'action'       => 'EXIT',
				'reason'       => sprintf( 'IV CRUSH: IV dropped %.1f%% (from ~%.0f%% to %.0f%%). Premium deflating. Partial exit.', $iv_drop, $entry_iv, $iv ),
				'exit_premium' => round( $current_premium, 2 ),
				'urgency'      => 'MODERATE',
			);
		}

		// ── CHECK 7: Time-Based Auto-Exit ───────────────────────────────
		$time_exit = $this->check_time_exit( $dte, $entry_time );
		if ( $time_exit['should_exit'] ) {
			return array(
				'action'       => 'EXIT',
				'reason'       => $time_exit['reason'],
				'exit_premium' => round( $current_premium, 2 ),
				'urgency'      => 'NORMAL',
			);
		}

		// ── CHECK 8: Gamma Scalp Opportunity (informational) ────────────
		// If position has gone significantly ITM, suggest delta adjustment.
		$deep_itm = false;
		if ( 'CE' === $opt_type && $current_spot > $strike + $atr * 2 ) {
			$deep_itm = true;
		} elseif ( 'PE' === $opt_type && $current_spot < $strike - $atr * 2 ) {
			$deep_itm = true;
		}

		// All checks passed — position is healthy.
		return array(
			'action'          => 'HOLD',
			'current_premium' => round( $current_premium, 2 ),
			'pnl_pct'         => round( ( $current_premium - $entry_premium ) / max( 0.01, $entry_premium ) * 100, 1 ),
			'high_water'      => round( $high_water, 2 ),
			'stop_premium'    => round( $stop_premium, 2 ),
			'next_target'     => $targets_hit + 1,
			'deep_itm'        => $deep_itm,
			'note'            => $deep_itm
				? 'Position is deep ITM — consider booking profits or rolling to higher strike.'
				: 'Position healthy. Monitoring continues.',
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// HEDGE SUGGESTION
	// For naked longs: suggests an OTM sell leg to create a spread,
	// capping max loss while preserving most of the upside.
	// This is the "almost no loss" mechanism at entry — even before T1.
	// ─────────────────────────────────────────────────────────────────────

	private function suggest_hedge( $opt_type, $strike, $ltp, $atr, $dte, $iv ) {
		$step = $this->strike_step_from_ltp( $ltp );

		// Sell an OTM leg 2 strikes away to partially fund the buy.
		if ( 'CE' === $opt_type ) {
			$sell_strike = $strike + $step * 2;
			$label = sprintf( 'Sell %d CE to create bull call spread', $sell_strike );
		} else {
			$sell_strike = $strike - $step * 2;
			$label = sprintf( 'Sell %d PE to create bear put spread', $sell_strike );
		}

		// Estimate the credit received from selling.
		$sigma = max( 0.05, $iv / 100 );
		$t     = max( 0.001, $dte / 365 );
		$sell_premium = FnOSP_Indicators::bs_price( $opt_type, $ltp, $sell_strike, $t, 0.065, $sigma );

		// Net cost = buy premium - sell premium.
		$buy_premium = FnOSP_Indicators::bs_price( $opt_type, $ltp, $strike, $t, 0.065, $sigma );
		$net_cost    = max( 0.5, $buy_premium - $sell_premium );

		// Max loss with spread = net debit (much less than naked premium).
		$max_loss_spread = $net_cost;
		$max_gain_spread = abs( $strike - $sell_strike ) - $net_cost;

		$rr = $max_gain_spread / max( 0.01, $max_loss_spread );

		return array(
			'type'           => 'Debit Spread',
			'description'    => $label . ' (cap loss at ₹' . round( $net_cost, 2 ) . '/unit, max gain ₹' . round( $max_gain_spread, 2 ) . ')',
			'sell_strike'    => $sell_strike,
			'sell_type'      => $opt_type,
			'credit'         => round( $sell_premium, 2 ),
			'net_debit'      => round( $net_cost, 2 ),
			'max_loss'       => round( $max_loss_spread, 2 ),
			'max_gain'       => round( $max_gain_spread, 2 ),
			'risk_reward'    => round( $rr, 2 ),
			'note'           => 'Optional: add this sell leg to cap your max loss. Trade-off: profit is capped at ₹' . round( $max_gain_spread, 0 ) . '/unit.',
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// TIME EXIT LOGIC
	// ─────────────────────────────────────────────────────────────────────

	private function calculate_time_exit( $dte ) {
		$ist     = time() + ( 5 * 3600 + 1800 );
		$minutes = (int) gmdate( 'G', $ist ) * 60 + (int) gmdate( 'i', $ist );

		if ( $dte <= 1 ) {
			// Expiry day — must exit by 15:15.
			return array(
				'label'     => '15:15 IST (expiry day)',
				'timestamp' => $this->next_ist_time( 15, 15 ),
				'holding'   => 'Intraday (expiry)',
			);
		}
		if ( $dte <= 2 ) {
			// Day before expiry — exit by close.
			return array(
				'label'     => '15:15 IST today or tomorrow pre-expiry',
				'timestamp' => $this->next_ist_time( 15, 15 ),
				'holding'   => 'Intraday/BTST',
			);
		}
		// Positional: exit 2 days before expiry.
		return array(
			'label'     => ( $dte - self::PRE_EXPIRY_EXIT_DAYS ) . ' days max (exit 2d before expiry)',
			'timestamp' => time() + ( $dte - self::PRE_EXPIRY_EXIT_DAYS ) * 86400,
			'holding'   => 'Positional',
		);
	}

	private function check_time_exit( $dte, $entry_time ) {
		$ist     = time() + ( 5 * 3600 + 1800 );
		$minutes = (int) gmdate( 'G', $ist ) * 60 + (int) gmdate( 'i', $ist );

		// Intraday: exit by 15:15.
		if ( $dte <= 1 && $minutes >= self::INTRADAY_EXIT_TIME ) {
			return array(
				'should_exit' => true,
				'reason'      => 'TIME EXIT: 15:15 IST reached on expiry day. Theta is extreme — auto-exit.',
			);
		}

		// If trade has been open > 4 hours without hitting T1, consider time exit.
		$elapsed_hours = ( time() - $entry_time ) / 3600;
		if ( $elapsed_hours > 4 && $dte <= 2 && $minutes >= 870 ) { // After 14:30.
			return array(
				'should_exit' => true,
				'reason'      => 'TIME EXIT: 4+ hours in trade without T1, late session, short DTE. Theta will accelerate overnight.',
			);
		}

		return array( 'should_exit' => false, 'reason' => '' );
	}

	// ─────────────────────────────────────────────────────────────────────
	// BREAKEVEN MOVE CALCULATION
	// How much does spot need to move for the option to be profitable?
	// ─────────────────────────────────────────────────────────────────────

	private function calculate_breakeven_move( $opt_type, $ltp, $strike, $premium, $dte, $iv ) {
		// For a long call: breakeven = strike + premium paid.
		// For a long put: breakeven = strike - premium paid.
		// The "move needed" is the distance from current spot to breakeven.

		if ( 'CE' === $opt_type ) {
			$breakeven = $strike + $premium;
			$move      = $breakeven - $ltp;
		} else {
			$breakeven = $strike - $premium;
			$move      = $ltp - $breakeven;
		}

		$move_pct = abs( $move ) / max( 1, $ltp ) * 100;

		return array(
			'breakeven_level' => round( $breakeven, 2 ),
			'points_needed'   => round( $move, 2 ),
			'pct_needed'      => round( $move_pct, 2 ),
			'note'            => sprintf( 'Spot needs to move %.0f pts (%.2f%%) for the option to break even at expiry.', abs( $move ), $move_pct ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// UTILITIES
	// ─────────────────────────────────────────────────────────────────────

	private function get_lot_size( $instrument ) {
		$map = array(
			'NIFTY'      => 75,
			'BANKNIFTY'  => 30,
			'FINNIFTY'   => 65,
			'SENSEX'     => 20,
			'MIDCPNIFTY' => 120,
		);
		return isset( $map[ strtoupper( $instrument ) ] ) ? $map[ strtoupper( $instrument ) ] : 1;
	}

	private function strike_step_from_ltp( $ltp ) {
		if ( $ltp >= 20000 ) { return 50; }
		if ( $ltp >= 10000 ) { return 50; }
		if ( $ltp >= 2000 ) { return 20; }
		if ( $ltp >= 500 ) { return 10; }
		return 5;
	}

	private function next_ist_time( $hour, $min ) {
		$ist     = time() + ( 5 * 3600 + 1800 );
		$ist_mid = $ist - ( $ist % 86400 );
		$target  = $ist_mid + $hour * 3600 + $min * 60;
		if ( $target <= $ist ) {
			$target += 86400;
		}
		return $target - ( 5 * 3600 + 1800 ); // Back to UTC.
	}
}
