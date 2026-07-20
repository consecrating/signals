"""
In-memory snapshot store + background refresher.

- The WebSocket ticker pushes live LTP into `Store` continuously.
- A background `Refresher` thread periodically fetches candles (for indicators)
  and option data (for PCR/OI/Max Pain/IV), then builds a full plugin-schema
  snapshot per instrument.
- The HTTP endpoint reads the latest snapshot and overlays the live tick LTP,
  so responses are instant and as fresh as the last tick.
"""

from __future__ import annotations

import threading
import time
from typing import Dict, List, Optional

import config
import indicators
import option_chain
from angel_client import AngelClient, INDEX_META, EXCH_TYPE


def _cols(candles: List[list]):
    """Split candle rows [[ts,o,h,l,c,v], ...] into column lists."""
    ts, o, h, l, c, v = [], [], [], [], [], []
    for row in candles:
        if len(row) < 6:
            continue
        ts.append(str(row[0]))
        o.append(float(row[1]))
        h.append(float(row[2]))
        l.append(float(row[3]))
        c.append(float(row[4]))
        v.append(float(row[5]))
    return ts, o, h, l, c, v


class Store:
    def __init__(self):
        self._lock = threading.Lock()
        self._snapshots: Dict[str, dict] = {}
        self._ticks: Dict[str, float] = {}          # token -> ltp
        self._token_instrument: Dict[str, str] = {}  # spot token -> instrument
        self._prev_oi: Dict[str, dict] = {}          # instrument -> {ce, pe}

    def map_token(self, token: str, instrument: str):
        with self._lock:
            self._token_instrument[token] = instrument

    def on_tick(self, message: dict):
        """SmartWebSocketV2 tick callback."""
        if not isinstance(message, dict):
            return
        token = str(message.get("token") or message.get("tk") or "")
        raw = message.get("last_traded_price", message.get("ltp"))
        if token == "" or raw is None:
            return
        try:
            ltp = float(raw) / 100.0  # SmartAPI streams price in paise
        except (TypeError, ValueError):
            return
        with self._lock:
            self._ticks[token] = ltp
            inst = self._token_instrument.get(token)
            if inst and inst in self._snapshots:
                self._snapshots[inst]["ltp"] = round(ltp, 2)
                self._snapshots[inst]["ohlc"]["close"] = round(ltp, 2)

    def set_snapshot(self, instrument: str, data: dict):
        with self._lock:
            self._snapshots[instrument] = data

    def prev_oi(self, instrument: str) -> dict:
        return self._prev_oi.get(instrument, {})

    def set_prev_oi(self, instrument: str, ce: float, pe: float):
        with self._lock:
            self._prev_oi[instrument] = {"ce": ce, "pe": pe}

    def get(self, instrument: str) -> Optional[dict]:
        with self._lock:
            snap = self._snapshots.get(instrument.upper())
            if snap:
                snap = dict(snap)
                snap["timestamp"] = int(time.time())
            return snap


def build_snapshot(instrument: str, intraday: List[list], daily: List[list],
                   vix: float, oc: dict, live_ltp: Optional[float], lot_size: Optional[int]) -> dict:
    _, o, h, l, c, v = _cols(intraday)
    if not c:
        return {}

    ltp = round(live_ltp if live_ltp else c[-1], 2)

    ema9 = round(indicators.ema(c, 9), 2)
    ema21 = round(indicators.ema(c, 21), 2)
    ema50 = round(indicators.ema(c, 50), 2)
    ema200 = round(indicators.ema(c, 200), 2)
    rsi = indicators.rsi(c, 14)
    macd = indicators.macd(c)
    atr = indicators.atr(h, l, c, 14)
    bb = indicators.bollinger(c, 20, 2)
    st = indicators.supertrend(h, l, c, 10, 3)

    # Current-session slice for VWAP + day OHLC (candles sharing the last date).
    ts, *_ = (_cols(intraday))
    last_day = ts[-1][:10] if ts else ""
    sh, sl, sc, sv = [], [], [], []
    day_open = None
    for i in range(len(ts)):
        if ts[i][:10] == last_day:
            if day_open is None:
                day_open = o[i]
            sh.append(h[i]); sl.append(l[i]); sc.append(c[i]); sv.append(v[i])
    vwap = indicators.vwap(sh, sl, sc, sv) if sc else round(ltp, 2)
    day_high = max(sh) if sh else max(h)
    day_low = min(sl) if sl else min(l)
    day_vol = sum(sv) if sv else 0.0

    # Average daily volume across the window.
    by_day = {}
    for i in range(len(ts)):
        d = ts[i][:10]
        by_day[d] = by_day.get(d, 0.0) + v[i]
    avg_vol = (sum(by_day.values()) / len(by_day)) if by_day else 0.0

    # Previous-day OHLC from daily candles.
    _, do, dh, dl, dc, _dv = _cols(daily)
    if len(dc) >= 2:
        idx = len(dc) - 2
        prev = {"open": round(do[idx], 2), "high": round(dh[idx], 2),
                "low": round(dl[idx], 2), "close": round(dc[idx], 2)}
    else:
        prev = {"open": ltp, "high": ltp, "low": ltp, "close": ltp}

    return {
        "instrument": instrument,
        "source": "angelone",
        "ltp": ltp,
        "open": round(day_open if day_open is not None else ltp, 2),
        "high": round(day_high, 2),
        "low": round(day_low, 2),
        "close": ltp,
        "ohlc": {"open": round(day_open if day_open is not None else ltp, 2),
                 "high": round(day_high, 2), "low": round(day_low, 2), "close": ltp},
        "prev_open": prev["open"], "prev_high": prev["high"],
        "prev_low": prev["low"], "prev_close": prev["close"],
        "volume": day_vol,
        "avg_volume": max(1.0, avg_vol),
        "vwap": vwap if vwap > 0 else round(ltp, 2),
        "rsi": rsi["latest"], "rsi_prev": rsi["prev"],
        "macd": macd["macd"], "macd_signal": macd["signal"], "macd_hist": macd["hist"],
        "supertrend": st,
        "ema9": ema9, "ema21": ema21, "ema50": ema50, "ema200": ema200,
        "atr": atr if atr > 0 else round(max(1.0, ltp * 0.004), 2),
        "bb_upper": bb["upper"], "bb_mid": bb["mid"], "bb_lower": bb["lower"],
        "pcr": oc.get("pcr", 1.0),
        "max_pain": oc.get("max_pain", round(ltp)),
        "iv": oc.get("iv", 0.0) or 14.0,
        "vix": vix if vix > 0 else 13.0,
        "call_oi_chg": oc.get("call_oi_chg", 0),
        "put_oi_chg": oc.get("put_oi_chg", 0),
        # Macro fields Angel does not provide -> neutral (plugin scores them lightly).
        "fii_net": 0.0, "dii_net": 0.0, "adv_decline": 1.0,
        "sector_strength": 0, "news_sentiment": 0.0,
        "lot_size": lot_size or 0,
    }


class Refresher(threading.Thread):
    def __init__(self, client: AngelClient, store: Store, instruments: List[str]):
        super().__init__(daemon=True)
        self.client = client
        self.store = store
        self.instruments = [s.upper() for s in instruments]
        self._index_meta = {}   # instrument -> resolved {exchange, token}
        self._last_oc = {}      # instrument -> last option refresh time

    def _setup_ticker(self):
        by_exch = {}
        for inst in self.instruments:
            info = self.client.resolve_index(inst)
            self._index_meta[inst] = info
            if info.get("token"):
                self.store.map_token(info["token"], inst)
                et = EXCH_TYPE.get(info["exchange"], 1)
                by_exch.setdefault(et, []).append(info["token"])
        sub = [{"exchangeType": et, "tokens": toks} for et, toks in by_exch.items()]
        if sub:
            self.client.start_ticker(sub, self.store.on_tick)

    def run(self):
        try:
            self._setup_ticker()
        except Exception:
            pass
        while True:
            for inst in self.instruments:
                try:
                    self._refresh_instrument(inst)
                except Exception:
                    pass
            time.sleep(max(10, config.INDICATOR_REFRESH_SEC))

    def _refresh_instrument(self, inst: str):
        info = self._index_meta.get(inst) or self.client.resolve_index(inst)
        exch, token = info["exchange"], info.get("token")
        intraday = self.client.get_candles(exch, token, config.CANDLE_INTERVAL, days=5)
        daily = self.client.get_candles(exch, token, "ONE_DAY", days=30)
        vix = self.client.india_vix()

        # Option chain analytics.
        oc = {}
        lot_size = None
        try:
            instruments = self.client.option_instruments(inst)
            if instruments:
                lot_size = instruments[0].get("lotsize") or None
                tokens = [r["token"] for r in instruments]
                market = self.client.get_market_data_full({"NFO": tokens})
                spot = self.store._ticks.get(token) or (float(intraday[-1][4]) if intraday else 0.0)
                oc = option_chain.build(spot, instruments, market, config.RISK_FREE_RATE)
                # Session-relative OI change vs previous refresh.
                prev = self.store.prev_oi(inst)
                if prev:
                    oc["call_oi_chg"] = int(oc.get("tot_ce_oi", 0) - prev.get("ce", 0))
                    oc["put_oi_chg"] = int(oc.get("tot_pe_oi", 0) - prev.get("pe", 0))
                self.store.set_prev_oi(inst, oc.get("tot_ce_oi", 0), oc.get("tot_pe_oi", 0))
        except Exception:
            oc = {}

        live_ltp = self.store._ticks.get(token)
        snap = build_snapshot(inst, intraday, daily, vix, oc, live_ltp, lot_size)
        if snap:
            self.store.set_snapshot(inst, snap)
