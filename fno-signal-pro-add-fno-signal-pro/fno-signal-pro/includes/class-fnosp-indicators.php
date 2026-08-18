<?php
/**
 * Pure-PHP technical indicator calculator.
 *
 * All methods operate on plain numeric arrays (oldest -> newest) and return
 * either the latest value or a structured array. No external dependencies.
 *
 * @package FnO_Signal_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FnOSP_Indicators {

	/**
	 * Simple Moving Average of the last $period values.
	 *
	 * @param float[] $values Series.
	 * @param int     $period Period.
	 * @return float
	 */
	public static function sma( array $values, $period ) {
		$n = count( $values );
		if ( $n < 1 ) {
			return 0.0;
		}
		$period = min( $period, $n );
		$slice  = array_slice( $values, -$period );
		return array_sum( $slice ) / max( 1, count( $slice ) );
	}

	/**
	 * Full EMA series (same length as input).
	 *
	 * @param float[] $values Series (oldest -> newest).
	 * @param int     $period Period.
	 * @return float[]
	 */
	public static function ema_series( array $values, $period ) {
		$n = count( $values );
		if ( 0 === $n ) {
			return array();
		}
		$k   = 2 / ( $period + 1 );
		$out = array();
		$ema = $values[0];
		foreach ( $values as $i => $v ) {
			if ( 0 === $i ) {
				$ema = $v;
			} else {
				$ema = ( $v - $ema ) * $k + $ema;
			}
			$out[] = $ema;
		}
		return $out;
	}

	/**
	 * Latest EMA value.
	 *
	 * @param float[] $values Series.
	 * @param int     $period Period.
	 * @return float
	 */
	public static function ema( array $values, $period ) {
		$series = self::ema_series( $values, $period );
		return empty( $series ) ? 0.0 : end( $series );
	}

	/**
	 * Wilder's RSI. Returns latest and previous value.
	 *
	 * @param float[] $closes Close series.
	 * @param int     $period Period (default 14).
	 * @return array { latest:float, prev:float }
	 */
	public static function rsi( array $closes, $period = 14 ) {
		$n = count( $closes );
		if ( $n < $period + 1 ) {
			return array(
				'latest' => 50.0,
				'prev'   => 50.0,
			);
		}

		$gains  = array();
		$losses = array();
		for ( $i = 1; $i < $n; $i++ ) {
			$diff     = $closes[ $i ] - $closes[ $i - 1 ];
			$gains[]  = $diff > 0 ? $diff : 0.0;
			$losses[] = $diff < 0 ? -$diff : 0.0;
		}

		// Initial averages.
		$avg_gain = array_sum( array_slice( $gains, 0, $period ) ) / $period;
		$avg_loss = array_sum( array_slice( $losses, 0, $period ) ) / $period;

		$rsi_series = array();
		$rsi_series[] = self::rsi_from_avg( $avg_gain, $avg_loss );

		for ( $i = $period; $i < count( $gains ); $i++ ) {
			$avg_gain = ( ( $avg_gain * ( $period - 1 ) ) + $gains[ $i ] ) / $period;
			$avg_loss = ( ( $avg_loss * ( $period - 1 ) ) + $losses[ $i ] ) / $period;
			$rsi_series[] = self::rsi_from_avg( $avg_gain, $avg_loss );
		}

		$cnt    = count( $rsi_series );
		$latest = $rsi_series[ $cnt - 1 ];
		$prev   = $cnt >= 2 ? $rsi_series[ $cnt - 2 ] : $latest;
		return array(
			'latest' => round( $latest, 2 ),
			'prev'   => round( $prev, 2 ),
		);
	}

	private static function rsi_from_avg( $avg_gain, $avg_loss ) {
		if ( $avg_loss <= 0 ) {
			return 100.0;
		}
		$rs = $avg_gain / $avg_loss;
		return 100 - ( 100 / ( 1 + $rs ) );
	}

	/**
	 * MACD (12,26,9). Returns latest macd line, signal, histogram.
	 *
	 * @param float[] $closes Close series.
	 * @param int     $fast   Fast period.
	 * @param int     $slow   Slow period.
	 * @param int     $signal Signal period.
	 * @return array { macd:float, signal:float, hist:float }
	 */
	public static function macd( array $closes, $fast = 12, $slow = 26, $signal = 9 ) {
		if ( count( $closes ) < $slow + $signal ) {
			return array(
				'macd'   => 0.0,
				'signal' => 0.0,
				'hist'   => 0.0,
			);
		}
		$ema_fast = self::ema_series( $closes, $fast );
		$ema_slow = self::ema_series( $closes, $slow );

		$macd_line = array();
		foreach ( $closes as $i => $c ) {
			$macd_line[] = $ema_fast[ $i ] - $ema_slow[ $i ];
		}
		$signal_series = self::ema_series( $macd_line, $signal );

		$macd_last   = end( $macd_line );
		$signal_last = end( $signal_series );
		return array(
			'macd'   => round( $macd_last, 2 ),
			'signal' => round( $signal_last, 2 ),
			'hist'   => round( $macd_last - $signal_last, 2 ),
		);
	}

	/**
	 * Wilder's ATR.
	 *
	 * @param float[] $highs  Highs.
	 * @param float[] $lows   Lows.
	 * @param float[] $closes Closes.
	 * @param int     $period Period (default 14).
	 * @return float
	 */
	public static function atr( array $highs, array $lows, array $closes, $period = 14 ) {
		$n = min( count( $highs ), count( $lows ), count( $closes ) );
		if ( $n < 2 ) {
			return 0.0;
		}
		$tr = array();
		for ( $i = 1; $i < $n; $i++ ) {
			$h_l  = $highs[ $i ] - $lows[ $i ];
			$h_pc = abs( $highs[ $i ] - $closes[ $i - 1 ] );
			$l_pc = abs( $lows[ $i ] - $closes[ $i - 1 ] );
			$tr[] = max( $h_l, $h_pc, $l_pc );
		}
		if ( count( $tr ) < $period ) {
			return round( array_sum( $tr ) / max( 1, count( $tr ) ), 2 );
		}
		$atr = array_sum( array_slice( $tr, 0, $period ) ) / $period;
		for ( $i = $period; $i < count( $tr ); $i++ ) {
			$atr = ( ( $atr * ( $period - 1 ) ) + $tr[ $i ] ) / $period;
		}
		return round( $atr, 2 );
	}

	/**
	 * Bollinger Bands (period 20, 2 std dev).
	 *
	 * @param float[] $closes Closes.
	 * @param int     $period Period.
	 * @param float   $mult   Std-dev multiplier.
	 * @return array { upper, mid, lower }
	 */
	public static function bollinger( array $closes, $period = 20, $mult = 2.0 ) {
		$n = count( $closes );
		if ( $n < 1 ) {
			return array(
				'upper' => 0.0,
				'mid'   => 0.0,
				'lower' => 0.0,
			);
		}
		$period = min( $period, $n );
		$slice  = array_slice( $closes, -$period );
		$mid    = array_sum( $slice ) / $period;
		$var    = 0.0;
		foreach ( $slice as $c ) {
			$var += ( $c - $mid ) ** 2;
		}
		$sd = sqrt( $var / $period );
		return array(
			'upper' => round( $mid + $mult * $sd, 2 ),
			'mid'   => round( $mid, 2 ),
			'lower' => round( $mid - $mult * $sd, 2 ),
		);
	}

	/**
	 * Simplified SuperTrend direction (period 10, multiplier 3).
	 * Returns 'bullish' or 'bearish'.
	 *
	 * @param float[] $highs  Highs.
	 * @param float[] $lows   Lows.
	 * @param float[] $closes Closes.
	 * @param int     $period ATR period.
	 * @param float   $mult   Multiplier.
	 * @return string
	 */
	public static function supertrend( array $highs, array $lows, array $closes, $period = 10, $mult = 3.0 ) {
		$n = min( count( $highs ), count( $lows ), count( $closes ) );
		if ( $n < $period + 1 ) {
			// Fallback: compare close to its short EMA.
			$ema = self::ema( $closes, 10 );
			return ( end( $closes ) >= $ema ) ? 'bullish' : 'bearish';
		}

		$atr = self::atr( $highs, $lows, $closes, $period );
		$dir = 'bullish';
		$prev_upper = null;
		$prev_lower = null;

		for ( $i = 1; $i < $n; $i++ ) {
			$hl2         = ( $highs[ $i ] + $lows[ $i ] ) / 2;
			$basic_upper = $hl2 + $mult * $atr;
			$basic_lower = $hl2 - $mult * $atr;

			$final_upper = ( null === $prev_upper )
				? $basic_upper
				: ( ( $basic_upper < $prev_upper || $closes[ $i - 1 ] > $prev_upper ) ? $basic_upper : $prev_upper );
			$final_lower = ( null === $prev_lower )
				? $basic_lower
				: ( ( $basic_lower > $prev_lower || $closes[ $i - 1 ] < $prev_lower ) ? $basic_lower : $prev_lower );

			if ( $closes[ $i ] > $final_upper ) {
				$dir = 'bullish';
			} elseif ( $closes[ $i ] < $final_lower ) {
				$dir = 'bearish';
			}

			$prev_upper = $final_upper;
			$prev_lower = $final_lower;
		}

		return $dir;
	}

	/**
	 * Session VWAP from typical price * volume.
	 *
	 * @param float[] $highs   Highs.
	 * @param float[] $lows    Lows.
	 * @param float[] $closes  Closes.
	 * @param float[] $volumes Volumes.
	 * @return float
	 */
	public static function vwap( array $highs, array $lows, array $closes, array $volumes ) {
		$n = min( count( $highs ), count( $lows ), count( $closes ), count( $volumes ) );
		if ( $n < 1 ) {
			return 0.0;
		}
		$pv  = 0.0;
		$vol = 0.0;
		for ( $i = 0; $i < $n; $i++ ) {
			$tp   = ( $highs[ $i ] + $lows[ $i ] + $closes[ $i ] ) / 3;
			$v    = max( 0.0, (float) $volumes[ $i ] );
			$pv  += $tp * $v;
			$vol += $v;
		}
		if ( $vol <= 0 ) {
			// No volume data — fall back to mean typical price.
			return round( self::sma( $closes, $n ), 2 );
		}
		return round( $pv / $vol, 2 );
	}

	/**
	 * Standard normal CDF (Abramowitz-Stegun approximation).
	 *
	 * @param float $x Value.
	 * @return float
	 */
	public static function norm_cdf( $x ) {
		// erf approximation.
		$sign = $x < 0 ? -1 : 1;
		$ax   = abs( $x ) / sqrt( 2 );
		$t    = 1 / ( 1 + 0.3275911 * $ax );
		$y    = 1 - ( ( ( ( ( 1.061405429 * $t - 1.453152027 ) * $t ) + 1.421413741 ) * $t - 0.284496736 ) * $t + 0.254829592 ) * $t * exp( -$ax * $ax );
		$erf  = $sign * $y;
		return 0.5 * ( 1 + $erf );
	}

	/**
	 * Black-Scholes European option price.
	 *
	 * @param string $type  'CE' (call) or 'PE' (put).
	 * @param float  $s     Spot.
	 * @param float  $k     Strike.
	 * @param float  $t     Time to expiry in years.
	 * @param float  $r     Risk-free rate (annual, decimal).
	 * @param float  $sigma Implied volatility (annual, decimal).
	 * @return float Theoretical premium (>= 0).
	 */
	public static function bs_price( $type, $s, $k, $t, $r, $sigma ) {
		$t     = max( $t, 1 / 3650 );      // Floor to avoid div-by-zero.
		$sigma = max( $sigma, 0.0001 );
		$sqrt  = $sigma * sqrt( $t );
		$d1    = ( log( $s / $k ) + ( $r + $sigma * $sigma / 2 ) * $t ) / $sqrt;
		$d2    = $d1 - $sqrt;
		$disc  = exp( -$r * $t );

		if ( 'PE' === strtoupper( $type ) ) {
			$price = $k * $disc * self::norm_cdf( -$d2 ) - $s * self::norm_cdf( -$d1 );
		} else {
			$price = $s * self::norm_cdf( $d1 ) - $k * $disc * self::norm_cdf( $d2 );
		}
		return max( 0.05, round( $price, 2 ) );
	}

	/**
	 * Black-Scholes delta.
	 *
	 * @param string $type  CE/PE.
	 * @param float  $s     Spot.
	 * @param float  $k     Strike.
	 * @param float  $t     Years to expiry.
	 * @param float  $r     Rate.
	 * @param float  $sigma IV (decimal).
	 * @return float
	 */
	public static function bs_delta( $type, $s, $k, $t, $r, $sigma ) {
		$t     = max( $t, 1 / 3650 );
		$sigma = max( $sigma, 0.0001 );
		$d1    = ( log( $s / $k ) + ( $r + $sigma * $sigma / 2 ) * $t ) / ( $sigma * sqrt( $t ) );
		$call  = self::norm_cdf( $d1 );
		return 'PE' === strtoupper( $type ) ? round( $call - 1, 3 ) : round( $call, 3 );
	}
}

