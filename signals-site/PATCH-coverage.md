# Patch: fail visibly when a factor has no data

## Corrected diagnosis

An earlier read of this called the `option_chain` HTTP 502 "the error". That was
over-stated. Re-checked with the market closed (IST 04:42, Thursday; session is
09:15-15:30):

```
health        200 OK
quote         200 OK
candles       200 OK   225 historical bars
indices       200 OK
ltp           200 OK
fiidii        200 OK
news          200 OK
option_chain  502      {"error":"Option chain unavailable",
                        "triedExpiries":["01SEP2026","08SEP2026","15SEP2026","22SEP2026"]}
```

The four expiries tried are all Tuesdays, which matches NSE's current NIFTY
weekly expiry, so the expiry calendar is correct. Everything that serves
last-traded or historical data works; only the live option chain and Greeks are
missing. **That is expected behaviour outside market hours** — Angel One does not
serve a live chain when the market is shut. It is not, on this evidence, a broken
endpoint.

## The defect that is real

The 502 is benign. What the page does *in response to it* is not.

`buildSnapshot()` succeeds after hours because candles are available. The chain
response is then discarded by a bare guard:

```js
if (gexResp && gexResp.status && gexResp.data) {
    snap.gex = gexResp.data;
    ...
}
// no else — nothing records that the options factor is absent
```

So the sequence right now, at 04:42 IST, is:

1. candles succeed, so `buildSnapshot` returns a snapshot
2. `option_chain` and `gex` fail; the guard silently skips
3. `generateSignal()` computes from **5 of 7 factors**
4. the UI renders `COMPONENT BREAKDOWN (7-FACTOR)` and an undiscounted confidence
5. `closed()` never fires, because it is only reached when `buildSnapshot`
   returns null — i.e. only when *candles* are missing

The result is a confident-looking 7-factor signal built on 5 factors, with
nothing on screen indicating anything is missing. The absent factor is the
options microstructure block — GEX, PCR, IV, IV skew, max pain, call/put walls —
which is the most heavily weighted input in the engine and the one the page
advertises as its edge.

This is the same failure mode as treating a missing dimension as a neutral one:
absence of evidence gets scored as evidence of neutrality. It is worse after
hours than during an outage, because it happens every single evening, night and
weekend rather than occasionally.

## What this patch does

Three changes, no behavioural change while all seven factors have data.

1. **Track coverage.** Record which factors actually received data, with their
   engine weights, so "how much of the model is live" is a number rather than an
   assumption.
2. **Discount confidence by missing coverage,** and never present a discounted
   signal as actionable if the missing weight is material.
3. **Say so on screen,** and distinguish *market closed* from *data outage*. The
   two look identical to the current UI and mean very different things.

## Apply

Load `signal-coverage.js` after the existing inline script, then make three
small edits at the anchors below. Each anchor is quoted from the deployed file so
it can be located exactly.

### Edit 1 — record coverage where the chain response is consumed

Find:

```js
if (gexResp && gexResp.status && gexResp.data) {
    snap.gex = gexResp.data;
```

Replace the whole `if` with:

```js
snap._coverage = FNOCoverage.assess({ meta, fii, news, mtf, gexResp });
if (gexResp && gexResp.status && gexResp.data) {
    snap.gex = gexResp.data;
```

(keep the existing body; only the preceding line is added)

### Edit 2 — discount the signal after it is generated

Find:

```js
let sig = generateSignal(snap, { con
```

Immediately after that statement completes, add:

```js
sig = FNOCoverage.applyDiscount(sig, snap._coverage);
```

### Edit 3 — render the banner above the signal card

Find:

```js
'<div class="card mt-2"><div class="card-title">COMPONENT BREAKDOWN (7-FACTOR)</div>'
```

Change to:

```js
FNOCoverage.banner(snap._coverage) +
'<div class="card mt-2"><div class="card-title">COMPONENT BREAKDOWN ('
  + FNOCoverage.factorLabel(snap._coverage) + ')</div>'
```

## Verify after applying

With the market closed, the page shows an amber banner reading:

> MARKET CLOSED (pre-open) — options, volatility unavailable.
> Signal computed on 5 of 7 factors (62% of model weight).
> Confidence reduced; not actionable.

and the breakdown heading reads `COMPONENT BREAKDOWN (5 OF 7 FACTORS)`.

Coverage is 62%, not 70%, because the option chain also supplies IV — losing it
takes both the options factor (weight 30) and volatility (weight 8) dark, so 38
of 100 weight is missing. A confidence of 78 becomes 48.4 and the direction is
forced to NO_TRADE.

During market hours with a healthy chain, the banner is absent and confidence is
unchanged — `applyDiscount` is a no-op at full coverage. That property is worth
checking explicitly before deploying.

## Not addressed here

- **Float precision.** The proxy serializes floats at full precision
  (`"open":24341.95000000000072759576141834259033203125`). With 225 candles
  across five arrays on a 30s auto-refresh this is a large, pointless payload.
  Fix belongs in `proxy.php`: `round($v, 2)` for prices, `round($v, 4)` for
  ratios. `proxy.php` is not in this repo, so it is out of scope for this patch.
- **Suspect DII value.** `fiidii` reported `dii: 6425.16` (Rs Cr). A single-day
  DII figure that large is implausible; typical is a few hundred to ~2000 Cr.
  Likely a cumulative figure reported as daily, or a scrape reading the wrong
  column. Needs checking against the NSE source, since it feeds the flow factor.
- **The 502 itself.** If the chain is also unavailable *during* market hours,
  that is a separate problem and this patch will correctly surface it rather than
  hide it — which is the point.

---

# URGENT, unrelated to this patch: an API key is exposed client-side

`signal.html` line 70, on the publicly served page:

```js
const OPENROUTER_KEY = 'sk-or-v1-<73-char key>';
```

Anyone who loads the page can read this from view-source and bill LLM calls to
the account. GitHub push protection blocked this file from being committed here,
which is how it surfaced — an earlier grep of mine missed it because the pattern
keyed on variable names (`api_key`, `token`, `secret`) rather than on the key
format `sk-or-v1-`.

**Do first:**

1. Revoke the key at openrouter.ai -> Keys. Assume it is compromised.
2. Issue a replacement and put it in `proxy.php` server-side only.
3. Add an action to `proxy.php` that forwards the LLM call, e.g.
   `?action=llm`, and have the page call that instead of OpenRouter directly.
   The browser must never hold the key.
4. Check OpenRouter usage/billing for calls you did not make.

Note this cannot be fixed by rotating alone: any key placed in client-side JS is
public by construction. It has to move server-side.

The deployed `signal.html` is deliberately **not** committed to this repo for the
same reason. Sanitise it first — replace the literal with a call to the proxy —
then track it.
