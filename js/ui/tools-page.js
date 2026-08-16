/**
 * tools-page.js — Advanced Trading Tools
 * Position Size Calculator, Black-Scholes, Risk/Reward, Volatility, Expiry Calendar, Lot Reference
 */

import { blackScholesPrice, blackScholesDelta, normCDF } from '../core/indicators.js';
import { CONFIG } from '../data/nse-provider.js';
import { initApp, formatINR, formatNumber, $ } from '../app.js';

const DEFAULT_CAPITAL = 12000;

document.addEventListener('DOMContentLoaded', () => {
  initApp();
  initToolsPage();
});

function initToolsPage() {
  // Position Size Calculator
  setupPositionCalc();
  // Black-Scholes Calculator
  setupBSCalc();
  // Risk/Reward Calculator
  setupRRCalc();
  // Render static content
  renderExpiryCalendar();
  renderLotSizeTable();
}

// ─── Position Size Calculator ────────────────────────────────────────────────
function setupPositionCalc() {
  const calcBtn = $('#pos-calc-btn');
  if (!calcBtn) return;

  // Set defaults
  const capitalInput = $('#pos-capital');
  if (capitalInput) capitalInput.value = DEFAULT_CAPITAL;

  calcBtn.addEventListener('click', () => {
    const capital = parseFloat($('#pos-capital')?.value) || DEFAULT_CAPITAL;
    const riskPct = parseFloat($('#pos-risk-pct')?.value) || 2;
    const slPoints = parseFloat($('#pos-sl-points')?.value) || 50;
    const lotSize = parseInt($('#pos-lot-size')?.value) || 25;
    const premium = parseFloat($('#pos-premium')?.value) || 0;

    const maxRisk = capital * (riskPct / 100);
    const riskPerLot = slPoints * lotSize;
    const lots = Math.floor(maxRisk / riskPerLot) || 0;
    const quantity = lots * lotSize;
    const actualRisk = lots * riskPerLot;
    const capitalForPremium = premium > 0 ? premium * lotSize * lots : 0;

    const resultDiv = $('#pos-calc-result');
    if (resultDiv) {
      resultDiv.innerHTML = `
        <div class="grid grid-3" style="gap:0.5rem">
          <div><span class="result-label">Max Risk (${riskPct}%)</span><div class="result-value">${formatINR(maxRisk)}</div></div>
          <div><span class="result-label">Lots Affordable</span><div class="result-value" style="color:var(--accent)">${lots}</div></div>
          <div><span class="result-label">Quantity</span><div class="result-value">${quantity}</div></div>
          <div><span class="result-label">Risk/Lot</span><div class="result-value text-red">${formatINR(riskPerLot)}</div></div>
          <div><span class="result-label">Total Risk</span><div class="result-value text-red">${formatINR(actualRisk)}</div></div>
          ${premium > 0 ? `<div><span class="result-label">Premium Capital</span><div class="result-value text-amber">${formatINR(capitalForPremium)}</div></div>` : ''}
        </div>
        ${lots === 0 ? `<p class="text-xs text-red mt-1">⚠ Capital insufficient for even 1 lot at this SL. Consider a tighter stop or reduce lot size.</p>` : ''}
        <p class="text-xs text-muted mt-1">Based on ${riskPct}% max risk rule with ₹${capital} capital</p>`;
    }
  });

  // Trigger initial calculation
  calcBtn.click();
}

// ─── Black-Scholes Calculator ────────────────────────────────────────────────
function setupBSCalc() {
  const calcBtn = $('#bs-calc-btn');
  if (!calcBtn) return;

  calcBtn.addEventListener('click', () => {
    const spot = parseFloat($('#bs-spot')?.value) || 24000;
    const strike = parseFloat($('#bs-strike')?.value) || 24000;
    const dte = parseInt($('#bs-dte')?.value) || 3;
    const iv = parseFloat($('#bs-iv')?.value) || 15;
    const rate = parseFloat($('#bs-rate')?.value) || 6.5;

    const t = dte / 365;
    const sigma = iv / 100;
    const r = rate / 100;

    const cePrice = blackScholesPrice('CE', spot, strike, t, r, sigma);
    const pePrice = blackScholesPrice('PE', spot, strike, t, r, sigma);
    const ceDelta = blackScholesDelta('CE', spot, strike, t, r, sigma);
    const peDelta = blackScholesDelta('PE', spot, strike, t, r, sigma);

    // Gamma, Theta, Vega calculations
    const d1 = (Math.log(spot / strike) + (r + sigma * sigma / 2) * t) / (sigma * Math.sqrt(t));
    const d2 = d1 - sigma * Math.sqrt(t);
    const nd1 = Math.exp(-d1 * d1 / 2) / Math.sqrt(2 * Math.PI);
    const gamma = nd1 / (spot * sigma * Math.sqrt(t));
    const theta = -(spot * nd1 * sigma) / (2 * Math.sqrt(t)) / 365;
    const vega = spot * nd1 * Math.sqrt(t) / 100;

    const resultDiv = $('#bs-calc-result');
    if (resultDiv) {
      resultDiv.innerHTML = `
        <div class="grid grid-2" style="gap:1rem">
          <div style="border-right:1px solid var(--border);padding-right:1rem">
            <div class="card-title text-green">CALL (CE)</div>
            <div class="result-value mt-1" style="font-size:1.5rem">${formatINR(cePrice)}</div>
            <div class="text-xs text-muted mt-1">Delta: ${ceDelta.toFixed(4)}</div>
          </div>
          <div>
            <div class="card-title text-red">PUT (PE)</div>
            <div class="result-value mt-1" style="font-size:1.5rem">${formatINR(pePrice)}</div>
            <div class="text-xs text-muted mt-1">Delta: ${peDelta.toFixed(4)}</div>
          </div>
        </div>
        <div class="grid grid-3 mt-2" style="gap:0.5rem">
          <div><span class="result-label">Gamma</span><div class="num text-sm">${gamma.toFixed(5)}</div></div>
          <div><span class="result-label">Theta/Day</span><div class="num text-sm text-red">${theta.toFixed(2)}</div></div>
          <div><span class="result-label">Vega</span><div class="num text-sm text-blue">${vega.toFixed(2)}</div></div>
        </div>
        <p class="text-xs text-muted mt-1">Black-Scholes model · Spot: ${spot} · Strike: ${strike} · DTE: ${dte} · IV: ${iv}%</p>`;
    }
  });
}

// ─── Risk/Reward Calculator ──────────────────────────────────────────────────
function setupRRCalc() {
  const calcBtn = $('#rr-calc-btn');
  if (!calcBtn) return;

  calcBtn.addEventListener('click', () => {
    const entry = parseFloat($('#rr-entry')?.value) || 0;
    const sl = parseFloat($('#rr-sl')?.value) || 0;
    const target = parseFloat($('#rr-target')?.value) || 0;
    const lots = parseInt($('#rr-lots')?.value) || 1;
    const lotSize = parseInt($('#rr-lot-size')?.value) || 25;

    if (!entry || !sl || !target) return;

    const risk = Math.abs(entry - sl);
    const reward = Math.abs(target - entry);
    const rr = risk > 0 ? (reward / risk).toFixed(2) : '∞';
    const maxLoss = risk * lots * lotSize;
    const maxProfit = reward * lots * lotSize;
    const breakeven = entry;
    const direction = target > entry ? 'LONG' : 'SHORT';

    const resultDiv = $('#rr-calc-result');
    if (resultDiv) {
      resultDiv.innerHTML = `
        <div class="grid grid-3" style="gap:0.5rem">
          <div><span class="result-label">R:R Ratio</span><div class="result-value text-blue">${rr}:1</div></div>
          <div><span class="result-label">Max Loss</span><div class="result-value text-red">${formatINR(maxLoss)}</div></div>
          <div><span class="result-label">Max Profit</span><div class="result-value text-green">${formatINR(maxProfit)}</div></div>
          <div><span class="result-label">Direction</span><div class="result-value">${direction}</div></div>
          <div><span class="result-label">Risk Points</span><div class="result-value num">${risk.toFixed(1)}</div></div>
          <div><span class="result-label">Reward Points</span><div class="result-value num">${reward.toFixed(1)}</div></div>
        </div>
        ${rr < 1 ? `<p class="text-xs text-red mt-1">⚠ R:R below 1:1 — risk exceeds potential reward. Consider adjusting levels.</p>` : ''}
        ${maxLoss > DEFAULT_CAPITAL * 0.05 ? `<p class="text-xs text-amber mt-1">⚠ Max loss exceeds 5% of ₹${DEFAULT_CAPITAL} capital. Size down.</p>` : ''}`;
    }
  });
}

// ─── Expiry Calendar ─────────────────────────────────────────────────────────
function renderExpiryCalendar() {
  const container = $('#expiry-calendar');
  if (!container) return;

  const today = new Date();
  const expiries = [];

  // Compute next few Thursday expiries
  const weeklyExpiries = getNextExpiries(today, 4, 4); // Next 4 Thursdays
  const monthlyExpiry = getMonthlyExpiry(today);

  container.innerHTML = `
    <table class="data-table">
      <thead><tr><th>Index</th><th>Expiry Day</th><th>Next Expiry</th><th>DTE</th></tr></thead>
      <tbody>
        <tr><td>NIFTY</td><td>Thursday</td><td>${formatDate(weeklyExpiries[0])}</td><td class="num">${getDTE(weeklyExpiries[0])}</td></tr>
        <tr><td>BANKNIFTY</td><td>Wednesday</td><td>${formatDate(getNextWeekday(today, 3))}</td><td class="num">${getDTE(getNextWeekday(today, 3))}</td></tr>
        <tr><td>FINNIFTY</td><td>Tuesday</td><td>${formatDate(getNextWeekday(today, 2))}</td><td class="num">${getDTE(getNextWeekday(today, 2))}</td></tr>
        <tr><td>SENSEX</td><td>Friday</td><td>${formatDate(getNextWeekday(today, 5))}</td><td class="num">${getDTE(getNextWeekday(today, 5))}</td></tr>
        <tr><td>MIDCPNIFTY</td><td>Monday</td><td>${formatDate(getNextWeekday(today, 1))}</td><td class="num">${getDTE(getNextWeekday(today, 1))}</td></tr>
        <tr style="background:var(--accent-dim)"><td class="font-bold">Monthly (All)</td><td>Last Thursday</td><td>${formatDate(monthlyExpiry)}</td><td class="num font-bold">${getDTE(monthlyExpiry)}</td></tr>
      </tbody>
    </table>`;
}

function getNextWeekday(from, dayOfWeek) {
  const d = new Date(from);
  const diff = (dayOfWeek - d.getDay() + 7) % 7;
  d.setDate(d.getDate() + (diff === 0 ? 7 : diff));
  return d;
}

function getNextExpiries(from, dayOfWeek, count) {
  const results = [];
  let d = new Date(from);
  for (let i = 0; i < count; i++) {
    d = getNextWeekday(d, dayOfWeek);
    results.push(new Date(d));
    d.setDate(d.getDate() + 1);
  }
  return results;
}

function getMonthlyExpiry(from) {
  const d = new Date(from.getFullYear(), from.getMonth() + 1, 0);
  while (d.getDay() !== 4) d.setDate(d.getDate() - 1);
  if (d < from) {
    const next = new Date(from.getFullYear(), from.getMonth() + 2, 0);
    while (next.getDay() !== 4) next.setDate(next.getDate() - 1);
    return next;
  }
  return d;
}

function getDTE(date) {
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  const d = new Date(date);
  d.setHours(0, 0, 0, 0);
  return Math.max(0, Math.ceil((d - now) / (1000 * 60 * 60 * 24)));
}

function formatDate(d) {
  return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' });
}

// ─── Lot Size Table ──────────────────────────────────────────────────────────
function renderLotSizeTable() {
  const container = $('#lot-size-table');
  if (!container) return;

  const lots = CONFIG.LOT_SIZES;
  let html = `<table class="data-table lot-table"><thead><tr><th>Symbol</th><th>Lot Size</th></tr></thead><tbody>`;
  for (const [sym, size] of Object.entries(lots)) {
    html += `<tr><td>${sym}</td><td>${size}</td></tr>`;
  }
  html += `</tbody></table>`;
  container.innerHTML = html;
}
