/*
 * FNOCoverage — make a missing factor visible instead of silent.
 *
 * Problem this solves
 * -------------------
 * signal.html enriches a candle snapshot with five parallel lookups (option
 * chain, FII/DII, news, higher-timeframe bias, GEX). Each is consumed behind a
 * bare truthiness guard with no else branch, so a failed lookup is
 * indistinguishable from a neutral reading. The engine then scores whatever
 * arrived and the UI labels the result "7-FACTOR" regardless.
 *
 * Outside market hours the option chain and Greeks are unavailable while candles
 * still return, so the page currently renders an undiscounted 7-factor signal
 * built on 5 factors every evening, night and weekend. The absent block is the
 * options microstructure — GEX, PCR, IV, IV skew, max pain, call/put walls —
 * which carries the largest weight in the model.
 *
 * Design
 * ------
 * A missing input must not be scored as a neutral input. Coverage is measured as
 * a fraction of model WEIGHT rather than a count of factors, because losing the
 * options block is not equivalent to losing the news sentiment nudge.
 *
 * applyDiscount is a strict no-op at full coverage, so behaviour during healthy
 * market hours is unchanged. That is the property to check before deploying.
 *
 * No dependencies. Attaches one global, FNOCoverage.
 */
(function (root) {
  'use strict';

  // Weights approximate the engine's own factor emphasis. They only need to be
  // proportionally right: they express "how much of the model is live", not a
  // score. Options dominates deliberately — it is the advertised edge.
  var FACTOR_WEIGHTS = {
    price:     15,  // from candles; effectively always present
    trend:     20,  // from candles
    momentum:  10,  // from candles
    options:   30,  // option chain + GEX  <- the one that fails after hours
    flow:      12,  // FII/DII
    volatility: 8,  // IV / VIX, largely chain-derived
    context:    5   // news sentiment, higher-TF bias
  };

  var TOTAL_WEIGHT = 0;
  for (var k in FACTOR_WEIGHTS) {
    if (Object.prototype.hasOwnProperty.call(FACTOR_WEIGHTS, k)) {
      TOTAL_WEIGHT += FACTOR_WEIGHTS[k];
    }
  }

  // Below this share of live weight a signal is not presented as actionable.
  // Losing the options block alone drops coverage to ~0.70, which is why the
  // threshold sits above that.
  var MIN_ACTIONABLE_COVERAGE = 0.80;

  // Market session, IST. Mirrors the server's own window.
  var OPEN_MIN = 9 * 60 + 15;
  var CLOSE_MIN = 15 * 60 + 30;

  function istNow() {
    // Convert reliably regardless of the browser's local zone.
    var now = new Date();
    var utcMs = now.getTime() + now.getTimezoneOffset() * 60000;
    return new Date(utcMs + 5.5 * 3600 * 1000);
  }

  function marketState() {
    var ist = istNow();
    var dow = ist.getDay(); // 0 Sun .. 6 Sat
    var mins = ist.getHours() * 60 + ist.getMinutes();
    if (dow === 0 || dow === 6) {
      return { open: false, reason: 'weekend', ist: ist };
    }
    if (mins < OPEN_MIN) return { open: false, reason: 'pre-open', ist: ist };
    if (mins > CLOSE_MIN) return { open: false, reason: 'after-close', ist: ist };
    return { open: true, reason: 'session', ist: ist };
  }

  function ok(resp) {
    if (!resp) return false;
    if (resp.status === false) return false;
    if (Object.prototype.hasOwnProperty.call(resp, 'status') && !resp.status) return false;
    if (Object.prototype.hasOwnProperty.call(resp, 'data') && !resp.data) return false;
    return true;
  }

  /**
   * Assess which factors received data.
   *
   * @param {{meta:*, fii:*, news:*, mtf:*, gexResp:*}} parts the enrichment
   *        results, exactly as Promise.all returns them in signal.html.
   * @returns {object} coverage report
   */
  function assess(parts) {
    parts = parts || {};

    var haveChain = ok(parts.meta);
    var haveGex = ok(parts.gexResp);
    // The options factor needs at least one of the two; both missing means the
    // whole microstructure block is dark.
    var haveOptions = haveChain || haveGex;

    var present = {
      price: true,          // candles are a precondition for reaching this code
      trend: true,
      momentum: true,
      options: haveOptions,
      flow: ok(parts.fii),
      volatility: haveOptions,   // IV is chain-derived
      context: ok(parts.news) || (parts.mtf !== null && parts.mtf !== undefined)
    };

    var liveWeight = 0;
    var missing = [];
    for (var f in FACTOR_WEIGHTS) {
      if (!Object.prototype.hasOwnProperty.call(FACTOR_WEIGHTS, f)) continue;
      if (present[f]) liveWeight += FACTOR_WEIGHTS[f];
      else missing.push(f);
    }

    var factorsTotal = 7;
    var factorsLive = 0;
    for (var g in present) {
      if (Object.prototype.hasOwnProperty.call(present, g) && present[g]) factorsLive++;
    }

    var mkt = marketState();
    var coverage = TOTAL_WEIGHT > 0 ? liveWeight / TOTAL_WEIGHT : 0;

    return {
      present: present,
      missing: missing,
      coverage: coverage,
      liveWeight: liveWeight,
      totalWeight: TOTAL_WEIGHT,
      factorsLive: factorsLive,
      factorsTotal: factorsTotal,
      complete: missing.length === 0,
      marketOpen: mkt.open,
      marketReason: mkt.reason,
      // An outage is only an outage if the market is actually open. After hours
      // a dark option chain is expected, and calling it an error trains the
      // operator to ignore the warning.
      isOutage: mkt.open && missing.length > 0
    };
  }

  /**
   * Reduce confidence in proportion to missing model weight.
   *
   * Strict no-op when coverage is complete.
   */
  function applyDiscount(sig, cov) {
    if (!sig || !cov || cov.complete) return sig;

    var original = sig.confidence;
    var discounted = original * cov.coverage;

    sig.confidence = Math.round(discounted * 10) / 10;
    sig._coverageOriginalConfidence = original;
    sig._coverage = cov.coverage;

    if (cov.coverage < MIN_ACTIONABLE_COVERAGE) {
      // Do not present a partial-model read as tradeable.
      sig._coverageSuppressed = true;
      sig.actionable = false;
      if (sig.direction && sig.direction !== 'NO_TRADE') {
        sig._coverageOriginalDirection = sig.direction;
        sig.direction = 'NO_TRADE';
      }
      sig.vetoes = (sig.vetoes || []).concat([
        'INSUFFICIENT_DATA: ' + cov.factorsLive + ' of ' + cov.factorsTotal +
        ' factors live (' + Math.round(cov.coverage * 100) + '% of model weight); ' +
        'missing ' + cov.missing.join(', ')
      ]);
    }
    return sig;
  }

  function factorLabel(cov) {
    if (!cov || cov.complete) return '7-FACTOR';
    return cov.factorsLive + ' OF ' + cov.factorsTotal + ' FACTORS';
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /** Amber banner when degraded, red when degraded during market hours. */
  function banner(cov) {
    if (!cov || cov.complete) return '';

    var pct = Math.round(cov.coverage * 100);
    var head, colour;

    if (cov.isOutage) {
      colour = '#b91c1c';
      head = 'DATA OUTAGE — market is open but ' + esc(cov.missing.join(', ')) +
             ' returned no data';
    } else {
      colour = '#b45309';
      head = 'MARKET CLOSED (' + esc(cov.marketReason) + ') — ' +
             esc(cov.missing.join(', ')) + ' unavailable';
    }

    var body = 'Signal computed on ' + cov.factorsLive + ' of ' + cov.factorsTotal +
               ' factors (' + pct + '% of model weight). Confidence reduced' +
               (cov.coverage < MIN_ACTIONABLE_COVERAGE ? '; not actionable.' : '.');

    return '<div class="card mt-2" role="status" aria-live="polite" style="' +
           'border-left:4px solid ' + colour + ';background:rgba(180,83,9,0.08)">' +
           '<div class="card-title" style="color:' + colour + '">' + head + '</div>' +
           '<p class="text-xs text-muted" style="margin:0.35rem 0 0">' + body + '</p>' +
           '</div>';
  }

  root.FNOCoverage = {
    assess: assess,
    applyDiscount: applyDiscount,
    banner: banner,
    factorLabel: factorLabel,
    marketState: marketState,
    FACTOR_WEIGHTS: FACTOR_WEIGHTS,
    MIN_ACTIONABLE_COVERAGE: MIN_ACTIONABLE_COVERAGE
  };
})(typeof window !== 'undefined' ? window : this);
