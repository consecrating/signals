/**
 * nse-provider.js — Live NSE market data provider
 * 
 * Fetches real-time data from NSE via the Cloudflare Worker proxy.
 * Computes option chain analytics (PCR, Max Pain, OI changes).
 * Integrates Yahoo Finance for intraday candles and technical indicators.
 *
 * THIS IS PRODUCTION CODE — connected to LIVE market data.
 * All data is real and used for actual trading decisions.
 */

// ─── Configuration ───────────────────────────────────────────────────────────

const NSE_PROXY_BASE = 'https://fno-nse-proxy.tanvipubg.workers.dev/';
const NSE_PROXY_KEY = 'NseProxy_9fK2xQ7mZ4vB8nR3wL6tY';

const NSE_API = {
  OPTION_CHAIN_INDEX: 'https://www.nseindia.com/api/option-chain-indices?symbol=',
  OPTION_CHAIN_EQUITY: 'https://www.nseindia.com/api/option-chain-equities?symbol=',
  FII_DII: 'https://www.nseindia.com/api/fiidiiTradeReact',
  ALL_INDICES: 'https://www.nseindia.com/api/allIndices',
  MARKET_STATUS: 'https://www.nseindia.com/api/marketStatus',
  EQUITY_META: 'https://www.nseindia.com/api/equity-meta?symbol=',
};

/** Yahoo Finance chart API for intraday candles */
const YAHOO_CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

/** Symbol mapping: NSE → Yahoo */
const YAHOO_SYMBOLS = {
  NIFTY: '^NSEI',
  BANKNIFTY: '^NSEBANK',
  FINNIFTY: 'NIFTY_FIN_SERVICE.NS',
  SENSEX: '^BSESN',
  MIDCPNIFTY: 'NIFTY_MID_SELECT.NS',
  INDIAVIX: '^INDIAVIX',
};

/** F&O Index list */
const FNO_INDICES = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY'];

/** Lot sizes */
const LOT_SIZES = {
  NIFTY: 25, BANKNIFTY: 15, FINNIFTY: 25, SENSEX: 10, MIDCPNIFTY: 50,
  RELIANCE: 250, HDFCBANK: 550, ICICIBANK: 700, INFY: 300, TCS: 175,
  SBIN: 750, BHARTIARTL: 475, ITC: 1600, KOTAKBANK: 400, LT: 150,
  AXISBANK: 600, TATAMOTORS: 1125, MARUTI: 100, WIPRO: 1500,
  HCLTECH: 350, ADANIENT: 250, BAJFINANCE: 125, TITAN: 175,
};

// ─── Cache Layer ─────────────────────────────────────────────────────────────

const cache = new Map();
const CACHE_TTL = {
  optionChain: 45000,     // 45 seconds
  indices: 30000,         // 30 seconds
  fiiDii: 300000,         // 5 minutes
  yahoo: 60000,           // 1 minute
  vix: 30000,             // 30 seconds
};

function getCached(key, ttl) {
  const entry = cache.get(key);
  if (entry && Date.now() - entry.time < ttl) return entry.data;
  return null;
}

function setCache(key, data) {
  cache.set(key, { data, time: Date.now() });
}

// ─── NSE Proxy Fetcher ───────────────────────────────────────────────────────

/**
 * Fetch with timeout wrapper.
 */
async function fetchWithTimeout(url, options = {}, timeoutMs = 8000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const res = await fetch(url, { ...options, signal: controller.signal });
    clearTimeout(timer);
    return res;
  } catch (err) {
    clearTimeout(timer);
    throw err;
  }
}

/**
 * Fetch from NSE via the Cloudflare Worker proxy.
 * Handles errors gracefully — returns null on failure.
 */
async function fetchNSE(nseUrl) {
  const proxyUrl = `${NSE_PROXY_BASE}?url=${encodeURIComponent(nseUrl)}&key=${NSE_PROXY_KEY}`;
  try {
    const res = await fetchWithTimeout(proxyUrl, {
      headers: { 'Accept': 'application/json' },
    }, 8000);
    if (!res.ok) {
      console.warn(`NSE Proxy error: ${res.status} for ${nseUrl}`);
      return null;
    }
    const data = await res.json();
    if (data && data.error) {
      console.warn(`NSE Proxy returned error: ${data.error}`);
      return null;
    }
    return data;
  } catch (err) {
    if (err.name === 'AbortError') {
      console.warn(`NSE fetch timeout for ${nseUrl}`);
    } else {
      console.warn(`NSE fetch failed: ${err.message}`);
    }
    return null;
  }
}

/**
 * Fetch from Yahoo Finance chart API.
 * NOTE: Yahoo blocks CORS from browsers. This only works server-side or via proxy.
 */
async function fetchYahoo(symbol, interval = '15m', range = '5d') {
  const yahooSymbol = YAHOO_SYMBOLS[symbol] || `${symbol}.NS`;
  const url = `${YAHOO_CHART_URL}${encodeURIComponent(yahooSymbol)}?interval=${interval}&range=${range}&includePrePost=false`;
  try {
    const res = await fetchWithTimeout(url, {
      mode: 'cors',
    }, 6000);
    if (!res.ok) {
      console.warn(`Yahoo ${res.status} for ${symbol}`);
      return null;
    }
    const data = await res.json();
    if (!data.chart || !data.chart.result || !data.chart.result[0]) return null;
    return data.chart.result[0];
  } catch (err) {
    // CORS errors, network errors, and timeouts all land here
    // This is expected when running in browser without a proxy
    return null;
  }
}

// ─── Data Provider Functions ─────────────────────────────────────────────────

/**
 * Get live option chain data for an instrument.
 * Computes: PCR, Max Pain, OI changes, IV, strike data.
 */
export async function getOptionChain(symbol) {
  const cacheKey = `oc_${symbol}`;
  const cached = getCached(cacheKey, CACHE_TTL.optionChain);
  if (cached) return cached;

  const isIndex = FNO_INDICES.includes(symbol);
  const url = isIndex
    ? `${NSE_API.OPTION_CHAIN_INDEX}${symbol}`
    : `${NSE_API.OPTION_CHAIN_EQUITY}${symbol}`;

  const data = await fetchNSE(url);
  if (!data || !data.records || !data.records.data) return null;

  const records = data.records;
  const strikePrices = records.strikePrices || [];
  const expiryDates = records.expiryDates || [];
  const underlyingValue = records.underlyingValue || data.records.data[0]?.PE?.underlyingValue || 0;

  // Use nearest expiry
  const nearestExpiry = expiryDates[0];
  const nearExpData = records.data.filter(d => d.expiryDate === nearestExpiry);

  // Compute PCR (Put OI / Call OI)
  let totalCallOI = 0, totalPutOI = 0;
  let totalCallOIChg = 0, totalPutOIChg = 0;
  let totalIV = 0, ivCount = 0;
  const strikeData = [];

  for (const row of nearExpData) {
    const callOI = row.CE?.openInterest || 0;
    const putOI = row.PE?.openInterest || 0;
    const callOIChg = row.CE?.changeinOpenInterest || 0;
    const putOIChg = row.PE?.changeinOpenInterest || 0;
    const callIV = row.CE?.impliedVolatility || 0;
    const putIV = row.PE?.impliedVolatility || 0;

    totalCallOI += callOI;
    totalPutOI += putOI;
    totalCallOIChg += callOIChg;
    totalPutOIChg += putOIChg;

    if (callIV > 0) { totalIV += callIV; ivCount++; }
    if (putIV > 0) { totalIV += putIV; ivCount++; }

    strikeData.push({
      strike: row.strikePrice,
      CE: row.CE ? {
        oi: callOI,
        oiChg: callOIChg,
        volume: row.CE.totalTradedVolume || 0,
        iv: callIV,
        ltp: row.CE.lastPrice || 0,
        change: row.CE.change || 0,
        bidQty: row.CE.bidQty || 0,
        askQty: row.CE.askQty || 0,
      } : null,
      PE: row.PE ? {
        oi: putOI,
        oiChg: putOIChg,
        volume: row.PE.totalTradedVolume || 0,
        iv: putIV,
        ltp: row.PE.lastPrice || 0,
        change: row.PE.change || 0,
        bidQty: row.PE.bidQty || 0,
        askQty: row.PE.askQty || 0,
      } : null,
    });
  }

  const pcr = totalCallOI > 0 ? Math.round((totalPutOI / totalCallOI) * 1000) / 1000 : 0;
  const avgIV = ivCount > 0 ? totalIV / ivCount : 15;

  // Compute Max Pain
  const maxPain = computeMaxPain(nearExpData);

  const result = {
    symbol,
    underlyingValue,
    expiry: nearestExpiry,
    expiryDates,
    pcr,
    maxPain,
    avgIV: Math.round(avgIV * 100) / 100,
    totalCallOI,
    totalPutOI,
    callOIChange: totalCallOIChg,
    putOIChange: totalPutOIChg,
    strikeData,
    strikePrices: nearExpData.map(d => d.strikePrice),
    timestamp: new Date().toISOString(),
  };

  setCache(cacheKey, result);
  return result;
}

/**
 * Compute Max Pain: strike that causes minimum payout to option writers.
 */
function computeMaxPain(chainData) {
  if (!chainData || chainData.length === 0) return null;

  let minPain = Infinity;
  let maxPainStrike = null;

  const strikes = chainData.map(d => d.strikePrice);

  for (const testStrike of strikes) {
    let totalPain = 0;

    for (const row of chainData) {
      const callOI = row.CE?.openInterest || 0;
      const putOI = row.PE?.openInterest || 0;
      const strike = row.strikePrice;

      // Call writers pain: if expiry above this strike, calls are ITM
      if (testStrike > strike) {
        totalPain += (testStrike - strike) * callOI;
      }
      // Put writers pain: if expiry below this strike, puts are ITM
      if (testStrike < strike) {
        totalPain += (strike - testStrike) * putOI;
      }
    }

    if (totalPain < minPain) {
      minPain = totalPain;
      maxPainStrike = testStrike;
    }
  }

  return maxPainStrike;
}

/**
 * Get all indices data (NIFTY, BANKNIFTY, etc.) with advance/decline.
 */
export async function getAllIndices() {
  const cacheKey = 'all_indices';
  const cached = getCached(cacheKey, CACHE_TTL.indices);
  if (cached) return cached;

  const data = await fetchNSE(NSE_API.ALL_INDICES);
  if (!data || !data.data) return null;

  const indices = {};
  for (const idx of data.data) {
    indices[idx.indexSymbol || idx.index] = {
      name: idx.index,
      last: idx.last,
      change: idx.percentChange,
      open: idx.open,
      high: idx.high,
      low: idx.low,
      prevClose: idx.previousClose,
      advances: idx.advances,
      declines: idx.declines,
      unchanged: idx.unchanged,
    };
  }

  setCache(cacheKey, indices);
  return indices;
}

/**
 * Get FII/DII activity data.
 */
export async function getFIIDII() {
  const cacheKey = 'fii_dii';
  const cached = getCached(cacheKey, CACHE_TTL.fiiDii);
  if (cached) return cached;

  const data = await fetchNSE(NSE_API.FII_DII);
  if (!data || !Array.isArray(data)) return null;

  const result = { fii: null, dii: null };

  for (const entry of data) {
    const category = (entry.category || '').toUpperCase();
    if (category.includes('FII') || category.includes('FPI')) {
      result.fii = {
        buyValue: parseFloat(entry.buyValue) || 0,
        sellValue: parseFloat(entry.sellValue) || 0,
        netValue: parseFloat(entry.netValue) || 0,
        date: entry.date,
      };
    }
    if (category.includes('DII')) {
      result.dii = {
        buyValue: parseFloat(entry.buyValue) || 0,
        sellValue: parseFloat(entry.sellValue) || 0,
        netValue: parseFloat(entry.netValue) || 0,
        date: entry.date,
      };
    }
  }

  setCache(cacheKey, result);
  return result;
}

/**
 * Get India VIX from Yahoo Finance.
 */
export async function getIndiaVIX() {
  const cacheKey = 'india_vix';
  const cached = getCached(cacheKey, CACHE_TTL.vix);
  if (cached) return cached;

  const data = await fetchYahoo('INDIAVIX', '1d', '2d');
  if (!data || !data.meta) return null;

  const result = {
    value: data.meta.regularMarketPrice || null,
    prevClose: data.meta.chartPreviousClose || null,
    change: null,
  };

  if (result.value && result.prevClose) {
    result.change = Math.round(((result.value - result.prevClose) / result.prevClose) * 10000) / 100;
  }

  setCache(cacheKey, result);
  return result;
}

/**
 * Get intraday candle data from Yahoo Finance.
 * Returns arrays: opens, highs, lows, closes, volumes, timestamps.
 */
export async function getIntradayCandles(symbol, interval = '15m', range = '5d') {
  const cacheKey = `candles_${symbol}_${interval}_${range}`;
  const cached = getCached(cacheKey, CACHE_TTL.yahoo);
  if (cached) return cached;

  const data = await fetchYahoo(symbol, interval, range);
  if (!data || !data.indicators || !data.indicators.quote || !data.indicators.quote[0]) return null;

  const quote = data.indicators.quote[0];
  const timestamps = data.timestamp || [];

  // Filter out null entries
  const opens = [], highs = [], lows = [], closes = [], volumes = [], times = [];
  for (let i = 0; i < timestamps.length; i++) {
    if (quote.close[i] !== null && quote.open[i] !== null) {
      opens.push(quote.open[i]);
      highs.push(quote.high[i]);
      lows.push(quote.low[i]);
      closes.push(quote.close[i]);
      volumes.push(quote.volume[i] || 0);
      times.push(timestamps[i]);
    }
  }

  const result = { opens, highs, lows, closes, volumes, timestamps: times, symbol };
  setCache(cacheKey, result);
  return result;
}

/**
 * Get daily candle data for longer-term analysis.
 */
export async function getDailyCandles(symbol, range = '6mo') {
  return getIntradayCandles(symbol, '1d', range);
}

/**
 * Build a complete market snapshot for the signal engine.
 * This is the canonical data contract expected by SignalEngine.generate().
 */
export async function buildSnapshot(symbol) {
  try {
    // Fetch intraday candles and option chain in parallel
    const [candles, optionChain, fiiDii, vix] = await Promise.all([
      getIntradayCandles(symbol, '15m', '5d').catch(() => null),
      getOptionChain(symbol).catch(() => null),
      getFIIDII().catch(() => null),
      getIndiaVIX().catch(() => null),
    ]);

    if (!candles || candles.closes.length < 20) {
      return null; // Insufficient data
    }

    const ltp = candles.closes[candles.closes.length - 1];

    // Determine nearest expiry for option plan
    let expiry = null;
    if (optionChain && optionChain.expiry) {
      expiry = optionChain.expiry;
    }

    const snapshot = {
      symbol,
      ltp,
      opens: candles.opens,
      highs: candles.highs,
      lows: candles.lows,
      closes: candles.closes,
      volumes: candles.volumes,
      timestamps: candles.timestamps,

      // Option chain data
      pcr: optionChain?.pcr || null,
      maxPain: optionChain?.maxPain || null,
      iv: optionChain ? optionChain.avgIV / 100 : 0.15, // Convert to decimal
      ivPercentile: null, // Would need historical IV for this
      oiChange: optionChain ? {
        ceOI: optionChain.callOIChange,
        peOI: optionChain.putOIChange,
      } : null,

      // Institutional
      fiiFlow: fiiDii?.fii?.netValue || null,
      diiFlow: fiiDii?.dii?.netValue || null,
      blockDeals: null,

      // Market context
      vix: vix?.value || null,
      newsSentiment: null, // Will be computed separately if needed

      // Expiry for option plans
      expiry,

      // Metadata
      isIndex: FNO_INDICES.includes(symbol),
      lotSize: LOT_SIZES[symbol] || 25,
      timestamp: new Date().toISOString(),
    };

    return snapshot;
  } catch (err) {
    console.warn(`buildSnapshot failed for ${symbol}:`, err.message);
    return null;
  }
}

/**
 * Get market status (open/closed/pre-open).
 */
export async function getMarketStatus() {
  const data = await fetchNSE(NSE_API.MARKET_STATUS);
  if (!data || !data.marketState) return { status: 'unknown' };

  const equity = data.marketState.find(m => m.market === 'Capital Market');
  return {
    status: equity?.marketStatus || 'unknown',
    reason: equity?.tradeDate || '',
    timestamp: new Date().toISOString(),
  };
}

/**
 * Check if market is currently open (Mon-Fri, 9:15 AM - 3:30 PM IST).
 */
export function isMarketHours() {
  const now = new Date();
  const ist = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  const day = ist.getDay();
  const hours = ist.getHours();
  const minutes = ist.getMinutes();
  const timeInMinutes = hours * 60 + minutes;

  // Weekday check (Mon=1 to Fri=5)
  if (day === 0 || day === 6) return false;

  // Market hours: 9:15 AM (555 min) to 3:30 PM (930 min)
  return timeInMinutes >= 555 && timeInMinutes <= 930;
}

/**
 * Get lot size for a symbol.
 */
export function getLotSize(symbol) {
  return LOT_SIZES[symbol] || 25;
}

/**
 * Export all constants for UI use.
 */
export const CONFIG = {
  NSE_PROXY_BASE,
  FNO_INDICES,
  LOT_SIZES,
  YAHOO_SYMBOLS,
  CACHE_TTL,
};

export default {
  getOptionChain,
  getAllIndices,
  getFIIDII,
  getIndiaVIX,
  getIntradayCandles,
  getDailyCandles,
  buildSnapshot,
  getMarketStatus,
  isMarketHours,
  getLotSize,
  CONFIG,
};
