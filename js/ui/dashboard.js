/**
 * dashboard.js — Dashboard page logic
 * Fetches indices, VIX, FII/DII, market breadth, and top scanner picks.
 */

import { getAllIndices, getFIIDII, getIndiaVIX, getMarketStatus, buildSnapshot, isMarketHours } from '../data/nse-provider.js';
import { Scanner } from '../core/scanner.js';
import { initApp, notify, refreshManager, formatNumber, formatChange, showLoading, showError, $ } from '../app.js';

// ─── Initialization ──────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  initApp();
  
  // Set a hard 10-second safety net: if any section is still loading, force update it
  setTimeout(forceResolveLoading, 10000);
  
  loadDashboard();
  refreshManager.register(loadDashboard);
});

function forceResolveLoading() {
  // Force-resolve any still-loading sections
  const scannerEl = $('#quick-scanner');
  if (scannerEl && scannerEl.querySelector('.spinner')) {
    scannerEl.innerHTML = `
      <div style="padding:1.5rem;text-align:center">
        <p style="font-size:1.5rem;margin-bottom:0.5rem">⚠️</p>
        <p class="text-muted" style="font-size:1.05rem;margin-bottom:0.5rem">Data Sources Unavailable</p>
        <p class="text-xs text-muted">NSE proxy and Yahoo Finance APIs did not respond in time.</p>
        <p class="text-xs text-muted mt-1">Possible causes: API key issue, market holiday, or network problem.</p>
        <p class="text-xs mt-1"><a href="tools.html">Tools</a> (Black-Scholes, Position Calculator) work offline.</p>
      </div>`;
  }
  
  const indicesGrid = $('#indices-grid');
  if (indicesGrid && indicesGrid.querySelector('.skeleton')) {
    renderIndicesFallback(indicesGrid);
  }
  
  const vixEl = $('#vix-display');
  if (vixEl && vixEl.querySelector('.skeleton')) {
    vixEl.innerHTML = `<span class="vix-value text-muted" style="font-size:1.5rem">—</span><p class="text-xs text-muted mt-1">Data unavailable</p>`;
  }
  
  const fiiEl = $('#fii-dii-panel');
  if (fiiEl && fiiEl.querySelector('.skeleton')) {
    fiiEl.innerHTML = `<p class="text-muted text-sm">Data unavailable</p>`;
  }
}

async function loadDashboard() {
  await Promise.allSettled([
    loadIndices(),
    loadVIX(),
    loadFIIDII(),
    loadQuickScanner(),
  ]);
}

// ─── Indices ─────────────────────────────────────────────────────────────────
async function loadIndices() {
  const container = $('#indices-grid');
  if (!container) return false;

  try {
    const data = await getAllIndices();
    if (!data) {
      renderIndicesFallback(container);
      return false;
    }

    const targets = ['NIFTY 50', 'NIFTY BANK', 'INDIA VIX', 'NIFTY FIN SERVICE', 'BSE SENSEX'];
    const mapped = {
      'NIFTY 50': { key: 'NIFTY 50', label: 'NIFTY 50' },
      'NIFTY BANK': { key: 'NIFTY BANK', label: 'BANKNIFTY' },
      'INDIA VIX': { key: 'INDIA VIX', label: 'INDIA VIX' },
      'NIFTY FIN SERVICE': { key: 'NIFTY FIN SERVICE', label: 'FINNIFTY' },
      'BSE SENSEX': { key: 'BSE SENSEX', label: 'SENSEX' },
    };

    let html = '';
    for (const t of targets) {
      const info = data[t] || data[mapped[t]?.label];
      if (info) {
        const changeClass = info.change >= 0 ? 'positive' : 'negative';
        const sign = info.change >= 0 ? '+' : '';
        html += `
          <div class="card index-card">
            <span class="index-name">${mapped[t]?.label || t}</span>
            <span class="index-price num">${formatNumber(info.last, 2)}</span>
            <span class="index-change ${changeClass} num">${sign}${formatNumber(info.change, 2)}%</span>
          </div>`;
      }
    }

    if (html) {
      container.innerHTML = html;
      return true;
    }
    renderIndicesFallback(container);
    return false;
  } catch (e) {
    console.error('Indices error:', e);
    renderIndicesFallback(container);
    return false;
  }
}

function renderIndicesFallback(container) {
  container.innerHTML = `
    <div class="card index-card"><span class="index-name">NIFTY 50</span><span class="index-price num">—</span><span class="index-change text-muted">Market Closed</span></div>
    <div class="card index-card"><span class="index-name">BANKNIFTY</span><span class="index-price num">—</span><span class="index-change text-muted">Market Closed</span></div>
    <div class="card index-card"><span class="index-name">SENSEX</span><span class="index-price num">—</span><span class="index-change text-muted">Market Closed</span></div>
    <div class="card index-card"><span class="index-name">FINNIFTY</span><span class="index-price num">—</span><span class="index-change text-muted">Market Closed</span></div>`;
}

// ─── VIX Widget ──────────────────────────────────────────────────────────────
async function loadVIX() {
  const el = $('#vix-display');
  if (!el) return false;

  try {
    const vix = await getIndiaVIX();
    if (!vix || !vix.value) {
      el.innerHTML = `<span class="vix-value text-muted" style="font-size:1.5rem">—</span><p class="text-xs text-muted mt-1">Market closed · Data unavailable</p>`;
      return false;
    }

    let cls = 'low';
    if (vix.value >= 20) cls = 'high';
    else if (vix.value >= 15) cls = 'medium';

    const changeStr = vix.change !== null ? `${vix.change >= 0 ? '+' : ''}${vix.change.toFixed(2)}%` : '';
    el.innerHTML = `
      <span class="vix-value ${cls}">${vix.value.toFixed(2)}</span>
      <span class="text-xs text-muted mt-1">India VIX ${changeStr}</span>
      <span class="text-xs ${cls === 'low' ? 'text-green' : cls === 'high' ? 'text-red' : 'text-amber'}" style="margin-top:0.25rem">
        ${cls === 'low' ? '✓ Low volatility — favorable for directional trades' : cls === 'high' ? '⚠ High volatility — use hedged positions' : '△ Moderate volatility — standard risk'}
      </span>`;
    return true;
  } catch (e) {
    console.error('VIX error:', e);
    el.innerHTML = `<span class="vix-value text-muted" style="font-size:1.5rem">—</span><p class="text-xs text-muted mt-1">Data unavailable</p>`;
    return false;
  }
}

// ─── FII/DII ─────────────────────────────────────────────────────────────────
async function loadFIIDII() {
  const container = $('#fii-dii-panel');
  if (!container) return false;

  try {
    const data = await getFIIDII();
    if (!data || (!data.fii && !data.dii)) {
      container.innerHTML = `<p class="text-muted text-sm">FII/DII data unavailable · Market closed</p>`;
      return false;
    }

    const fiiNet = data.fii ? data.fii.netValue : 0;
    const diiNet = data.dii ? data.dii.netValue : 0;
    const maxVal = Math.max(Math.abs(fiiNet), Math.abs(diiNet), 1);

    container.innerHTML = `
      <div class="flow-bar">
        <span class="flow-label">FII</span>
        <div class="flow-track">
          <div class="flow-fill" style="width:${Math.abs(fiiNet)/maxVal*100}%;background:${fiiNet >= 0 ? 'var(--primary)' : 'var(--danger)'}"></div>
        </div>
        <span class="flow-value num ${fiiNet >= 0 ? 'text-green' : 'text-red'}">${fiiNet >= 0 ? '+' : ''}${formatNumber(fiiNet, 0)} Cr</span>
      </div>
      <div class="flow-bar">
        <span class="flow-label">DII</span>
        <div class="flow-track">
          <div class="flow-fill" style="width:${Math.abs(diiNet)/maxVal*100}%;background:${diiNet >= 0 ? 'var(--primary)' : 'var(--danger)'}"></div>
        </div>
        <span class="flow-value num ${diiNet >= 0 ? 'text-green' : 'text-red'}">${diiNet >= 0 ? '+' : ''}${formatNumber(diiNet, 0)} Cr</span>
      </div>
      <p class="text-xs text-muted mt-1">${data.fii?.date || 'Latest available'}</p>`;
    return true;
  } catch (e) {
    console.error('FII/DII error:', e);
    container.innerHTML = `<p class="text-muted text-sm">Data unavailable</p>`;
    return false;
  }
}

// ─── Quick Scanner ───────────────────────────────────────────────────────────
async function loadQuickScanner() {
  const container = $('#quick-scanner');
  if (!container) return false;

  showLoading(container);

  try {
    // Set a hard timeout for the scanner (15 seconds max)
    const scanPromise = (async () => {
      const scanner = new Scanner({
        universe: ['NIFTY', 'BANKNIFTY', 'FINNIFTY', 'RELIANCE', 'HDFCBANK', 'ICICIBANK', 'INFY', 'TCS', 'SBIN'],
        minConfidence: 65,
        confidenceThreshold: 70,
        fetchData: buildSnapshot,
      });
      return await scanner.scan();
    })();

    const timeoutPromise = new Promise((_, reject) =>
      setTimeout(() => reject(new Error('Scanner timeout — market may be closed')), 15000)
    );

    const results = await Promise.race([scanPromise, timeoutPromise]);

    let html = '';

    // Top BUY picks
    const topBuy = results.buy.slice(0, 3);
    if (topBuy.length > 0) {
      html += `<div class="scan-section"><div class="scan-section-title buy">📈 Top BUY Signals</div>`;
      for (const s of topBuy) {
        html += renderQuickPick(s, 'buy');
      }
      html += `</div>`;
    }

    // Top SELL picks
    const topSell = results.sell.slice(0, 3);
    if (topSell.length > 0) {
      html += `<div class="scan-section"><div class="scan-section-title sell">📉 Top SELL Signals</div>`;
      for (const s of topSell) {
        html += renderQuickPick(s, 'sell');
      }
      html += `</div>`;
    }

    if (!topBuy.length && !topSell.length) {
      html = `<div style="padding:1.5rem;text-align:center">
        <p class="text-muted" style="font-size:0.95rem">No high-confidence signals at this time.</p>
        <p class="text-xs text-muted mt-1">Market may be consolidating or data is unavailable (market closed).</p>
      </div>`;
    }

    container.innerHTML = html;
    return true;
  } catch (e) {
    console.error('Quick scanner error:', e);
    container.innerHTML = `
      <div style="padding:1.5rem;text-align:center">
        <p class="text-muted" style="font-size:1.1rem">📴 Market Closed</p>
        <p class="text-xs text-muted mt-1">Live data unavailable. Scanner requires NSE market hours (Mon-Fri, 9:15 AM – 3:30 PM IST).</p>
        <p class="text-xs text-muted mt-1">Tools, Charts, and Black-Scholes Calculator are available anytime.</p>
      </div>`;
    return false;
  }
}

function renderQuickPick(signal, type) {
  const confClass = signal.confidence >= 80 ? 'high' : signal.confidence >= 70 ? 'medium' : 'low';
  return `
    <div class="flex items-center justify-between" style="padding:0.5rem 0;border-bottom:1px solid var(--border)">
      <div class="flex items-center gap-1">
        <span class="signal-badge ${type}">${signal.direction}</span>
        <span class="font-bold">${signal.symbol}</span>
      </div>
      <div class="flex items-center gap-1">
        <span class="confidence-badge ${confClass}">${signal.confidence.toFixed(0)}%</span>
        ${signal.optionStrategy ? `<span class="text-xs text-muted">${signal.optionStrategy.action}</span>` : ''}
      </div>
    </div>`;
}

// ─── Market Breadth ──────────────────────────────────────────────────────────
async function loadBreadth() {
  const canvas = document.getElementById('breadth-canvas');
  if (!canvas) return;

  try {
    const data = await getAllIndices();
    if (!data || !data['NIFTY 50']) return;

    const nifty = data['NIFTY 50'];
    const advances = nifty.advances || 0;
    const declines = nifty.declines || 0;
    const total = advances + declines || 1;

    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.offsetWidth * 2;
    const h = canvas.height = canvas.offsetHeight * 2;
    ctx.scale(2, 2);

    const cx = canvas.offsetWidth / 2;
    const cy = canvas.offsetHeight / 2;
    const r = Math.min(cx, cy) - 10;

    const advAngle = (advances / total) * Math.PI * 2;

    // Advances arc
    ctx.beginPath();
    ctx.arc(cx, cy, r, -Math.PI / 2, -Math.PI / 2 + advAngle);
    ctx.lineWidth = 10;
    ctx.strokeStyle = '#10b981';
    ctx.lineCap = 'round';
    ctx.stroke();

    // Declines arc
    ctx.beginPath();
    ctx.arc(cx, cy, r, -Math.PI / 2 + advAngle, -Math.PI / 2 + Math.PI * 2);
    ctx.strokeStyle = '#ef4444';
    ctx.stroke();

    // Center text
    ctx.fillStyle = '#f9fafb';
    ctx.font = '600 14px Inter';
    ctx.textAlign = 'center';
    ctx.fillText(`${advances}A / ${declines}D`, cx, cy + 5);
  } catch (e) {
    console.error('Breadth error:', e);
  }
}
