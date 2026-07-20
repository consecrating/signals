"""
Angel One SmartAPI client wrapper.

Responsibilities:
  - Login (generateSession) using API key + client code + MPIN + TOTP.
  - Download & cache the scrip master (instrument -> token mapping).
  - Resolve index spot tokens and NFO option instruments.
  - Fetch historical candles and full market data (LTP/OHLC/OI).
  - Provide a WebSocket ticker (SmartWebSocketV2) for live LTP updates.

SmartAPI-specific field names are isolated here. If Angel changes its API,
this is the only file that should need adjustment.

Requires: smartapi-python, pyotp, requests, logzero, websocket-client.
"""

from __future__ import annotations

import threading
import time
import datetime as dt
from typing import Callable, Dict, List, Optional

import requests

import config

SCRIP_MASTER_URL = "https://margincalculator.angelbroking.com/OpenAPI_File/files/OpenAPIScripMaster.json"

# Our symbol -> index metadata. `default_token` is a best-effort fallback for the
# NSE index spot; `nfo_name` is the "name" field used by NFO option rows.
INDEX_META = {
    "NIFTY":      {"spot_name": "Nifty 50",        "default_token": "26000", "nfo_name": "NIFTY",      "exchange": "NSE"},
    "NIFTY50":    {"spot_name": "Nifty 50",        "default_token": "26000", "nfo_name": "NIFTY",      "exchange": "NSE"},
    "BANKNIFTY":  {"spot_name": "Nifty Bank",      "default_token": "26009", "nfo_name": "BANKNIFTY",  "exchange": "NSE"},
    "FINNIFTY":   {"spot_name": "Nifty Fin Service","default_token": "26037", "nfo_name": "FINNIFTY",   "exchange": "NSE"},
    "MIDCPNIFTY": {"spot_name": "NIFTY MID SELECT","default_token": "26074", "nfo_name": "MIDCPNIFTY", "exchange": "NSE"},
}

# WebSocket exchange type codes.
EXCH_TYPE = {"NSE": 1, "NFO": 2, "BSE": 3, "BFO": 4}


class AngelClient:
    def __init__(self):
        self.smart = None
        self.auth_token = None
        self.feed_token = None
        self._scrip = None            # full scrip master (list of dicts)
        self._scrip_at = 0.0
        self._lock = threading.Lock()

    # ----------------------------------------------------------------- login
    def login(self):
        from SmartApi import SmartConnect  # lazy import
        import pyotp

        self.smart = SmartConnect(api_key=config.API_KEY)
        totp = pyotp.TOTP(config.TOTP_SECRET).now()
        data = self.smart.generateSession(config.CLIENT_CODE, config.MPIN, totp)
        if not data or not data.get("status"):
            raise RuntimeError("Angel login failed: %s" % (data.get("message") if data else "no response"))
        self.auth_token = data["data"]["jwtToken"]
        self.feed_token = self.smart.getfeedToken()
        return True

    # ---------------------------------------------------------- scrip master
    def scrip_master(self) -> List[dict]:
        # Cache for 12 hours; Angel refreshes it daily.
        with self._lock:
            if self._scrip and (time.time() - self._scrip_at) < 12 * 3600:
                return self._scrip
        resp = requests.get(SCRIP_MASTER_URL, timeout=30)
        resp.raise_for_status()
        data = resp.json()
        with self._lock:
            self._scrip = data
            self._scrip_at = time.time()
        return data

    def resolve_index(self, symbol: str) -> dict:
        """Return {exchange, token, name} for an index spot."""
        symbol = symbol.upper()
        meta = INDEX_META.get(symbol)
        if not meta:
            # Treat as an NSE equity symbol.
            return {"exchange": "NSE", "token": None, "name": symbol}

        overrides = config.index_token_overrides()
        if symbol in overrides:
            return {"exchange": meta["exchange"], "token": overrides[symbol], "name": meta["spot_name"]}

        # Try to find the spot token in the scrip master by symbol/name.
        want = meta["spot_name"].upper()
        try:
            for row in self.scrip_master():
                if row.get("exch_seg") != meta["exchange"]:
                    continue
                sym = str(row.get("symbol", "")).upper()
                nm = str(row.get("name", "")).upper()
                if want in (sym, nm):
                    return {"exchange": meta["exchange"], "token": str(row.get("token")), "name": meta["spot_name"]}
        except Exception:
            pass
        # Fallback to the well-known default token.
        return {"exchange": meta["exchange"], "token": meta["default_token"], "name": meta["spot_name"]}

    def option_instruments(self, symbol: str) -> List[dict]:
        """
        Return the nearest-expiry NFO index option instruments for a symbol:
        [{token, strike, opt_type, symbol, expiry, lotsize}, ...]
        """
        symbol = symbol.upper()
        meta = INDEX_META.get(symbol)
        if not meta:
            return []
        nfo_name = meta["nfo_name"]
        rows = []
        for row in self.scrip_master():
            if row.get("exch_seg") != "NFO":
                continue
            if row.get("instrumenttype") != "OPTIDX":
                continue
            if str(row.get("name", "")).upper() != nfo_name:
                continue
            sym = str(row.get("symbol", ""))
            if sym.endswith("CE"):
                opt = "CE"
            elif sym.endswith("PE"):
                opt = "PE"
            else:
                continue
            expiry = str(row.get("expiry", ""))
            try:
                strike = float(row.get("strike", 0)) / 100.0  # Angel stores strike * 100
            except (TypeError, ValueError):
                continue
            rows.append({
                "token": str(row.get("token")),
                "strike": strike,
                "opt_type": opt,
                "symbol": sym,
                "expiry": expiry,
                "lotsize": int(row.get("lotsize", 0) or 0),
            })
        if not rows:
            return []
        # Pick the nearest non-expired expiry.
        nearest = self._nearest_expiry(sorted({r["expiry"] for r in rows}))
        return [r for r in rows if r["expiry"] == nearest]

    @staticmethod
    def _nearest_expiry(expiries: List[str]) -> Optional[str]:
        today = dt.date.today()
        best = None
        best_date = None
        for e in expiries:
            try:
                d = dt.datetime.strptime(e, "%d%b%Y").date()  # e.g. 31JUL2026
            except ValueError:
                continue
            if d >= today and (best_date is None or d < best_date):
                best_date = d
                best = e
        return best or (expiries[0] if expiries else None)

    # ---------------------------------------------------------- market data
    def get_candles(self, exchange: str, token: str, interval: str, days: int = 5) -> List[List[float]]:
        """Return candles [[ts, o, h, l, c, v], ...] oldest -> newest."""
        if not token:
            return []
        to_d = dt.datetime.now()
        from_d = to_d - dt.timedelta(days=days)
        params = {
            "exchange": exchange,
            "symboltoken": token,
            "interval": interval,
            "fromdate": from_d.strftime("%Y-%m-%d 09:15"),
            "todate": to_d.strftime("%Y-%m-%d %H:%M"),
        }
        res = self.smart.getCandleData(params)
        if not res or not res.get("status"):
            return []
        return res.get("data") or []

    def get_market_data_full(self, exchange_tokens: Dict[str, List[str]]) -> Dict[str, dict]:
        """
        FULL market data for many tokens. Returns {token: row}.
        exchange_tokens = {"NSE": ["26000"], "NFO": ["...", ...]}
        """
        out = {}
        if not exchange_tokens:
            return out
        try:
            res = self.smart.getMarketData("FULL", exchange_tokens)
        except Exception:
            return out
        if not res or not res.get("status"):
            return out
        fetched = (res.get("data") or {}).get("fetched") or []
        for row in fetched:
            out[str(row.get("symbolToken"))] = row
        return out

    def india_vix(self) -> float:
        """Best-effort India VIX via candles (token 26017 on NSE). 0 on failure."""
        try:
            candles = self.get_candles("NSE", "26017", "ONE_DAY", days=7)
            if candles:
                return round(float(candles[-1][4]), 2)  # last close
        except Exception:
            pass
        return 0.0

    # -------------------------------------------------------------- ticker
    def start_ticker(self, sub_tokens: List[dict], on_tick: Callable[[dict], None]):
        """
        Start the SmartWebSocketV2 feed in a background thread.
        sub_tokens: [{"exchangeType": 1, "tokens": ["26000", "26009"]}, ...]
        on_tick receives decoded tick dicts (token + last_traded_price in paise).
        """
        from SmartApi.smartWebSocketV2 import SmartWebSocketV2

        sws = SmartWebSocketV2(self.auth_token, config.API_KEY, config.CLIENT_CODE, self.feed_token,
                               max_retry_attempt=5)

        def _on_open(wsapp):
            # mode 3 = SNAP_QUOTE (LTP + OHLC + OI where available).
            sws.subscribe("fnosp_adapter", 3, sub_tokens)

        def _on_data(wsapp, message):
            try:
                on_tick(message)
            except Exception:
                pass

        def _on_error(wsapp, error):
            pass

        def _on_close(wsapp):
            pass

        sws.on_open = _on_open
        sws.on_data = _on_data
        sws.on_error = _on_error
        sws.on_close = _on_close

        t = threading.Thread(target=sws.connect, daemon=True)
        t.start()
        return sws
