/**
 * app.js — Main Application Controller
 * FNO Signal Pro — Live F&O Trading Intelligence Platform
 */

// ─── Global Config ───────────────────────────────────────────────────────────
/**
 * Load API key from config.js (gitignored) or use empty string.
 * Create a file config.js in the root with: export const OPENROUTER_KEY = 'your-key-here';
 */
let _loadedKey = '';
try {
  const cfg = await import('../config.js');
  _loadedKey = cfg.OPENROUTER_KEY || '';
} catch (_) {
  // config.js not found — key must be set at runtime
}

export const APP_CONFIG = {
  OPENROUTER_API_KEY: _loadedKey,
  REFRESH_INTERVAL: 30000, // 30 seconds
  DEFAULT_CAPITAL: 12000,
  DISCLAIMER: 'For informational purposes only. Not financial advice. Trade at your own risk. F&O trading involves substantial risk of capital loss.',
};

// ─── Notification System ─────────────────────────────────────────────────────
class NotificationManager {
  constructor() {
    this.container = null;
    this._init();
  }

  _init() {
    this.container = document.createElement('div');
    this.container.className = 'toast-container';
    document.body.appendChild(this.container);
  }

  show(message, type = 'info', duration = 4000) {
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    this.container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(100%)';
      setTimeout(() => toast.remove(), 300);
    }, duration);
  }

  error(msg) { this.show(msg, 'error', 5000); }
  success(msg) { this.show(msg, 'success'); }
  info(msg) { this.show(msg, 'info'); }
}

export const notify = new NotificationManager();

// ─── Auto-Refresh Manager ────────────────────────────────────────────────────
class RefreshManager {
  constructor() {
    this.timerId = null;
    this.callbacks = [];
    this.isRunning = false;
  }

  register(callback) {
    this.callbacks.push(callback);
  }

  start(interval = APP_CONFIG.REFRESH_INTERVAL) {
    if (this.isRunning) return;
    this.isRunning = true;
    this.timerId = setInterval(() => {
      this._tick();
    }, interval);
  }

  stop() {
    if (this.timerId) clearInterval(this.timerId);
    this.timerId = null;
    this.isRunning = false;
  }

  async _tick() {
    for (const cb of this.callbacks) {
      try {
        await cb();
      } catch (e) {
        console.warn('[RefreshManager] Callback error:', e.message);
      }
    }
  }
}

export const refreshManager = new RefreshManager();

// ─── Market Hours Check ──────────────────────────────────────────────────────
export function isMarketOpen() {
  const now = new Date();
  const ist = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  const day = ist.getDay();
  const hours = ist.getHours();
  const minutes = ist.getMinutes();
  const timeInMinutes = hours * 60 + minutes;
  if (day === 0 || day === 6) return false;
  return timeInMinutes >= 555 && timeInMinutes <= 930;
}

// ─── Format Helpers ──────────────────────────────────────────────────────────
export function formatNumber(num, decimals = 2) {
  if (num === null || num === undefined) return '—';
  return Number(num).toLocaleString('en-IN', {
    minimumFractionDigits: decimals,
    maximumFractionDigits: decimals,
  });
}

export function formatINR(num) {
  if (num === null || num === undefined) return '—';
  return '₹' + Number(num).toLocaleString('en-IN', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
}

export function formatChange(val) {
  if (val === null || val === undefined) return '—';
  const sign = val >= 0 ? '+' : '';
  return sign + Number(val).toFixed(2) + '%';
}

// ─── DOM Helpers ─────────────────────────────────────────────────────────────
export function $(selector) {
  return document.querySelector(selector);
}

export function $$(selector) {
  return document.querySelectorAll(selector);
}

export function showLoading(container) {
  if (!container) return;
  container.innerHTML = `
    <div class="loading-overlay">
      <div class="spinner"></div>
      <span>Fetching live data...</span>
    </div>`;
}

export function showError(container, message) {
  if (!container) return;
  container.innerHTML = `
    <div class="loading-overlay">
      <span style="color:var(--danger)">⚠️ ${message}</span>
      <button class="btn btn-outline" onclick="location.reload()">Retry</button>
    </div>`;
}

// ─── Navigation Highlighter ──────────────────────────────────────────────────
export function initNavigation() {
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  document.querySelectorAll('.nav-links a').forEach(link => {
    const href = link.getAttribute('href');
    if (href === currentPage || (currentPage === '' && href === 'index.html')) {
      link.classList.add('active');
    }
  });
}

// ─── Market Status Updater ───────────────────────────────────────────────────
export function updateMarketStatus() {
  const badge = document.getElementById('market-status');
  if (!badge) return;
  const open = isMarketOpen();
  const now = new Date();
  const ist = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Kolkata' }));
  const timeMin = ist.getHours() * 60 + ist.getMinutes();
  const day = ist.getDay();

  let status, cls;
  if (day === 0 || day === 6) {
    status = 'CLOSED';
    cls = 'closed';
  } else if (timeMin >= 540 && timeMin < 555) {
    status = 'PRE-MARKET';
    cls = 'pre-market';
  } else if (open) {
    status = 'MARKET OPEN';
    cls = 'open';
  } else {
    status = 'CLOSED';
    cls = 'closed';
  }

  badge.className = `market-status-badge ${cls}`;
  badge.innerHTML = `<span class="status-dot"></span>${status}`;
}

// ─── Global Error Handler ────────────────────────────────────────────────────
window.addEventListener('unhandledrejection', (event) => {
  console.error('[Unhandled]', event.reason);
  notify.error('A data fetch failed. Retrying on next cycle.');
});

// ─── Page Init ───────────────────────────────────────────────────────────────
export function initApp() {
  initNavigation();
  updateMarketStatus();
  setInterval(updateMarketStatus, 30000);

  // Start auto-refresh during market hours
  if (isMarketOpen()) {
    refreshManager.start();
  } else {
    // Check every minute if market opens
    setInterval(() => {
      if (isMarketOpen() && !refreshManager.isRunning) {
        refreshManager.start();
        notify.info('Market is open — auto-refresh enabled');
      }
    }, 60000);
  }
}
