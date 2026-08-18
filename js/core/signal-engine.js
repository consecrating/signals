/**
 * signal-engine.js — Institutional-grade 10-step F&O signal generation framework
 *
 * Scoring system: 100-point weighted composite
 *   Trend(25) + Price Action(20) + Options(20) + Volume(10) +
 *   Momentum(10) + Institutional(10) + News(5) = 100
 *
 * Each component returns a bias in [-1..1], multiplied by its weight.
 * Net score in [-100..100] drives direction, confidence, and trade plan.
 */

import {
  SMA,
  EMA,
  emaSeries,
  RSI,
  MACD,
  ATR,
  bollingerBands,
  superTrend,
  VWAP,
  blackScholesPrice,
  blackScholesDelta,
} from './indicators.js';

// ─── Configuration Constants ─────────────────────────────────────────────────

/** Strike price step sizes per index */
const STRIKE_STEPS = {
  NIFTY: 50,
  BANKNIFTY: 100,
  FINNIFTY: 50,
  SENSEX: 100,
  MIDCPNIFTY: 25,
};

/** Lot sizes per index */
const LOT_SIZES = {
  NIFTY: 25,
  BANKNIFTY: 15,
  FINNIFTY: 25,
  SENSEX: 10,
  MIDCPNIFTY: 50,
};

/** Default risk-free rate for Black-Scholes */
const RISK_FREE_RATE = 0.065;

/** Default confidence gate threshold */
const DEFAULT_CONFIDENCE_THRESHOLD = 75;

// ─── Utility Helpers ─────────────────────────────────────────────────────────

/**
 * Clamp a value between min and max.
 */
function clamp(val, min, max) {
  return Math.max(min, Math.min(max, val));
}

/**
 * Round to nearest strike step.
 */
function roundToStrike(price, step) {
  return Math.round(price / step) * step;
}

/**
 * Get days to expiry (fractional years for B-S).
 * @param {string|Date} expiry
 * @returns {number} years
 */
function daysToExpiryYears(expiry) {
  const exp = new Date(expiry);
  const now = new Date();
  const diffMs = exp - now;
  const days = Math.max(diffMs / (1000 * 60 * 60 * 24), 0.01); // Floor at ~15min
  return days / 365;
}

// ─── Component Scoring Functions ─────────────────────────────────────────────

/**
 * Step 1: Trend Analysis (weight 25)
 * EMA crossovers, SuperTrend, price vs key MAs.
 */
function scoreTrend(snapshot) {
  const { closes, highs, lows } = snapshot;
  if (!closes || closes.length < 50) return 0;

  let bias = 0;
  let factors = 0;

  // EMA 9/21 crossover
  const ema9 = EMA(closes, 9);
  const ema21 = EMA(closes, 21);
  if (ema9 !== null && ema21 !== null) {
    if (ema9 > ema21) bias += 0.3;
    else if (ema9 < ema21) bias -= 0.3;
    factors++;
  }

  // Price vs 50-SMA
  const sma50 = SMA(closes, 50);
  const ltp = closes[closes.length - 1];
  if (sma50 !== null) {
    if (ltp > sma50) bias += 0.2;
    else bias -= 0.2;
    factors++;
  }

  // Price vs 200-SMA (if available)
  if (closes.length >= 200) {
    const sma200 = SMA(closes, 200);
    if (sma200 !== null) {
      if (ltp > sma200) bias += 0.15;
      else bias -= 0.15;
      factors++;
    }
  }

  // SuperTrend direction
  if (highs && lows && highs.length >= 11) {
    const st = superTrend(highs, lows, closes);
    if (st === 'bullish') bias += 0.35;
    else bias -= 0.35;
    factors++;
  }

  return factors > 0 ? clamp(bias / (factors * 0.25), -1, 1) : 0;
}

/**
 * Step 2: Price Action (weight 20)
 * Candlestick patterns, recent highs/lows, breakout proximity.
 */
function scorePriceAction(snapshot) {
  const { closes, highs, lows, opens } = snapshot;
  if (!closes || closes.length < 5) return 0;

  let bias = 0;
  const len = closes.length;
  const ltp = closes[len - 1];

  // Recent momentum: 5-bar direction
  const recentChange = (closes[len - 1] - closes[len - 5]) / closes[len - 5];
  if (recentChange > 0.005) bias += 0.3;
  else if (recentChange < -0.005) bias -= 0.3;

  // Higher highs / lower lows over last 3 bars
  if (highs && lows && len >= 3) {
    const hh = highs[len - 1] > highs[len - 2] && highs[len - 2] > highs[len - 3];
    const ll = lows[len - 1] < lows[len - 2] && lows[len - 2] < lows[len - 3];
    if (hh) bias += 0.25;
    if (ll) bias -= 0.25;
  }

  // Bullish/bearish engulfing (last 2 candles)
  if (opens && len >= 2) {
    const prevBody = closes[len - 2] - opens[len - 2];
    const currBody = closes[len - 1] - opens[len - 1];
    if (prevBody < 0 && currBody > 0 && Math.abs(currBody) > Math.abs(prevBody)) {
      bias += 0.25; // Bullish engulfing
    } else if (prevBody > 0 && currBody < 0 && Math.abs(currBody) > Math.abs(prevBody)) {
      bias -= 0.25; // Bearish engulfing
    }
  }

  // Proximity to 20-bar high/low
  if (len >= 20) {
    const h20 = Math.max(...highs.slice(-20));
    const l20 = Math.min(...lows.slice(-20));
    const range = h20 - l20;
    if (range > 0) {
      const position = (ltp - l20) / range;
      if (position > 0.9) bias += 0.2; // Near breakout high
      if (position < 0.1) bias -= 0.2; // Near breakdown low
    }
  }

  return clamp(bias, -1, 1);
}

/**
 * Step 3: Options Flow (weight 20)
 * PCR, OI changes, max pain, IV skew.
 */
function scoreOptionsFlow(snapshot) {
  const { pcr, oiChange, maxPain, ltp, iv, ivPercentile } = snapshot;
  let bias = 0;
  let factors = 0;

  // Put-Call Ratio
  if (pcr !== undefined && pcr !== null) {
    if (pcr > 1.2) { bias += 0.3; factors++; }      // Heavy put writing = bullish
    else if (pcr < 0.7) { bias -= 0.3; factors++; }  // Heavy call writing = bearish
    else { factors++; }
  }

  // OI Change analysis (net CE vs PE OI addition)
  if (oiChange) {
    const { ceOI, peOI } = oiChange;
    if (ceOI !== undefined && peOI !== undefined) {
      const netOI = peOI - ceOI; // More PE OI = bullish support
      if (netOI > 0) bias += 0.25;
      else if (netOI < 0) bias -= 0.25;
      factors++;
    }
  }

  // Max Pain proximity
  if (maxPain && ltp) {
    const deviation = (ltp - maxPain) / maxPain;
    if (deviation > 0.02) bias -= 0.15;       // Far above max pain, pull-back likely
    else if (deviation < -0.02) bias += 0.15;  // Below max pain, bounce likely
    factors++;
  }

  // IV Percentile — high IV favors selling, low IV favors buying
  if (ivPercentile !== undefined) {
    if (ivPercentile > 80) bias -= 0.1;  // Mean reversion bias
    else if (ivPercentile < 20) bias += 0.1;
    factors++;
  }

  return factors > 0 ? clamp(bias / (factors * 0.3) * 1.0, -1, 1) : 0;
}

/**
 * Step 4: Volume Analysis (weight 10)
 * Volume vs average, delivery %, accumulation/distribution.
 */
function scoreVolume(snapshot) {
  const { volumes, closes, highs, lows } = snapshot;
  if (!volumes || volumes.length < 10) return 0;

  let bias = 0;
  const len = volumes.length;
  const avgVol = volumes.slice(-20).reduce((s, v) => s + v, 0) / Math.min(20, volumes.length);
  const currentVol = volumes[len - 1];

  // Volume spike detection
  const volRatio = currentVol / avgVol;
  if (volRatio > 1.5 && closes[len - 1] > closes[len - 2]) {
    bias += 0.4; // Bullish volume spike
  } else if (volRatio > 1.5 && closes[len - 1] < closes[len - 2]) {
    bias -= 0.4; // Bearish volume spike
  }

  // VWAP positioning
  if (highs && lows) {
    const vwap = VWAP(highs, lows, closes, volumes);
    if (vwap !== null) {
      const ltp = closes[len - 1];
      if (ltp > vwap) bias += 0.3;
      else bias -= 0.3;
    }
  }

  // Volume trend (increasing on up-moves)
  if (len >= 5) {
    let upVol = 0, downVol = 0;
    for (let i = len - 5; i < len; i++) {
      if (closes[i] > closes[i - 1]) upVol += volumes[i];
      else downVol += volumes[i];
    }
    if (upVol > downVol * 1.3) bias += 0.2;
    else if (downVol > upVol * 1.3) bias -= 0.2;
  }

  return clamp(bias, -1, 1);
}

/**
 * Step 5: Momentum (weight 10)
 * RSI, MACD histogram, rate of change.
 */
function scoreMomentum(snapshot) {
  const { closes } = snapshot;
  if (!closes || closes.length < 30) return 0;

  let bias = 0;

  // RSI
  const rsi = RSI(closes);
  if (rsi.latest !== null) {
    if (rsi.latest > 60) bias += 0.3;
    else if (rsi.latest < 40) bias -= 0.3;
    // RSI divergence hint
    if (rsi.prev !== null) {
      if (rsi.latest > rsi.prev && closes[closes.length - 1] < closes[closes.length - 2]) {
        bias += 0.15; // Bullish divergence
      } else if (rsi.latest < rsi.prev && closes[closes.length - 1] > closes[closes.length - 2]) {
        bias -= 0.15; // Bearish divergence
      }
    }
  }

  // MACD Histogram
  const macd = MACD(closes);
  if (macd.hist !== null) {
    if (macd.hist > 0) bias += 0.3;
    else bias -= 0.3;
    // Histogram expansion/contraction
    // (using sign as proxy — full series not stored)
  }

  // Rate of Change (10-period)
  if (closes.length >= 11) {
    const roc = (closes[closes.length - 1] - closes[closes.length - 11]) / closes[closes.length - 11];
    if (roc > 0.02) bias += 0.2;
    else if (roc < -0.02) bias -= 0.2;
  }

  return clamp(bias, -1, 1);
}

/**
 * Step 6: Institutional Activity (weight 10)
 * FII/DII flows, large OI positions, block deals.
 */
function scoreInstitutional(snapshot) {
  const { fiiFlow, diiFlow, blockDeals } = snapshot;
  let bias = 0;
  let factors = 0;

  // FII net flows (in crores)
  if (fiiFlow !== undefined && fiiFlow !== null) {
    if (fiiFlow > 500) bias += 0.4;
    else if (fiiFlow > 0) bias += 0.2;
    else if (fiiFlow < -500) bias -= 0.4;
    else if (fiiFlow < 0) bias -= 0.2;
    factors++;
  }

  // DII flows (counter-balancer)
  if (diiFlow !== undefined && diiFlow !== null) {
    if (diiFlow > 500) bias += 0.2;
    else if (diiFlow < -500) bias -= 0.1;
    factors++;
  }

  // Block deals (heavy buying/selling)
  if (blockDeals) {
    if (blockDeals.netBuy > 0) bias += 0.2;
    else if (blockDeals.netBuy < 0) bias -= 0.2;
    factors++;
  }

  return factors > 0 ? clamp(bias, -1, 1) : 0;
}

/**
 * Step 7: News/Sentiment (weight 5)
 * Pre-computed sentiment score from external feed.
 */
function scoreNews(snapshot) {
  const { newsSentiment } = snapshot;
  if (newsSentiment === undefined || newsSentiment === null) return 0;
  // newsSentiment expected as [-1..1]
  return clamp(newsSentiment, -1, 1);
}

// ─── Signal Engine Class ─────────────────────────────────────────────────────

export class SignalEngine {
  /**
   * @param {object} [options]
   * @param {number} [options.confidenceThreshold=75] - Min confidence to pass gate
   * @param {number} [options.riskFreeRate=0.065]
   */
  constructor(options = {}) {
    this.confidenceThreshold = options.confidenceThreshold || DEFAULT_CONFIDENCE_THRESHOLD;
    this.riskFreeRate = options.riskFreeRate || RISK_FREE_RATE;
  }

  /**
   * Generate a complete institutional-grade signal from a market snapshot.
   *
   * @param {object} snapshot - Market data object containing:
   *   - symbol {string}
   *   - closes {number[]}
   *   - highs {number[]}
   *   - lows {number[]}
   *   - opens {number[]}
   *   - volumes {number[]}
   *   - ltp {number} - Last traded price
   *   - pcr {number} - Put-call ratio
   *   - oiChange {object} - { ceOI, peOI }
   *   - maxPain {number}
   *   - iv {number} - Implied volatility
   *   - ivPercentile {number}
   *   - fiiFlow {number}
   *   - diiFlow {number}
   *   - blockDeals {object}
   *   - newsSentiment {number}
   *   - expiry {string|Date}
   *
   * @returns {object} Complete signal result
   */
  generate(snapshot) {
    const { symbol, closes, highs, lows, volumes, ltp, expiry } = snapshot;
    const timestamp = new Date().toISOString();

    // Resolve current price
    const currentPrice = ltp || (closes ? closes[closes.length - 1] : null);
    if (!currentPrice) {
      return this._noTradeResult(symbol, timestamp, 'Insufficient price data');
    }

    // ─── Step 1-7: Component Scoring ───────────────────────────────────
    const components = {
      trend: { bias: scoreTrend(snapshot), weight: 25 },
      priceAction: { bias: scorePriceAction(snapshot), weight: 20 },
      options: { bias: scoreOptionsFlow(snapshot), weight: 20 },
      volume: { bias: scoreVolume(snapshot), weight: 10 },
      momentum: { bias: scoreMomentum(snapshot), weight: 10 },
      institutional: { bias: scoreInstitutional(snapshot), weight: 10 },
      news: { bias: scoreNews(snapshot), weight: 5 },
    };

    // Net score: sum(bias * weight)
    let netScore = 0;
    for (const key of Object.keys(components)) {
      netScore += components[key].bias * components[key].weight;
    }
    netScore = clamp(netScore, -100, 100);

    // Agreement factor: how many components agree on direction
    const signs = Object.values(components).map((c) => Math.sign(c.bias));
    const dominant = netScore >= 0 ? 1 : -1;
    const agreeing = signs.filter((s) => s === dominant).length;
    const agreementFactor = agreeing / signs.length;

    // ─── Step 8: Direction & Confidence ────────────────────────────────
    let direction;
    if (netScore > 5) direction = 'BUY';
    else if (netScore < -5) direction = 'SELL';
    else direction = 'NO_TRADE';

    const confidence = Math.min(99, Math.abs(netScore) * 0.62 + agreementFactor * 42);

    // ─── Step 9: Risk Filters ──────────────────────────────────────────
    const rsi = RSI(closes || []);
    let riskVeto = null;

    if (rsi.latest !== null) {
      if (rsi.latest > 85 && direction === 'BUY') {
        riskVeto = 'RSI_OVERBOUGHT';
        direction = 'NO_TRADE';
      }
      if (rsi.latest < 15 && direction === 'SELL') {
        riskVeto = 'RSI_OVERSOLD';
        direction = 'NO_TRADE';
      }
    }

    // VWAP deviation veto
    if (highs && lows && closes && volumes) {
      const vwap = VWAP(highs, lows, closes, volumes);
      if (vwap !== null) {
        const deviation = Math.abs(currentPrice - vwap) / vwap;
        if (deviation > 0.03) {
          // 3% VWAP deviation = extended, risky entry
          if (!riskVeto) riskVeto = 'VWAP_EXTENDED';
          // Don't change direction, but flag it
        }
      }
    }

    // ─── Step 10: Confidence Gate ──────────────────────────────────────
    if (confidence < this.confidenceThreshold && direction !== 'NO_TRADE') {
      return this._noTradeResult(symbol, timestamp, `Confidence ${confidence.toFixed(1)}% below threshold ${this.confidenceThreshold}%`, {
        components,
        netScore,
        confidence,
        riskVeto,
      });
    }

    if (direction === 'NO_TRADE') {
      return this._noTradeResult(symbol, timestamp, riskVeto || 'Neutral score', {
        components,
        netScore,
        confidence,
        riskVeto,
      });
    }

    // ─── Trade Setup ───────────────────────────────────────────────────
    const atr = ATR(highs || [], lows || [], closes || []);
    const effectiveATR = atr || currentPrice * 0.012; // Fallback: 1.2% of price

    const entryZone = {
      ideal: currentPrice,
      low: currentPrice - 0.3 * effectiveATR,
      high: currentPrice + 0.3 * effectiveATR,
    };

    const slDistance = 1.2 * effectiveATR;
    const stopLoss = direction === 'BUY'
      ? currentPrice - slDistance
      : currentPrice + slDistance;

    const targets = [
      { label: 'T1', price: direction === 'BUY' ? currentPrice + 1.0 * effectiveATR : currentPrice - 1.0 * effectiveATR, rr: '1:1' },
      { label: 'T2', price: direction === 'BUY' ? currentPrice + 2.0 * effectiveATR : currentPrice - 2.0 * effectiveATR, rr: '1.67:1' },
      { label: 'T3', price: direction === 'BUY' ? currentPrice + 3.2 * effectiveATR : currentPrice - 3.2 * effectiveATR, rr: '2.67:1' },
    ];

    // ─── Option Strategy ───────────────────────────────────────────────
    const strikeStep = STRIKE_STEPS[symbol] || 50;
    const lotSize = LOT_SIZES[symbol] || 25;
    const atmStrike = roundToStrike(currentPrice, strikeStep);
    const t = expiry ? daysToExpiryYears(expiry) : 3 / 365; // Default: 3 days to expiry
    const iv = snapshot.iv || 0.15;

    const optionType = direction === 'BUY' ? 'CE' : 'PE';
    const estimatedPremium = blackScholesPrice(optionType, currentPrice, atmStrike, t, this.riskFreeRate, iv);
    const delta = blackScholesDelta(optionType, currentPrice, atmStrike, t, this.riskFreeRate, iv);

    const optionStrategy = {
      action: `Buy ${symbol} ${atmStrike} ${optionType}`,
      type: optionType,
      strike: atmStrike,
      estimatedPremium: Math.round(estimatedPremium * 100) / 100,
      delta: Math.round(delta * 1000) / 1000,
      lotSize,
      capitalRequired: Math.round(estimatedPremium * lotSize * 100) / 100,
    };

    // ─── Probability Table ─────────────────────────────────────────────
    const probabilityTable = this._buildProbabilityTable(confidence, direction);

    // ─── Option Plan Builder ───────────────────────────────────────────
    const optionPlan = this._buildOptionPlan(symbol, currentPrice, direction, strikeStep, t, iv, lotSize);

    // ─── Advanced Analytics ────────────────────────────────────────────
    const analytics = this._advancedAnalytics(snapshot, effectiveATR, currentPrice, lotSize, confidence);

    // ─── Final Verdict ─────────────────────────────────────────────────
    const verdict = this._generateVerdict(symbol, direction, confidence, optionStrategy, targets, riskVeto);

    return {
      symbol,
      timestamp,
      direction,
      confidence: Math.round(confidence * 10) / 10,
      netScore: Math.round(netScore * 10) / 10,
      components,
      agreementFactor: Math.round(agreementFactor * 100) / 100,
      riskVeto,
      entryZone,
      stopLoss: Math.round(stopLoss * 100) / 100,
      targets,
      atr: Math.round(effectiveATR * 100) / 100,
      optionStrategy,
      probabilityTable,
      optionPlan,
      analytics,
      verdict,
      rsi: rsi.latest ? Math.round(rsi.latest * 10) / 10 : null,
    };
  }

  /**
   * Build heuristic probability table based on confidence.
   */
  _buildProbabilityTable(confidence, direction) {
    const base = confidence / 100;
    return {
      hitT1: Math.min(95, Math.round((base * 0.82 + 0.12) * 100)),
      hitT2: Math.min(85, Math.round((base * 0.65 + 0.05) * 100)),
      hitT3: Math.min(70, Math.round((base * 0.48 - 0.02) * 100)),
      hitSL: Math.max(5, Math.round((1 - base) * 55)),
      direction,
      note: 'Heuristic probabilities based on historical pattern confidence',
    };
  }

  /**
   * Build per-strike option plans with B-S estimated premiums.
   */
  _buildOptionPlan(symbol, spotPrice, direction, strikeStep, t, iv, lotSize) {
    const atmStrike = roundToStrike(spotPrice, strikeStep);
    const optType = direction === 'BUY' ? 'CE' : 'PE';
    const strikes = [];

    // Generate 5 strikes: 2 ITM, ATM, 2 OTM
    for (let offset = -2; offset <= 2; offset++) {
      const strike = direction === 'BUY'
        ? atmStrike - offset * strikeStep  // For CE: lower = ITM
        : atmStrike + offset * strikeStep; // For PE: higher = ITM

      const premium = blackScholesPrice(optType, spotPrice, strike, t, this.riskFreeRate, iv);
      const delta = blackScholesDelta(optType, spotPrice, strike, t, this.riskFreeRate, iv);
      const moneyness = direction === 'BUY'
        ? (spotPrice - strike) / strikeStep
        : (strike - spotPrice) / strikeStep;

      let label;
      if (Math.abs(moneyness) < 0.5) label = 'ATM';
      else if (moneyness > 0) label = `ITM${Math.abs(offset)}`;
      else label = `OTM${Math.abs(offset)}`;

      strikes.push({
        strike,
        type: optType,
        label,
        premium: Math.round(premium * 100) / 100,
        delta: Math.round(delta * 1000) / 1000,
        lotSize,
        totalCost: Math.round(premium * lotSize * 100) / 100,
        action: offset === 0 ? 'PRIMARY_BUY' : (Math.abs(offset) === 1 ? 'ALTERNATIVE' : 'HEDGE'),
      });
    }

    return {
      symbol,
      direction,
      optionType: optType,
      spotPrice: Math.round(spotPrice * 100) / 100,
      strikes,
      recommendation: `Buy ${symbol} ${atmStrike} ${optType} as primary position`,
    };
  }

  /**
   * Advanced analytics: volatility squeeze, pivots, momentum gauge, risk calc, signal strength.
   */
  _advancedAnalytics(snapshot, atr, currentPrice, lotSize, confidence) {
    const { closes, highs, lows } = snapshot;

    // Volatility Squeeze Detector
    const bb = closes ? bollingerBands(closes) : { upper: null, mid: null, lower: null };
    let volSqueeze = false;
    let bbWidth = null;
    if (bb.upper !== null && bb.lower !== null && bb.mid !== null && bb.mid > 0) {
      bbWidth = ((bb.upper - bb.lower) / bb.mid) * 100;
      volSqueeze = bbWidth < 1.5;
    }

    // Pivot S/R Levels (classic floor pivots from last completed bar)
    let pivots = null;
    if (highs && lows && closes && highs.length >= 2) {
      const h = highs[highs.length - 2];
      const l = lows[lows.length - 2];
      const c = closes[closes.length - 2];
      const pp = (h + l + c) / 3;
      pivots = {
        pp: Math.round(pp * 100) / 100,
        r1: Math.round((2 * pp - l) * 100) / 100,
        r2: Math.round((pp + (h - l)) * 100) / 100,
        r3: Math.round((h + 2 * (pp - l)) * 100) / 100,
        s1: Math.round((2 * pp - h) * 100) / 100,
        s2: Math.round((pp - (h - l)) * 100) / 100,
        s3: Math.round((l - 2 * (h - pp)) * 100) / 100,
      };
    }

    // Momentum Gauge (0-100)
    let momentumGauge = 50; // Neutral default
    if (closes && closes.length >= 14) {
      const rsi = RSI(closes);
      const macd = MACD(closes);
      if (rsi.latest !== null) {
        momentumGauge = rsi.latest; // RSI as primary gauge
        if (macd.hist !== null) {
          // Adjust by MACD histogram sign
          momentumGauge += macd.hist > 0 ? 5 : -5;
        }
      }
    }
    momentumGauge = clamp(Math.round(momentumGauge), 0, 100);

    // Risk Calculator (₹ per lot)
    const riskPerLot = Math.round(atr * 1.2 * lotSize);
    const rewardT1PerLot = Math.round(atr * 1.0 * lotSize);
    const rewardT2PerLot = Math.round(atr * 2.0 * lotSize);

    // Signal Strength Classifier
    let signalStrength;
    if (confidence >= 90) signalStrength = 'VERY_STRONG';
    else if (confidence >= 82) signalStrength = 'STRONG';
    else if (confidence >= 75) signalStrength = 'MODERATE';
    else if (confidence >= 68) signalStrength = 'WEAK';
    else signalStrength = 'VERY_WEAK';

    return {
      volatilitySqueeze: {
        detected: volSqueeze,
        bbWidth: bbWidth !== null ? Math.round(bbWidth * 100) / 100 : null,
        interpretation: volSqueeze
          ? 'Bollinger squeeze detected — explosive move imminent'
          : 'Normal volatility regime',
      },
      pivots,
      momentumGauge: {
        value: momentumGauge,
        label: momentumGauge > 70 ? 'Bullish' : momentumGauge < 30 ? 'Bearish' : 'Neutral',
      },
      riskCalculator: {
        riskPerLot,
        rewardT1PerLot,
        rewardT2PerLot,
        rrRatio: Math.round((rewardT1PerLot / riskPerLot) * 100) / 100,
        currency: 'INR',
        lotSize,
      },
      signalStrength,
    };
  }

  /**
   * Generate the final human-readable verdict.
   */
  _generateVerdict(symbol, direction, confidence, optionStrategy, targets, riskVeto) {
    const action = direction === 'BUY' ? '📈 BUY' : '📉 SELL';
    const strength = confidence >= 85 ? 'HIGH-CONVICTION' : confidence >= 75 ? 'CONFIDENT' : 'MODERATE';

    let verdict = `${action} ${symbol} | ${strength} (${confidence.toFixed(1)}%)\n`;
    verdict += `Strategy: ${optionStrategy.action} @ ₹${optionStrategy.estimatedPremium}\n`;
    verdict += `Targets: ${targets.map((t) => `${t.label}=₹${t.price.toFixed(0)}`).join(' | ')}\n`;
    verdict += `Capital: ₹${optionStrategy.capitalRequired} per lot`;

    if (riskVeto) {
      verdict += `\n⚠️ Risk flag: ${riskVeto}`;
    }

    return verdict;
  }

  /**
   * No-trade result template.
   */
  _noTradeResult(symbol, timestamp, reason, extras = {}) {
    return {
      symbol,
      timestamp,
      direction: 'NO_TRADE',
      confidence: extras.confidence ? Math.round(extras.confidence * 10) / 10 : 0,
      netScore: extras.netScore ? Math.round(extras.netScore * 10) / 10 : 0,
      reason,
      components: extras.components || null,
      riskVeto: extras.riskVeto || null,
      verdict: `⏸️ NO TRADE — ${symbol}: ${reason}`,
    };
  }
}

export default SignalEngine;
