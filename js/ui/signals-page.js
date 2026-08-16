/**
 * signals-page.js — Signal Generator page logic
 * Full signal generation with AI narrative, option plan, and advanced analytics.
 */

import { buildSnapshot, getOptionChain, getLotSize, CONFIG } from '../data/nse-provider.js';
import { SignalEngine } from '../core/signal-engine.js';
import { AIAnalyst } from '../core/ai-analyst.js';
import { initApp, APP_CONFIG, notify, refreshManager, formatNumber, formatINR, showLoading, showError, $ } from '../app.js';

const engine = new SignalEngine({ confidenceThreshold: 70 });
const aiAnalyst = new AIAnalyst({ apiKey: APP_CONFIG.OPENROUTER_API_KEY });

let currentSignal = null;
let autoRefreshEnabled = false;

// ─── Instruments List ────────────────────────────────────────────────────────
const INSTRUMENTS = [
  'NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY',
  'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS',
  'SBIN', 'BHARTIARTL', 'ITC', 'KOTAKBANK', 'LT',
  'AXISBANK', 'TATAMOTORS', 'MARUTI', 'WIPRO', 'HCLTECH',
  'ADANIENT', 'BAJFINANCE', 'TITAN',
];

// ─── Initialization ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initApp();
  initSignalPage();
});

function initSignalPage() {
  // Populate instrument selector
  const selector = $('#instrument-select');
  if (selector) {
    INSTRUMENTS.forEach(sym => {
      const opt = document.createElement('option');
      opt.value = sym;
      opt.textContent = sym;
      selector.appendChild(opt);
    });
    selector.addEventListener('change', () => generateSignal());
  }

  // Generate button
  const genBtn = $('#generate-btn');
  if (genBtn) {
    genBtn.addEventListener('click', () => generateSignal());
  }

  // Auto-refresh toggle
  const autoBtn = $('#auto-refresh-toggle');
  if (autoBtn) {
    autoBtn.addEventListener('click', () => {
      autoRefreshEnabled = !autoRefreshEnabled;
      autoBtn.textContent = autoRefreshEnabled ? '⏸ Stop Auto' : '▶ Auto Refresh';
      autoBtn.className = autoRefreshEnabled ? 'btn btn-danger' : 'btn btn-outline';
      if (autoRefreshEnabled) {
        refreshManager.register(generateSignal);
        refreshManager.start();
      } else {
        refreshManager.stop();
      }
    });
  }

  // Generate initial signal
  generateSignal();
}

// ─── Signal Generation ───────────────────────────────────────────────────────
async function generateSignal() {
  const symbol = $('#instrument-select')?.value || 'NIFTY';
  const resultsDiv = $('#signal-results');
  if (!resultsDiv) return;

  showLoading(resultsDiv);

  try {
    // 1. Build market snapshot
    const snapshot = await buildSnapshot(symbol);
    if (!snapshot) {
      showError(resultsDiv, 'Unable to fetch market data for ' + symbol + '. Market may be closed.');
      return;
    }

    // 2. Generate signal
    const signal = engine.generate(snapshot);
    currentSignal = signal;

    // 3. Render the full signal UI
    renderSignal(signal, resultsDiv);

    // 4. Generate AI narrative (async, non-blocking)
    if (signal.direction !== 'NO_TRADE') {
      generateAINarrative(signal);
    }

  } catch (e) {
    console.error('Signal generation error:', e);
    showError(resultsDiv, 'Signal generation failed: ' + e.message);
  }
}

// ─── Render Signal ───────────────────────────────────────────────────────────
function renderSignal(signal, container) {
  const { symbol, direction, confidence, netScore, components, entryZone, stopLoss, targets, atr, optionStrategy, probabilityTable, optionPlan, analytics, verdict, rsi, riskVeto } = signal;

  const dirClass = direction === 'BUY' ? 'buy' : direction === 'SELL' ? 'sell' : 'no-trade';
  const dirLabel = direction === 'NO_TRADE' ? 'NO TRADE' : direction;

  let html = `
    <!-- Signal Display -->
    <div class="card signal-display">
      <div class="signal-direction ${dirClass}">${dirLabel}</div>
      <div class="text-secondary text-sm">${symbol} · Score: ${netScore || 0}/100</div>
    </div>

    <div class="grid grid-2 mt-2">
      <!-- Confidence Gauge -->
      <div class="card" style="text-align:center">
        <div class="card-title">CONFIDENCE</div>
        ${renderGauge(confidence || 0, dirClass)}
      </div>

      <!-- Trade Setup -->
      <div class="card">
        <div class="card-title">TRADE SETUP</div>
        ${direction !== 'NO_TRADE' && entryZone ? `
          <div style="margin-top:0.75rem">
            <div class="flex justify-between mb-1">
              <span class="text-xs text-muted">Entry Zone</span>
              <span class="num text-sm">${formatINR(entryZone.low)} – ${formatINR(entryZone.high)}</span>
            </div>
            <div class="flex justify-between mb-1">
              <span class="text-xs text-muted">Stop Loss</span>
              <span class="num text-sm text-red">${formatINR(stopLoss)}</span>
            </div>
            ${targets ? targets.map(t => `
              <div class="flex justify-between mb-1">
                <span class="text-xs text-muted">${t.label} (${t.rr})</span>
                <span class="num text-sm text-green">${formatINR(t.price)}</span>
              </div>`).join('') : ''}
            <div class="flex justify-between mt-1">
              <span class="text-xs text-muted">ATR</span>
              <span class="num text-sm">${formatINR(atr)}</span>
            </div>
          </div>
        ` : `<p class="text-muted text-sm mt-1">${signal.reason || 'No actionable setup at this time'}</p>`}
      </div>
    </div>`;

  // Option Strategy
  if (optionStrategy && direction !== 'NO_TRADE') {
    html += `
    <div class="card mt-2">
      <div class="card-title">OPTION STRATEGY</div>
      <div class="grid grid-4 mt-1" style="gap:0.75rem">
        <div><span class="text-xs text-muted">Action</span><div class="num font-bold">${optionStrategy.action}</div></div>
        <div><span class="text-xs text-muted">Premium</span><div class="num font-bold">${formatINR(optionStrategy.estimatedPremium)}</div></div>
        <div><span class="text-xs text-muted">Delta</span><div class="num">${optionStrategy.delta}</div></div>
        <div><span class="text-xs text-muted">Capital/Lot</span><div class="num font-bold text-amber">${formatINR(optionStrategy.capitalRequired)}</div></div>
      </div>
    </div>`;
  }

  // Probability Table
  if (probabilityTable && direction !== 'NO_TRADE') {
    html += `
    <div class="card mt-2">
      <div class="card-title">PROBABILITY TABLE</div>
      <div style="margin-top:0.75rem">
        ${renderProbBar('Hit T1', probabilityTable.hitT1, 'green')}
        ${renderProbBar('Hit T2', probabilityTable.hitT2, 'green')}
        ${renderProbBar('Hit T3', probabilityTable.hitT3, 'blue')}
        ${renderProbBar('Hit SL', probabilityTable.hitSL, 'red')}
      </div>
      <p class="text-xs text-muted mt-1">${probabilityTable.note}</p>
    </div>`;
  }

  // Option Plan
  if (optionPlan && optionPlan.strikes && direction !== 'NO_TRADE') {
    html += `
    <div class="card mt-2">
      <div class="card-title">OPTION PLAN — MULTI-STRIKE COMPARISON</div>
      <div class="overflow-auto mt-1">
        <table class="data-table">
          <thead><tr>
            <th>Strike</th><th>Type</th><th>Label</th><th>Premium</th><th>Delta</th><th>Total Cost</th><th>Role</th>
          </tr></thead>
          <tbody>
            ${optionPlan.strikes.map(s => `
              <tr${s.action === 'PRIMARY_BUY' ? ' style="background:var(--accent-dim)"' : ''}>
                <td>${s.strike}</td>
                <td>${s.type}</td>
                <td>${s.label}</td>
                <td>${formatINR(s.premium)}</td>
                <td>${s.delta}</td>
                <td class="font-bold">${formatINR(s.totalCost)}</td>
                <td><span class="confidence-badge ${s.action === 'PRIMARY_BUY' ? 'high' : 'medium'}">${s.action}</span></td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <p class="text-sm mt-1 text-muted">Recommendation: ${optionPlan.recommendation}</p>
    </div>`;
  }

  // Advanced Analytics
  if (analytics && direction !== 'NO_TRADE') {
    html += `
    <div class="grid grid-2 mt-2">
      <!-- Volatility Squeeze -->
      <div class="card">
        <div class="card-title">VOLATILITY SQUEEZE</div>
        <div class="mt-1">
          <span class="num font-bold ${analytics.volatilitySqueeze.detected ? 'text-amber' : 'text-muted'}">
            ${analytics.volatilitySqueeze.detected ? '⚡ SQUEEZE DETECTED' : 'Normal Regime'}
          </span>
          <p class="text-xs text-muted mt-1">BB Width: ${analytics.volatilitySqueeze.bbWidth || '—'}%</p>
          <p class="text-xs text-muted">${analytics.volatilitySqueeze.interpretation}</p>
        </div>
      </div>

      <!-- Momentum Gauge -->
      <div class="card">
        <div class="card-title">MOMENTUM GAUGE</div>
        <div style="text-align:center;margin-top:0.5rem">
          ${renderGauge(analytics.momentumGauge.value, analytics.momentumGauge.label === 'Bullish' ? 'buy' : analytics.momentumGauge.label === 'Bearish' ? 'sell' : 'no-trade', 80)}
          <p class="text-xs text-muted">${analytics.momentumGauge.label}</p>
        </div>
      </div>

      <!-- Pivot Levels -->
      <div class="card">
        <div class="card-title">PIVOT S/R LEVELS</div>
        ${analytics.pivots ? `
        <div style="margin-top:0.5rem">
          <div class="flex justify-between text-xs"><span class="text-red">R3</span><span class="num">${analytics.pivots.r3}</span></div>
          <div class="flex justify-between text-xs"><span class="text-red">R2</span><span class="num">${analytics.pivots.r2}</span></div>
          <div class="flex justify-between text-xs"><span class="text-red">R1</span><span class="num">${analytics.pivots.r1}</span></div>
          <div class="flex justify-between text-xs" style="background:var(--accent-dim);padding:0.2rem 0.4rem;border-radius:4px"><span class="text-blue font-bold">PP</span><span class="num font-bold">${analytics.pivots.pp}</span></div>
          <div class="flex justify-between text-xs"><span class="text-green">S1</span><span class="num">${analytics.pivots.s1}</span></div>
          <div class="flex justify-between text-xs"><span class="text-green">S2</span><span class="num">${analytics.pivots.s2}</span></div>
          <div class="flex justify-between text-xs"><span class="text-green">S3</span><span class="num">${analytics.pivots.s3}</span></div>
        </div>` : '<p class="text-muted text-xs">Insufficient data</p>'}
      </div>

      <!-- Risk Calculator -->
      <div class="card">
        <div class="card-title">RISK CALCULATOR</div>
        <div style="margin-top:0.5rem">
          <div class="flex justify-between mb-1"><span class="text-xs text-muted">Risk/Lot</span><span class="num text-red">${formatINR(analytics.riskCalculator.riskPerLot)}</span></div>
          <div class="flex justify-between mb-1"><span class="text-xs text-muted">Reward T1/Lot</span><span class="num text-green">${formatINR(analytics.riskCalculator.rewardT1PerLot)}</span></div>
          <div class="flex justify-between mb-1"><span class="text-xs text-muted">Reward T2/Lot</span><span class="num text-green">${formatINR(analytics.riskCalculator.rewardT2PerLot)}</span></div>
          <div class="flex justify-between"><span class="text-xs text-muted">R:R Ratio</span><span class="num font-bold text-blue">${analytics.riskCalculator.rrRatio}:1</span></div>
          <div class="flex justify-between mt-1"><span class="text-xs text-muted">Signal Strength</span><span class="confidence-badge ${analytics.signalStrength === 'VERY_STRONG' || analytics.signalStrength === 'STRONG' ? 'high' : 'medium'}">${analytics.signalStrength}</span></div>
        </div>
      </div>
    </div>`;
  }

  // AI Narrative placeholder
  html += `
  <div class="card mt-2">
    <div class="card-title">AI ANALYSIS</div>
    <div id="ai-narrative-content" class="ai-narrative mt-1">
      ${direction !== 'NO_TRADE' ? '<div class="loading-overlay" style="padding:1rem"><div class="spinner"></div><span>Generating AI analysis...</span></div>' : 'No trade signal — AI analysis skipped.'}
    </div>
  </div>`;

  // Final Verdict
  html += `
  <div class="card mt-2" style="border-color:${direction === 'BUY' ? 'var(--primary)' : direction === 'SELL' ? 'var(--danger)' : 'var(--border)'}">
    <div class="card-title">FINAL VERDICT</div>
    <pre class="num" style="white-space:pre-wrap;margin-top:0.5rem;font-size:0.85rem;line-height:1.6">${verdict || 'Awaiting analysis...'}</pre>
  </div>`;

  // Component breakdown
  if (components) {
    html += `
    <div class="card mt-2">
      <div class="card-title">COMPONENT BREAKDOWN (7-FACTOR MODEL)</div>
      <div style="margin-top:0.75rem">
        ${Object.entries(components).map(([key, val]) => {
          const contribution = (val.bias * val.weight).toFixed(1);
          const barWidth = Math.abs(val.bias) * 100;
          const barColor = val.bias >= 0 ? 'green' : 'red';
          return `
          <div class="flex items-center gap-1 mb-1">
            <span class="text-xs" style="width:90px;text-transform:capitalize">${key}</span>
            <div class="progress-bar" style="flex:1"><div class="fill ${barColor}" style="width:${barWidth}%"></div></div>
            <span class="num text-xs" style="width:45px;text-align:right">${contribution}</span>
          </div>`;
        }).join('')}
      </div>
    </div>`;
  }

  container.innerHTML = html;
}

// ─── Gauge Renderer ──────────────────────────────────────────────────────────
function renderGauge(value, type, size = 140) {
  const r = (size - 20) / 2;
  const circ = 2 * Math.PI * r;
  const offset = circ - (value / 100) * circ;
  const color = type === 'buy' ? 'var(--primary)' : type === 'sell' ? 'var(--danger)' : 'var(--text-muted)';

  return `
    <div class="gauge-container" style="width:${size}px;height:${size}px">
      <svg width="${size}" height="${size}">
        <circle class="gauge-bg" cx="${size/2}" cy="${size/2}" r="${r}"/>
        <circle class="gauge-fill" cx="${size/2}" cy="${size/2}" r="${r}"
          stroke="${color}"
          stroke-dasharray="${circ}"
          stroke-dashoffset="${offset}"/>
      </svg>
      <div class="gauge-value" style="color:${color}">${value.toFixed(0)}%</div>
    </div>`;
}

// ─── Probability Bar ─────────────────────────────────────────────────────────
function renderProbBar(label, value, color) {
  return `
    <div class="flex items-center gap-1 mb-1">
      <span class="text-xs" style="width:55px">${label}</span>
      <div class="progress-bar" style="flex:1"><div class="fill ${color}" style="width:${value}%"></div></div>
      <span class="num text-xs" style="width:35px;text-align:right">${value}%</span>
    </div>`;
}

// ─── AI Narrative ────────────────────────────────────────────────────────────
async function generateAINarrative(signal) {
  const container = $('#ai-narrative-content');
  if (!container) return;

  try {
    const result = await aiAnalyst.generateNarrative(signal);
    container.innerHTML = result.narrative;
    if (result.model !== 'fallback_local') {
      container.innerHTML += `<p class="text-xs text-muted mt-1" style="opacity:0.6">Analysis by ${result.model} · ${result.latency_ms}ms</p>`;
    }
  } catch (e) {
    container.innerHTML = `<p class="text-muted">AI analysis unavailable: ${e.message}</p>`;
  }
}
