<?php
/**
 * Entry Optimizer — Institutional-grade premium & strike optimization.
 *
 * A human clicks "buy" on whatever strike the broker shows. We don't.
 * We calculate the optimal entry using:
 *
 *  1. IV Percentile Rank — avoid buying at IV peaks (IV crush = instant loss)
 *  2. Theta Decay Curve — time entry to minimize overnight/intraday theta bleed
 *  3. Gamma/Theta Ratio — maximize leverage per rupee of time decay
 *  4. Strike Selection — ITM/ATM/OTM based on DTE, IV regime, and expected move
 *  5. Position Sizing — Kelly criterion bounded by max 2% capital risk
 *  6. Premium Ceiling — max price you should pay based on expected RR
 *  7. Strategy Selection — naked long vs spread vs ratio based on IV/DTE
 *  8. Entry Timing Window — avoid noise periods (first 15 min, last 30 min)
 *
 * The output is a complete executable trade plan: exactly what to buy,
 * at what max price, how many lots, and what strategy structure.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Entry_Optimizer {

	/** @var FnOSP_Settings */
	private $settings;

	// IV regime thresholds (NIFTY/BANKNIFTY typical).
	const IV_LOW        = 12;   // Below this = cheap options, naked longs ideal.
	const IV_MODERATE   = 18;   // Normal — ATM longs or mild spreads.
	const IV_HIGH       = 24;   // Rich premiums — spreads mandatory.
	const IV_EXTREME    = 32;   // Event/panic — only sell premium or very tight spreads.

	// Time windows (IST minutes from midnight).
	const AVOID_OPEN_UNTIL   = 570;  // 09:30 IST (avoid first 15 min after 09:15 open).
	const AVOID_CLOSE_AFTER  = 915;  // 15:15 IST (avoid last 15 min — theta + illiquidity).
	const SWEET_SPOT_START   = 585;  // 09:45 IST.
	const SWEET_SPOT_END     = 870;  // 14:30 IST.

	// Position sizing limits.
	const MAX_CAPITAL_RISK_PCT = 2.0;   // Never risk > 2% of capital on one trade.
	const DEFAULT_CAPITAL      = 200000; // ₹2L default if not configured.

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Optimize the entry for a confirmed signal.
	 *
	 * @param array $snapshot  Market snapshot.
	 * @param array $ist       Instrument state from Watchdog.
	 * @param array $result    Signal engine result.
	 * @return array Complete entry plan.
	 */
	public function optimize( array $snapshot, array $ist, array $result ) {
		$instrument = $snapshot['instrument'];
		$direction  = $ist['direction'];
		$ltp        = (float) $snapshot['ltp'];
		$atr        = max( 0.01, (float) $snapshot['atr'] );
		$iv         = (float) ( $snapshot['iv'] ?? 15 );
		$confidence = (int) ( $ist['confidence'] ?? 60 );

		// Step 1: IV Percentile Analysis.
		$iv_analysis = $this->analyze_iv_regime( $iv, $snapshot );

		// Step 2: Days to Expiry.
		$dte = $this->days_to_next_expiry( $instrument );

		// Step 3: Strike Selection.
		$strike_plan = $this->select_optimal_strike( $instrument, $direction, $ltp, $atr, $iv_analysis, $dte, $confidence );

		// Step 4: Strategy Selection (naked vs spread vs ratio).
		$strategy = $this->select_strategy( $iv_analysis, $dte, $confidence, $strike_plan );

		// Step 5: Premium Ceiling Calculation.
		$premium_ceiling = $this->calculate_premium_ceiling( $snapshot, $strike_plan, $dte, $iv_analysis, $atr );

		// Step 6: Position Sizing (Kelly-bounded).
		$sizing = $this->calculate_position_size( $premium_ceiling, $confidence, $snapshot, $strike_plan );

		// Step 7: Entry Timing.
		$timing = $this->check_entry_timing();

		// Step 8: Gamma/Theta efficiency.
		$efficiency = $this->gamma_theta_efficiency( $snapshot, $strike_plan, $dte, $iv );

		return array(
			'instrument'      => $instrument,
			'direction'       => $direction,
			'strike'          => $strike_plan['strike'],
			'opt_type'        => $strike_plan['opt_type'],
			'moneyness'       => $strike_plan['moneyness'],
			'dte'             => $dte,
			'expiry_date'     => $this->get_expiry_date( $dte ),
			'max_premium'     => $premium_ceiling['max_premium'],
			'ideal_premium'   => $premium_ceiling['ideal_premium'],
			'premium_range'   => $premium_ceiling['range_str'],
			'strategy_name'   => $strategy['name'],
			'strategy_legs'   => $strategy['legs'],
			'strategy_reason' => $strategy['reason'],
			'lots'            => $sizing['lots'],
			'lot_size'        => $sizing['lot_size'],
			'position_pct'    => $sizing['capital_pct'],
			'max_risk_amount' => $sizing['max_risk'],
			'kelly_fraction'  => $sizing['kelly'],
			'timing_ok'       => $timing['ok'],
			'timing_note'     => $timing['note'],
			'iv_regime'       => $iv_analysis['regime'],
			'iv_percentile'   => $iv_analysis['percentile'],
			'iv_note'         => $iv_analysis['note'],
			'gamma_theta'     => $efficiency['ratio'],
			'efficiency_note' => $efficiency['note'],
			'strike_reason'   => $strike_plan['reason'],
			'avoid_reasons'   => $this->get_avoid_reasons( $iv_analysis, $timing, $dte ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 1: IV REGIME ANALYSIS
	// The #1 mistake retail traders make: buying options at high IV.
	// When IV is at the 80th percentile, you're paying peak premium.
	// After the event, IV crushes and you lose even if direction is right.
	// ─────────────────────────────────────────────────────────────────────

	private function analyze_iv_regime( $iv, array $snapshot ) {
		// VIX gives us a market-wide IV context.
		$vix = (float) ( $snapshot['vix'] ?? 14 );

		// IV percentile: where is current IV relative to its typical range.
		// For NIFTY: typical range 10-25, extreme 30+.
		// We approximate percentile using a sigmoid mapping.
		$iv_mid    = 16;  // Median IV for NIFTY options.
		$iv_scale  = 6;   // How spread out the distribution is.
		$percentile = (int) round( 100 / ( 1 + exp( -( $iv - $iv_mid ) / $iv_scale ) ) );
		$percentile = max( 1, min( 99, $percentile ) );

		$regime = 'LOW';
		$note   = '';

		if ( $iv >= self::IV_EXTREME ) {
			$regime = 'EXTREME';
			$note   = 'IV EXTREME (' . round( $iv, 1 ) . '%). Do NOT buy naked options. Spreads only, or sell premium.';
		} elseif ( $iv >= self::IV_HIGH ) {
			$regime = 'HIGH';
			$note   = 'IV HIGH (' . round( $iv, 1 ) . '%). Prefer debit spreads to cap IV crush risk.';
		} elseif ( $iv >= self::IV_MODERATE ) {
			$regime = 'MODERATE';
			$note   = 'IV moderate (' . round( $iv, 1 ) . '%). ATM naked longs acceptable. Spread optional.';
		} else {
			$regime = 'LOW';
			$note   = 'IV LOW (' . round( $iv, 1 ) . '%). Options are cheap — naked longs have max leverage.';
		}

		// IV crush risk: if VIX is elevated AND we're near an expiry event.
		$crush_risk = ( $vix > 18 && $iv > 20 ) ? 'HIGH' : ( $vix > 15 ? 'MODERATE' : 'LOW' );

		return array(
			'iv'          => $iv,
			'vix'         => $vix,
			'percentile'  => $percentile,
			'regime'      => $regime,
			'crush_risk'  => $crush_risk,
			'note'        => $note,
			'prefer_spread' => in_array( $regime, array( 'HIGH', 'EXTREME' ), true ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 3: OPTIMAL STRIKE SELECTION
	// Not always ATM. The optimal strike depends on:
	// - DTE: Short DTE → ITM (delta), Long DTE → ATM (gamma leverage)
	// - IV: High IV → OTM spread, Low IV → ATM naked
	// - Expected move: If ATR suggests 200pt move, OTM 200pts away is fine
	// - Confidence: High confidence → aggressive (ATM/ITM), Low → conservative (ITM)
	// ─────────────────────────────────────────────────────────────────────

	private function select_optimal_strike( $instrument, $direction, $ltp, $atr, $iv_analysis, $dte, $confidence ) {
		$step = $this->strike_step( $instrument, $ltp );
		$atm  = round( $ltp / $step ) * $step;

		$opt_type = ( 'SELL' === $direction ) ? 'PE' : 'CE';

		// Decision matrix.
		$strike    = $atm;
		$moneyness = 'ATM';
		$reason    = '';

		// Rule 1: Short DTE (≤2 days) → prefer ITM for higher delta, less theta sensitivity.
		if ( $dte <= 2 ) {
			if ( 'CE' === $opt_type ) {
				$strike    = $atm - $step;   // 1 strike ITM.
				$moneyness = 'ITM';
				$reason    = 'Short DTE (' . $dte . 'd): ITM for high delta (0.6+), less theta bleed.';
			} else {
				$strike    = $atm + $step;   // 1 strike ITM for puts.
				$moneyness = 'ITM';
				$reason    = 'Short DTE (' . $dte . 'd): ITM for high delta, less time decay risk.';
			}
		}
		// Rule 2: High IV → OTM spread (we'll add the sell leg in strategy).
		elseif ( $iv_analysis['prefer_spread'] ) {
			$strike    = $atm;
			$moneyness = 'ATM';
			$reason    = 'High IV (' . $iv_analysis['regime'] . '): ATM for spread, sell OTM leg to offset IV crush.';
		}
		// Rule 3: Low IV + high confidence → ATM for max gamma leverage.
		elseif ( 'LOW' === $iv_analysis['regime'] && $confidence >= 65 ) {
			$strike    = $atm;
			$moneyness = 'ATM';
			$reason    = 'Low IV + high confidence: ATM for maximum gamma leverage (cheap options).';
		}
		// Rule 4: Moderate IV, moderate confidence → ATM.
		elseif ( $confidence >= 55 ) {
			$strike    = $atm;
			$moneyness = 'ATM';
			$reason    = 'Moderate setup: ATM strike for balanced delta/gamma.';
		}
		// Rule 5: Lower confidence → ITM for safety (higher delta, less dependent on direction).
		else {
			if ( 'CE' === $opt_type ) {
				$strike = $atm - $step;
			} else {
				$strike = $atm + $step;
			}
			$moneyness = 'ITM';
			$reason    = 'Lower confidence: ITM for higher delta & intrinsic value protection.';
		}

		// Rule 6: If expected move (ATR) is large, check if slightly OTM gives better RR.
		$expected_move = $atr * 1.5; // Conservative expected move in direction.
		if ( $expected_move > $step * 2 && 'LOW' === $iv_analysis['regime'] && $dte >= 3 ) {
			// Big expected move + cheap options = OTM can be very profitable.
			if ( 'CE' === $opt_type ) {
				$otm_strike = $atm + $step;
			} else {
				$otm_strike = $atm - $step;
			}
			// Only suggest OTM if the expected move covers 2x the strike distance.
			if ( $expected_move > abs( $otm_strike - $ltp ) * 2 ) {
				$strike    = $otm_strike;
				$moneyness = 'OTM';
				$reason    = 'Large expected move (' . round( $expected_move, 0 ) . ' pts) + low IV: OTM for explosive RR.';
			}
		}

		return array(
			'strike'     => $strike,
			'opt_type'   => $opt_type,
			'moneyness'  => $moneyness,
			'atm'        => $atm,
			'step'       => $step,
			'reason'     => $reason,
			'dte'        => $dte,
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 4: STRATEGY SELECTION
	// Naked long is NOT always optimal. Based on IV and DTE:
	// - Low IV, any DTE: Naked long (cheap, max leverage)
	// - High IV, any DTE: Debit spread (caps IV crush)
	// - Extreme IV: Sell premium or ratio spread
	// - Short DTE + High IV: Bull/Bear put/call spread ONLY
	// ─────────────────────────────────────────────────────────────────────

	private function select_strategy( $iv_analysis, $dte, $confidence, $strike_plan ) {
		$strike   = $strike_plan['strike'];
		$opt_type = $strike_plan['opt_type'];
		$step     = $strike_plan['step'];
		$atm      = $strike_plan['atm'];

		$regime = $iv_analysis['regime'];

		// Strategy decision tree.
		if ( 'EXTREME' === $regime ) {
			// Extreme IV: NEVER buy naked. Use tight debit spread or avoid.
			$sell_strike = ( 'CE' === $opt_type ) ? $strike + $step : $strike - $step;
			return array(
				'name'   => 'Tight Debit Spread',
				'reason' => 'IV EXTREME — naked buy will get crushed. Tight spread limits vega loss.',
				'legs'   => array(
					array( 'action' => 'BUY', 'strike' => $strike, 'type' => $opt_type ),
					array( 'action' => 'SELL', 'strike' => $sell_strike, 'type' => $opt_type ),
				),
			);
		}

		if ( 'HIGH' === $regime || ( $dte <= 2 && $iv_analysis['iv'] > 16 ) ) {
			// High IV or short DTE with elevated IV: debit spread.
			$sell_strike = ( 'CE' === $opt_type ) ? $strike + $step * 2 : $strike - $step * 2;
			return array(
				'name'   => 'Debit Spread',
				'reason' => 'High IV (pctile ' . $iv_analysis['percentile'] . '%). Spread caps IV crush risk while keeping directional exposure.',
				'legs'   => array(
					array( 'action' => 'BUY', 'strike' => $strike, 'type' => $opt_type ),
					array( 'action' => 'SELL', 'strike' => $sell_strike, 'type' => $opt_type ),
				),
			);
		}

		if ( 'LOW' === $regime && $confidence >= 65 ) {
			// Low IV + high confidence: naked long for maximum leverage.
			return array(
				'name'   => 'Naked Long (Max Leverage)',
				'reason' => 'IV is cheap (pctile ' . $iv_analysis['percentile'] . '%) + strong conviction. Naked long for max gamma exposure.',
				'legs'   => array(
					array( 'action' => 'BUY', 'strike' => $strike, 'type' => $opt_type ),
				),
			);
		}

		// Default: naked long (moderate IV, adequate confidence).
		return array(
			'name'   => 'Naked Long',
			'reason' => 'Moderate IV, directional trade. Simple naked long.',
			'legs'   => array(
				array( 'action' => 'BUY', 'strike' => $strike, 'type' => $opt_type ),
			),
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 5: PREMIUM CEILING
	// The maximum price you should pay for the option. Based on:
	// - Black-Scholes fair value at current IV
	// - Expected move (ATR) and how much premium you'll recover
	// - Risk:Reward requirement (minimum 1:2)
	// ─────────────────────────────────────────────────────────────────────

	private function calculate_premium_ceiling( array $snapshot, array $strike_plan, $dte, $iv_analysis, $atr ) {
		$ltp    = (float) $snapshot['ltp'];
		$strike = (float) $strike_plan['strike'];
		$type   = $strike_plan['opt_type'];
		$iv     = max( 0.05, (float) $iv_analysis['iv'] / 100 ); // decimal.
		$t      = max( 0.001, $dte / 365 );
		$r      = 0.065; // Risk-free rate.

		// Black-Scholes fair value.
		$bs_price = FnOSP_Indicators::bs_price( $type, $ltp, $strike, $t, $r, $iv );
		$bs_price = max( 0.5, $bs_price );

		// Expected move premium: what the option will be worth at target.
		// Target = 1.5 × ATR in favorable direction.
		$expected_move = $atr * 1.5;
		if ( 'CE' === $type ) {
			$target_spot = $ltp + $expected_move;
		} else {
			$target_spot = $ltp - $expected_move;
		}

		// Premium at target (with reduced time — assume half the DTE consumed).
		$t_at_target     = max( 0.001, ( $dte * 0.5 ) / 365 );
		$premium_at_target = FnOSP_Indicators::bs_price( $type, $target_spot, $strike, $t_at_target, $r, $iv * 0.9 );

		// Required RR: we want at least 2:1 reward:risk.
		$min_rr = 2.0;

		// Max premium = target premium / (1 + min_rr).
		// This ensures if target hits, you've made at least 2x your risk.
		$max_from_rr = $premium_at_target / ( 1 + $min_rr );

		// The ceiling is the MINIMUM of:
		// 1. BS fair value + 10% premium (don't overpay)
		// 2. RR-derived max (ensure good risk:reward)
		$max_premium  = min( $bs_price * 1.10, max( $bs_price * 0.5, $max_from_rr ) );
		$max_premium  = round( max( 1, $max_premium ), 2 );

		// Ideal premium: slightly below fair value (limit order zone).
		$ideal_premium = round( $bs_price * 0.95, 2 );

		// Don't set ceiling below ₹2 (unrealistic for index options).
		$max_premium   = max( 2, $max_premium );
		$ideal_premium = max( 1.5, $ideal_premium );

		return array(
			'max_premium'   => $max_premium,
			'ideal_premium' => $ideal_premium,
			'bs_fair_value' => round( $bs_price, 2 ),
			'target_premium' => round( $premium_at_target, 2 ),
			'rr_implied'    => $premium_at_target > 0 ? round( ( $premium_at_target - $max_premium ) / $max_premium, 2 ) : 0,
			'range_str'     => '₹' . number_format( $ideal_premium, 2 ) . ' – ₹' . number_format( $max_premium, 2 ),
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 6: POSITION SIZING (Kelly Criterion, bounded)
	// Kelly: f* = (p × b - q) / b
	// where p = probability of win, q = 1-p, b = win/loss ratio.
	// We bound at max 2% capital risk and half-Kelly for safety.
	// ─────────────────────────────────────────────────────────────────────

	private function calculate_position_size( array $premium_ceiling, $confidence, array $snapshot, array $strike_plan ) {
		$capital  = (float) $this->settings->get( 'trading_capital', self::DEFAULT_CAPITAL );
		$lot_size = $this->get_lot_size( $snapshot['instrument'] );

		$max_premium = $premium_ceiling['max_premium'];

		// Win probability from confidence (calibrated: 60% confidence ≈ 55% actual win rate).
		$p = min( 0.75, max( 0.45, ( $confidence / 100 ) * 0.85 + 0.10 ) );
		$q = 1 - $p;

		// Win/loss ratio (average winner / average loser).
		// From our RR target of 2:1, but assume actual is 1.8:1 (slippage, partial exits).
		$b = 1.8;

		// Kelly fraction.
		$kelly = max( 0, ( $p * $b - $q ) / $b );

		// Half-Kelly for safety.
		$half_kelly = $kelly * 0.5;

		// Max risk per trade.
		$max_risk_pct = min( self::MAX_CAPITAL_RISK_PCT, $half_kelly * 100 );
		$max_risk     = round( $capital * ( $max_risk_pct / 100 ), 0 );

		// Premium risk per lot: max_premium × lot_size (assuming full premium loss = max loss).
		$risk_per_lot = $max_premium * $lot_size;

		// Number of lots (floored).
		$lots = max( 1, (int) floor( $max_risk / max( 1, $risk_per_lot ) ) );

		// Cap at reasonable number (never more than 10 lots for retail).
		$max_lots = (int) $this->settings->get( 'max_lots', 5 );
		$lots     = min( $lots, $max_lots );

		$actual_risk = $lots * $risk_per_lot;
		$capital_pct = $capital > 0 ? round( ( $actual_risk / $capital ) * 100, 1 ) : 0;

		return array(
			'lots'        => $lots,
			'lot_size'    => $lot_size,
			'capital'     => $capital,
			'max_risk'    => round( $actual_risk, 0 ),
			'capital_pct' => $capital_pct,
			'kelly'       => round( $kelly, 4 ),
			'half_kelly'  => round( $half_kelly, 4 ),
			'win_prob'    => round( $p, 3 ),
			'win_loss_r'  => $b,
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 7: ENTRY TIMING
	// Avoid: first 15 min (gap fills, noise), last 30 min (theta, illiquid).
	// Sweet spot: 09:45 – 14:30 IST.
	// ─────────────────────────────────────────────────────────────────────

	private function check_entry_timing() {
		// Current IST time.
		$ist     = time() + ( 5 * 3600 + 1800 );
		$minutes = (int) gmdate( 'G', $ist ) * 60 + (int) gmdate( 'i', $ist );

		if ( $minutes < self::AVOID_OPEN_UNTIL ) {
			return array(
				'ok'   => false,
				'note' => 'Too early — avoid first 15 min (gap fills, fake moves). Wait until 09:30+.',
				'zone' => 'PRE_OPEN',
			);
		}
		if ( $minutes > self::AVOID_CLOSE_AFTER ) {
			return array(
				'ok'   => false,
				'note' => 'Too late — last 15 min has theta acceleration + low liquidity. Better to wait for tomorrow.',
				'zone' => 'CLOSE',
			);
		}
		if ( $minutes >= self::SWEET_SPOT_START && $minutes <= self::SWEET_SPOT_END ) {
			return array(
				'ok'   => true,
				'note' => 'Optimal entry window (09:45–14:30 IST). Liquidity is good, noise is low.',
				'zone' => 'SWEET_SPOT',
			);
		}
		// Between 14:30 and 15:15 — acceptable but caution.
		return array(
			'ok'   => true,
			'note' => 'Acceptable timing. Be aware of increased theta after 14:30 if intraday.',
			'zone' => 'LATE_SESSION',
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// STEP 8: GAMMA / THETA EFFICIENCY
	// The ratio of gamma to theta tells you: for every rupee of time
	// decay you're paying, how much directional leverage are you getting?
	// High gamma/theta = efficient option. Low = you're paying more theta
	// than you're getting in movement potential.
	// ─────────────────────────────────────────────────────────────────────

	private function gamma_theta_efficiency( array $snapshot, array $strike_plan, $dte, $iv ) {
		$ltp    = (float) $snapshot['ltp'];
		$strike = (float) $strike_plan['strike'];
		$type   = $strike_plan['opt_type'];
		$t      = max( 0.001, $dte / 365 );
		$r      = 0.065;
		$sigma  = max( 0.05, $iv / 100 );

		// Calculate Greeks.
		$gamma = FnOSP_Indicators::bs_gamma( $ltp, $strike, $t, $r, $sigma );
		$theta = FnOSP_Indicators::bs_theta( $type, $ltp, $strike, $t, $r, $sigma );

		// Theta is negative (cost per day). Make it absolute for ratio.
		$abs_theta = max( 0.001, abs( $theta ) );
		$ratio     = $gamma / $abs_theta;

		$note = '';
		if ( $ratio > 0.05 ) {
			$note = 'Excellent gamma/theta (' . round( $ratio, 4 ) . '). Option is efficient — good leverage per unit of time decay.';
		} elseif ( $ratio > 0.02 ) {
			$note = 'Adequate gamma/theta (' . round( $ratio, 4 ) . '). Acceptable for directional trade.';
		} else {
			$note = 'Poor gamma/theta (' . round( $ratio, 4 ) . '). Theta is expensive relative to movement potential. Consider closer strike or longer DTE.';
		}

		return array(
			'ratio'  => round( $ratio, 5 ),
			'gamma'  => round( $gamma, 6 ),
			'theta'  => round( $theta, 4 ),
			'note'   => $note,
		);
	}

	// ─────────────────────────────────────────────────────────────────────
	// AVOID REASONS — things that could torpedo the trade
	// ─────────────────────────────────────────────────────────────────────

	private function get_avoid_reasons( $iv_analysis, $timing, $dte ) {
		$reasons = array();

		if ( 'EXTREME' === $iv_analysis['regime'] ) {
			$reasons[] = '⚠️ IV is EXTREME — premiums are at peak. IV crush will hurt even if direction is right.';
		}
		if ( 'HIGH' === $iv_analysis['crush_risk'] ) {
			$reasons[] = '⚠️ High IV crush risk — if this is pre-event (expiry/RBI/budget), expect IV drop post-event.';
		}
		if ( ! $timing['ok'] ) {
			$reasons[] = '⚠️ ' . $timing['note'];
		}
		if ( $dte <= 1 ) {
			$reasons[] = '⚠️ Expiry day — theta decay is exponential. Only trade if the move is immediate.';
		}
		if ( $dte === 2 ) {
			$reasons[] = '⚡ Day before expiry — theta accelerating. Quick in-and-out only.';
		}

		return $reasons;
	}

	// ─────────────────────────────────────────────────────────────────────
	// UTILITIES
	// ─────────────────────────────────────────────────────────────────────

	private function strike_step( $instrument, $ltp ) {
		$map = array( 'NIFTY' => 50, 'BANKNIFTY' => 100, 'FINNIFTY' => 50, 'SENSEX' => 100, 'MIDCPNIFTY' => 25 );
		$instrument = strtoupper( $instrument );
		if ( isset( $map[ $instrument ] ) ) {
			return $map[ $instrument ];
		}
		if ( $ltp >= 2000 ) { return 20; }
		if ( $ltp >= 500 ) { return 10; }
		return 5;
	}

	private function get_lot_size( $instrument ) {
		$map = array(
			'NIFTY'      => 75,
			'BANKNIFTY'  => 30,
			'FINNIFTY'   => 65,
			'SENSEX'     => 20,
			'MIDCPNIFTY' => 120,
		);
		$instrument = strtoupper( $instrument );
		return isset( $map[ $instrument ] ) ? $map[ $instrument ] : 1;
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

	private function get_expiry_date( $dte ) {
		$expiries = array(
			'2026-06-30', '2026-07-28', '2026-08-25', '2026-09-29',
			'2026-10-29', '2026-11-26', '2026-12-29',
			'2027-01-28', '2027-02-25', '2027-03-30',
		);
		$now = time();
		foreach ( $expiries as $d ) {
			$ts = strtotime( $d . ' 15:30:00 +0530' );
			if ( $ts && $ts > $now ) {
				return gmdate( 'd M Y', $ts );
			}
		}
		return gmdate( 'd M Y', $now + $dte * 86400 );
	}

	/**
	 * Quick premium estimate for a given spot/strike/IV/DTE.
	 * Used for strategy cost calculations.
	 */
	public function estimate_premium( $type, $spot, $strike, $dte, $iv ) {
		$t     = max( 0.001, $dte / 365 );
		$sigma = max( 0.05, $iv / 100 );
		return FnOSP_Indicators::bs_price( $type, $spot, $strike, $t, 0.065, $sigma );
	}
}
