# Patch: correct P&L, cost and win/loss arithmetic in PaperTrade

## What is wrong now

`tool.html` computes open-position P&L as:

```js
const pnlPct = p.cost > 0
    ? Math.round((p.currentPremium - p.entryPremium) / p.entryPremium * 100) : 0;
const pnlAmt = Math.round((p.currentPremium - p.entryPremium)
                          * p.lotSize * (p.sizeMultiplier || 1));
```

and classifies a closed trade as:

```js
const isWin = t.pnl > 0;
```

Four separate accuracy defects.

### 1. Zero transaction costs

A real NSE options round trip pays brokerage, STT on the sell leg, exchange
transaction charges, GST on those, SEBI turnover fees, stamp duty on the buy leg,
and the bid-ask spread twice. Measured on a NIFTY ATM trade — lot 75, entry 150,
exit 165:

```
turnover buy Rs11,250  sell Rs12,375

brokerage     Rs40.00     (2 x Rs20)
stt           Rs12.38     (0.10% of SELL turnover only)
exchangeTxn    Rs8.28     (0.03503% of total turnover)
sebi           Rs0.02
stamp          Rs0.34     (0.003% of BUY turnover only)
gst            Rs8.69     (18% of brokerage + txn + sebi)
              ────────
statutory     Rs69.71
spread        Rs82.69     (0.35% per side, crossed twice)
              ────────
total         Rs152.40    = 1.35% of capital deployed
```

So a trade the current code shows as **+10.00% / Rs1,125** is actually
**+8.65% / Rs973**. Every displayed number is overstated by the round-trip cost.

Slippage is already tracked server-side and shown in the summary, but the
client-side open-position display subtracts none of it.

### 2. Win classified on gross

`t.pnl > 0` counts a one-paisa gain as a win. Concretely, a +1 point move on a
150 premium:

```
gross  +Rs75      net  -Rs72      isWin: false (was true)
```

The old code recorded that as a **win**. Win rate is the number used to decide
whether the strategy works, so this inflates the one metric that matters most.

### 3. Rounding before aggregation

`Math.round` is applied per trade, then those rounded values are summed. Error
accumulates linearly in the trade count. 200 trades each gaining Rs0.40:

```
correct sum       Rs80
naive per-trade Math.round then sum   Rs0
```

Round at the point of display, never before arithmetic.

### 4. No breakeven shown

Nothing tells the trader how far premium must move just to cover costs. On a 150
premium with 75 lot that is **151.95, a +1.30% move to be flat.** Any target
inside that band is guaranteed to lose money. This is the single most useful
number for a scalp and it is absent.

## The change

`fno-pnl.js` exposes `FNOPnL` with no dependencies:

| function | purpose |
|---|---|
| `charges(entry, exit, qty, opts)` | itemised statutory charges |
| `frictionCost(entry, exit, qty, opts)` | spread + absolute slippage |
| `positionPnL(p, opts)` | gross, net, costs, breakeven, `isWin` on **net** |
| `summarise(trades, opts)` | exact aggregation, Wilson lower bound, PF, max DD |
| `inr(v)` / `pct(v)` | the only place rounding happens |

Every rate is overridable, because they differ per broker and change over time.
Defaults are set on the realistic side rather than the optimistic side — a paper
system that flatters itself is worse than no paper system.

Breakeven is solved algebraically, not iteratively:

    K = s + x + b + (x + b)g + sp
    C = B(1+g) + Eq[x + b + (x+b)g + st + sp] + 2·slip·q
    X = (Eq + C) / (q(1 - K))

An earlier draft omitted the two `(x + b)·g` GST terms and left the reported
breakeven Rs1.43 short, which meant "breakeven" was not breakeven. Now exact to
two paise, asserted in the test.

## Apply

Load `fno-pnl.js` before the existing inline script, then two edits.

### Edit 1 — open-position P&L

Find:

```js
const pnlPct = p.cost > 0 ? Math.round((p.currentPremium - p.entryPremium) / p.entryPremium * 100) : 0;
const pnlAmt = Math.round((p.currentPremium - p.entryPremium) * p.lotSize * (p.sizeMultiplier || 1));
```

Replace with:

```js
const _r     = FNOPnL.positionPnL(p);
const pnlPct = Math.round(_r.netPct);          // net of costs
const pnlAmt = Math.round(_r.netAmt);          // net of costs
const pnlGrossAmt = Math.round(_r.grossAmt);   // for the tooltip
const beText = 'BE ' + _r.breakevenPremium.toFixed(2)
             + ' (' + FNOPnL.pct(_r.breakevenMovePct) + ')';
```

Then surface `beText` next to the existing `Slippage: Rs...` line, so the trader
sees the cost hurdle alongside the position.

### Edit 2 — win classification

Find:

```js
const isWin = t.pnl > 0;
```

Replace with:

```js
// Win must be decided on P&L net of costs. `t.pnl > 0` counted a one-paisa
// gain as a win and inflated the reported win rate.
const isWin = (typeof t.netPnl === 'number')
  ? t.netPnl > 0
  : FNOPnL.positionPnL({
      entryPremium: t.entryPremium,
      currentPremium: t.exitPremium != null ? t.exitPremium : t.currentPremium,
      lotSize: t.lotSize, sizeMultiplier: t.sizeMultiplier
    }).isWin;
```

### Optional but recommended — honest scorecard

Replace the win-rate display with `FNOPnL.summarise(closedTrades)`, which returns
`winRateLowerBoundPct` and `sampleSufficient` alongside the point estimate. On
9 wins from 12 trades:

```
winRate 75.0%   lower bound 46.8%   sampleSufficient: false
```

75% looks like an edge. A lower bound of 46.8% is below break-even, so on that
sample there is no demonstrated edge either way. A ±10 point interval needs about
96 trades.

## Verify

```
bun test-pnl.js     # 30 checks, all passing
```

Covers: charge arithmetic against hand-computed values, STT on the sell leg only,
stamp on the buy leg only, GST base, net-below-gross, the small-gain-is-a-loss
case, breakeven exactness, rounding accumulation, win rate on net, Wilson bound,
invalid input returning `valid:false` rather than a fabricated number, and rate
overrides for illiquid strikes.

## Server side, not covered here

The paper trade engine itself is server-side — `tool.html` renders a report
object. If the server computes `pnl` gross, this patch corrects the display but
not the stored history. Applying the same cost model where trades are booked is
what makes the recorded track record accurate.
