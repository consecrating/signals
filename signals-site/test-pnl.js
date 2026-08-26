/* Verification for FNOPnL. Run: bun test-pnl.js */
const fs = require('fs');
eval(fs.readFileSync('fno-pnl.js', 'utf8'));
const P = FNOPnL;
let FAIL = 0;
function ok(cond, msg, extra) {
  if (cond) console.log('  pass: ' + msg);
  else { console.log('  FAIL: ' + msg + (extra ? '  ' + extra : '')); FAIL++; }
}
function near(a, b, tol, msg) { ok(Math.abs(a - b) <= tol, msg, `got ${a} want ~${b}`); }

console.log('=== A. charges on a realistic NIFTY ATM trade ===');
// NIFTY lot 75, entry 150, exit 165. Premium turnover 11,250 + 12,375.
const q = 75, entry = 150, exit = 165;
const ch = P.charges(entry, exit, q);
console.log(`  turnover buy ₹${(entry*q).toLocaleString('en-IN')} sell ₹${(exit*q).toLocaleString('en-IN')}`);
for (const k of ['brokerage','stt','exchangeTxn','sebi','stamp','gst','total'])
  console.log(`    ${k.padEnd(12)} ₹${ch[k].toFixed(2)}`);
near(ch.brokerage, 40, 0.001, 'brokerage = 2 x Rs20');
near(ch.stt, 12375 * 0.001, 0.01, 'STT charged on SELL turnover only');
near(ch.stamp, 11250 * 0.00003, 0.001, 'stamp charged on BUY turnover only');
near(ch.exchangeTxn, 23625 * 0.0003503, 0.01, 'exchange txn on total turnover');
near(ch.gst, (ch.brokerage + ch.exchangeTxn + ch.sebi) * 0.18, 0.01, 'GST on brokerage+txn+sebi');
ok(ch.stt > 0 && ch.total > ch.stt, 'total exceeds any single component');

console.log('\n=== B. net vs gross on that trade ===');
const r = P.positionPnL({ entryPremium: entry, currentPremium: exit, lotSize: q });
console.log(`  gross            ${P.inr(r.grossAmt)}  (${P.pct(r.grossPct)} on premium)`);
console.log(`  statutory        ${P.inr(r.charges.total)}`);
console.log(`  spread/slippage  ${P.inr(r.friction)}`);
console.log(`  total cost       ${P.inr(r.costTotal)}  (${r.costPctOfCapital.toFixed(2)}% of capital)`);
console.log(`  NET              ${P.inr(r.netAmt)}  (${P.pct(r.netPct)})`);
near(r.grossAmt, 15 * 75, 0.01, 'gross = (165-150) x 75 = 1125');
ok(r.netAmt < r.grossAmt, 'net is below gross');
ok(r.costTotal > 0, 'costs are non-zero');
near(r.costTotal, r.charges.total + r.friction, 0.01, 'cost = statutory + friction');
ok(r.isWin === true, 'a genuine 10% move is still a win after costs');

console.log('\n=== C. the defect: small gain is a LOSS after costs ===');
// +1 point on a 150 premium: gross +Rs75, well under round-trip cost.
const tiny = P.positionPnL({ entryPremium: 150, currentPremium: 151, lotSize: 75 });
console.log(`  +1 point: gross ${P.inr(tiny.grossAmt)}  net ${P.inr(tiny.netAmt)}  isWin=${tiny.isWin}`);
ok(tiny.grossAmt > 0, 'gross is positive');
ok(tiny.netAmt < 0, 'net is negative');
ok(tiny.isWin === false, 'NOT counted as a win  <-- old code counted this as a win');

console.log('\n=== D. breakeven is reported ===');
console.log(`  entry 150 -> breakeven premium ${r.breakevenPremium.toFixed(2)} `
  + `(${P.pct(r.breakevenMovePct)} move just to be flat)`);
ok(r.breakevenPremium > 150, 'breakeven above entry');
const atBE = P.positionPnL({ entryPremium: 150, currentPremium: r.breakevenPremium, lotSize: 75 });
near(atBE.netAmt, 0, 0.02, 'net is 0 exactly at the computed breakeven');
ok(r.breakevenMovePct > 0.5 && r.breakevenMovePct < 5,
   'breakeven move is a plausible 0.5-5% band');

console.log('\n=== E. no rounding error accumulation ===');
// 200 trades each +Rs0.40 gross. Rounding each to Rs0 then summing loses it all.
const many = [];
for (let i = 0; i < 200; i++) many.push({ netPnl: 0.4 });
const s = P.summarise(many);
near(s.netPnl, 80, 0.001, '200 x Rs0.40 sums to Rs80, not Rs0');
console.log(`  summed exactly: ${s.netPnl}  (naive per-trade Math.round would give 0)`);

console.log('\n=== F. win rate is computed on NET, with a Wilson lower bound ===');
const mixed = [];
for (let i = 0; i < 9; i++) mixed.push({ entryPremium: 150, exitPremium: 170, lotSize: 75 });
for (let i = 0; i < 3; i++) mixed.push({ entryPremium: 150, exitPremium: 130, lotSize: 75 });
const ms = P.summarise(mixed);
console.log(`  ${ms.wins}W/${ms.losses}L of ${ms.scored}  winRate ${ms.winRatePct.toFixed(1)}% `
  + `lower bound ${ms.winRateLowerBoundPct.toFixed(1)}%`);
console.log(`  net ${P.inr(ms.netPnl)}  gross ${P.inr(ms.grossPnl)}  costs ${P.inr(ms.totalCosts)} `
  + `(${ms.costsAsPctOfGross.toFixed(1)}% of gross)`);
console.log(`  PF ${ms.profitFactor.toFixed(2)}  maxDD ${P.inr(ms.maxDrawdown)}  `
  + `sampleSufficient=${ms.sampleSufficient}`);
ok(ms.wins === 9 && ms.losses === 3, '9 wins / 3 losses');
near(ms.winRatePct, 75, 0.01, 'win rate 75%');
ok(ms.winRateLowerBoundPct < 55, 'lower bound falls below break-even on n=12');
ok(ms.sampleSufficient === false, 'n=12 flagged as insufficient');
ok(ms.totalCosts > 0, 'costs aggregated');
ok(ms.maxDrawdown < 0, 'drawdown recorded');

console.log('\n=== G. bad input is excluded, never guessed ===');
const bad = P.positionPnL({ entryPremium: 0, lotSize: 75 });
ok(bad.valid === false, 'zero entry premium -> valid:false');
ok(bad.netAmt === 0, 'no fabricated number');
const partial = P.summarise([{ foo: 1 }, { netPnl: 100 }]);
ok(partial.scored === 1 && partial.excluded === 1, 'incomplete trade excluded, not guessed');

console.log('\n=== H. overrides work (different broker / illiquid strike) ===');
const zero = P.positionPnL({ entryPremium: 150, currentPremium: 151, lotSize: 75 },
  { brokeragePerOrder: 0, sttRateSell: 0, exchangeTxnRate: 0, gstRate: 0,
    sebiRate: 0, stampRateBuy: 0, spreadPctPerSide: 0 });
near(zero.netAmt, 75, 0.01, 'all costs zeroed -> net equals gross');
const wide = P.positionPnL({ entryPremium: 150, currentPremium: 165, lotSize: 75 },
  { spreadPctPerSide: 0.02 });
ok(wide.netAmt < r.netAmt, 'a wider spread reduces net further');
console.log(`  illiquid (2% spread/side): net ${P.inr(wide.netAmt)} vs liquid ${P.inr(r.netAmt)}`);

console.log('\n' + (FAIL === 0 ? 'ALL CHECKS PASSED' : FAIL + ' CHECK(S) FAILED'));
