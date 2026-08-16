/**
 * scanner-page.js — Market Scanner page logic
 * Scans full universe, renders sortable results with confidence filters.
 */

import { buildSnapshot } from '../data/nse-provider.js';
import { Scanner } from '../core/scanner.js';
import { initApp, notify, formatNumber, formatINR, showLoading, showError, $ } from '../app.js';

let lastResults = null;
let minConfidenceFilter = 65;

document.addEventListener('DOMContentLoaded', () => {
  initApp();
  initScannerPage();
});

function initScannerPage() {
  const scanBtn = $('#scan-btn');
  if (scanBtn) scanBtn.addEventListener('click', runScan);

  const slider = $('#confidence-slider');
  if (slider) {
    slider.addEventListener('input', (e) => {
      minConfidenceFilter = parseInt(e.target.value);
      $('#confidence-value').textContent = minConfidenceFilter + '%';
      if (lastResults) renderResults(lastResults);
    });
  }

  const universeSelect = $('#universe-select');
  if (universeSelect) universeSelect.addEventListener('change', runScan);

  // Auto-run scan
  runScan();
}

async function runScan() {
  const container = $('#scanner-results');
  const statusEl = $('#scan-status');
  if (!container) return;

  showLoading(container);
  if (statusEl) statusEl.textContent = 'Scanning...';

  const universeType = $('#universe-select')?.value || 'all';
  let universe;
  if (universeType === 'indices') {
    universe = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY'];
  } else if (universeType === 'stocks') {
    universe = ['RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN',
      'BHARTIARTL', 'ITC', 'KOTAKBANK', 'LT', 'AXISBANK', 'TATAMOTORS',
      'MARUTI', 'WIPRO', 'HCLTECH', 'ADANIENT', 'BAJFINANCE', 'TITAN'];
  } else {
    universe = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY',
      'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN',
      'BHARTIARTL', 'ITC', 'KOTAKBANK', 'LT', 'AXISBANK', 'TATAMOTORS',
      'MARUTI', 'WIPRO', 'HCLTECH', 'ADANIENT', 'BAJFINANCE', 'TITAN'];
  }

  try {
    const scanner = new Scanner({
      universe,
      minConfidence: 60,
      confidenceThreshold: 70,
      fetchData: buildSnapshot,
    });

    const results = await scanner.scan();
    lastResults = results;

    if (statusEl) {
      statusEl.innerHTML = `Scanned <strong>${results.scanned}</strong> instruments in <strong>${(results.scan_duration_ms / 1000).toFixed(1)}s</strong> · ${results.filtered} actionable signals · ${new Date().toLocaleTimeString('en-IN', { timeZone: 'Asia/Kolkata' })} IST`;
    }

    renderResults(results);
  } catch (e) {
    console.error('Scanner error:', e);
    showError(container, 'Scanner failed: ' + e.message);
  }
}

function renderResults(results) {
  const container = $('#scanner-results');
  if (!container) return;

  const buySignals = results.buy.filter(s => s.confidence >= minConfidenceFilter);
  const sellSignals = results.sell.filter(s => s.confidence >= minConfidenceFilter);

  let html = '';

  if (buySignals.length > 0) {
    html += `
    <div class="scan-section">
      <div class="scan-section-title buy">📈 BUY Signals (${buySignals.length})</div>
      <div class="overflow-auto">
        <table class="data-table">
          <thead><tr>
            <th>Symbol</th><th>Signal</th><th>Confidence</th><th>Score</th><th>Entry</th><th>Stop Loss</th><th>Target 1</th><th>R:R</th>
          </tr></thead>
          <tbody>
            ${buySignals.map(s => renderScanRow(s)).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
  }

  if (sellSignals.length > 0) {
    html += `
    <div class="scan-section">
      <div class="scan-section-title sell">📉 SELL Signals (${sellSignals.length})</div>
      <div class="overflow-auto">
        <table class="data-table">
          <thead><tr>
            <th>Symbol</th><th>Signal</th><th>Confidence</th><th>Score</th><th>Entry</th><th>Stop Loss</th><th>Target 1</th><th>R:R</th>
          </tr></thead>
          <tbody>
            ${sellSignals.map(s => renderScanRow(s)).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
  }

  if (!buySignals.length && !sellSignals.length) {
    html = `<div class="loading-overlay"><span class="text-muted">No signals above ${minConfidenceFilter}% confidence threshold. Try lowering the filter or scan during market hours.</span></div>`;
  }

  if (results.errors && results.errors.length > 0) {
    html += `<p class="text-xs text-muted mt-2">⚠ ${results.errors.length} instrument(s) had data issues: ${results.errors.map(e => e.symbol).join(', ')}</p>`;
  }

  container.innerHTML = html;

  // Add click handlers for expandable rows
  container.querySelectorAll('.expandable-row').forEach(row => {
    row.addEventListener('click', () => {
      const details = row.nextElementSibling;
      if (details) details.classList.toggle('open');
    });
  });
}

function renderScanRow(signal) {
  const dirClass = signal.direction === 'BUY' ? 'buy' : 'sell';
  const confClass = signal.confidence >= 80 ? 'high' : signal.confidence >= 70 ? 'medium' : 'low';

  const entry = signal.entryZone ? formatNumber(signal.entryZone.ideal, 0) : '—';
  const sl = signal.stopLoss ? formatNumber(signal.stopLoss, 0) : '—';
  const t1 = signal.targets && signal.targets[0] ? formatNumber(signal.targets[0].price, 0) : '—';
  const rr = signal.analytics?.riskCalculator?.rrRatio || '—';

  return `
    <tr class="expandable-row">
      <td class="font-bold">${signal.symbol}</td>
      <td><span class="signal-badge ${dirClass}">${signal.direction}</span></td>
      <td><span class="confidence-badge ${confClass}">${signal.confidence.toFixed(0)}%</span></td>
      <td class="num">${signal.netScore}/100</td>
      <td class="num">${entry}</td>
      <td class="num text-red">${sl}</td>
      <td class="num text-green">${t1}</td>
      <td class="num text-blue">${rr}:1</td>
    </tr>
    <tr><td colspan="8" class="row-details">
      <div class="detail-grid">
        <div class="detail-item"><span class="detail-label">Strategy</span><span class="detail-value">${signal.optionStrategy?.action || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Premium</span><span class="detail-value">${signal.optionStrategy ? formatINR(signal.optionStrategy.estimatedPremium) : '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Capital/Lot</span><span class="detail-value">${signal.optionStrategy ? formatINR(signal.optionStrategy.capitalRequired) : '—'}</span></div>
        <div class="detail-item"><span class="detail-label">RSI</span><span class="detail-value">${signal.rsi || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">ATR</span><span class="detail-value">${signal.atr ? formatINR(signal.atr) : '—'}</span></div>
        <div class="detail-item"><span class="detail-label">Strength</span><span class="detail-value">${signal.analytics?.signalStrength || '—'}</span></div>
        <div class="detail-item"><span class="detail-label">T2</span><span class="detail-value">${signal.targets?.[1] ? formatNumber(signal.targets[1].price, 0) : '—'}</span></div>
        <div class="detail-item"><span class="detail-label">T3</span><span class="detail-value">${signal.targets?.[2] ? formatNumber(signal.targets[2].price, 0) : '—'}</span></div>
      </div>
    </td></tr>`;
}
