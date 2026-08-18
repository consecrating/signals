/**
 * indicators.js — Pure JavaScript ES6 technical indicator library
 * For F&O Option Trading Signal Engine
 *
 * All functions are deterministic, side-effect-free, and operate on
 * plain numeric arrays. They assume inputs are ordered oldest→newest.
 */

// ─── Statistical Helpers ─────────────────────────────────────────────────────

/**
 * Cumulative Normal Distribution via Abramowitz & Stegun approximation.
 * Accuracy ~1e-7 for all x.
 * @param {number} x
 * @returns {number}
 */
export function normCDF(x) {
  const a1 = 0.254829592;
  const a2 = -0.284496736;
  const a3 = 1.421413741;
  const a4 = -1.453152027;
  const a5 = 1.061405429;
  const p = 0.3275911;

  const sign = x < 0 ? -1 : 1;
  const absX = Math.abs(x);
  const t = 1.0 / (1.0 + p * absX);
  const y = 1.0 - ((((a5 * t + a4) * t + a3) * t + a2) * t + a1) * t * Math.exp(-absX * absX / 2);

  return 0.5 * (1.0 + sign * y);
}

// ─── Moving Averages ─────────────────────────────────────────────────────────

/**
 * Simple Moving Average — returns the latest value only.
 * @param {number[]} values - Price series (oldest first)
 * @param {number} period
 * @returns {number|null}
 */
export function SMA(values, period) {
  if (!values || values.length < period) return null;
  const slice = values.slice(-period);
  return slice.reduce((sum, v) => sum + v, 0) / period;
}

/**
 * Exponential Moving Average — returns the latest value only.
 * Uses the standard multiplier: 2/(period+1).
 * @param {number[]} values
 * @param {number} period
 * @returns {number|null}
 */
export function EMA(values, period) {
  const series = emaSeries(values, period);
  if (!series || series.length === 0) return null;
  return series[series.length - 1];
}

/**
 * Full EMA series — returns an array the same length as `values`.
 * The first `period-1` entries are null (insufficient data).
 * @param {number[]} values
 * @param {number} period
 * @returns {(number|null)[]}
 */
export function emaSeries(values, period) {
  if (!values || values.length < period) return [];
  const k = 2 / (period + 1);
  const result = new Array(values.length).fill(null);

  // Seed: SMA of the first `period` values
  let ema = 0;
  for (let i = 0; i < period; i++) {
    ema += values[i];
  }
  ema /= period;
  result[period - 1] = ema;

  // Recursive EMA
  for (let i = period; i < values.length; i++) {
    ema = (values[i] - ema) * k + ema;
    result[i] = ema;
  }
  return result;
}

// ─── Oscillators ─────────────────────────────────────────────────────────────

/**
 * Relative Strength Index.
 * Returns the latest and previous RSI values (useful for divergence detection).
 * @param {number[]} closes
 * @param {number} [period=14]
 * @returns {{latest: number|null, prev: number|null}}
 */
export function RSI(closes, period = 14) {
  if (!closes || closes.length < period + 1) return { latest: null, prev: null };

  let gains = 0;
  let losses = 0;

  // Initial averages over the first `period` changes
  for (let i = 1; i <= period; i++) {
    const change = closes[i] - closes[i - 1];
    if (change > 0) gains += change;
    else losses -= change;
  }

  let avgGain = gains / period;
  let avgLoss = losses / period;

  let prevRSI = null;
  let latestRSI = null;

  // Wilder's smoothing for remaining bars
  for (let i = period + 1; i < closes.length; i++) {
    const change = closes[i] - closes[i - 1];
    avgGain = (avgGain * (period - 1) + (change > 0 ? change : 0)) / period;
    avgLoss = (avgLoss * (period - 1) + (change < 0 ? -change : 0)) / period;

    const rs = avgLoss === 0 ? 100 : avgGain / avgLoss;
    prevRSI = latestRSI;
    latestRSI = 100 - 100 / (1 + rs);
  }

  // Handle edge: if we only had exactly period+1 data, compute from initial averages
  if (latestRSI === null) {
    const rs = avgLoss === 0 ? 100 : avgGain / avgLoss;
    latestRSI = 100 - 100 / (1 + rs);
  }

  return { latest: latestRSI, prev: prevRSI };
}

// ─── MACD ────────────────────────────────────────────────────────────────────

/**
 * Moving Average Convergence Divergence.
 * @param {number[]} closes
 * @param {number} [fast=12]
 * @param {number} [slow=26]
 * @param {number} [signalPeriod=9]
 * @returns {{macd: number|null, signal: number|null, hist: number|null}}
 */
export function MACD(closes, fast = 12, slow = 26, signalPeriod = 9) {
  if (!closes || closes.length < slow + signalPeriod) {
    return { macd: null, signal: null, hist: null };
  }

  const emaFast = emaSeries(closes, fast);
  const emaSlow = emaSeries(closes, slow);

  // MACD line = fast EMA - slow EMA
  const macdLine = [];
  for (let i = 0; i < closes.length; i++) {
    if (emaFast[i] !== null && emaSlow[i] !== null) {
      macdLine.push(emaFast[i] - emaSlow[i]);
    }
  }

  if (macdLine.length < signalPeriod) {
    return { macd: null, signal: null, hist: null };
  }

  // Signal line = EMA of MACD line
  const signalLine = emaSeries(macdLine, signalPeriod);
  const lastMACD = macdLine[macdLine.length - 1];
  const lastSignal = signalLine[signalLine.length - 1];

  return {
    macd: lastMACD,
    signal: lastSignal,
    hist: lastSignal !== null ? lastMACD - lastSignal : null,
  };
}

// ─── Volatility Indicators ───────────────────────────────────────────────────

/**
 * Average True Range (Wilder's smoothing).
 * @param {number[]} highs
 * @param {number[]} lows
 * @param {number[]} closes
 * @param {number} [period=14]
 * @returns {number|null}
 */
export function ATR(highs, lows, closes, period = 14) {
  const len = highs.length;
  if (len < period + 1) return null;

  // True Range calculation
  const trValues = [];
  for (let i = 1; i < len; i++) {
    const hl = highs[i] - lows[i];
    const hc = Math.abs(highs[i] - closes[i - 1]);
    const lc = Math.abs(lows[i] - closes[i - 1]);
    trValues.push(Math.max(hl, hc, lc));
  }

  if (trValues.length < period) return null;

  // Initial ATR = simple average of first `period` true ranges
  let atr = 0;
  for (let i = 0; i < period; i++) {
    atr += trValues[i];
  }
  atr /= period;

  // Wilder's smoothing
  for (let i = period; i < trValues.length; i++) {
    atr = (atr * (period - 1) + trValues[i]) / period;
  }

  return atr;
}

/**
 * Bollinger Bands.
 * @param {number[]} closes
 * @param {number} [period=20]
 * @param {number} [stddev=2.0]
 * @returns {{upper: number|null, mid: number|null, lower: number|null}}
 */
export function bollingerBands(closes, period = 20, stddev = 2.0) {
  if (!closes || closes.length < period) {
    return { upper: null, mid: null, lower: null };
  }

  const slice = closes.slice(-period);
  const mid = slice.reduce((s, v) => s + v, 0) / period;

  const variance = slice.reduce((s, v) => s + (v - mid) ** 2, 0) / period;
  const sd = Math.sqrt(variance);

  return {
    upper: mid + stddev * sd,
    mid,
    lower: mid - stddev * sd,
  };
}

// ─── Trend Indicators ────────────────────────────────────────────────────────

/**
 * SuperTrend indicator — returns 'bullish' or 'bearish'.
 * @param {number[]} highs
 * @param {number[]} lows
 * @param {number[]} closes
 * @param {number} [period=10]
 * @param {number} [multiplier=3.0]
 * @returns {string} 'bullish' or 'bearish'
 */
export function superTrend(highs, lows, closes, period = 10, multiplier = 3.0) {
  const len = closes.length;
  if (len < period + 1) return 'bearish';

  // Compute ATR series using Wilder's method
  const trValues = [];
  for (let i = 1; i < len; i++) {
    const hl = highs[i] - lows[i];
    const hc = Math.abs(highs[i] - closes[i - 1]);
    const lc = Math.abs(lows[i] - closes[i - 1]);
    trValues.push(Math.max(hl, hc, lc));
  }

  // ATR series (indexed from period-1 onward in trValues)
  const atrSeries = new Array(trValues.length).fill(0);
  let atrVal = 0;
  for (let i = 0; i < period; i++) atrVal += trValues[i];
  atrVal /= period;
  atrSeries[period - 1] = atrVal;
  for (let i = period; i < trValues.length; i++) {
    atrVal = (atrVal * (period - 1) + trValues[i]) / period;
    atrSeries[i] = atrVal;
  }

  // SuperTrend logic (offset by 1 due to TR starting at index 1)
  let upperBand = 0;
  let lowerBand = 0;
  let superTrendDir = 1; // 1 = bullish, -1 = bearish
  let prevUpperBand = 0;
  let prevLowerBand = 0;

  for (let i = period; i < len; i++) {
    const atrIdx = i - 1; // TR/ATR index offset
    const currentATR = atrSeries[atrIdx];
    const hl2 = (highs[i] + lows[i]) / 2;

    const basicUpper = hl2 + multiplier * currentATR;
    const basicLower = hl2 - multiplier * currentATR;

    // Final upper band
    upperBand = (basicUpper < prevUpperBand || closes[i - 1] > prevUpperBand)
      ? basicUpper
      : prevUpperBand;

    // Final lower band
    lowerBand = (basicLower > prevLowerBand || closes[i - 1] < prevLowerBand)
      ? basicLower
      : prevLowerBand;

    // Direction
    if (superTrendDir === 1) {
      // Was bullish
      if (closes[i] < lowerBand) {
        superTrendDir = -1;
      }
    } else {
      // Was bearish
      if (closes[i] > upperBand) {
        superTrendDir = 1;
      }
    }

    prevUpperBand = upperBand;
    prevLowerBand = lowerBand;
  }

  return superTrendDir === 1 ? 'bullish' : 'bearish';
}

// ─── Volume Indicators ───────────────────────────────────────────────────────

/**
 * Volume-Weighted Average Price for current session.
 * @param {number[]} highs
 * @param {number[]} lows
 * @param {number[]} closes
 * @param {number[]} volumes
 * @returns {number|null}
 */
export function VWAP(highs, lows, closes, volumes) {
  if (!highs || highs.length === 0) return null;

  let cumulativeTPV = 0;
  let cumulativeVol = 0;

  for (let i = 0; i < highs.length; i++) {
    const typicalPrice = (highs[i] + lows[i] + closes[i]) / 3;
    cumulativeTPV += typicalPrice * volumes[i];
    cumulativeVol += volumes[i];
  }

  return cumulativeVol === 0 ? null : cumulativeTPV / cumulativeVol;
}

// ─── Black-Scholes Option Pricing ────────────────────────────────────────────

/**
 * Black-Scholes European option price.
 * @param {'CE'|'PE'} type - Call (CE) or Put (PE)
 * @param {number} spot - Current spot price
 * @param {number} strike - Strike price
 * @param {number} t - Time to expiry in years (e.g., 3/365)
 * @param {number} r - Risk-free rate (annualized, e.g., 0.065)
 * @param {number} sigma - Implied volatility (annualized, e.g., 0.15)
 * @returns {number} Option premium
 */
export function blackScholesPrice(type, spot, strike, t, r, sigma) {
  if (t <= 0 || sigma <= 0) {
    // At or past expiry: intrinsic value
    if (type === 'CE') return Math.max(0, spot - strike);
    return Math.max(0, strike - spot);
  }

  const d1 = (Math.log(spot / strike) + (r + sigma * sigma / 2) * t) / (sigma * Math.sqrt(t));
  const d2 = d1 - sigma * Math.sqrt(t);

  if (type === 'CE') {
    return spot * normCDF(d1) - strike * Math.exp(-r * t) * normCDF(d2);
  }
  // Put
  return strike * Math.exp(-r * t) * normCDF(-d2) - spot * normCDF(-d1);
}

/**
 * Black-Scholes Delta.
 * @param {'CE'|'PE'} type
 * @param {number} spot
 * @param {number} strike
 * @param {number} t
 * @param {number} r
 * @param {number} sigma
 * @returns {number} Delta value
 */
export function blackScholesDelta(type, spot, strike, t, r, sigma) {
  if (t <= 0 || sigma <= 0) {
    if (type === 'CE') return spot > strike ? 1 : 0;
    return spot < strike ? -1 : 0;
  }

  const d1 = (Math.log(spot / strike) + (r + sigma * sigma / 2) * t) / (sigma * Math.sqrt(t));

  if (type === 'CE') {
    return normCDF(d1);
  }
  return normCDF(d1) - 1;
}
