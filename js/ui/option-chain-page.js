/**
 * option-chain-page.js — Option Chain Analyzer
 * Full option chain table, PCR gauge, Max Pain, OI charts.
 */

import { getOptionChain, CONFIG } from '../data/nse-provider.js';
import { initApp, notify, formatNumber, showLoading, showError, $ } from '../app.js';

const SYMBOLS = ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'SENSEX', 'MIDCPNIFTY',
  'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN',
  'BHARTIARTL', 'ITC', 'KOTAKBANK', 'LT', 'AXISBANK', 'TATAMOTORS',
  'MARUTI', 'WIPRO', 'HCLTECH', 'ADANIENT', 'BAJFINANCE', 'TITAN'];

let currentData = null;

document.addEventListener('DOMContentLoaded', () => {
  initApp();
  initOptionChainPage();
});

function initOptionChainPage() {
  const selector = $('#oc-symbol-select');
  if (selector) {
    SYMBOLS.forEach(sym => {
      const opt = document.createElement('option');
      opt.value = sym;
      opt.textContent = sym;
      selector.appendChild(opt);
    });
    selector.addEventListener('change', loadOptionChain);
  }

  const loadBtn = $('#oc-load-btn');
  if (loadBtn) loadBtn.addEventListener('click', loadOptionChain);

  loadOptionChain();
}

async function loadOptionChain() {
  const symbol = $('#oc-symbol-select')?.value || 'NIFTY';
  const tableContainer = $('#oc-table-container');
  const analyticsContainer = $('#oc-analytics');

  if (tableContainer) showLoading(tableContainer);

  try {
    const data = await getOptionChain(symbol);
    if (!data) {
      if (tableContainer) showError(tableContainer, 'Option chain data unavailable. Market may be closed.');
      return;
    }

    currentData = data;
    renderOptionChainTable(data, tableContainer);
    renderAnalytics(data, analyticsContainer);
    renderOIChart(data);
  } catch (e) {
    console.error('Option chain error:', e);
    if (tableContainer) showError(tableContainer, 'Failed to load option chain: ' + e.message);
  }
}

function renderOptionChainTable(data, container) {
  if (!container) return;

  const { strikeData, underlyingValue, maxPain } = data;

  // Filter strikes around ATM (±15 strikes)
  const atmIdx = strikeData.findIndex(s => s.strike >= underlyingValue);
  const start = Math.max(0, atmIdx - 15);
  const end = Math.min(strikeData.length, atmIdx + 15);
  const visible = strikeData.slice(start, end);

  let html = `
    <div class="oc-table-wrapper">
      <table class="oc-table">
        <thead>
          <tr>
            <th class="ce-section">CE OI</th>
            <th class="ce-section">CE OI Chg</th>
            <th class="ce-section">CE Vol</th>
            <th class="ce-section">CE IV</th>
            <th class="ce-section">CE LTP</th>
            <th class="strike-col">STRIKE</th>
            <th class="pe-section">PE LTP</th>
            <th class="pe-section">PE IV</th>
            <th class="pe-section">PE Vol</th>
            <th class="pe-section">PE OI Chg</th>
            <th class="pe-section">PE OI</th>
          </tr>
        </thead>
        <tbody>`;

  for (const row of visible) {
    const isATM = Math.abs(row.strike - underlyingValue) < (strikeData[1]?.strike - strikeData[0]?.strike || 50);
    const isMaxPain = row.strike === maxPain;
    const rowClass = isATM ? 'atm-row' : isMaxPain ? 'max-oi-row' : '';

    const ceOiChgClass = row.CE ? (row.CE.oiChg > 0 ? 'negative' : row.CE.oiChg < 0 ? 'positive' : '') : '';
    const peOiChgClass = row.PE ? (row.PE.oiChg > 0 ? 'positive' : row.PE.oiChg < 0 ? 'negative' : '') : '';

    html += `<tr class="${rowClass}">
      <td class="ce-section">${row.CE ? formatCompact(row.CE.oi) : '—'}</td>
      <td class="ce-section ${ceOiChgClass}">${row.CE ? formatCompact(row.CE.oiChg) : '—'}</td>
      <td class="ce-section">${row.CE ? formatCompact(row.CE.volume) : '—'}</td>
      <td class="ce-section">${row.CE && row.CE.iv ? row.CE.iv.toFixed(1) : '—'}</td>
      <td class="ce-section">${row.CE ? row.CE.ltp.toFixed(2) : '—'}</td>
      <td class="strike-col">${row.strike}${isATM ? ' ⬤' : ''}${isMaxPain ? ' ◆' : ''}</td>
      <td class="pe-section">${row.PE ? row.PE.ltp.toFixed(2) : '—'}</td>
      <td class="pe-section">${row.PE && row.PE.iv ? row.PE.iv.toFixed(1) : '—'}</td>
      <td class="pe-section">${row.PE ? formatCompact(row.PE.volume) : '—'}</td>
      <td class="pe-section ${peOiChgClass}">${row.PE ? formatCompact(row.PE.oiChg) : '—'}</td>
      <td class="pe-section">${row.PE ? formatCompact(row.PE.oi) : '—'}</td>
    </tr>`;
  }

  html += `</tbody></table></div>
    <div class="flex justify-between mt-1 text-xs text-muted">
      <span>⬤ = ATM Strike · ◆ = Max Pain</span>
      <span>Expiry: ${data.expiry} · Spot: ${formatNumber(underlyingValue)}</span>
    </div>`;

  container.innerHTML = html;
}

function renderAnalytics(data, container) {
  if (!container) return;

  const { pcr, maxPain, underlyingValue, totalCallOI, totalPutOI, callOIChange, putOIChange, avgIV } = data;

  const pcrColor = pcr > 1.2 ? 'var(--primary)' : pcr < 0.7 ? 'var(--danger)' : 'var(--accent)';
  const pcrLabel = pcr > 1.2 ? 'Bullish (Put support)' : pcr < 0.7 ? 'Bearish (Call resistance)' : 'Neutral';

  container.innerHTML = `
    <div class="grid grid-3">
      <!-- PCR Gauge -->
      <div class="card" style="text-align:center">
        <div class="card-title">PUT-CALL RATIO</div>
        <div style="margin-top:1rem">
          ${renderPCRGauge(pcr, pcrColor)}
          <div class="num font-bold mt-1" style="font-size:1.5rem;color:${pcrColor}">${pcr.toFixed(3)}</div>
          <div class="text-xs text-muted">${pcrLabel}</div>
        </div>
      </div>

      <!-- Max Pain -->
      <div class="card" style="text-align:center">
        <div class="card-title">MAX PAIN</div>
        <div style="margin-top:1rem">
          <div class="num font-bold" style="font-size:2rem;color:var(--warning)">${maxPain || '—'}</div>
          <div class="text-xs text-muted mt-1">Spot: ${formatNumber(underlyingValue)}</div>
          ${maxPain ? `<div class="text-xs mt-1 ${underlyingValue > maxPain ? 'text-red' : 'text-green'}">
            ${underlyingValue > maxPain ? `↑ ${formatNumber(underlyingValue - maxPain, 0)} above max pain` : `↓ ${formatNumber(maxPain - underlyingValue, 0)} below max pain`}
          </div>` : ''}
        </div>
      </div>

      <!-- OI Summary -->
      <div class="card">
        <div class="card-title">OI SUMMARY</div>
        <div style="margin-top:0.75rem">
          <div class="flex justify-between mb-1">
            <span class="text-xs text-muted">Total CE OI</span>
            <span class="num text-sm">${formatCompact(totalCallOI)}</span>
          </div>
          <div class="flex justify-between mb-1">
            <span class="text-xs text-muted">Total PE OI</span>
            <span class="num text-sm">${formatCompact(totalPutOI)}</span>
          </div>
          <div class="flex justify-between mb-1">
            <span class="text-xs text-muted">CE OI Change</span>
            <span class="num text-sm ${callOIChange > 0 ? 'text-red' : 'text-green'}">${formatCompact(callOIChange)}</span>
          </div>
          <div class="flex justify-between mb-1">
            <span class="text-xs text-muted">PE OI Change</span>
            <span class="num text-sm ${putOIChange > 0 ? 'text-green' : 'text-red'}">${formatCompact(putOIChange)}</span>
          </div>
          <div class="flex justify-between">
            <span class="text-xs text-muted">Avg IV</span>
            <span class="num text-sm">${avgIV}%</span>
          </div>
        </div>
      </div>
    </div>`;
}

function renderPCRGauge(pcr, color) {
  const val = Math.min(pcr / 2, 1) * 100; // Normalize 0-2 to 0-100
  const size = 100;
  const r = 35;
  const circ = 2 * Math.PI * r;
  const offset = circ - (val / 100) * circ;

  return `
    <svg width="${size}" height="${size}" style="transform:rotate(-90deg)">
      <circle cx="${size/2}" cy="${size/2}" r="${r}" fill="none" stroke="var(--border)" stroke-width="8"/>
      <circle cx="${size/2}" cy="${size/2}" r="${r}" fill="none" stroke="${color}" stroke-width="8"
        stroke-linecap="round" stroke-dasharray="${circ}" stroke-dashoffset="${offset}"/>
    </svg>`;
}

function renderOIChart(data) {
  const canvas = document.getElementById('oi-chart-canvas');
  if (!canvas) return;

  const { strikeData, underlyingValue } = data;
  const ctx = canvas.getContext('2d');

  // Filter around ATM
  const atmIdx = strikeData.findIndex(s => s.strike >= underlyingValue);
  const start = Math.max(0, atmIdx - 10);
  const end = Math.min(strikeData.length, atmIdx + 10);
  const visible = strikeData.slice(start, end);

  const dpr = window.devicePixelRatio || 1;
  const w = canvas.offsetWidth;
  const h = canvas.offsetHeight || 250;
  canvas.width = w * dpr;
  canvas.height = h * dpr;
  ctx.scale(dpr, dpr);

  // Clear
  ctx.clearRect(0, 0, w, h);

  if (visible.length === 0) return;

  const maxOI = Math.max(...visible.map(s => Math.max(s.CE?.oi || 0, s.PE?.oi || 0)), 1);
  const barWidth = (w - 60) / visible.length / 2 - 2;
  const chartH = h - 40;

  // Draw bars
  visible.forEach((s, i) => {
    const x = 30 + i * ((w - 60) / visible.length);
    const ceH = ((s.CE?.oi || 0) / maxOI) * chartH;
    const peH = ((s.PE?.oi || 0) / maxOI) * chartH;

    // CE bar (left)
    ctx.fillStyle = 'rgba(239, 68, 68, 0.6)';
    ctx.fillRect(x, chartH - ceH + 10, barWidth, ceH);

    // PE bar (right)
    ctx.fillStyle = 'rgba(16, 185, 129, 0.6)';
    ctx.fillRect(x + barWidth + 2, chartH - peH + 10, barWidth, peH);

    // Strike label
    if (i % 2 === 0) {
      ctx.fillStyle = '#9ca3af';
      ctx.font = '9px Inter';
      ctx.textAlign = 'center';
      ctx.fillText(s.strike, x + barWidth, h - 5);
    }
  });

  // Legend
  ctx.fillStyle = 'rgba(239, 68, 68, 0.8)';
  ctx.fillRect(w - 120, 5, 10, 10);
  ctx.fillStyle = '#9ca3af';
  ctx.font = '10px Inter';
  ctx.textAlign = 'left';
  ctx.fillText('CE OI', w - 105, 14);

  ctx.fillStyle = 'rgba(16, 185, 129, 0.8)';
  ctx.fillRect(w - 60, 5, 10, 10);
  ctx.fillStyle = '#9ca3af';
  ctx.fillText('PE OI', w - 45, 14);
}

function formatCompact(num) {
  if (num === null || num === undefined) return '—';
  const abs = Math.abs(num);
  const sign = num < 0 ? '-' : '';
  if (abs >= 10000000) return sign + (abs / 10000000).toFixed(2) + 'Cr';
  if (abs >= 100000) return sign + (abs / 100000).toFixed(2) + 'L';
  if (abs >= 1000) return sign + (abs / 1000).toFixed(1) + 'K';
  return sign + num.toString();
}
