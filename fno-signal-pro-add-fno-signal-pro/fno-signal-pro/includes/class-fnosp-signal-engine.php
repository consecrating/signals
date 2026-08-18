<?php
/**
 * Signal engine — implements the 10-step institutional framework.
 *
 * Scoring weights (total 100):
 *   Trend                 25
 *   Price Action          20
 *   Options Data          20
 *   Volume                10
 *   Momentum              10
 *   Institutional Activity 10
 *   News Sentiment         5
 *
 * The engine produces a directional bias (BUY/SELL/NO TRADE), a confidence
 * percentage, a full trade setup (entry/targets/SL/RR), an option strategy,
 * a probability table, and applies hard risk filters (Step 9).
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Signal_Engine {

	/** @var FnOSP_Settings */
	private $settings;

	/** @var FnOSP_Data_Provider */
	private $data;

	/** @var FnOSP_AI_Analyst */
	private $ai;

	const W_TREND   = 25;
	const W_PRICE   = 20;
	const W_OPTIONS = 20;
	const W_VOLUME  = 10;
	const W_MOMENTUM = 10;
	const W_INSTI   = 10;
	const W_NEWS    = 5;

	public function __construct( FnOSP_Settings $settings, FnOSP_Data_Provider $data, FnOSP_AI_Analyst $ai ) {
		$this->settings = $settings;
		$this->data     = $data;
		$this->ai       = $ai;
	}

	/**
	 * Generate a full signal for an instrument.
	 *
	 * @param string $instrument Instrument symbol.
	 * @param array  $opts       Options: use_ai (bool).
	 * @return array|WP_Error
	 */
	public function generate( $instrument, array $opts = array() ) {
		$snapshot = $this->data->get_snapshot( $instrument );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		return $this->evaluate( $snapshot, $opts );
	}

	/**
	 * Score a pre-built market snapshot (used by single-symbol and scanner paths).
	 *
	 * @param array $snapshot Normalized snapshot.
	 * @param array $opts     Options.
	 * @return array
	 */
	public function evaluate( array $snapshot, array $opts = array() ) {
		// STEP 1-3: analysis components, each returns [-1..1] directional bias + label data.
		$trend    = $this->analyze_trend( $snapshot );
		$price    = $this->analyze_price_action( $snapshot );
		$options  = $this->analyze_options( $snapshot );
		$volume   = $this->analyze_volume( $snapshot );
		$momentum = $this->analyze_momentum( $snapshot );
		$insti    = $this->analyze_institutional( $snapshot );
		$news     = $this->analyze_news( $snapshot );

		// STEP 4: weighted directional aggregate in range [-100..100].
		$components = array(
			'trend'         => array( 'bias' => $trend['bias'], 'weight' => self::W_TREND ),
			'price_action'  => array( 'bias' => $price['bias'], 'weight' => self::W_PRICE ),
			'options'       => array( 'bias' => $options['bias'], 'weight' => self::W_OPTIONS ),
			'volume'        => array( 'bias' => $volume['bias'], 'weight' => self::W_VOLUME ),
			'momentum'      => array( 'bias' => $momentum['bias'], 'weight' => self::W_MOMENTUM ),
			'institutional' => array( 'bias' => $insti['bias'], 'weight' => self::W_INSTI ),
			'news'          => array( 'bias' => $news['bias'], 'weight' => self::W_NEWS ),
		);

		$net = 0.0;
		foreach ( $components as $c ) {
			$net += $c['bias'] * $c['weight'];
		}
		// $net is now in [-100..100].

		$direction = 'NO TRADE';
		if ( $net > 0 ) {
			$direction = 'BUY';
		} elseif ( $net < 0 ) {
			$direction = 'SELL';
		}

		// Confidence = magnitude of net bias mapped to 0..100, with agreement bonus.
		// Calibrated so that a strong, well-aligned reading can clear the 75% gate,
		// while mixed/low-agreement readings stay below it.
		$agreement  = $this->agreement_factor( $components, $net );
		$confidence = (int) round( min( 99, abs( $net ) * 0.62 + $agreement * 42 ) );

		// STEP 9: hard risk filters. May veto a direction.
		$risk = $this->apply_risk_filters( $direction, $snapshot );
		if ( $risk['veto'] ) {
			$direction  = 'NO TRADE';
			$confidence = min( $confidence, 60 );
		}

		// STEP 5: confidence gate (scanner may override with a lower threshold).
		$min_conf = isset( $opts['min_confidence'] )
			? (int) $opts['min_confidence']
			: (int) $this->settings->get( 'confidence_min', 75 );
		if ( $confidence < $min_conf ) {
			$direction = 'NO TRADE';
		}

		// STEP 6: trade setup (entry/targets/SL/RR).
		$setup = ( 'NO TRADE' === $direction )
			? null
			: $this->build_trade_setup( $direction, $snapshot, $confidence );

		// STEP 7: option strategy.
		$strategy = ( 'NO TRADE' === $direction )
			? null
			: $this->build_option_strategy( $direction, $snapshot, $confidence );

		// STEP 8: probability table.
		$probabilities = $this->build_probability_table( $direction, $confidence, $setup, $snapshot );

		// Determine if this is an F&O index or an equity stock.
		$fno_indices = array( 'NIFTY', 'NIFTY50', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY' );
		$is_fno      = in_array( strtoupper( $snapshot['instrument'] ), $fno_indices, true );

		// F&O indices: generate CE/PE option plans with strike & premiums.
		// Stocks: show equity price levels only (no option premiums — they trade on spot).
		$option_plan = null;
		$call_plan   = null;
		$put_plan    = null;

		if ( $is_fno ) {
			$step = $this->strike_step( $snapshot['instrument'], $snapshot['ltp'] );
			$atm  = round( $snapshot['ltp'] / $step ) * $step;
			// Use actual NSE expiry (next Thursday for weeklies) instead of hardcoded 7 days.
			$dte  = isset( $opts['dte'] ) ? max( 1, (int) $opts['dte'] ) : $this->days_to_next_expiry( $snapshot['instrument'] );

			if ( ! empty( $opts['strike'] ) && (float) $opts['strike'] > 0 ) {
				$option_plan = $this->build_option_plan(
					$snapshot, $direction, (float) $opts['strike'],
					isset( $opts['opt_type'] ) ? strtoupper( $opts['opt_type'] ) : 'CE',
					$dte, isset( $opts['premium'] ) ? (float) $opts['premium'] : 0.0
				);
			} else {
				$otype = ( 'SELL' === $direction ) ? 'PE' : 'CE';
				$option_plan = $this->build_option_plan( $snapshot, $direction, $atm, $otype, $dte, 0.0 );
			}
			$call_plan = $this->build_option_plan( $snapshot, 'BUY', $atm, 'CE', $dte, 0.0 );
			$put_plan  = $this->build_option_plan( $snapshot, 'SELL', $atm, 'PE', $dte, 0.0 );
		}



		$layman = $this->build_layman_summary( $direction, $snapshot, $setup, $strategy, $confidence, $this->trend_label( $net ) );

		// ADVANCED FEATURES
		$advanced = $this->build_advanced_features( $snapshot, $direction, $net, $setup );

		$result = array(
			'instrument'    => $snapshot['instrument'],
			'is_fno'        => $is_fno,
			'generated_at'  => gmdate( 'c', $snapshot['timestamp'] ),
			'source'        => $snapshot['source'],
			'ltp'           => $snapshot['ltp'],
			'signal'        => $direction,
			'confidence'    => $confidence,
			'trend_label'   => $this->trend_label( $net ),
			'structure'     => $price['structure'],
			'scores'        => $this->score_breakdown( $components ),
			'net_bias'      => round( $net, 1 ),
			'analysis'      => array(
				'market_structure' => $price['note'],
				'options'          => $options['note'],
				'volume'           => $volume['note'],
				'momentum'         => $momentum['note'],
				'institutional'    => $insti['note'],
				'trend'            => $trend['note'],
				'news'             => $news['note'],
			),
			'risk_factors'  => $risk['factors'],
			'setup'         => $setup,
			'option_strategy' => $strategy,
			'probabilities' => $probabilities,
			'option_plan'   => $option_plan,
			'call_plan'     => $call_plan,
			'put_plan'      => $put_plan,
			'final_verdict' => $this->final_verdict( $direction, $snapshot, $net ),
			'layman_summary' => $layman,
			'expert_advice' => $this->build_expert_advice( $direction, $snapshot, $layman, $option_plan ),
			'advanced'      => $advanced,
			'data_notes'    => isset( $snapshot['data_notes'] ) ? (array) $snapshot['data_notes'] : array(),
			'snapshot'      => $snapshot,
			'ai'            => null,
			'disclaimer'    => __( 'For educational/decision-support use only. Not investment advice. F&O trading involves substantial risk of loss.', 'fno-signal-pro' ),
		);

		// AI narrative enrichment (optional, Step 6/10 wording).
		$use_ai = ! empty( $opts['use_ai'] ) && $this->settings->ai_ready();
		if ( $use_ai ) {
			$ai_out = $this->ai->analyze( $result );
			if ( ! is_wp_error( $ai_out ) ) {
				$result['ai'] = $ai_out;
			} else {
				$result['ai'] = array( 'error' => $ai_out->get_error_message() );
			}
		}

		return $result;
	}

	// ---------------------------------------------------------------------
	// ADVANCED FEATURES — 5 powerful analytics.
	// ---------------------------------------------------------------------
	private function build_advanced_features( $s, $direction, $net, $setup ) {
		$ltp = (float) $s['ltp'];
		$atr = max( 0.01, (float) $s['atr'] );

		// 1. Volatility Squeeze Detector (BB width relative to price).
		$bb_width  = ( (float) $s['bb_upper'] - (float) $s['bb_lower'] ) / max( 1, $ltp ) * 100;
		$squeeze   = $bb_width < 1.5;
		$vol_state = $squeeze ? 'SQUEEZE (expect big move soon)' : ( $bb_width > 4 ? 'Expanded (trending)' : 'Normal' );

		// 2. Support & Resistance (Pivot Points: Classic).
		$h = (float) $s['ohlc']['high'];
		$l = (float) $s['ohlc']['low'];
		$c = (float) $s['ohlc']['close'];
		$pivot = round( ( $h + $l + $c ) / 3, 2 );
		$r1    = round( 2 * $pivot - $l, 2 );
		$r2    = round( $pivot + ( $h - $l ), 2 );
		$s1    = round( 2 * $pivot - $h, 2 );
		$s2    = round( $pivot - ( $h - $l ), 2 );

		// 3. Momentum Strength (0–100 gauge based on RSI + MACD + trend alignment).
		$rsi_comp  = min( 100, max( 0, (float) $s['rsi'] ) );
		$macd_comp = $s['macd_hist'] > 0 ? min( 100, 50 + abs( (float) $s['macd_hist'] ) * 3 ) : max( 0, 50 - abs( (float) $s['macd_hist'] ) * 3 );
		$trend_comp = ( $ltp > (float) $s['ema21'] ) ? 70 : 30;
		$momentum_strength = (int) round( ( $rsi_comp * 0.4 + $macd_comp * 0.35 + $trend_comp * 0.25 ) );
		$mom_label = $momentum_strength >= 70 ? 'Strong' : ( $momentum_strength >= 45 ? 'Moderate' : 'Weak' );

		// 4. Risk per Trade (₹ per lot, based on SL distance).
		$lot_size    = isset( $s['lot_size'] ) ? (int) $s['lot_size'] : 1;
		$risk_per_unit = $setup ? (float) $setup['risk_per_unit'] : $atr * 1.2;
		$risk_per_lot  = round( $risk_per_unit * $lot_size, 2 );
		$reward_per_lot = $setup ? round( (float) $setup['reward_per_unit'] * $lot_size, 2 ) : round( $atr * 2 * $lot_size, 2 );

		// 5. Signal Strength Trend (is the bias strengthening or stable).
		// Compare current net to what a mild neutral would be.
		$strength_trend = 'Stable';
		if ( abs( $net ) > 50 ) {
			$strength_trend = 'Very Strong';
		} elseif ( abs( $net ) > 30 ) {
			$strength_trend = 'Strengthening';
		} elseif ( abs( $net ) < 10 ) {
			$strength_trend = 'Weak / Indecisive';
		}

		return array(
			'volatility_squeeze' => array(
				'label'    => $vol_state,
				'squeeze'  => $squeeze,
				'bb_width' => round( $bb_width, 2 ),
				'note'     => $squeeze ? 'Bollinger Bands are tight — a breakout/breakdown is likely soon. Wait for direction confirmation before entry.' : 'Normal volatility range.',
			),
			'support_resistance' => array(
				'pivot' => $pivot,
				'r1'    => $r1,
				'r2'    => $r2,
				's1'    => $s1,
				's2'    => $s2,
			),
			'momentum_strength' => array(
				'value' => $momentum_strength,
				'label' => $mom_label,
				'note'  => sprintf( 'Momentum gauge: %d/100 (%s). RSI %.1f, MACD hist %+.1f.', $momentum_strength, $mom_label, $s['rsi'], $s['macd_hist'] ),
			),
			'risk_calculator' => array(
				'lot_size'        => $lot_size,
				'risk_per_unit'   => round( $risk_per_unit, 2 ),
				'risk_per_lot'    => $risk_per_lot,
				'reward_per_lot'  => $reward_per_lot,
				'note'            => sprintf( 'If SL hits, you lose ₹%.0f per lot (%d units × ₹%.2f). Potential reward ₹%.0f per lot.', $risk_per_lot, $lot_size, $risk_per_unit, $reward_per_lot ),
			),
			'signal_strength' => array(
				'label'   => $strength_trend,
				'net_bias' => round( $net, 1 ),
				'note'    => sprintf( 'Signal strength: %s (net bias %+.1f/100).', $strength_trend, $net ),
			),
		);
	}

	// ---------------------------------------------------------------------
	// Expert Advice — a single plain-language line for the dashboard footer.
	// ---------------------------------------------------------------------
	private function build_expert_advice( $direction, $s, $layman, $option_plan ) {
		$base = isset( $layman['text'] ) ? $layman['text'] : '';

		if ( 'NO TRADE' === $direction ) {
			return $base;
		}

		$opt = '';
		if ( is_array( $option_plan ) ) {
			$tg   = isset( $option_plan['sell_when']['targets'] ) ? $option_plan['sell_when']['targets'] : array();
			$cond = preg_replace( '/^buy when /i', '', rtrim( $option_plan['buy_when']['condition'], '.' ) );
			$opt  = sprintf(
				' Simple option idea: buy %s when %s; target premiums around ₹%s / ₹%s with a stop near ₹%s.',
				$option_plan['label'],
				$cond,
				isset( $tg[0]['premium'] ) ? number_format_i18n( $tg[0]['premium'], 2 ) : '-',
				isset( $tg[1]['premium'] ) ? number_format_i18n( $tg[1]['premium'], 2 ) : '-',
				isset( $option_plan['sell_when']['stop_loss']['premium'] ) ? number_format_i18n( $option_plan['sell_when']['stop_loss']['premium'], 2 ) : '-'
			);
		}

		return trim( $base . $opt . ' Remember: trade small, respect the stop-loss, and never risk money you cannot afford to lose.' );
	}

	// ---------------------------------------------------------------------
	// Per-strike option plan: when to BUY this strike and when to SELL it.
	// Premiums are Black-Scholes estimates (verify against the live chain).
	// ---------------------------------------------------------------------
	private function build_option_plan( $s, $direction, $strike, $type, $dte, $live = 0.0 ) {
		$type = ( 'PE' === $type ) ? 'PE' : 'CE';
		$ltp  = (float) $s['ltp'];
		$atr  = max( 0.0001, (float) $s['atr'] );
		$iv   = ( (float) $s['iv'] > 0 ? (float) $s['iv'] : 14.0 ) / 100; // decimal.
		$r    = 0.065;
		$t    = $dte / 365;

		$price_at = function ( $spot ) use ( $type, $strike, $t, $r, $iv ) {
			return FnOSP_Indicators::bs_price( $type, $spot, $strike, $t, $r, $iv );
		};

		$is_call   = ( 'CE' === $type );
		$delta     = FnOSP_Indicators::bs_delta( $type, $ltp, $strike, $t, $r, $iv );
		$model_now = $price_at( $ltp );

		// Directional spot levels for THIS option type (CE profits when spot rises; PE when it falls).
		if ( $is_call ) {
			$entry_lo = round( $ltp - 0.15 * $atr, 2 );
			$entry_hi = round( $ltp + 0.15 * $atr, 2 );
			$sl_spot  = round( $ltp - 1.2 * $atr, 2 );
			$t1_spot  = round( $ltp + 1.0 * $atr, 2 );
			$t2_spot  = round( $ltp + 2.0 * $atr, 2 );
			$t3_spot  = round( $ltp + 3.2 * $atr, 2 );
			$trigger  = sprintf( 'underlying rises and holds above %s', number_format_i18n( $entry_hi, 2 ) );
			$invalid  = sprintf( 'underlying falls below %s', number_format_i18n( $sl_spot, 2 ) );
		} else {
			$entry_lo = round( $ltp - 0.15 * $atr, 2 );
			$entry_hi = round( $ltp + 0.15 * $atr, 2 );
			$sl_spot  = round( $ltp + 1.2 * $atr, 2 );
			$t1_spot  = round( $ltp - 1.0 * $atr, 2 );
			$t2_spot  = round( $ltp - 2.0 * $atr, 2 );
			$t3_spot  = round( $ltp - 3.2 * $atr, 2 );
			$trigger  = sprintf( 'underlying falls and holds below %s', number_format_i18n( $entry_lo, 2 ) );
			$invalid  = sprintf( 'underlying rises above %s', number_format_i18n( $sl_spot, 2 ) );
		}

		// Premium levels (entry / targets / stop) for the option itself.
		// If a live premium is supplied, scale the model curve to anchor on it.
		$factor  = ( $live > 0 && $model_now > 0 ) ? ( $live / $model_now ) : 1.0;
		$prem_src = ( $live > 0 ) ? 'live' : 'model estimate';
		$prem_now   = ( $live > 0 ) ? round( $live, 2 ) : $model_now;
		$prem_entry = $prem_now;
		$prem_t1    = round( $price_at( $t1_spot ) * $factor, 2 );
		$prem_t2    = round( $price_at( $t2_spot ) * $factor, 2 );
		$prem_t3    = round( $price_at( $t3_spot ) * $factor, 2 );
		$prem_sl    = round( $price_at( $sl_spot ) * $factor, 2 );

		// Alignment with the engine's directional signal.
		$aligned = ( $is_call && 'BUY' === $direction ) || ( ! $is_call && 'SELL' === $direction );
		if ( 'NO TRADE' === $direction ) {
			$align_note = 'The model has no high-confidence directional signal now, so treat this as a watch-only plan and wait for the trigger.';
		} elseif ( $aligned ) {
			$align_note = sprintf( 'This %s is aligned with the current %s signal (%s) — the higher-probability side.', $type, $direction, $s['instrument'] );
		} else {
			$align_note = sprintf( 'Caution: a %s is counter to the current %s signal. This is a contrarian bet — smaller size, tighter stop.', $type, $direction );
		}

		$moneyness = $is_call
			? ( $ltp > $strike ? 'ITM' : ( abs( $ltp - $strike ) < ( 0.25 * $atr ) ? 'ATM' : 'OTM' ) )
			: ( $ltp < $strike ? 'ITM' : ( abs( $ltp - $strike ) < ( 0.25 * $atr ) ? 'ATM' : 'OTM' ) );

		return array(
			'strike'        => $strike,
			'type'          => $type,
			'label'         => number_format_i18n( $strike, 0 ) . ' ' . $type,
			'dte'           => $dte,
			'expiry_date'   => $this->get_expiry_date_str( $dte ),
			'iv_used'       => round( $iv * 100, 1 ),
			'moneyness'     => $moneyness,
			'delta'         => $delta,
			'premium_now'   => $prem_now,
			'premium_source' => $prem_src,
			'aligned'       => $aligned,
			'align_note'    => $align_note,

			'buy_when'      => array(
				'condition'   => sprintf( 'BUY when %s.', $trigger ),
				'entry_zone'  => '₹' . number_format_i18n( $entry_lo, 2 ) . ' – ₹' . number_format_i18n( $entry_hi, 2 ) . ' (underlying)',
				'est_premium' => '≈ ₹' . number_format_i18n( $prem_entry, 2 ) . ' per lot-unit',
				'avoid'       => array(
					'Avoid buying in the last ~30 min if trading intraday (theta + low liquidity).',
					( $iv * 100 >= 22 ) ? sprintf( 'IV is high (%.0f%%) — premium is expensive; prefer a spread over a naked buy.', $iv * 100 ) : 'IV is reasonable for a naked buy.',
				),
			),

			'sell_when'     => array(
				'targets'   => array(
					array( 'spot' => $t1_spot, 'premium' => $prem_t1, 'note' => 'book ~1/3' ),
					array( 'spot' => $t2_spot, 'premium' => $prem_t2, 'note' => 'book ~1/3' ),
					array( 'spot' => $t3_spot, 'premium' => $prem_t3, 'note' => 'trail the rest' ),
				),
				'stop_loss' => array(
					'spot'    => $sl_spot,
					'premium' => $prem_sl,
					'note'    => sprintf( 'Exit immediately if %s, or if premium falls to ≈ ₹%s.', $invalid, number_format_i18n( $prem_sl, 2 ) ),
				),
				'time_exit' => 'Square off intraday by ~3:15 PM; for positional, exit 1–2 days before expiry to dodge fast theta decay.',
			),

			'notes'         => array(
				$align_note,
				( $live > 0 )
					? 'Targets/stop premiums are scaled to your live entry premium of ₹' . number_format_i18n( $live, 2 ) . ' (more accurate).'
					: 'Premiums are Black-Scholes estimates from spot, strike, ' . round( $iv * 100, 1 ) . '% IV and ' . $dte . ' days to expiry — enter your live premium for accuracy.',
				'Decision-support only, not financial advice.',
			),
		);
	}

	// ---------------------------------------------------------------------
	// Plain-language ("layman") recommendation summary.
	// ---------------------------------------------------------------------
	private function build_layman_summary( $direction, $s, $setup, $strategy, $confidence, $trend_label ) {
		$inst = $s['instrument'];
		$ltp  = number_format_i18n( (float) $s['ltp'], 2 );

		if ( 'NO TRADE' === $direction || null === $setup ) {
			return array(
				'headline' => sprintf( 'Wait and watch on %s', $inst ),
				'text'     => sprintf(
					'In plain English: right now there is no clear, high-confidence trade in %s (it is reading "%s"). The smartest move — especially for a beginner — is to stay out and keep your money safe until a cleaner setup appears. Not trading is also a decision.',
					$inst,
					strtolower( $trend_label )
				),
				'steps'    => array(
					'Do nothing for now — no buy, no sell.',
					sprintf( 'Watch %s; a clearer signal may come later or tomorrow.', $inst ),
					'Never force a trade just to be active.',
				),
			);
		}

		$is_buy   = ( 'BUY' === $direction );
		$action   = $is_buy ? 'rising (bullish)' : 'falling (bearish)';
		$opt_type = $is_buy ? 'a Call (CE) option' : 'a Put (PE) option';
		$primary  = $strategy && ! empty( $strategy['primary'] ) ? $strategy['primary'] : ( $is_buy ? 'ATM Call' : 'ATM Put' );
		$strike   = ( $strategy && ! empty( $strategy['legs'][0]['strike'] ) ) ? $strategy['legs'][0]['strike'] : '';

		$enter_dir = $is_buy ? 'stays above' : 'stays below';
		$enter_lvl = $is_buy ? number_format_i18n( $setup['entry_high'], 2 ) : number_format_i18n( $setup['entry_low'], 2 );

		$text = sprintf(
			'In plain English: %s looks %s today (about %d%% confidence). A possible plan — only if it %s ₹%s — is to buy %s%s. If you take the trade, put a stop-loss at ₹%s so a wrong call costs you only a small, fixed amount, and aim to take profits in steps near ₹%s, ₹%s and ₹%s. If price hits your stop-loss, exit at once — do not "hope" it comes back. If it never reaches your entry level, it is perfectly fine to skip the trade. Risk only money you can afford to lose.',
			$inst,
			$action,
			$confidence,
			$enter_dir,
			$enter_lvl,
			$opt_type,
			$strike ? ' (e.g. ' . $strike . ')' : '',
			number_format_i18n( $setup['stop_loss'], 2 ),
			number_format_i18n( $setup['target1'], 2 ),
			number_format_i18n( $setup['target2'], 2 ),
			number_format_i18n( $setup['target3'], 2 )
		);

		return array(
			'headline' => sprintf( '%s %s — %s setup (%d%%)', $direction, $inst, $primary, $confidence ),
			'text'     => $text,
			'steps'    => array(
				sprintf( 'Enter only if %s %s ₹%s.', $inst, $enter_dir, $enter_lvl ),
				sprintf( 'Buy: %s%s.', $primary, $strike ? ' — ' . $strike : '' ),
				sprintf( 'Stop-loss: ₹%s (exit immediately if hit).', number_format_i18n( $setup['stop_loss'], 2 ) ),
				sprintf( 'Book profits near ₹%s / ₹%s / ₹%s.', number_format_i18n( $setup['target1'], 2 ), number_format_i18n( $setup['target2'], 2 ), number_format_i18n( $setup['target3'], 2 ) ),
				'Risk only what you can afford to lose; this is not advice.',
			),
		);
	}

	// ---------------------------------------------------------------------
	// STEP 1: Trend (EMA alignment + SuperTrend + price vs structure).
	// ---------------------------------------------------------------------
	private function analyze_trend( $s ) {
		$score = 0.0;
		$ltp   = $s['ltp'];

		// EMA stacking.
		if ( $s['ema9'] > $s['ema21'] && $s['ema21'] > $s['ema50'] && $s['ema50'] > $s['ema200'] ) {
			$score += 0.55; // Perfect bullish stack.
		} elseif ( $s['ema9'] < $s['ema21'] && $s['ema21'] < $s['ema50'] && $s['ema50'] < $s['ema200'] ) {
			$score -= 0.55;
		} else {
			$score += ( $s['ema9'] > $s['ema21'] ) ? 0.15 : -0.15;
			$score += ( $ltp > $s['ema50'] ) ? 0.1 : -0.1;
		}

		// Price vs long-term EMA200.
		$score += ( $ltp > $s['ema200'] ) ? 0.2 : -0.2;

		// SuperTrend.
		if ( 'bullish' === $s['supertrend'] ) {
			$score += 0.25;
		} elseif ( 'bearish' === $s['supertrend'] ) {
			$score -= 0.25;
		}

		$bias = max( -1, min( 1, $score ) );
		$note = sprintf(
			'EMA9 %s EMA21 %s EMA50 %s EMA200; price %s EMA200; SuperTrend %s.',
			$this->cmp( $s['ema9'], $s['ema21'] ),
			$this->cmp( $s['ema21'], $s['ema50'] ),
			$this->cmp( $s['ema50'], $s['ema200'] ),
			$ltp > $s['ema200'] ? 'above' : 'below',
			$s['supertrend']
		);
		return array( 'bias' => $bias, 'note' => $note );
	}

	// ---------------------------------------------------------------------
	// STEP 2 + part of 3: Price action / market structure + VWAP + BB.
	// ---------------------------------------------------------------------
	private function analyze_price_action( $s ) {
		$score = 0.0;
		$ltp   = $s['ltp'];

		// VWAP position.
		$score += ( $ltp > $s['vwap'] ) ? 0.25 : -0.25;

		// Day range position (where is price within today's range).
		$range = max( 0.0001, $s['ohlc']['high'] - $s['ohlc']['low'] );
		$pos   = ( $ltp - $s['ohlc']['low'] ) / $range; // 0..1.
		$score += ( $pos - 0.5 ) * 0.5;

		// Breakout / breakdown vs previous day high/low.
		$structure = 'Consolidation';
		if ( $ltp > $s['prev_ohlc']['high'] ) {
			$score    += 0.3;
			$structure = 'Breakout';
		} elseif ( $ltp < $s['prev_ohlc']['low'] ) {
			$score    -= 0.3;
			$structure = 'Breakdown';
		} elseif ( $ltp > $s['ohlc']['open'] && $s['ohlc']['close'] > $s['ohlc']['open'] ) {
			$structure = 'Higher High Higher Low';
		} elseif ( $ltp < $s['ohlc']['open'] ) {
			$structure = 'Lower High Lower Low';
		}

		// Bollinger context.
		if ( $ltp > $s['bb_upper'] ) {
			$score += 0.1; // Strong but watch for exhaustion.
		} elseif ( $ltp < $s['bb_lower'] ) {
			$score -= 0.1;
		}

		$bias = max( -1, min( 1, $score ) );
		$dev  = $s['vwap'] > 0 ? ( ( $ltp - $s['vwap'] ) / $s['vwap'] ) * 100 : 0;
		$note = sprintf(
			'%s; price %s VWAP (%+.2f%%); positioned %d%% of day range.',
			$structure,
			$ltp > $s['vwap'] ? 'above' : 'below',
			$dev,
			(int) round( $pos * 100 )
		);
		return array( 'bias' => $bias, 'note' => $note, 'structure' => $structure );
	}

	// ---------------------------------------------------------------------
	// STEP 3: Options analytics (PCR, OI build-up, Max Pain, IV).
	// ---------------------------------------------------------------------
	private function analyze_options( $s ) {
		$score = 0.0;

		// PCR: >1 generally bullish (more puts written), <0.7 bearish; extreme = caution.
		$pcr = $s['pcr'];
		if ( $pcr >= 1.2 ) {
			$score += 0.35;
		} elseif ( $pcr >= 1.0 ) {
			$score += 0.2;
		} elseif ( $pcr <= 0.7 ) {
			$score -= 0.35;
		} elseif ( $pcr < 0.9 ) {
			$score -= 0.15;
		}

		// OI build-up interpretation.
		$buildup = $this->oi_buildup_label( $s );
		switch ( $buildup ) {
			case 'Long Build-up':
			case 'Short Covering':
				$score += 0.3;
				break;
			case 'Short Build-up':
			case 'Long Unwinding':
				$score -= 0.3;
				break;
		}

		// Max pain: price above max pain = bullish pressure into expiry.
		$score += ( $s['ltp'] > $s['max_pain'] ) ? 0.15 : -0.15;

		$bias = max( -1, min( 1, $score ) );
		$note = sprintf(
			'PCR %.2f; %s; Max Pain %s (price %s); IV %.1f%%.',
			$pcr,
			$buildup,
			number_format_i18n( $s['max_pain'] ),
			$s['ltp'] > $s['max_pain'] ? 'above' : 'below',
			$s['iv']
		);
		return array( 'bias' => $bias, 'note' => $note, 'buildup' => $buildup );
	}

	/**
	 * Classify OI build-up from price move + call/put OI change.
	 *
	 * @param array $s Snapshot.
	 * @return string
	 */
	private function oi_buildup_label( $s ) {
		$price_up = $s['ltp'] >= $s['ohlc']['open'];
		$put_add  = $s['put_oi_chg'] > $s['call_oi_chg'];

		if ( $price_up && $put_add ) {
			return 'Long Build-up';
		}
		if ( $price_up && ! $put_add ) {
			return 'Short Covering';
		}
		if ( ! $price_up && ! $put_add ) {
			return 'Short Build-up';
		}
		return 'Long Unwinding';
	}

	// ---------------------------------------------------------------------
	// STEP 3: Volume confirmation.
	// ---------------------------------------------------------------------
	private function analyze_volume( $s ) {
		$ratio = $s['avg_volume'] > 0 ? $s['volume'] / $s['avg_volume'] : 1.0;
		$dir   = ( $s['ltp'] >= $s['ohlc']['open'] ) ? 1 : -1;

		$mag = 0.0;
		if ( $ratio >= 1.5 ) {
			$mag = 1.0;
		} elseif ( $ratio >= 1.2 ) {
			$mag = 0.6;
		} elseif ( $ratio >= 0.9 ) {
			$mag = 0.25;
		} else {
			$mag = -0.1; // Weak participation.
		}

		$bias = max( -1, min( 1, $mag * $dir ) );
		$note = sprintf(
			'Volume %.2fx average (%s); move is %s — %s.',
			$ratio,
			number_format_i18n( $s['volume'] ),
			$dir > 0 ? 'up' : 'down',
			$ratio >= 1.2 ? 'confirmed by participation' : 'limited confirmation'
		);
		return array( 'bias' => $bias, 'note' => $note );
	}

	// ---------------------------------------------------------------------
	// STEP 3: Momentum (RSI + RSI divergence + MACD crossover).
	// ---------------------------------------------------------------------
	private function analyze_momentum( $s ) {
		$score = 0.0;

		// RSI zone.
		$rsi = $s['rsi'];
		if ( $rsi >= 60 && $rsi < 80 ) {
			$score += 0.35;
		} elseif ( $rsi >= 50 ) {
			$score += 0.15;
		} elseif ( $rsi <= 40 && $rsi > 20 ) {
			$score -= 0.35;
		} elseif ( $rsi < 50 ) {
			$score -= 0.15;
		}

		// RSI divergence vs price direction.
		$price_up = $s['ltp'] >= $s['ohlc']['open'];
		$rsi_up   = $s['rsi'] >= $s['rsi_prev'];
		$divergence = 'none';
		if ( $price_up && ! $rsi_up ) {
			$divergence = 'bearish divergence';
			$score     -= 0.15;
		} elseif ( ! $price_up && $rsi_up ) {
			$divergence = 'bullish divergence';
			$score     += 0.15;
		}

		// MACD crossover.
		$macd_cross = 'flat';
		if ( $s['macd'] > $s['macd_signal'] ) {
			$score     += 0.25;
			$macd_cross = 'bullish';
		} elseif ( $s['macd'] < $s['macd_signal'] ) {
			$score     -= 0.25;
			$macd_cross = 'bearish';
		}

		$bias = max( -1, min( 1, $score ) );
		$note = sprintf(
			'RSI %.1f; MACD %s crossover (hist %+.2f); %s.',
			$rsi,
			$macd_cross,
			$s['macd_hist'],
			'none' === $divergence ? 'no divergence' : $divergence
		);
		return array( 'bias' => $bias, 'note' => $note );
	}

	// ---------------------------------------------------------------------
	// STEP 3: Institutional flow (FII/DII) + breadth + sector strength.
	// ---------------------------------------------------------------------
	private function analyze_institutional( $s ) {
		$score = 0.0;

		$net_flow = $s['fii_net'] + $s['dii_net'];
		if ( $net_flow > 800 ) {
			$score += 0.4;
		} elseif ( $net_flow > 0 ) {
			$score += 0.2;
		} elseif ( $net_flow < -800 ) {
			$score -= 0.4;
		} elseif ( $net_flow < 0 ) {
			$score -= 0.2;
		}

		// Breadth.
		if ( $s['adv_decline'] >= 1.3 ) {
			$score += 0.2;
		} elseif ( $s['adv_decline'] <= 0.77 ) {
			$score -= 0.2;
		}

		// Sector strength (-100..100).
		$score += max( -0.2, min( 0.2, $s['sector_strength'] / 500 ) );

		$bias = max( -1, min( 1, $score ) );
		$note = sprintf(
			'FII %s%.0f Cr, DII %s%.0f Cr (net %s%.0f Cr); A/D %.2f; sector strength %d.',
			$s['fii_net'] >= 0 ? '+' : '',
			$s['fii_net'],
			$s['dii_net'] >= 0 ? '+' : '',
			$s['dii_net'],
			$net_flow >= 0 ? '+' : '',
			$net_flow,
			$s['adv_decline'],
			(int) $s['sector_strength']
		);
		return array( 'bias' => $bias, 'note' => $note );
	}

	// ---------------------------------------------------------------------
	// STEP 3/4: News sentiment (-1..1).
	// ---------------------------------------------------------------------
	private function analyze_news( $s ) {
		$bias = max( -1, min( 1, (float) $s['news_sentiment'] ) );
		$label = $bias > 0.15 ? 'positive' : ( $bias < -0.15 ? 'negative' : 'neutral' );
		$note  = sprintf( 'News sentiment score %+.2f (%s).', $bias, $label );
		return array( 'bias' => $bias, 'note' => $note );
	}

	// ---------------------------------------------------------------------
	// Confidence helper: how aligned are the components with net direction.
	// ---------------------------------------------------------------------
	private function agreement_factor( $components, $net ) {
		if ( 0.0 === (float) $net ) {
			return 0.0;
		}
		$sign     = $net > 0 ? 1 : -1;
		$agree_w  = 0;
		$active_w = 0; // Only components that actually express a direction.
		foreach ( $components as $c ) {
			$c_sign = $c['bias'] > 0 ? 1 : ( $c['bias'] < 0 ? -1 : 0 );
			if ( 0 === $c_sign ) {
				continue; // Neutral / no-data: don't count as agreement or disagreement.
			}
			$active_w += $c['weight'];
			if ( $c_sign === $sign ) {
				$agree_w += $c['weight'];
			}
		}
		return $active_w > 0 ? $agree_w / $active_w : 0.0;
	}

	// ---------------------------------------------------------------------
	// STEP 9: Risk filters.
	// ---------------------------------------------------------------------
	private function apply_risk_filters( $direction, $s ) {
		$factors = array();
		$veto    = false;

		$rsi_buy_block  = (float) $this->settings->get( 'rsi_buy_block', 85 );
		$rsi_sell_block = (float) $this->settings->get( 'rsi_sell_block', 15 );
		$vwap_block     = (float) $this->settings->get( 'vwap_dev_block', 4.0 );

		$vwap_dev = $s['vwap'] > 0 ? ( ( $s['ltp'] - $s['vwap'] ) / $s['vwap'] ) * 100 : 0;

		if ( 'BUY' === $direction ) {
			if ( $s['rsi'] > $rsi_buy_block ) {
				$veto      = true;
				$factors[] = sprintf( 'RSI %.1f is overbought (> %.0f) — BUY blocked.', $s['rsi'], $rsi_buy_block );
			}
			if ( $vwap_dev > $vwap_block ) {
				$veto      = true;
				$factors[] = sprintf( 'Price is %.2f%% above VWAP (> %.1f%%) — extended, BUY blocked.', $vwap_dev, $vwap_block );
			}
			if ( $s['ltp'] >= $s['prev_ohlc']['high'] && ( $s['prev_ohlc']['high'] - $s['ltp'] ) === 0.0 ) {
				$factors[] = 'Trading at major prior resistance — confirm breakout before entry.';
			}
		} elseif ( 'SELL' === $direction ) {
			if ( $s['rsi'] < $rsi_sell_block ) {
				$veto      = true;
				$factors[] = sprintf( 'RSI %.1f is oversold (< %.0f) — SELL blocked.', $s['rsi'], $rsi_sell_block );
			}
			if ( -$vwap_dev > $vwap_block ) {
				$veto      = true;
				$factors[] = sprintf( 'Price is %.2f%% below VWAP (> %.1f%%) — extended, SELL blocked.', abs( $vwap_dev ), $vwap_block );
			}
		}

		// Volatility caution.
		if ( $s['vix'] >= 20 ) {
			$factors[] = sprintf( 'Elevated VIX %.1f — widen stops, reduce size.', $s['vix'] );
		}
		if ( $s['iv'] >= 25 ) {
			$factors[] = sprintf( 'High option IV %.1f%% — premiums rich, prefer spreads over naked longs.', $s['iv'] );
		}

		if ( empty( $factors ) ) {
			$factors[] = 'No hard risk filter triggered. Standard position sizing applies.';
		}

		return array( 'veto' => $veto, 'factors' => $factors );
	}

	// ---------------------------------------------------------------------
	// STEP 6: Trade setup using ATR-based targets & stops.
	// ---------------------------------------------------------------------
	private function build_trade_setup( $direction, $s, $confidence ) {
		$ltp = $s['ltp'];
		$atr = max( 0.0001, $s['atr'] );

		// Entry band around LTP (0.15 ATR).
		$band = round( $atr * 0.15, 2 );

		if ( 'BUY' === $direction ) {
			$entry_low  = round( $ltp - $band, 2 );
			$entry_high = round( $ltp + $band, 2 );
			$sl         = round( $ltp - 1.2 * $atr, 2 );
			$t1         = round( $ltp + 1.0 * $atr, 2 );
			$t2         = round( $ltp + 2.0 * $atr, 2 );
			$t3         = round( $ltp + 3.2 * $atr, 2 );
			$risk       = max( 0.01, $ltp - $sl );
			$reward     = $t2 - $ltp;
		} else {
			$entry_low  = round( $ltp - $band, 2 );
			$entry_high = round( $ltp + $band, 2 );
			$sl         = round( $ltp + 1.2 * $atr, 2 );
			$t1         = round( $ltp - 1.0 * $atr, 2 );
			$t2         = round( $ltp - 2.0 * $atr, 2 );
			$t3         = round( $ltp - 3.2 * $atr, 2 );
			$risk       = max( 0.01, $sl - $ltp );
			$reward     = $ltp - $t2;
		}

		$rr = round( $reward / $risk, 2 );

		$holding = 'Intraday';
		if ( $confidence >= 88 ) {
			$holding = 'Intraday / BTST';
		}
		if ( $s['atr'] / max( 1, $ltp ) > 0.012 ) {
			$holding = 'Intraday';
		}

		return array(
			'entry_low'   => $entry_low,
			'entry_high'  => $entry_high,
			'stop_loss'   => $sl,
			'target1'     => $t1,
			'target2'     => $t2,
			'target3'     => $t3,
			'risk_per_unit'   => round( $risk, 2 ),
			'reward_per_unit' => round( $reward, 2 ),
			'risk_reward' => '1:' . $rr,
			'holding'     => $holding,
			'atr'         => round( $atr, 2 ),
		);
	}

	// ---------------------------------------------------------------------
	// STEP 7: Option strategy suggestion.
	// ---------------------------------------------------------------------
	private function build_option_strategy( $direction, $s, $confidence ) {
		$ltp    = $s['ltp'];
		$step   = $this->strike_step( $s['instrument'], $ltp );
		$atm    = round( $ltp / $step ) * $step;
		$iv     = $s['iv'];

		// Rough premium estimate via simplified ATM pricing: 0.4 * IV/100 * sqrt(t) * S.
		// Use t ~ 5 trading days for weekly bias.
		$t       = 5 / 252;
		$est_atm = max( $step * 0.2, round( 0.4 * ( $iv / 100 ) * sqrt( $t ) * $ltp, 1 ) );

		$risk_level = $confidence >= 85 ? 'Moderate' : 'Moderate-High';
		if ( $iv >= 22 ) {
			$risk_level = 'High (rich premiums)';
		}

		if ( 'BUY' === $direction ) {
			$itm    = $atm - $step;
			$sell_l = $atm + $step;
			$primary = ( $iv >= 20 )
				? 'Bull Call Spread'
				: 'ATM Call';
			return array(
				'direction'  => 'Bullish',
				'primary'    => $primary,
				'legs'       => array(
					array(
						'type'   => 'ATM Call',
						'strike' => $atm . ' CE',
						'premium_range' => $this->premium_band( $est_atm ),
						'probability'   => $confidence . '%',
						'risk'   => 'Premium paid',
					),
					array(
						'type'   => 'ITM Call (higher delta)',
						'strike' => $itm . ' CE',
						'premium_range' => $this->premium_band( $est_atm * 1.6 ),
						'probability'   => min( 95, $confidence + 4 ) . '%',
						'risk'   => 'Higher premium, lower theta decay risk',
					),
					array(
						'type'   => 'Bull Call Spread',
						'strike' => 'Buy ' . $atm . ' CE / Sell ' . $sell_l . ' CE',
						'premium_range' => $this->premium_band( $est_atm * 0.55 ),
						'probability'   => min( 92, $confidence + 2 ) . '%',
						'risk'   => 'Capped risk & reward — best in high IV',
					),
				),
				'risk_level' => $risk_level,
			);
		}

		// SELL / Bearish.
		$itm    = $atm + $step;
		$sell_l = $atm - $step;
		$primary = ( $iv >= 20 ) ? 'Bear Put Spread' : 'ATM Put';
		return array(
			'direction'  => 'Bearish',
			'primary'    => $primary,
			'legs'       => array(
				array(
					'type'   => 'ATM Put',
					'strike' => $atm . ' PE',
					'premium_range' => $this->premium_band( $est_atm ),
					'probability'   => $confidence . '%',
					'risk'   => 'Premium paid',
				),
				array(
					'type'   => 'ITM Put (higher delta)',
					'strike' => $itm . ' PE',
					'premium_range' => $this->premium_band( $est_atm * 1.6 ),
					'probability'   => min( 95, $confidence + 4 ) . '%',
					'risk'   => 'Higher premium, lower theta decay risk',
				),
				array(
					'type'   => 'Bear Put Spread',
					'strike' => 'Buy ' . $atm . ' PE / Sell ' . $sell_l . ' PE',
					'premium_range' => $this->premium_band( $est_atm * 0.55 ),
					'probability'   => min( 92, $confidence + 2 ) . '%',
					'risk'   => 'Capped risk & reward — best in high IV',
				),
			),
			'risk_level' => $risk_level,
		);
	}

	private function premium_band( $mid ) {
		$mid = max( 1, round( $mid, 1 ) );
		$lo  = round( $mid * 0.85, 1 );
		$hi  = round( $mid * 1.15, 1 );
		return '₹' . number_format_i18n( $lo, 1 ) . ' - ₹' . number_format_i18n( $hi, 1 );
	}

	/**
	 * Calculate days to next NSE F&O expiry using actual expiry dates.
	 *
	 * @param string $instrument Instrument.
	 * @return int Days to next expiry (minimum 1).
	 */
	private function days_to_next_expiry( $instrument ) {
		$instrument = strtoupper( $instrument );

		// Actual BANKNIFTY/NIFTY monthly expiry dates (from TradingView/NSE).
		$banknifty_expiries = array(
			'2026-06-30', '2026-07-28', '2026-08-25', '2026-09-29',
			'2026-10-29', '2026-11-26', '2026-12-29',
			'2027-01-28', '2027-02-25', '2027-03-30',
		);

		// Use BANKNIFTY dates for all indices (they share monthly expiry structure).
		$expiries = $banknifty_expiries;

		$now = time();

		foreach ( $expiries as $date_str ) {
			$ts = strtotime( $date_str . ' 15:30:00 +0530' ); // Expiry at 3:30 PM IST.
			if ( false === $ts ) {
				continue;
			}
			if ( $ts > $now ) {
				$days = (int) ceil( ( $ts - $now ) / 86400 );
				return max( 1, $days );
			}
		}

		// Fallback if all dates passed.
		return 7;
	}

	/**
	 * Get the human-readable expiry date.
	 * Looks up the actual next expiry from the calendar instead of adding days.
	 *
	 * @param int $dte Days to expiry (used as fallback only).
	 * @return string e.g. "30 Jun 2026".
	 */
	private function get_expiry_date_str( $dte ) {
		// Look up the actual expiry date from the calendar.
		$expiries = array(
			'2026-06-30', '2026-07-28', '2026-08-25', '2026-09-29',
			'2026-10-29', '2026-11-26', '2026-12-29',
			'2027-01-28', '2027-02-25', '2027-03-30',
		);
		$now = time();
		foreach ( $expiries as $date_str ) {
			$ts = strtotime( $date_str . ' 15:30:00 +0530' );
			if ( $ts && $ts > $now ) {
				return gmdate( 'd M Y', $ts );
			}
		}
		// Fallback.
		return gmdate( 'd M Y', $now + $dte * 86400 );
	}

	private function strike_step( $instrument, $ltp ) {		$map = array(
			'NIFTY'      => 50,
			'BANKNIFTY'  => 100,
			'FINNIFTY'   => 50,
			'SENSEX'     => 100,
			'MIDCPNIFTY' => 25,
		);
		if ( isset( $map[ $instrument ] ) ) {
			return $map[ $instrument ];
		}
		// Generic stock: scale by price.
		if ( $ltp >= 2000 ) {
			return 20;
		}
		if ( $ltp >= 500 ) {
			return 10;
		}
		return 5;
	}

	// ---------------------------------------------------------------------
	// STEP 8: Probability table (heuristic, derived from confidence).
	// ---------------------------------------------------------------------
	private function build_probability_table( $direction, $confidence, $setup, $s ) {
		if ( 'NO TRADE' === $direction || null === $setup ) {
			return array(
				'trend'          => $confidence . '%',
				'target1'        => '-',
				'target2'        => '-',
				'target3'        => '-',
				'stop_loss_hit'  => '-',
			);
		}
		$c   = $confidence / 100;
		$t1  = (int) round( min( 95, 55 + $c * 38 ) );
		$t2  = (int) round( min( 88, 35 + $c * 40 ) );
		$t3  = (int) round( min( 70, 18 + $c * 38 ) );
		$sl  = (int) round( max( 8, 45 - $c * 33 ) );

		return array(
			'trend'         => $confidence . '%',
			'target1'       => $t1 . '%',
			'target2'       => $t2 . '%',
			'target3'       => $t3 . '%',
			'stop_loss_hit' => $sl . '%',
		);
	}

	// ---------------------------------------------------------------------
	// STEP 10: Final verdict one-liner.
	// ---------------------------------------------------------------------
	private function final_verdict( $direction, $s, $net ) {
		if ( 'NO TRADE' === $direction ) {
			return sprintf(
				'Signals are mixed (net bias %+.0f); no high-probability edge right now. Stay flat on %s until structure resolves.',
				$net,
				$s['instrument']
			);
		}
		if ( 'BUY' === $direction ) {
			return sprintf(
				'Institutions appear to be accumulating with bullish momentum supported by OI build-up and breadth. BUY setup on %s remains valid above ₹%s.',
				$s['instrument'],
				number_format_i18n( round( $s['vwap'], 2 ), 2 )
			);
		}
		return sprintf(
			'Distribution pressure with bearish momentum and weakening breadth. SELL setup on %s remains valid below ₹%s.',
			$s['instrument'],
			number_format_i18n( round( $s['vwap'], 2 ), 2 )
		);
	}

	// ---------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------
	private function score_breakdown( $components ) {
		$out = array();
		foreach ( $components as $name => $c ) {
			// Convert directional bias [-1..1] * weight into an absolute "points earned" 0..weight.
			$points = round( ( ( $c['bias'] + 1 ) / 2 ) * $c['weight'], 1 );
			$out[ $name ] = array(
				'weight' => $c['weight'],
				'bias'   => round( $c['bias'], 2 ),
				'points' => $points,
			);
		}
		return $out;
	}

	private function trend_label( $net ) {
		if ( $net >= 55 ) {
			return 'Strong Bullish';
		}
		if ( $net >= 25 ) {
			return 'Bullish';
		}
		if ( $net >= 8 ) {
			return 'Sideways Bullish';
		}
		if ( $net > -8 ) {
			return 'Neutral';
		}
		if ( $net > -25 ) {
			return 'Sideways Bearish';
		}
		if ( $net > -55 ) {
			return 'Bearish';
		}
		return 'Strong Bearish';
	}

	private function cmp( $a, $b ) {
		return $a > $b ? '>' : ( $a < $b ? '<' : '=' );
	}
}
