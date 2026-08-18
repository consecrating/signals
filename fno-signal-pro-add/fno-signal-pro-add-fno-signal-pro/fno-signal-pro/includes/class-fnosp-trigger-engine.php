<?php
/**
 * Trigger Engine — 8-condition institutional-grade validation.
 *
 * This is NOT a generic "price crossed a level" check. It validates the
 * microstructure of the move using signals that are impossible for a human
 * to monitor simultaneously in real-time:
 *
 *  1. OI Shift Velocity      — rate of change of OI at the trigger strike
 *  2. Bid-Ask Compression    — when market makers commit, spread tightens
 *  3. Delta Acceleration     — 2nd derivative of delta shows momentum building
 *  4. GEX Flip Proximity     — how close price is to the gamma-exposure flip point
 *  5. Volume Spike Detection  — sudden 3x+ volume on the option strike = smart money
 *  6. Multi-TF Candle Align  — 1m, 5m, 15m all showing momentum bars in same direction
 *  7. VWAP Reclaim/Rejection — institutional execution benchmark
 *  8. Order Flow Imbalance   — buy/sell aggression ratio at the trigger level
 *
 * Confirmation requires ≥5 of 8 conditions met simultaneously.
 * In Negative Gamma regime: only ≥4 needed (moves amplify, less confirmation needed).
 * In Positive Gamma regime: ≥6 needed (moves dampened, need stronger proof).
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Trigger_Engine {

	/** @var FnOSP_Settings */
	private $settings;

	// Minimum conditions for confirmation by GEX regime.
	const MIN_CONDITIONS_POSITIVE_GAMMA = 6;  // Dealers stabilize → need more proof.
	const MIN_CONDITIONS_NEGATIVE_GAMMA = 4;  // Dealers amplify → less proof needed.
	const MIN_CONDITIONS_DEFAULT        = 5;  // Unknown regime.

	public function __construct( FnOSP_Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Validate all 8 trigger conditions against current market microstructure.
	 *
	 * @param array $snapshot  Current market snapshot.
	 * @param array $ist       Instrument state from Watchdog (includes snapshots history, trigger_level, etc).
	 * @return array {
	 *     @type bool     $confirmed    Whether enough conditions are met.
	 *     @type string[] $met          List of condition names that passed.
	 *     @type string[] $failed       List of condition names that failed.
	 *     @type int      $met_count    Number of conditions met.
	 *     @type int      $required     Number required for this regime.
	 *     @type array    $details      Per-condition scoring details.
	 * }
	 */
	public function validate( array $snapshot, array $ist ) {
		$met     = array();
		$failed  = array();
		$details = array();

		$direction     = $ist['direction'] ?? 'SELL';
		$trigger_level = $ist['trigger_level'] ?? 0;
		$trigger_type  = $ist['trigger_type'] ?? 'below';
		$snapshots     = $ist['snapshots'] ?? array();
		$gex_regime    = $ist['gex_regime'] ?? null;

		// ── Condition 1: OI Shift Velocity ──────────────────────────────
		$oi_result = $this->check_oi_shift_velocity( $snapshot, $snapshots, $direction );
		$details['oi_shift_velocity'] = $oi_result;
		if ( $oi_result['passed'] ) {
			$met[] = 'OI Shift Velocity (' . $oi_result['label'] . ')';
		} else {
			$failed[] = 'OI Shift Velocity';
		}

		// ── Condition 2: Bid-Ask Spread Compression ─────────────────────
		$spread_result = $this->check_bid_ask_compression( $snapshot, $snapshots );
		$details['bid_ask_compression'] = $spread_result;
		if ( $spread_result['passed'] ) {
			$met[] = 'Bid-Ask Compression (' . $spread_result['label'] . ')';
		} else {
			$failed[] = 'Bid-Ask Compression';
		}

		// ── Condition 3: Delta Acceleration ─────────────────────────────
		$delta_result = $this->check_delta_acceleration( $snapshot, $snapshots, $direction );
		$details['delta_acceleration'] = $delta_result;
		if ( $delta_result['passed'] ) {
			$met[] = 'Delta Acceleration (' . $delta_result['label'] . ')';
		} else {
			$failed[] = 'Delta Acceleration';
		}

		// ── Condition 4: GEX Flip Proximity ─────────────────────────────
		$gex_result = $this->check_gex_flip_proximity( $snapshot, $ist );
		$details['gex_flip_proximity'] = $gex_result;
		if ( $gex_result['passed'] ) {
			$met[] = 'GEX Flip (' . $gex_result['label'] . ')';
		} else {
			$failed[] = 'GEX Flip Proximity';
		}

		// ── Condition 5: Volume Spike Detection ─────────────────────────
		$vol_result = $this->check_volume_spike( $snapshot, $snapshots );
		$details['volume_spike'] = $vol_result;
		if ( $vol_result['passed'] ) {
			$met[] = 'Volume Spike (' . $vol_result['label'] . ')';
		} else {
			$failed[] = 'Volume Spike';
		}

		// ── Condition 6: Multi-Timeframe Candle Alignment ───────────────
		$mtf_result = $this->check_multi_tf_alignment( $snapshot, $snapshots, $direction );
		$details['multi_tf_alignment'] = $mtf_result;
		if ( $mtf_result['passed'] ) {
			$met[] = 'Multi-TF Aligned (' . $mtf_result['label'] . ')';
		} else {
			$failed[] = 'Multi-TF Alignment';
		}

		// ── Condition 7: VWAP Reclaim / Rejection ───────────────────────
		$vwap_result = $this->check_vwap_action( $snapshot, $direction );
		$details['vwap_action'] = $vwap_result;
		if ( $vwap_result['passed'] ) {
			$met[] = 'VWAP ' . $vwap_result['label'];
		} else {
			$failed[] = 'VWAP Action';
		}

		// ── Condition 8: Order Flow Imbalance ───────────────────────────
		$flow_result = $this->check_order_flow_imbalance( $snapshot, $snapshots, $direction );
		$details['order_flow_imbalance'] = $flow_result;
		if ( $flow_result['passed'] ) {
			$met[] = 'Order Flow (' . $flow_result['label'] . ')';
		} else {
			$failed[] = 'Order Flow Imbalance';
		}

		// ── Determine required threshold based on GEX regime ────────────
		$required = self::MIN_CONDITIONS_DEFAULT;
		if ( 'negative' === $gex_regime ) {
			$required = self::MIN_CONDITIONS_NEGATIVE_GAMMA;
		} elseif ( 'positive' === $gex_regime ) {
			$required = self::MIN_CONDITIONS_POSITIVE_GAMMA;
		}

		// ── BONUS: Trigger-level breach is mandatory ────────────────────
		// Even if 5/8 conditions are met, the price MUST have actually crossed
		// the trigger level. This prevents false confirmations in choppy markets.
		$trigger_breached = $this->is_trigger_breached( $snapshot, $trigger_level, $trigger_type );

		$met_count = count( $met );
		$confirmed = ( $met_count >= $required ) && $trigger_breached;

		return array(
			'confirmed'        => $confirmed,
			'met'              => $met,
			'failed'           => $failed,
			'met_count'        => $met_count,
			'required'         => $required,
			'trigger_breached' => $trigger_breached,
			'gex_regime'       => $gex_regime,
			'details'          => $details,
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 1: OI SHIFT VELOCITY
	// Smart money enters via options. When OI on the relevant side builds
	// rapidly (put OI for bearish, call OI for bullish), it signals
	// institutional positioning. We measure the RATE of change, not just
	// absolute levels — velocity matters more than position.
	// ═════════════════════════════════════════════════════════════════════

	private function check_oi_shift_velocity( array $snapshot, array $snapshots, $direction ) {
		// Current OI change rates.
		$put_oi_chg  = (float) ( $snapshot['put_oi_chg'] ?? 0 );
		$call_oi_chg = (float) ( $snapshot['call_oi_chg'] ?? 0 );

		// Calculate velocity: compare current OI change to previous snapshots.
		$prev_put_oi  = 0;
		$prev_call_oi = 0;
		$has_prev     = false;

		if ( count( $snapshots ) >= 2 ) {
			$prev = $snapshots[ count( $snapshots ) - 2 ];
			$prev_put_oi  = (float) ( $prev['oi_pe'] ?? 0 );
			$prev_call_oi = (float) ( $prev['oi_ce'] ?? 0 );
			$has_prev     = true;
		}

		$put_velocity  = $has_prev ? ( $put_oi_chg - $prev_put_oi ) : $put_oi_chg;
		$call_velocity = $has_prev ? ( $call_oi_chg - $prev_call_oi ) : $call_oi_chg;

		// For SELL signal: put writers are building (put OI increasing = support building)
		// OR call OI is declining rapidly (call unwinding = bears winning).
		// Key insight: if PUT OI builds while price falls → Short Build-up (bearish).
		// If PUT OI builds while price rises → Long Build-up via puts (bullish support).
		$price_falling = isset( $snapshots[ count( $snapshots ) - 1 ] )
			? ( $snapshot['ltp'] < $snapshots[ count( $snapshots ) - 1 ]['ltp'] )
			: ( $snapshot['ltp'] < $snapshot['ohlc']['open'] );

		$passed = false;
		$label  = 'Neutral';
		$score  = 0;

		if ( 'SELL' === $direction ) {
			// Bearish: want call OI building (writers selling calls = bearish) AND/OR
			// put OI declining (put buyers exiting = no support below).
			// Actually: Short Build-up = price falling + OI rising on call side.
			// Most reliable: call OI velocity positive + put OI velocity negative while price drops.
			$bearish_oi = ( $call_velocity > 0 && $price_falling ) || ( $call_oi_chg > $put_oi_chg * 1.3 );
			$strong     = abs( $call_velocity ) > abs( $put_velocity ) * 1.5;

			if ( $bearish_oi && $strong ) {
				$passed = true;
				$label  = 'Strong bearish OI velocity';
				$score  = 1.0;
			} elseif ( $bearish_oi ) {
				$passed = true;
				$label  = 'Bearish OI shift detected';
				$score  = 0.7;
			} elseif ( $call_oi_chg > $put_oi_chg ) {
				// Mild bearish — call OI just higher than put OI.
				$label = 'Mild bearish lean';
				$score = 0.4;
				// Pass if combined with price action.
				$passed = $price_falling;
			}
		} else {
			// Bullish: want put OI building (writers selling puts = bullish floor) AND/OR
			// call OI declining.
			$bullish_oi = ( $put_velocity > 0 && ! $price_falling ) || ( $put_oi_chg > $call_oi_chg * 1.3 );
			$strong     = abs( $put_velocity ) > abs( $call_velocity ) * 1.5;

			if ( $bullish_oi && $strong ) {
				$passed = true;
				$label  = 'Strong bullish OI velocity';
				$score  = 1.0;
			} elseif ( $bullish_oi ) {
				$passed = true;
				$label  = 'Bullish OI shift detected';
				$score  = 0.7;
			} elseif ( $put_oi_chg > $call_oi_chg ) {
				$label  = 'Mild bullish lean';
				$score  = 0.4;
				$passed = ! $price_falling;
			}
		}

		return array(
			'passed'        => $passed,
			'label'         => $label,
			'score'         => $score,
			'put_velocity'  => round( $put_velocity, 0 ),
			'call_velocity' => round( $call_velocity, 0 ),
			'price_falling' => $price_falling,
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 2: BID-ASK SPREAD COMPRESSION
	// When market makers have conviction about direction, they tighten
	// spreads on the option they expect to move. A compressing spread on
	// the relevant strike (PE for sell, CE for buy) signals smart money
	// commitment. Wide spreads = uncertainty. Tight = conviction.
	// ═════════════════════════════════════════════════════════════════════

	private function check_bid_ask_compression( array $snapshot, array $snapshots ) {
		// Use IV as a proxy for spread tightness when direct bid-ask isn't available.
		// Lower IV relative to recent history → market is pricing less uncertainty → tighter spreads.
		// Also: volume surge → more liquidity → tighter spreads.
		$current_iv = (float) ( $snapshot['iv'] ?? 15 );
		$volume     = (float) ( $snapshot['volume'] ?? 0 );
		$avg_vol    = (float) ( $snapshot['avg_volume'] ?? 1 );

		// Calculate IV trend from snapshots (is IV declining? = compression).
		$iv_declining = false;
		if ( count( $snapshots ) >= 3 ) {
			$recent_ivs = array_map( function ( $s ) {
				return isset( $s['iv'] ) ? (float) $s['iv'] : 15;
			}, array_slice( $snapshots, -3 ) );
			// Use RSI-like logic: if 2 of last 3 show declining IV → compression.
			$declines = 0;
			for ( $i = 1; $i < count( $recent_ivs ); $i++ ) {
				if ( $recent_ivs[ $i ] <= $recent_ivs[ $i - 1 ] ) {
					$declines++;
				}
			}
			$iv_declining = ( $declines >= 2 );
		}

		// Volume concentration: high volume = more market maker participation = tighter.
		$vol_ratio = $avg_vol > 0 ? $volume / $avg_vol : 1.0;

		// PCR stability: when PCR is stable (not swinging wildly), it signals
		// market makers have settled on a range → spread compression.
		$pcr_stable = true;
		if ( count( $snapshots ) >= 3 ) {
			$pcrs = array_map( function ( $s ) { return (float) ( $s['pcr'] ?? 1 ); }, array_slice( $snapshots, -3 ) );
			$pcr_range = max( $pcrs ) - min( $pcrs );
			$pcr_stable = ( $pcr_range < 0.15 ); // Low variance = stable.
		}

		$passed = false;
		$label  = 'Normal spreads';
		$score  = 0;

		if ( $iv_declining && $vol_ratio >= 1.5 && $pcr_stable ) {
			$passed = true;
			$label  = 'Strong compression (IV↓, Vol↑, PCR stable)';
			$score  = 1.0;
		} elseif ( $vol_ratio >= 2.0 ) {
			// Very high volume alone indicates liquidity = tight spreads.
			$passed = true;
			$label  = 'High liquidity compression (Vol ' . round( $vol_ratio, 1 ) . 'x)';
			$score  = 0.8;
		} elseif ( $iv_declining && $vol_ratio >= 1.2 ) {
			$passed = true;
			$label  = 'Moderate compression (IV↓, Vol adequate)';
			$score  = 0.6;
		} elseif ( $current_iv < 14 && $vol_ratio >= 1.0 ) {
			// Absolute low IV = spreads are naturally tight.
			$passed = true;
			$label  = 'Low-IV environment (naturally tight)';
			$score  = 0.5;
		}

		return array(
			'passed'       => $passed,
			'label'        => $label,
			'score'        => $score,
			'iv'           => $current_iv,
			'iv_declining' => $iv_declining,
			'vol_ratio'    => round( $vol_ratio, 2 ),
			'pcr_stable'   => $pcr_stable,
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 3: DELTA ACCELERATION (2nd Derivative)
	// Delta tells you how fast the option moves with the underlying.
	// Delta acceleration (2nd derivative / gamma effect) tells you
	// momentum is BUILDING — the option is about to move exponentially,
	// not linearly. This is what separates a ₹15→₹20 move from ₹15→₹70.
	// ═════════════════════════════════════════════════════════════════════

	private function check_delta_acceleration( array $snapshot, array $snapshots, $direction ) {
		// Calculate effective delta acceleration from price movement vs underlying.
		// If the option is moving faster per point of underlying than before → gamma is hot.
		$ltp = (float) $snapshot['ltp'];
		$atr = max( 0.01, (float) ( $snapshot['atr'] ?? 1 ) );

		// Measure price velocity over recent snapshots.
		$velocities = array();
		for ( $i = 1; $i < count( $snapshots ); $i++ ) {
			$dt = max( 1, (int) $snapshots[ $i ]['ts'] - (int) $snapshots[ $i - 1 ]['ts'] );
			$dp = abs( (float) $snapshots[ $i ]['ltp'] - (float) $snapshots[ $i - 1 ]['ltp'] );
			$velocities[] = $dp / ( $dt / 60 ); // Points per minute.
		}

		$accelerating = false;
		$accel_rate   = 0;

		if ( count( $velocities ) >= 2 ) {
			$recent_v = array_slice( $velocities, -3 );
			// Acceleration = each velocity > previous.
			$increasing = 0;
			for ( $i = 1; $i < count( $recent_v ); $i++ ) {
				if ( $recent_v[ $i ] > $recent_v[ $i - 1 ] * 1.2 ) { // 20% faster.
					$increasing++;
				}
			}
			$accelerating = ( $increasing >= 1 );
			$accel_rate   = count( $recent_v ) >= 2
				? ( end( $recent_v ) - reset( $recent_v ) ) / max( 0.01, reset( $recent_v ) ) * 100
				: 0;
		}

		// RSI momentum: RSI moving decisively toward extremes = delta building.
		$rsi = (float) ( $snapshot['rsi'] ?? 50 );
		$rsi_momentum = false;
		if ( 'SELL' === $direction ) {
			$rsi_momentum = ( $rsi < 42 ); // Dropping fast.
		} else {
			$rsi_momentum = ( $rsi > 58 ); // Rising fast.
		}

		// MACD histogram expanding = momentum accelerating.
		$macd_hist     = (float) ( $snapshot['macd_hist'] ?? 0 );
		$macd_expanding = false;
		if ( 'SELL' === $direction ) {
			$macd_expanding = ( $macd_hist < -0.5 ); // Growing negative.
		} else {
			$macd_expanding = ( $macd_hist > 0.5 ); // Growing positive.
		}

		// Price moving at > 0.5 ATR per 5 minutes = high delta acceleration.
		$recent_move = 0;
		if ( count( $snapshots ) >= 2 ) {
			$last_snap   = end( $snapshots );
			$recent_move = abs( $ltp - (float) $last_snap['ltp'] );
		}
		$fast_move = ( $recent_move > $atr * 0.3 );

		$passed = false;
		$label  = 'Stable delta';
		$score  = 0;

		$signals = (int) $accelerating + (int) $rsi_momentum + (int) $macd_expanding + (int) $fast_move;

		if ( $signals >= 3 ) {
			$passed = true;
			$label  = 'Strong delta acceleration (momentum surging)';
			$score  = 1.0;
		} elseif ( $signals >= 2 ) {
			$passed = true;
			$label  = 'Moderate acceleration building';
			$score  = 0.7;
		} elseif ( $fast_move && ( $rsi_momentum || $macd_expanding ) ) {
			$passed = true;
			$label  = 'Fast move with momentum';
			$score  = 0.6;
		}

		return array(
			'passed'         => $passed,
			'label'          => $label,
			'score'          => $score,
			'accelerating'   => $accelerating,
			'accel_rate'     => round( $accel_rate, 1 ),
			'rsi_momentum'   => $rsi_momentum,
			'macd_expanding' => $macd_expanding,
			'fast_move'      => $fast_move,
			'recent_move'    => round( $recent_move, 2 ),
			'atr'            => round( $atr, 2 ),
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 4: GEX FLIP PROXIMITY
	// Gamma Exposure (GEX) defines how dealers hedge. Above the GEX flip
	// level, dealers are long gamma (they stabilize moves — sell into
	// rallies, buy into dips). Below it, they're short gamma (they
	// amplify moves — sell into drops, buy into rallies).
	//
	// KEY INSIGHT: A move that pushes price THROUGH the GEX flip level
	// causes a regime change. Dealers switch from stabilizing to amplifying.
	// This is when ₹15 → ₹70 moves happen. We want to confirm EXACTLY
	// at this transition point.
	// ═════════════════════════════════════════════════════════════════════

	private function check_gex_flip_proximity( array $snapshot, array $ist ) {
		$ltp      = (float) $snapshot['ltp'];
		$atr      = max( 0.01, (float) ( $snapshot['atr'] ?? 1 ) );
		$gex_flip = isset( $ist['gex_flip'] ) ? (float) $ist['gex_flip'] : 0;
		$gex_regime = $ist['gex_regime'] ?? null;
		$direction  = $ist['direction'] ?? 'SELL';

		if ( $gex_flip <= 0 ) {
			// No GEX data — use max pain as a proxy.
			$gex_flip = (float) ( $snapshot['max_pain'] ?? 0 );
		}

		if ( $gex_flip <= 0 ) {
			// Still no reference — can't evaluate.
			return array(
				'passed' => false,
				'label'  => 'No GEX/Max-Pain data',
				'score'  => 0,
				'distance_atr' => null,
			);
		}

		$distance     = abs( $ltp - $gex_flip );
		$distance_atr = $distance / $atr;

		$passed = false;
		$label  = 'Far from flip';
		$score  = 0;

		if ( 'SELL' === $direction ) {
			// For sells: we want price near or below the flip level.
			// If price just crossed below → regime flipping to negative gamma → amplification.
			if ( $ltp <= $gex_flip && $distance_atr < 1.0 ) {
				$passed = true;
				$label  = 'BELOW GEX flip — negative gamma amplifying';
				$score  = 1.0;
			} elseif ( $ltp <= $gex_flip ) {
				$passed = true;
				$label  = 'Below GEX flip (established)';
				$score  = 0.8;
			} elseif ( $distance_atr < 0.5 ) {
				// Very close to flip — about to cross.
				$passed = true;
				$label  = 'Near GEX flip (' . round( $distance, 0 ) . ' pts away)';
				$score  = 0.6;
			} elseif ( $distance_atr < 1.0 ) {
				$label = 'Approaching GEX flip (' . round( $distance, 0 ) . ' pts)';
				$score = 0.3;
			}
		} else {
			// For buys: we want price near or above the flip level.
			if ( $ltp >= $gex_flip && $distance_atr < 1.0 ) {
				$passed = true;
				$label  = 'ABOVE GEX flip — positive gamma stabilizing';
				$score  = 1.0;
			} elseif ( $ltp >= $gex_flip ) {
				$passed = true;
				$label  = 'Above GEX flip (established)';
				$score  = 0.8;
			} elseif ( $distance_atr < 0.5 ) {
				$passed = true;
				$label  = 'Near GEX flip (' . round( $distance, 0 ) . ' pts away)';
				$score  = 0.6;
			}
		}

		return array(
			'passed'       => $passed,
			'label'        => $label,
			'score'        => $score,
			'gex_flip'     => $gex_flip,
			'ltp'          => $ltp,
			'distance'     => round( $distance, 2 ),
			'distance_atr' => round( $distance_atr, 2 ),
			'regime'       => $gex_regime,
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 5: VOLUME SPIKE DETECTION
	// When smart money enters, they can't hide the volume. A sudden
	// spike to 3x+ average volume at the trigger level — especially in
	// the relevant option strike — signals institutional entry.
	// We differentiate: volume WITH price movement = real.
	// Volume WITHOUT price movement = accumulation/distribution.
	// ═════════════════════════════════════════════════════════════════════

	private function check_volume_spike( array $snapshot, array $snapshots ) {
		$volume  = (float) ( $snapshot['volume'] ?? 0 );
		$avg_vol = max( 1, (float) ( $snapshot['avg_volume'] ?? 1 ) );
		$ratio   = $volume / $avg_vol;

		// Volume acceleration: is volume increasing scan-over-scan?
		$vol_accelerating = false;
		if ( count( $snapshots ) >= 2 ) {
			$prev_vol = (float) ( $snapshots[ count( $snapshots ) - 1 ]['volume'] ?? 0 );
			if ( $prev_vol > 0 ) {
				$vol_accelerating = ( $volume > $prev_vol * 1.3 ); // 30%+ increase.
			}
		}

		// Price must be moving WITH the volume (not just churn).
		$price_moving = false;
		$ltp = (float) $snapshot['ltp'];
		if ( count( $snapshots ) >= 1 ) {
			$first_snap   = reset( $snapshots );
			$total_move   = abs( $ltp - (float) $first_snap['ltp'] );
			$atr          = max( 0.01, (float) ( $snapshot['atr'] ?? 1 ) );
			$price_moving = ( $total_move > $atr * 0.2 );
		}

		$passed = false;
		$label  = 'Normal volume';
		$score  = 0;

		if ( $ratio >= 3.0 && $price_moving ) {
			$passed = true;
			$label  = 'Massive spike (' . round( $ratio, 1 ) . 'x) with price movement';
			$score  = 1.0;
		} elseif ( $ratio >= 2.0 && $price_moving && $vol_accelerating ) {
			$passed = true;
			$label  = 'Strong spike (' . round( $ratio, 1 ) . 'x) + accelerating';
			$score  = 0.85;
		} elseif ( $ratio >= 2.0 && $price_moving ) {
			$passed = true;
			$label  = 'Volume spike (' . round( $ratio, 1 ) . 'x) confirmed';
			$score  = 0.7;
		} elseif ( $ratio >= 1.5 && $vol_accelerating && $price_moving ) {
			$passed = true;
			$label  = 'Moderate spike + acceleration';
			$score  = 0.55;
		}

		return array(
			'passed'          => $passed,
			'label'           => $label,
			'score'           => $score,
			'ratio'           => round( $ratio, 2 ),
			'accelerating'    => $vol_accelerating,
			'price_moving'    => $price_moving,
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 6: MULTI-TIMEFRAME CANDLE ALIGNMENT
	// A move that shows up on 1-min but NOT on 5-min/15-min is noise.
	// A move where ALL timeframes show momentum bars = real institutional
	// flow. We reconstruct approximate multi-TF picture from our 60-second
	// snapshot history.
	// ═════════════════════════════════════════════════════════════════════

	private function check_multi_tf_alignment( array $snapshot, array $snapshots, $direction ) {
		$ltp = (float) $snapshot['ltp'];

		// 1-minute frame: current snapshot vs 1 scan ago.
		$tf1_bearish = false;
		$tf1_bullish = false;
		if ( count( $snapshots ) >= 1 ) {
			$prev1 = end( $snapshots );
			$tf1_bearish = ( $ltp < (float) $prev1['ltp'] );
			$tf1_bullish = ( $ltp > (float) $prev1['ltp'] );
		}

		// 5-minute frame: current vs 5 scans ago (5 × 60s = 5 min).
		$tf5_bearish = false;
		$tf5_bullish = false;
		if ( count( $snapshots ) >= 5 ) {
			$prev5 = $snapshots[ count( $snapshots ) - 5 ];
			$tf5_bearish = ( $ltp < (float) $prev5['ltp'] );
			$tf5_bullish = ( $ltp > (float) $prev5['ltp'] );
		} elseif ( count( $snapshots ) >= 3 ) {
			// Fewer snapshots — use what we have.
			$prev5 = $snapshots[0];
			$tf5_bearish = ( $ltp < (float) $prev5['ltp'] );
			$tf5_bullish = ( $ltp > (float) $prev5['ltp'] );
		}

		// 15-minute proxy: use today's OHLC context.
		// If price is below open AND below VWAP → 15-min bearish structure.
		$vwap   = (float) ( $snapshot['vwap'] ?? $ltp );
		$open   = (float) ( $snapshot['ohlc']['open'] ?? $ltp );
		$tf15_bearish = ( $ltp < $open && $ltp < $vwap );
		$tf15_bullish = ( $ltp > $open && $ltp > $vwap );

		// EMA alignment as additional timeframe proxy.
		$ema9   = (float) ( $snapshot['ema9'] ?? $ltp );
		$ema21  = (float) ( $snapshot['ema21'] ?? $ltp );
		$ema_bearish = ( $ltp < $ema9 && $ema9 < $ema21 );
		$ema_bullish = ( $ltp > $ema9 && $ema9 > $ema21 );

		$passed = false;
		$label  = 'Mixed timeframes';
		$score  = 0;

		if ( 'SELL' === $direction ) {
			$aligned_count = (int) $tf1_bearish + (int) $tf5_bearish + (int) $tf15_bearish + (int) $ema_bearish;
			if ( $aligned_count >= 4 ) {
				$passed = true;
				$label  = 'All TFs bearish (1m, 5m, 15m, EMA)';
				$score  = 1.0;
			} elseif ( $aligned_count >= 3 ) {
				$passed = true;
				$label  = $aligned_count . '/4 TFs bearish';
				$score  = 0.75;
			} elseif ( $aligned_count >= 2 && $tf5_bearish ) {
				// 5-min + one other = adequate.
				$passed = true;
				$label  = '5m + ' . ( $aligned_count - 1 ) . ' other TF bearish';
				$score  = 0.55;
			}
		} else {
			$aligned_count = (int) $tf1_bullish + (int) $tf5_bullish + (int) $tf15_bullish + (int) $ema_bullish;
			if ( $aligned_count >= 4 ) {
				$passed = true;
				$label  = 'All TFs bullish (1m, 5m, 15m, EMA)';
				$score  = 1.0;
			} elseif ( $aligned_count >= 3 ) {
				$passed = true;
				$label  = $aligned_count . '/4 TFs bullish';
				$score  = 0.75;
			} elseif ( $aligned_count >= 2 && $tf5_bullish ) {
				$passed = true;
				$label  = '5m + ' . ( $aligned_count - 1 ) . ' other TF bullish';
				$score  = 0.55;
			}
		}

		return array(
			'passed'   => $passed,
			'label'    => $label,
			'score'    => $score,
			'tf1'      => 'SELL' === $direction ? $tf1_bearish : $tf1_bullish,
			'tf5'      => 'SELL' === $direction ? $tf5_bearish : $tf5_bullish,
			'tf15'     => 'SELL' === $direction ? $tf15_bearish : $tf15_bullish,
			'ema'      => 'SELL' === $direction ? $ema_bearish : $ema_bullish,
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 7: VWAP RECLAIM / REJECTION
	// VWAP = the price institutional traders watch for execution.
	// A SELL is confirmed when price rejects VWAP from below (tried to
	// reclaim, failed, dumped). A BUY confirms when price reclaims VWAP
	// from above (held above, pulled away higher).
	// ═════════════════════════════════════════════════════════════════════

	private function check_vwap_action( array $snapshot, $direction ) {
		$ltp  = (float) $snapshot['ltp'];
		$vwap = (float) ( $snapshot['vwap'] ?? $ltp );

		if ( abs( $vwap ) < 1 ) {
			return array( 'passed' => false, 'label' => 'No VWAP data', 'score' => 0 );
		}

		$dev_pct = ( ( $ltp - $vwap ) / $vwap ) * 100;
		$open    = (float) ( $snapshot['ohlc']['open'] ?? $ltp );

		$passed = false;
		$label  = 'Neutral VWAP';
		$score  = 0;

		if ( 'SELL' === $direction ) {
			// SELL confirmation: price is BELOW VWAP (sellers dominate).
			// Stronger: was above VWAP earlier (rejection happened).
			if ( $ltp < $vwap ) {
				$was_above = ( $open > $vwap ) || ( (float) ( $snapshot['ohlc']['high'] ?? 0 ) > $vwap );
				if ( $was_above && $dev_pct < -0.15 ) {
					$passed = true;
					$label  = 'VWAP rejection (failed reclaim, now ' . round( abs( $dev_pct ), 2 ) . '% below)';
					$score  = 1.0;
				} elseif ( $dev_pct < -0.1 ) {
					$passed = true;
					$label  = 'Below VWAP (' . round( abs( $dev_pct ), 2 ) . '%)';
					$score  = 0.7;
				} elseif ( $ltp < $vwap ) {
					$passed = true;
					$label  = 'Marginally below VWAP';
					$score  = 0.5;
				}
			}
		} else {
			// BUY confirmation: price is ABOVE VWAP.
			if ( $ltp > $vwap ) {
				$was_below = ( $open < $vwap ) || ( (float) ( $snapshot['ohlc']['low'] ?? PHP_INT_MAX ) < $vwap );
				if ( $was_below && $dev_pct > 0.15 ) {
					$passed = true;
					$label  = 'VWAP reclaim (broke above, now ' . round( $dev_pct, 2 ) . '% above)';
					$score  = 1.0;
				} elseif ( $dev_pct > 0.1 ) {
					$passed = true;
					$label  = 'Above VWAP (' . round( $dev_pct, 2 ) . '%)';
					$score  = 0.7;
				} elseif ( $ltp > $vwap ) {
					$passed = true;
					$label  = 'Marginally above VWAP';
					$score  = 0.5;
				}
			}
		}

		return array(
			'passed'  => $passed,
			'label'   => $label,
			'score'   => $score,
			'ltp'     => $ltp,
			'vwap'    => $vwap,
			'dev_pct' => round( $dev_pct, 3 ),
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// CONDITION 8: ORDER FLOW IMBALANCE
	// Measures the aggression of buyers vs sellers at the current level.
	// Approximated by: volume × direction × price position within range.
	// A strong imbalance means one side is dominating — not just drifting.
	// ═════════════════════════════════════════════════════════════════════

	private function check_order_flow_imbalance( array $snapshot, array $snapshots, $direction ) {
		$ltp    = (float) $snapshot['ltp'];
		$open   = (float) ( $snapshot['ohlc']['open'] ?? $ltp );
		$high   = (float) ( $snapshot['ohlc']['high'] ?? $ltp );
		$low    = (float) ( $snapshot['ohlc']['low'] ?? $ltp );
		$close  = (float) ( $snapshot['ohlc']['close'] ?? $ltp );
		$volume = (float) ( $snapshot['volume'] ?? 0 );

		// Buying pressure estimation (Chaikin-style):
		// CLV (Close Location Value) = ((Close - Low) - (High - Close)) / (High - Low)
		// Range: -1 (all selling) to +1 (all buying).
		$range = max( 0.01, $high - $low );
		$clv   = ( ( $close - $low ) - ( $high - $close ) ) / $range;

		// Accumulation/Distribution proxy: CLV × Volume.
		$ad_proxy = $clv * $volume;

		// Momentum of imbalance: compare current CLV to recent.
		$clv_momentum = 0;
		if ( count( $snapshots ) >= 2 ) {
			// Approximate previous CLV from price action.
			$prev = end( $snapshots );
			$prev_ltp = (float) $prev['ltp'];
			// If price dropped since last scan → selling pressure.
			$clv_momentum = ( $ltp - $prev_ltp ) / max( 0.01, abs( $prev_ltp ) ) * 100;
		}

		// Advance/Decline as flow proxy (market-wide breadth).
		$ad_ratio = (float) ( $snapshot['adv_decline'] ?? 1.0 );

		$passed = false;
		$label  = 'Balanced flow';
		$score  = 0;

		if ( 'SELL' === $direction ) {
			// Want: strong selling imbalance (CLV < -0.3, declining CLV momentum, AD < 0.8).
			$sell_signals = 0;
			if ( $clv < -0.3 ) { $sell_signals++; }
			if ( $clv < -0.6 ) { $sell_signals++; }
			if ( $clv_momentum < -0.05 ) { $sell_signals++; }
			if ( $ad_ratio < 0.8 ) { $sell_signals++; }

			if ( $sell_signals >= 3 ) {
				$passed = true;
				$label  = 'Strong sell imbalance (CLV ' . round( $clv, 2 ) . ')';
				$score  = 1.0;
			} elseif ( $sell_signals >= 2 ) {
				$passed = true;
				$label  = 'Sell-side dominance';
				$score  = 0.7;
			} elseif ( $clv < -0.2 && $clv_momentum < 0 ) {
				$passed = true;
				$label  = 'Mild sell pressure building';
				$score  = 0.5;
			}
		} else {
			// Want: strong buying imbalance.
			$buy_signals = 0;
			if ( $clv > 0.3 ) { $buy_signals++; }
			if ( $clv > 0.6 ) { $buy_signals++; }
			if ( $clv_momentum > 0.05 ) { $buy_signals++; }
			if ( $ad_ratio > 1.2 ) { $buy_signals++; }

			if ( $buy_signals >= 3 ) {
				$passed = true;
				$label  = 'Strong buy imbalance (CLV ' . round( $clv, 2 ) . ')';
				$score  = 1.0;
			} elseif ( $buy_signals >= 2 ) {
				$passed = true;
				$label  = 'Buy-side dominance';
				$score  = 0.7;
			} elseif ( $clv > 0.2 && $clv_momentum > 0 ) {
				$passed = true;
				$label  = 'Mild buy pressure building';
				$score  = 0.5;
			}
		}

		return array(
			'passed'       => $passed,
			'label'        => $label,
			'score'        => $score,
			'clv'          => round( $clv, 3 ),
			'clv_momentum' => round( $clv_momentum, 4 ),
			'ad_ratio'     => round( $ad_ratio, 2 ),
		);
	}

	// ═════════════════════════════════════════════════════════════════════
	// MANDATORY: TRIGGER LEVEL BREACH CHECK
	// Even with 8/8 conditions met, if price hasn't actually crossed
	// the trigger level, we don't confirm. This prevents false signals
	// in choppy/range-bound markets.
	// ═════════════════════════════════════════════════════════════════════

	private function is_trigger_breached( array $snapshot, $trigger_level, $trigger_type ) {
		if ( ! $trigger_level || $trigger_level <= 0 ) {
			return true; // No trigger set — allow conditions to decide.
		}

		$ltp = (float) $snapshot['ltp'];

		if ( 'below' === $trigger_type ) {
			return $ltp <= $trigger_level;
		}
		if ( 'above' === $trigger_type ) {
			return $ltp >= $trigger_level;
		}

		return false;
	}

	// ═════════════════════════════════════════════════════════════════════
	// COMPOSITE SCORE (for logging / dashboard)
	// ═════════════════════════════════════════════════════════════════════

	/**
	 * Calculate a weighted composite confidence from trigger conditions.
	 * This gives a more nuanced view than just "5/8 met".
	 *
	 * @param array $details Per-condition detail arrays.
	 * @return float 0-100 composite trigger confidence.
	 */
	public function composite_score( array $details ) {
		$weights = array(
			'oi_shift_velocity'    => 20,  // Most predictive of institutional intent.
			'delta_acceleration'   => 18,  // Momentum matters for options.
			'gex_flip_proximity'   => 16,  // Regime change = explosive move.
			'volume_spike'         => 14,  // Can't hide volume.
			'order_flow_imbalance' => 12,  // Direct buy/sell pressure.
			'vwap_action'          => 10,  // Institutional benchmark.
			'multi_tf_alignment'   => 6,   // Confirmation, not trigger.
			'bid_ask_compression'  => 4,   // Liquidity, not direction.
		);

		$total_weight = array_sum( $weights );
		$earned       = 0;

		foreach ( $weights as $key => $w ) {
			if ( isset( $details[ $key ] ) && ! empty( $details[ $key ]['passed'] ) ) {
				$score = (float) ( $details[ $key ]['score'] ?? 0.5 );
				$earned += $w * $score;
			}
		}

		return round( ( $earned / $total_weight ) * 100, 1 );
	}
}
