/*
 * FNOPnL — correct P&L, cost and win/loss arithmetic for F&O paper trading.
 *
 * Defects this replaces
 * ---------------------
 *
 * tool.html computes open-position P&L as:
 *
 *     const pnlPct = p.cost > 0
 *         ? Math.round((p.currentPremium - p.entryPremium) / p.entryPremium * 100) : 0;
 *     const pnlAmt = Math.round((p.currentPremium - p.entryPremium)
 *                               * p.lotSize * (p.sizeMultiplier || 1));
 *
 * and classifies a trade as:
 *
 *     const isWin = t.pnl > 0;
 *
 * Four separate accuracy problems:
 *
 * 1. **Zero transaction costs.** A real NSE options round trip costs brokerage,
 *    STT on the sell leg, exchange transaction charges, GST on both, SEBI
 *    turnover fees and stamp duty on the buy leg. On a 150-point ATM NIFTY option
 *    that is roughly 0.6% of premium before spread, and about 2% once the bid-ask
 *    is crossed twice. Every displayed P&L is overstated by that amount.
 *
 * 2. **Win classified on gross.** `t.pnl > 0` counts a trade that gained one
 *    paisa as a win. With costs applied that same trade is a loss. This inflates
 *    the win rate systematically, and win rate is the number used to decide
 *    whether the strategy works.
 *
 * 3. **Rounding before aggregation.** `Math.round` is applied to each trade's
 *    amount and percentage, then those rounded values are summed. Rounding error
 *    accumulates linearly in the trade count: across 200 trades that is up to
 *    +/-100 rupees of pure artefact. Round at display, never before arithmetic.
 *
 * 4. **No breakeven.** Nothing tells the trader how far premium must move just to
 *    cover costs. On a 150-point option that is about 3 points before the trade is
 *    even flat, which is the single most useful number for a scalp.
 *
 * Cost model
 * ----------
 * Rates are the standard discount-broker F&O schedule as of 2025-26 and are all
 * overridable, because they change and they differ per broker. Defaults are
 * deliberately on the realistic side rather than the optimistic side: a paper
 * system that flatters itself is worse than no paper system.
 *
 * No dependencies. Attaches one global, FNOPnL.
 */
(function (root) {
  'use strict';

  // ── Default cost schedule (Indian F&O options, discount broker) ────────────
  var DEFAULTS = {
    // Brokerage is per executed order, flat, both legs.
    brokeragePerOrder: 20.00,
    // STT applies to the SELL leg only, on premium turnover.
    sttRateSell: 0.001,          // 0.10%
    // Exchange transaction charges on premium turnover, both legs (NSE).
    exchangeTxnRate: 0.0003503,  // 0.03503%
    // GST on (brokerage + exchange transaction charges + SEBI fees).
    gstRate: 0.18,
    // SEBI turnover fee on premium turnover, both legs.
    sebiRate: 0.000001,          // Rs 10 per crore
    // Stamp duty on the BUY leg only.
    stampRateBuy: 0.00003,       // 0.003%
    // Bid-ask half-spread actually paid, as a fraction of premium. Crossing the
    // spread on entry and exit means paying this twice.
    spreadPctPerSide: 0.0035,    // 0.35% per side
    // Optional absolute slippage in premium points per side, added to the above.
    slippagePointsPerSide: 0.0
  };

  function cfg(overrides) {
    var o = {};
    for (var k in DEFAULTS) {
      if (Object.prototype.hasOwnProperty.call(DEFAULTS, k)) o[k] = DEFAULTS[k];
    }
    if (overrides) {
      for (var j in overrides) {
        if (Object.prototype.hasOwnProperty.call(overrides, j) &&
            overrides[j] !== null && overrides[j] !== undefined &&
            !isNaN(overrides[j])) {
          o[j] = Number(overrides[j]);
        }
      }
    }
    return o;
  }

  function num(v, d) {
    var n = Number(v);
    return (isFinite(n) ? n : (d === undefined ? 0 : d));
  }

  /**
   * Statutory + brokerage charges for one round trip.
   *
   * @param {number} entryPremium premium paid per unit
   * @param {number} exitPremium  premium received per unit
   * @param {number} qty          total units (lotSize * lots)
   * @param {object} [opts]       cost overrides
   * @returns {object} itemised charges
   */
  function charges(entryPremium, exitPremium, qty, opts) {
    var c = cfg(opts);
    var ep = num(entryPremium), xp = num(exitPremium), q = num(qty);
    if (q <= 0) {
      return { brokerage: 0, stt: 0, exchangeTxn: 0, sebi: 0, stamp: 0,
               gst: 0, total: 0 };
    }
    var buyTurnover = ep * q;
    var sellTurnover = xp * q;
    var turnover = buyTurnover + sellTurnover;

    var brokerage = c.brokeragePerOrder * 2;                 // entry + exit
    var stt = sellTurnover * c.sttRateSell;                   // sell leg only
    var exchangeTxn = turnover * c.exchangeTxnRate;
    var sebi = turnover * c.sebiRate;
    var stamp = buyTurnover * c.stampRateBuy;                 // buy leg only
    var gst = (brokerage + exchangeTxn + sebi) * c.gstRate;

    var total = brokerage + stt + exchangeTxn + sebi + stamp + gst;
    return {
      brokerage: brokerage, stt: stt, exchangeTxn: exchangeTxn,
      sebi: sebi, stamp: stamp, gst: gst, total: total
    };
  }

  /**
   * Spread and slippage cost, in rupees, for a round trip.
   *
   * Separated from statutory charges because it is an execution cost rather than
   * a fee, and because it is the larger of the two on liquid options.
   */
  function frictionCost(entryPremium, exitPremium, qty, opts) {
    var c = cfg(opts);
    var q = num(qty);
    if (q <= 0) return 0;
    var entrySide = num(entryPremium) * c.spreadPctPerSide + c.slippagePointsPerSide;
    var exitSide = num(exitPremium) * c.spreadPctPerSide + c.slippagePointsPerSide;
    return (entrySide + exitSide) * q;
  }

  /**
   * Net P&L for a position. This is the number that should be displayed.
   *
   * Nothing is rounded here. Round only at the point of display, so summing
   * across trades does not accumulate rounding error.
   */
  function positionPnL(p, opts) {
    var entry = num(p && p.entryPremium);
    var current = num(p && (p.currentPremium !== undefined && p.currentPremium !== null
                            ? p.currentPremium : p.entryPremium));
    var lot = num(p && p.lotSize, 0);
    var mult = num(p && p.sizeMultiplier, 1) || 1;
    var qty = lot * mult;

    if (entry <= 0 || qty <= 0) {
      return {
        valid: false, grossAmt: 0, netAmt: 0, grossPct: 0, netPct: 0,
        charges: charges(0, 0, 0, opts), friction: 0, costTotal: 0,
        capitalDeployed: 0, breakevenPremium: entry, breakevenMovePct: 0,
        isWin: false,
        note: 'entryPremium and lotSize are required to compute P&L'
      };
    }

    var grossAmt = (current - entry) * qty;
    var ch = charges(entry, current, qty, opts);
    var fr = frictionCost(entry, current, qty, opts);
    var costTotal = ch.total + fr;
    var netAmt = grossAmt - costTotal;

    var capitalDeployed = entry * qty;
    var grossPct = (current - entry) / entry * 100;
    // Percentage is expressed on capital actually deployed, which is what the
    // trader risked, not on premium alone.
    var netPct = netAmt / capitalDeployed * 100;

    // What premium must the option reach for the trade to be flat?
    //
    // Solved algebraically rather than iteratively. Let X be the breakeven
    // premium, E the entry, q the quantity. Setting net P&L to zero:
    //
    //     (X - E)q = brokerage(1+g) + stt + exTxn + sebi + stamp + gst + friction
    //
    // STT, exchange charges, SEBI fees and the exit half-spread all scale with X,
    // and GST scales with the exchange and SEBI components, so those X-dependent
    // parts must be collected on the left. Writing
    //
    //     K = s + x + b + (x + b)g + sp        (per-rupee-of-X cost)
    //     C = B(1+g) + Eq[x + b + (x+b)g + st + sp] + 2·slip·q
    //
    // gives  Xq(1 - K) = Eq + C, hence X = (Eq + C) / (q(1 - K)).
    //
    // An earlier version omitted the two (x + b)·g GST terms, which left the
    // reported breakeven about Rs1.43 short on a 75-lot 150-premium trade — small,
    // but it meant "breakeven" was not actually breakeven.
    var c2 = cfg(opts);
    var g = c2.gstRate;
    var s = c2.sttRateSell;
    var x = c2.exchangeTxnRate;
    var b = c2.sebiRate;
    var st = c2.stampRateBuy;
    var sp = c2.spreadPctPerSide;
    var B = c2.brokeragePerOrder * 2;

    var K = s + x + b + (x + b) * g + sp;
    var C = B * (1 + g)
            + entry * qty * (x + b + (x + b) * g + st + sp)
            + 2 * c2.slippagePointsPerSide * qty;
    var denom = qty * (1 - K);
    var breakevenPremium = denom > 0 ? (entry * qty + C) / denom : entry;
    var breakevenMovePct = (breakevenPremium - entry) / entry * 100;

    return {
      valid: true,
      grossAmt: grossAmt,
      netAmt: netAmt,
      grossPct: grossPct,
      netPct: netPct,
      charges: ch,
      friction: fr,
      costTotal: costTotal,
      costPctOfCapital: costTotal / capitalDeployed * 100,
      capitalDeployed: capitalDeployed,
      qty: qty,
      breakevenPremium: breakevenPremium,
      breakevenMovePct: breakevenMovePct,
      // Win is decided on NET P&L. Deciding it on gross counted a one-paisa
      // gain as a win and inflated every reported win rate.
      isWin: netAmt > 0
    };
  }

  /**
   * Aggregate a set of closed trades without accumulating rounding error.
   *
   * Accepts either trades that already carry a net `pnl`, or raw trades with
   * entry/exit premiums, in which case costs are applied here.
   */
  function summarise(trades, opts) {
    var list = Array.isArray(trades) ? trades : [];
    var netTotal = 0, grossTotal = 0, costTotal = 0;
    var wins = 0, losses = 0, scratches = 0, scored = 0;
    var netPcts = [];
    var grossWinSum = 0, grossLossSum = 0;

    for (var i = 0; i < list.length; i++) {
      var t = list[i] || {};
      var r;
      if (t.entryPremium !== undefined && t.entryPremium !== null &&
          (t.exitPremium !== undefined || t.currentPremium !== undefined)) {
        r = positionPnL({
          entryPremium: t.entryPremium,
          currentPremium: (t.exitPremium !== undefined && t.exitPremium !== null)
                            ? t.exitPremium : t.currentPremium,
          lotSize: t.lotSize, sizeMultiplier: t.sizeMultiplier
        }, opts);
        if (!r.valid) continue;
      } else if (typeof t.netPnl === 'number') {
        r = { netAmt: t.netPnl, grossAmt: t.grossPnl || t.netPnl,
              costTotal: (t.grossPnl || t.netPnl) - t.netPnl,
              netPct: t.pnlPct || 0, isWin: t.netPnl > 0, valid: true };
      } else {
        continue; // not enough information; excluded rather than guessed
      }

      scored++;
      netTotal += r.netAmt;
      grossTotal += r.grossAmt;
      costTotal += r.costTotal;
      netPcts.push(r.netPct);
      if (r.netAmt > 0) { wins++; grossWinSum += r.netAmt; }
      else if (r.netAmt < 0) { losses++; grossLossSum += Math.abs(r.netAmt); }
      else scratches++;
    }

    var mean = netPcts.length
      ? netPcts.reduce(function (a, b) { return a + b; }, 0) / netPcts.length : 0;

    // Wilson lower bound, so a small sample cannot masquerade as an edge.
    var wl = wilsonLower(wins, scored);

    return {
      trades: list.length,
      scored: scored,
      excluded: list.length - scored,
      wins: wins, losses: losses, scratches: scratches,
      winRatePct: scored ? wins / scored * 100 : 0,
      winRateLowerBoundPct: wl * 100,
      netPnl: netTotal,
      grossPnl: grossTotal,
      totalCosts: costTotal,
      costsAsPctOfGross: grossTotal !== 0
        ? Math.abs(costTotal / grossTotal) * 100 : 0,
      expectancyPct: mean,
      profitFactor: grossLossSum > 0 ? grossWinSum / grossLossSum : null,
      maxDrawdown: maxDrawdown(list, opts),
      // A +/-10 point interval needs roughly 96 trades.
      sampleSufficient: scored >= 96
    };
  }

  function wilsonLower(successes, n, z) {
    if (!n) return 0;
    z = z || 1.96;
    var p = successes / n, z2 = z * z, d = 1 + z2 / n;
    var centre = (p + z2 / (2 * n)) / d;
    var margin = (z * Math.sqrt(p * (1 - p) / n + z2 / (4 * n * n))) / d;
    return Math.max(0, centre - margin);
  }

  function maxDrawdown(trades, opts) {
    var equity = 0, peak = 0, worst = 0;
    for (var i = 0; i < (trades || []).length; i++) {
      var t = trades[i] || {};
      var v = null;
      if (typeof t.netPnl === 'number') v = t.netPnl;
      else if (t.entryPremium != null && (t.exitPremium != null || t.currentPremium != null)) {
        var r = positionPnL({
          entryPremium: t.entryPremium,
          currentPremium: t.exitPremium != null ? t.exitPremium : t.currentPremium,
          lotSize: t.lotSize, sizeMultiplier: t.sizeMultiplier
        }, opts);
        if (r.valid) v = r.netAmt;
      }
      if (v === null) continue;
      equity += v;
      if (equity > peak) peak = equity;
      if (equity - peak < worst) worst = equity - peak;
    }
    return worst;
  }

  // ── Display helpers. Rounding happens here and nowhere else. ───────────────
  function inr(v) {
    var n = num(v);
    return (n < 0 ? '-₹' : '₹') + Math.abs(Math.round(n)).toLocaleString('en-IN');
  }
  function pct(v, dp) {
    var n = num(v);
    return (n >= 0 ? '+' : '') + n.toFixed(dp === undefined ? 2 : dp) + '%';
  }

  root.FNOPnL = {
    DEFAULTS: DEFAULTS,
    charges: charges,
    frictionCost: frictionCost,
    positionPnL: positionPnL,
    summarise: summarise,
    wilsonLower: wilsonLower,
    maxDrawdown: maxDrawdown,
    inr: inr,
    pct: pct
  };
})(typeof globalThis !== 'undefined' ? globalThis
  : (typeof window !== 'undefined' ? window : this));
