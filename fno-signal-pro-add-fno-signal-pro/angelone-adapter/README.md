# F&O Signal Pro — Angel One SmartAPI Adapter

A small service that bridges **Angel One SmartAPI** to the F&O Signal Pro
WordPress plugin. It logs in with your credentials, **streams live tick data
over WebSocket**, computes the full technical-indicator set, builds option-chain
analytics (PCR / OI / Max Pain / IV), and serves it in the exact JSON schema the
plugin's **"Custom REST endpoint"** provider expects.

> You host this yourself and set your own credentials as environment variables.
> **Your Angel One credentials never leave your server.**

---

## What it provides

| Field group | Source |
|-------------|--------|
| LTP / OHLC (live) | SmartWebSocketV2 tick stream + candles |
| EMA/RSI/MACD/ATR/Bollinger/SuperTrend/VWAP | Computed from SmartAPI historical candles |
| PCR / Max Pain / OI change | NFO option instruments + FULL market data (open interest) |
| IV | Solved from ATM CE/PE premiums (Black-Scholes) |
| India VIX | SmartAPI candles (token 26017) |

Macro fields (FII/DII, breadth, news) are returned neutral — Angel doesn't
provide them; the plugin scores those lightly.

---

## 1. Prerequisites
- An Angel One account with **SmartAPI** enabled: create an app at
  <https://smartapi.angelbroking.com/> to get your **API key**.
- Your **client code**, **login MPIN**, and your **TOTP secret** (the base32 seed
  behind the authenticator QR you set up for SmartAPI — not the 6-digit code).
- Python 3.9+ (or Docker).

## 2. Configure
```bash
cp .env.example .env
# edit .env and fill in ANGEL_* values and a strong ADAPTER_SECRET
```

## 3. Run

**Option A — Python**
```bash
pip install -r requirements.txt
uvicorn app:app --host 0.0.0.0 --port 8080
```

**Option B — Docker**
```bash
docker build -t fnosp-angel .
docker run -d --env-file .env -p 8080:8080 --name fnosp-angel fnosp-angel
```

## 4. Verify
```bash
curl "http://localhost:8080/health"
# {"ok":true,"logged_in":true,"tracking":["NIFTY","BANKNIFTY",...]}

curl -H "X-API-Key: <your ADAPTER_SECRET>" "http://localhost:8080/?instrument=BANKNIFTY"
# -> full JSON snapshot (ltp, rsi, macd, pcr, iv, ...)
```
Note: snapshots warm up shortly after startup and update on every tick. Outside
market hours the feed is quiet, so values reflect the last session.

## 5. Connect the plugin
In WordPress → **F&O Signal Pro → Settings**:
- **Provider:** `Custom REST endpoint`
- **Data API URL:** `https://your-adapter-host:8080/` (must be reachable from your WP server; put it behind HTTPS in production)
- **Data API Key:** the same value as `ADAPTER_SECRET`

Save, then generate a signal. The footer **source** will read `custom_rest`, and
the option premium/IV will now be **live** from Angel One.

---

## Deployment tips
- Put it behind HTTPS (a reverse proxy like Caddy/Nginx, or a platform like
  Render/Railway/Fly.io/a small VPS). WordPress must be able to reach the URL.
- Keep it running during market hours; the WebSocket auto-reconnects.
- One SmartAPI session per running instance. Don't share the same client across
  many instances.

## Security
- Credentials come only from environment variables — never commit your `.env`.
- The `ADAPTER_SECRET` gates every data request; use a long random value.
- CORS is not enabled; the plugin calls this server-to-server.

## Notes / limitations
- Index spot tokens are auto-resolved from Angel's scrip master, with sensible
  fallbacks. If an index doesn't resolve, set `INDEX_TOKEN_OVERRIDES`.
- OI *change* is measured since the adapter started (session-relative), not
  official day-over-day.
- SmartAPI field names are isolated in `angel_client.py`; adjust there if Angel
  revises its API. **Test during market hours** before relying on it.
