# F&O Signal Pro

Institutional-grade Futures & Options trading-signal system for Indian markets
(NIFTY, BANKNIFTY, FINNIFTY, SENSEX, MIDCPNIFTY, stock futures & equity options).

> **Disclaimer:** Educational / decision-support tool only. **Not investment
> advice.** F&O trading carries substantial risk of loss. No software can
> reliably predict markets. Always do your own research.

## Contents

| Path | What it is |
|------|------------|
| `fno-signal-pro/` | The WordPress plugin (source) |
| `nse-proxy-worker/` | Optional Cloudflare Worker that proxies NSE (for blocked hosts) |
| `dist/fno-signal-pro.zip` | Installable plugin zip (upload in WP → Plugins → Add New → Upload) |
| `dist/nse-proxy-worker.zip` | Zipped Worker project |

## Highlights

- Transparent **100-point scoring engine** implementing a 10-step institutional
  framework (Trend 25 / Price 20 / Options 20 / Volume 10 / Momentum 10 /
  Institutional 10 / News 5). Emits BUY / SELL / NO TRADE only above a
  configurable confidence threshold (default 75%).
- **Works with no API key:** Free live data mode pulls real price/OHLC/volume
  from a public source and computes EMA/RSI/MACD/ATR/VWAP/Bollinger/SuperTrend
  in PHP. Keyless India VIX, FII/DII, market breadth + sector strength and
  news-sentiment. Best-effort live option chain (PCR/OI/Max Pain).
- **Alerts:** Telegram + Email when a high-confidence signal fires, with
  market-hours gating and per-symbol dedup/cooldown.
- **Surfaces:** admin dashboard, `[fno_signal]` shortcode, and a REST API.
- Optional **Anthropic Claude** commentary (configurable model) on top of the
  deterministic numeric signal.

## Install (plugin)

1. Download `dist/fno-signal-pro.zip`.
2. WordPress → **Plugins → Add New → Upload Plugin** → choose the zip →
   **Install Now** → **Activate**.
3. **F&O Signals → Settings** — defaults to free live data (no key). Optionally
   add Telegram/Email alerts, an Anthropic key, or an NSE proxy URL.

## Optional NSE proxy

If your host's IP is blocked by NSE (disables live option chain / FII-DII /
breadth), deploy `nse-proxy-worker/` to Cloudflare (free) and paste its URL into
the plugin's **NSE proxy** setting. See `nse-proxy-worker/README.md`.
