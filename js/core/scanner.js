/**
 * scanner.js — Market Scanner for F&O Option Trading
 *
 * Scans a universe of instruments, generates signals for each, filters
 * by minimum confidence, and returns ranked BUY/SELL opportunities.
 *
 * Designed for LIVE TRADING — applies strict confidence filters and
 * risk controls to protect capital.
 */

import { SignalEngine } from './signal-engine.js';

// ─── Default Universe ────────────────────────────────────────────────────────

/** Index instruments */
const INDEX_UNIVERSE = [
  'NIFTY',
  'BANKNIFTY',
  'FINNIFTY',
  'SENSEX',
  'MIDCPNIFTY',
];

/** Stock F&O instruments */
const STOCK_UNIVERSE = [
  'RELIANCE',
  'HDFCBANK',
  'ICICIBANK',
  'INFY',
  'TCS',
  'SBIN',
  'BHARTIARTL',
  'ITC',
  'KOTAKBANK',
  'LT',
  'AXISBANK',
  'TATAMOTORS',
  'MARUTI',
  'WIPRO',
  'HCLTECH',
  'ADANIENT',
  'BAJFINANCE',
  'TITAN',
];

/** Full default universe */
const DEFAULT_UNIVERSE = [...INDEX_UNIVERSE, ...STOCK_UNIVERSE];

/** Minimum confidence to include in results (conservative for live trading) */
const DEFAULT_MIN_CONFIDENCE = 68;

// ─── Scanner Class ───────────────────────────────────────────────────────────

export class Scanner {
  /**
   * @param {object} [options]
   * @param {string[]} [options.universe] - Custom instrument list
   * @param {number} [options.minConfidence=68] - Minimum confidence threshold
   * @param {number} [options.confidenceThreshold=75] - Engine confidence gate
   * @param {function} [options.fetchData] - Async function(symbol) → snapshot
   */
  constructor(options = {}) {
    this.universe = options.universe || DEFAULT_UNIVERSE;
    this.minConfidence = options.minConfidence || DEFAULT_MIN_CONFIDENCE;
    this.engine = new SignalEngine({
      confidenceThreshold: options.confidenceThreshold || 75,
    });
    this.fetchData = options.fetchData || null;
  }

  /**
   * Run a full scan of the instrument universe.
   *
   * For each instrument:
   *   1. Fetch lite market data via the configured fetchData function
   *   2. Run signal engine evaluation (no AI — pure quantitative)
   *   3. Filter by min confidence
   *   4. Sort BUY/SELL separately by confidence descending
   *
   * @param {object} [overrides]
   * @param {function} [overrides.fetchData] - Override data fetcher for this scan
   * @param {object} [overrides.macro] - Macro context (VIX, advance/decline, etc.)
   * @returns {Promise<object>} Scan results
   */
  async scan(overrides = {}) {
    const startTime = Date.now();
    const fetchFn = overrides.fetchData || this.fetchData;

    if (!fetchFn) {
      throw new Error('Scanner requires a fetchData function to retrieve market snapshots');
    }

    const results = [];
    const errors = [];

    // Scan each instrument concurrently with controlled parallelism
    const batchSize = 5;
    for (let i = 0; i < this.universe.length; i += batchSize) {
      const batch = this.universe.slice(i, i + batchSize);
      const batchResults = await Promise.allSettled(
        batch.map(async (symbol) => {
          try {
            const snapshot = await fetchFn(symbol);
            if (!snapshot || !snapshot.closes || snapshot.closes.length < 20) {
              errors.push({ symbol, error: 'Insufficient data' });
              return null;
            }
            // Ensure symbol is attached
            snapshot.symbol = symbol;
            // Generate signal (no AI - pure quantitative)
            const signal = this.engine.generate(snapshot);
            return signal;
          } catch (err) {
            errors.push({ symbol, error: err.message || 'Fetch failed' });
            return null;
          }
        })
      );

      for (const result of batchResults) {
        if (result.status === 'fulfilled' && result.value !== null) {
          results.push(result.value);
        }
      }
    }

    // Filter by minimum confidence and actionable direction
    const actionable = results.filter(
      (r) => r.direction !== 'NO_TRADE' && r.confidence >= this.minConfidence
    );

    // Separate and sort BUY / SELL
    const buySignals = actionable
      .filter((r) => r.direction === 'BUY')
      .sort((a, b) => b.confidence - a.confidence);

    const sellSignals = actionable
      .filter((r) => r.direction === 'SELL')
      .sort((a, b) => b.confidence - a.confidence);

    // Build macro context
    const macro = overrides.macro || this._defaultMacro();

    return {
      generated_at: new Date().toISOString(),
      scan_duration_ms: Date.now() - startTime,
      scanned: this.universe.length,
      successful: results.length,
      filtered: actionable.length,
      errors: errors.length > 0 ? errors : undefined,
      buy: buySignals,
      sell: sellSignals,
      macro,
      metadata: {
        minConfidence: this.minConfidence,
        engineThreshold: this.engine.confidenceThreshold,
        universe: this.universe,
      },
    };
  }

  /**
   * Scan a single instrument.
   * @param {string} symbol
   * @param {object} snapshot - Pre-fetched market snapshot
   * @returns {object} Signal result
   */
  scanSingle(symbol, snapshot) {
    snapshot.symbol = symbol;
    return this.engine.generate(snapshot);
  }

  /**
   * Quick-rank: score all instruments and return sorted list without full signal.
   * Useful for watchlist prioritization.
   * @param {function} fetchFn
   * @returns {Promise<Array<{symbol: string, score: number, direction: string}>>}
   */
  async quickRank(fetchFn) {
    const fn = fetchFn || this.fetchData;
    if (!fn) throw new Error('Requires fetchData function');

    const rankings = [];

    for (const symbol of this.universe) {
      try {
        const snapshot = await fn(symbol);
        if (!snapshot || !snapshot.closes) continue;
        snapshot.symbol = symbol;
        const signal = this.engine.generate(snapshot);
        rankings.push({
          symbol,
          score: signal.netScore,
          direction: signal.direction,
          confidence: signal.confidence,
        });
      } catch (_) {
        // Skip failed instruments in quick rank
      }
    }

    return rankings.sort((a, b) => Math.abs(b.score) - Math.abs(a.score));
  }

  /**
   * Default macro context stub — should be replaced with live data in production.
   */
  _defaultMacro() {
    return {
      indiaVIX: null,
      advanceDecline: null,
      globalCues: null,
      note: 'Connect live macro feed for VIX, advance/decline ratio, and global cues',
    };
  }

  /**
   * Get the configured universe.
   * @returns {string[]}
   */
  getUniverse() {
    return [...this.universe];
  }

  /**
   * Update the instrument universe dynamically.
   * @param {string[]} symbols
   */
  setUniverse(symbols) {
    this.universe = [...symbols];
  }

  /**
   * Add instruments to the universe.
   * @param {string[]} symbols
   */
  addToUniverse(symbols) {
    const set = new Set(this.universe);
    for (const s of symbols) set.add(s);
    this.universe = [...set];
  }
}

export default Scanner;
