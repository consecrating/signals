# F&O Signal Pro — NSE Proxy (Cloudflare Worker)

A tiny, free Cloudflare Worker that lets the **F&O Signal Pro** WordPress plugin
fetch live NSE data (option chain / FII-DII / market breadth) from **any host**,
even when your web host's IP is blocked by NSE.

It primes the NSE session cookie at Cloudflare's edge, adds browser-like
headers, caches responses briefly, and returns JSON with permissive CORS. Only
requests to `nseindia.com` are forwarded, so it can't be abused as an open proxy.

## Why you might need this

NSE blocks many datacenter IP ranges. In the plugin's Free data mode, the
price/indicators/VIX still work, but the **option chain**, **FII/DII**, and
**market breadth** calls can fail with "unavailable from server". Routing those
calls through this Worker fixes that.

## Deploy (about 5 minutes, free)

You need a free [Cloudflare account](https://dash.cloudflare.com/sign-up) and
Node.js installed locally.

```bash
cd nse-proxy-worker
npm install
npx wrangler login        # opens browser to authorize
npx wrangler deploy
```

Wrangler prints your Worker URL, e.g.:

```
https://fno-nse-proxy.<your-subdomain>.workers.dev
```

### (Recommended) protect it with a secret

So only your site can use it:

```bash
npx wrangler secret put PROXY_SECRET
# enter any random string, e.g. a 24-char password
```

## Connect it to the plugin

In WordPress: **F&O Signals → Settings → Market Data Source → NSE proxy**, paste:

- Without a secret:
  ```
  https://fno-nse-proxy.<your-subdomain>.workers.dev/?url={url}
  ```
- With a secret:
  ```
  https://fno-nse-proxy.<your-subdomain>.workers.dev/?url={url}&key=YOUR_SECRET
  ```

Save. The plugin substitutes `{url}` with the URL-encoded NSE endpoint at request
time. Generate a signal — the **Data Coverage** panel should now show
"Option chain: live" and "Market breadth & sector strength: live".

## Test it

```bash
# Health check
curl "https://fno-nse-proxy.<your-subdomain>.workers.dev/health"

# Proxied option chain (URL-encode the target)
curl "https://fno-nse-proxy.<your-subdomain>.workers.dev/?url=https%3A%2F%2Fwww.nseindia.com%2Fapi%2Foption-chain-indices%3Fsymbol%3DNIFTY"
```

## Notes

- Free Workers plan allows 100k requests/day — far more than this needs.
- Responses are edge-cached for ~25s to be polite to NSE and keep the plugin fast.
- This does not bypass any paywall; NSE option-chain data is publicly available.
  It only adds the session priming + headers NSE expects, from an edge IP.
- Respect NSE's terms of use and rate limits.

## Files

- `src/worker.js` — the Worker code.
- `wrangler.toml` — Cloudflare deployment config.
- `package.json` — dev dependency (wrangler) + scripts.
