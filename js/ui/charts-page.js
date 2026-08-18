/**
 * charts-page.js — Charts & Technical Analysis
 * TradingView widget integration + indicator readings.
 */

import { getIntradayCandles } from '../data/nse-provider.js';
import { RSI, MACD, ATR, bollingerBands, superTrend, VWAP, SMA, EMA } from '../core/indicators.js';
import { initApp, formatNumber, $ } from '../app.js';

const SYMBOLS = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY',
  'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN'];

const TV_SYMBOL_MAP = {
  'NIFTY': 'NSE:NIFTY',
  'BANKNIFTY': 'NSE:BANKNIFTY',
  'FINNIFTY': 'NSE:NIFTY_FIN_SERVICE',
  'SENSEX': 'BSE:SENSEX',
  'MIDCPNIFTY': 'NSE:NIFTY_MID_SELECT',
  'RELIANCE': 'NSE:RELIANCE',
  'HDFCBANK': 'NSE:HDFCBANK',
  'ICICIBANK': 'NSE:ICICIBANK',
  'INFY': 'NSE:INFY',
  'TCS': 'NSE:TCS',
  'SBIN': 'NSE:SBIN',
};

let currentSymbol = 'NIFTY';
let currentInterval = '15m';

document.addEventListener('DOMContentLoaded', () => {
  initApp();
  initChartsPage();
});

function initChartsPage() {
  // Symbol selector
  const selector = $('#chart-symbol-select');
  if (selector) {
    SYMBOLS.forEach(sym => {
      const opt = document.createElement('option');
      opt.value = sym;
      opt.textContent = sym;
      selector.appendChild(opt);
    });
    selector.addEventListener('change', (e) => {
      currentSymbol = e.target.value;
      loadTradingViewWidget();
      loadIndicators();
    });
  }

  // Timeframe tabs
  document.querySelectorAll('.tf-tab').forEach(tab => {
    tab.addEventListener('click', (e) => {
      document.querySelectorAll('.tf-tab').forEach(t => t.classList.remove('active'));
      e.target.classList.add('active');
      currentInterval = e.target.dataset.interval;
      loadIndicators();
    });
  });

  loadTradingViewWidget();
  loadIndicators();
}

function loadTradingViewWidget() {
  const container = $('#tv-chart-container');
  if (!container) return;

  const tvSymbol = TV_SYMBOL_MAP[currentSymbol] || `NSE:${currentSymbol}`;

  container.innerHTML = '';
  const widgetDiv = document.createElement('div');
  widgetDiv.className = 'tradingview-widget-container';
  widgetDiv.style.height = '100%';
  widgetDiv.innerHTML = `<div class="tradingview-widget-container__widget" style="height:100%"></div>`;
  container.appendChild(widgetDiv);

  const script = document.createElement('script');
  script.type = 'text/javascript';
  script.src = 'https://s3.tradingview.com/external-embedding/embed-widget-advanced-chart.js';
  script.async = true;
  script.innerHTML = JSON.stringify({
    autosize: true,
    symbol: tvSymbol,
    interval: currentInterval === '5m' ? '5' : currentInterval === '15m' ? '15' : currentInterval === '1H' ? '60' : 'D',
    timezone: 'Asia/Kolkata',
    theme: 'dark',
    style: '1',
    locale: 'en',
    backgroundColor: '#0a0e17',
    gridColor: 'rgba(31, 41, 55, 0.3)',
    hide_top_toolbar: false,
    hide_legend: false,
    allow_symbol_change: true,
    save_image: false,
    calendar: false,
    studies: ['STD;RSI', 'STD;MACD'],
    support_host: 'https://www.tradingview.com',
  });
  widgetDiv.appendChild(script);
}

async function loadIndicators() {
  const container = $('#indicator-readings');
  if (!container) return;

  const rangeMap = { '5m': '2d', '15m': '5d', '1H': '15d', '1D': '6mo' };
  const range = rangeMap[currentInterval] || '5d';

  try {
    const candles = await getIntradayCandles(currentSymbol, currentInterval === '1D' ? '1d' : currentInterval, range);
    if (!candles || candles.closes.length < 20) {
      container.innerHTML = '<p class="text-muted text-sm">Indicator data requires market to be open</p>';
      return;
    }

    const { opens, highs, lows, closes, volumes } = candles;
    const ltp = closes[closes.length - 1];

    // Calculate indicators
    const rsi = RSI(closes);
    const macd = MACD(closes);
    const atr = ATR(highs, lows, closes);
    const bb = bollingerBands(closes);
    const st = superTrend(highs, lows, closes);
    const vwap = VWAP(highs, lows, closes, volumes);
    const sma20 = SMA(closes, 20);
    const ema9 = EMA(closes, 9);
    const ema21 = EMA(closes, 21);

    // S/R from pivots
    const h = highs[highs.length - 2];
    const l = lows[lows.length - 2];
    const c = closes[closes.length - 2];
    const pp = (h + l + c) / 3;
    const r1 = 2 * pp - l;
    const s1 = 2 * pp - h;
    const r2 = pp + (h - l);
    const s2 = pp - (h - l);

    // Determine colors
    const rsiColor = rsi.latest > 70 ? 'text-red' : rsi.latest < 30 ? 'text-green' : 'text-blue';
    const macdColor = macd.hist > 0 ? 'text-green' : 'text-red';
    const stColor = st === 'bullish' ? 'text-green' : 'text-red';
    const vwapColor = ltp > vwap ? 'text-green' : 'text-red';

    container.innerHTML = `
      <div class="grid grid-4" style="gap:0.75rem">
        <div class="card" style="padding:0.75rem">
          <div class="card-title">RSI (14)</div>
          <div class="num font-bold ${rsiColor}" style="font-size:1.3rem">${rsi.latest ? rsi.latest.toFixed(1) : '—'}</div>
          <div class="text-xs text-muted">${rsi.latest > 70 ? 'Overbought' : rsi.latest < 30 ? 'Oversold' : 'Neutral'}</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">MACD</div>
          <div class="num font-bold ${macdColor}" style="font-size:1.1rem">${macd.hist !== null ? macd.hist.toFixed(2) : '—'}</div>
          <div class="text-xs text-muted">Signal: ${macd.signal ? macd.signal.toFixed(2) : '—'}</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">SuperTrend</div>
          <div class="num font-bold ${stColor}" style="font-size:1.1rem">${st.toUpperCase()}</div>
          <div class="text-xs text-muted">10, 3.0</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">VWAP</div>
          <div class="num font-bold ${vwapColor}" style="font-size:1.1rem">${vwap ? formatNumber(vwap, 1) : '—'}</div>
          <div class="text-xs text-muted">${ltp > vwap ? 'Above' : 'Below'} VWAP</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">Bollinger Bands</div>
          <div class="text-xs num">U: ${bb.upper ? formatNumber(bb.upper, 1) : '—'}</div>
          <div class="text-xs num">M: ${bb.mid ? formatNumber(bb.mid, 1) : '—'}</div>
          <div class="text-xs num">L: ${bb.lower ? formatNumber(bb.lower, 1) : '—'}</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">ATR (14)</div>
          <div class="num font-bold" style="font-size:1.3rem">${atr ? formatNumber(atr, 1) : '—'}</div>
          <div class="text-xs text-muted">Volatility measure</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">EMAs</div>
          <div class="text-xs num">EMA 9: ${ema9 ? formatNumber(ema9, 1) : '—'}</div>
          <div class="text-xs num">EMA 21: ${ema21 ? formatNumber(ema21, 1) : '—'}</div>
          <div class="text-xs num">SMA 20: ${sma20 ? formatNumber(sma20, 1) : '—'}</div>
        </div>
        <div class="card" style="padding:0.75rem">
          <div class="card-title">Pivot Levels</div>
          <div class="text-xs num text-red">R2: ${formatNumber(r2, 0)}</div>
          <div class="text-xs num text-red">R1: ${formatNumber(r1, 0)}</div>
          <div class="text-xs num text-blue font-bold">PP: ${formatNumber(pp, 0)}</div>
          <div class="text-xs num text-green">S1: ${formatNumber(s1, 0)}</div>
          <div class="text-xs num text-green">S2: ${formatNumber(s2, 0)}</div>
        </div>
      </div>`;
  } catch (e) {
    console.error('Indicators error:', e);
    container.innerHTML = '<p class="text-muted text-sm">Failed to load indicator data</p>';
  }
}
