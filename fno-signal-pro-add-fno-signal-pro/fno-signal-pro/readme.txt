=== F&O Signal Pro ===
Contributors: fnosignalpro
Tags: trading, futures, options, nifty, banknifty, signals, fno
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Institutional-grade Futures & Options trading signal engine with a 100-point quantitative framework and optional AI (Anthropic Claude) commentary.

== Description ==

F&O Signal Pro generates structured BUY / SELL / NO TRADE signals for Indian
index & stock derivatives (NIFTY, BANKNIFTY, FINNIFTY, SENSEX, MIDCPNIFTY and
stock futures/options). It implements a transparent 10-step institutional
framework and a 100-point scoring model:

* Trend (25)
* Price Action (20)
* Options Data (20)
* Volume (10)
* Momentum (10)
* Institutional Activity (10)
* News Sentiment (5)

A signal is only emitted above your configured confidence threshold (default
75%). Hard risk filters block over-extended entries (e.g. RSI extremes, large
VWAP deviation). Output includes entry range, three targets, stop loss,
risk:reward, an option strategy (ATM/ITM/spread), a probability table, and a
one-line institutional verdict.

An optional AI layer (Anthropic Claude, model configurable) adds a concise
narrative commentary on top of the deterministic numeric signal. The AI never
overrides the computed numbers — it only explains and contextualises them.

= IMPORTANT DISCLAIMER =

This plugin is an educational / decision-support tool. It is NOT investment
advice and cannot guarantee accuracy or profits. Trading in futures & options
carries a substantial risk of loss. Always do your own research and consult a
SEBI-registered adviser where appropriate. No software can reliably "predict"
markets.

== Features ==

* Transparent 100-point scoring engine (no black box).
* Works with NO API key: Free live data mode + Demo mode both run keyless.
* Free live data: real price/OHLC/volume from a public source, with all
  technical indicators (EMA/RSI/MACD/ATR/VWAP/Bollinger/SuperTrend) computed
  on your server in PHP. Keyless India VIX, FII/DII flows, market breadth +
  sector strength, and news-sentiment. Best-effort live option chain
  (PCR/OI/Max Pain). Up to 6 of the 7 scoring components run with no API key.
* Pluggable live data via a Custom REST endpoint (you supply the feed).
* Optional Anthropic Claude commentary with a configurable model id.
* Fast: transient caching for near-instant repeat responses.
* REST API: GET /wp-json/fnosp/v1/signal?instrument=NIFTY&ai=0
* Telegram alerts: auto-push high-confidence BUY/SELL signals to one or more
  Telegram chats (free bot token, no paid key). Market-hours gating, per-symbol
  dedup/cooldown, configurable confidence threshold, and a one-click test.
* Email alerts: the same alerts delivered by email via your site mailer/SMTP,
  to one or more recipients (defaults to the site admin email).
* Frontend shortcode: [fno_signal instrument="NIFTY" ai="0" refresh="0"]
* Admin dashboard to generate and inspect signals interactively.

== Installation ==

1. Upload the `fno-signal-pro` folder to `/wp-content/plugins/`.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to "F&O Signals" -> "Settings".
4. (Optional) Add your Anthropic API key and model id to enable AI commentary.
5. (Optional) Switch the data provider to "Custom REST endpoint" and supply
   your market-data API URL/key. Otherwise it runs in Demo mode.
6. Use the Dashboard to generate a signal, or embed the shortcode on any page.

== Data modes (no API key required) ==

The plugin ships with THREE data modes. Two of them need no API key at all:

1. Free live data (default) — NO KEY NEEDED
   Pulls real price/OHLC/volume from a public chart source and computes every
   technical indicator on your server. Index option-chain analytics (PCR, OI
   change, Max Pain, IV) are fetched best-effort from NSE; if your host's IP is
   blocked by NSE those fields fall back to neutral and the signal becomes more
   conservative (clearly noted in the "Data Coverage" section of the output).
   India VIX is fetched keyless. FII/DII net cash, market breadth and sector
   strength are fetched best-effort from NSE (work from many hosts; use the NSE
   proxy option if your IP is blocked). News sentiment is computed keyless from
   public news-search RSS headlines using a finance lexicon.
   The only component not covered keyless is intraday market-breadth refresh
   frequency on some hosts; everything degrades gracefully to neutral with a
   clear note when a source is briefly unavailable.

   NSE proxy (optional): NSE blocks many datacenter IPs, which can disable the
   live option chain & FII/DII. Set an NSE proxy URL in Settings to route those
   requests through it. Use {url} as a placeholder for the target URL, end the
   value with "=" to append the URL-encoded target, or provide a plain prefix.

2. Demo — NO KEY NEEDED
   Deterministic synthetic data for previewing the UI and logic offline.

3. Custom REST endpoint — your own feed (optional key)
   For full institutional coverage (FII/DII, breadth, sector, news, VIX), point
   the plugin at your own data API using the JSON schema below.

Note: the Anthropic Claude commentary is entirely OPTIONAL. The numeric signal
engine works fully without any AI key.

== Telegram alerts setup ==

1. In Telegram, message @BotFather and run /newbot to create a free bot. Copy
   the bot token it gives you.
2. Find your chat id: message your new bot once, then message @userinfobot (or
   @getidsbot) which replies with your numeric chat id. For a group/channel,
   add the bot and use the negative group id.
3. In WP Admin -> F&O Signals -> Settings -> Telegram Alerts: enable alerts,
   paste the token and chat id(s), set the alert confidence threshold and
   directions, then Save.
4. Click "Send test message" to confirm delivery.

The plugin scans your watched instruments every 5 minutes (via WP-Cron) during
NSE market hours and pushes an alert whenever a signal meets your confidence
threshold. The same direction is not re-sent until the cooldown elapses; a
direction flip (e.g. BUY -> SELL) always alerts.

Note: WP-Cron runs on site traffic. For reliable timing on a low-traffic site,
set up a real server cron to hit wp-cron.php every few minutes.

== Custom data provider JSON schema ==
When "Custom REST endpoint" is selected, the plugin sends:

    GET {your_url}?instrument=NIFTY
    Authorization: Bearer {your_key}    (also sent as X-API-Key)

Your endpoint must return JSON. Recognised numeric fields (missing fields fall
back to safe defaults):

    {
      "ltp": 23510.5,
      "open": 23450, "high": 23560, "low": 23420, "close": 23510.5,
      "prev_open": 23380, "prev_high": 23470, "prev_low": 23330, "prev_close": 23420,
      "volume": 1250000, "avg_volume": 1000000,
      "vwap": 23480,
      "rsi": 61.2, "rsi_prev": 58.4,
      "macd": 12.3, "macd_signal": 8.1, "macd_hist": 4.2,
      "supertrend": "bullish",
      "ema9": 23500, "ema21": 23470, "ema50": 23380, "ema200": 22950,
      "atr": 120,
      "bb_upper": 23700, "bb_mid": 23480, "bb_lower": 23260,
      "pcr": 1.18, "max_pain": 23500, "iv": 13.5, "vix": 12.9,
      "call_oi_chg": -45000, "put_oi_chg": 90000,
      "fii_net": 1200, "dii_net": 800,
      "adv_decline": 1.4, "sector_strength": 35, "news_sentiment": 0.25,
      "lot_size": 25
    }

== Frequently Asked Questions ==

= Does it guarantee profitable trades? =
No. It is a structured analysis tool. Markets are probabilistic; treat every
signal as one input into your own risk-managed process.

= Do I need an AI key? =
No. The numeric signal engine works fully without AI. The Claude integration is
optional commentary.

= Which Claude model should I use? =
Any model id your Anthropic account supports. Set it in Settings. The default is
a current Sonnet model; you can change it to an Opus model id if you prefer.

== Changelog ==

= 1.0.0 =
* Initial release: scoring engine, REST API, caching, admin dashboard,
  settings, frontend shortcode, optional Anthropic Claude commentary,
  free keyless live data (price/indicators/VIX/FII-DII/breadth/news),
  optional NSE proxy, and Telegram alerts with market-hours gating & dedup.
