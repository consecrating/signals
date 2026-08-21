"""
Pure-Python technical indicators + a Black-Scholes implied-volatility solver.

Formulas mirror the PHP engine in the plugin (class-fnosp-indicators.php) so the
adapter's output is consistent with what the plugin would compute in Free mode.

All series are ordered oldest -> newest.
"""

from __future__ import annotations

import math
from typing import List, Dict


# ---------------------------------------------------------------------------
# Moving averages
# ---------------------------------------------------------------------------
def sma(values: List[float], period: int) -> float:
    n = len(values)
    if n < 1:
        return 0.0
    period = min(period, n)
    s = values[-period:]
    return sum(s) / max(1, len(s))


def ema_series(values: List[float], period: int) -> List[float]:
    n = len(values)
    if n == 0:
        return []
    k = 2 / (period + 1)
    out = []
    ema = values[0]
    for i, v in enumerate(values):
        ema = v if i == 0 else (v - ema) * k + ema
        out.append(ema)
    return out


def ema(values: List[float], period: int) -> float:
    s = ema_series(values, period)
    return s[-1] if s else 0.0


# ---------------------------------------------------------------------------
# RSI (Wilder)
# ---------------------------------------------------------------------------
def _rsi_from_avg(avg_gain: float, avg_loss: float) -> float:
    if avg_loss <= 0:
        return 100.0
    rs = avg_gain / avg_loss
    return 100 - (100 / (1 + rs))


def rsi(closes: List[float], period: int = 14) -> Dict[str, float]:
    n = len(closes)
    if n < period + 1:
        return {"latest": 50.0, "prev": 50.0}
    gains, losses = [], []
    for i in range(1, n):
        diff = closes[i] - closes[i - 1]
        gains.append(diff if diff > 0 else 0.0)
        losses.append(-diff if diff < 0 else 0.0)
    avg_gain = sum(gains[:period]) / period
    avg_loss = sum(losses[:period]) / period
    series = [_rsi_from_avg(avg_gain, avg_loss)]
    for i in range(period, len(gains)):
        avg_gain = ((avg_gain * (period - 1)) + gains[i]) / period
        avg_loss = ((avg_loss * (period - 1)) + losses[i]) / period
        series.append(_rsi_from_avg(avg_gain, avg_loss))
    latest = series[-1]
    prev = series[-2] if len(series) >= 2 else latest
    return {"latest": round(latest, 2), "prev": round(prev, 2)}


# ---------------------------------------------------------------------------
# MACD (12, 26, 9)
# ---------------------------------------------------------------------------
def macd(closes: List[float], fast: int = 12, slow: int = 26, signal: int = 9) -> Dict[str, float]:
    if len(closes) < slow + signal:
        return {"macd": 0.0, "signal": 0.0, "hist": 0.0}
    ema_fast = ema_series(closes, fast)
    ema_slow = ema_series(closes, slow)
    macd_line = [ema_fast[i] - ema_slow[i] for i in range(len(closes))]
    signal_series = ema_series(macd_line, signal)
    macd_last = macd_line[-1]
    signal_last = signal_series[-1]
    return {
        "macd": round(macd_last, 2),
        "signal": round(signal_last, 2),
        "hist": round(macd_last - signal_last, 2),
    }


# ---------------------------------------------------------------------------
# ATR (Wilder)
# ---------------------------------------------------------------------------
def atr(highs: List[float], lows: List[float], closes: List[float], period: int = 14) -> float:
    n = min(len(highs), len(lows), len(closes))
    if n < 2:
        return 0.0
    tr = []
    for i in range(1, n):
        h_l = highs[i] - lows[i]
        h_pc = abs(highs[i] - closes[i - 1])
        l_pc = abs(lows[i] - closes[i - 1])
        tr.append(max(h_l, h_pc, l_pc))
    if len(tr) < period:
        return round(sum(tr) / max(1, len(tr)), 2)
    a = sum(tr[:period]) / period
    for i in range(period, len(tr)):
        a = ((a * (period - 1)) + tr[i]) / period
    return round(a, 2)


# ---------------------------------------------------------------------------
# Bollinger Bands (20, 2)
# ---------------------------------------------------------------------------
def bollinger(closes: List[float], period: int = 20, mult: float = 2.0) -> Dict[str, float]:
    n = len(closes)
    if n < 1:
        return {"upper": 0.0, "mid": 0.0, "lower": 0.0}
    period = min(period, n)
    s = closes[-period:]
    mid = sum(s) / period
    var = sum((c - mid) ** 2 for c in s) / period
    sd = math.sqrt(var)
    return {
        "upper": round(mid + mult * sd, 2),
        "mid": round(mid, 2),
        "lower": round(mid - mult * sd, 2),
    }


# ---------------------------------------------------------------------------
# SuperTrend (10, 3) -> "bullish" / "bearish"
# ---------------------------------------------------------------------------
def supertrend(highs: List[float], lows: List[float], closes: List[float], period: int = 10, mult: float = 3.0) -> str:
    n = min(len(highs), len(lows), len(closes))
    if n < period + 1:
        return "bullish" if (closes and closes[-1] >= ema(closes, 10)) else "bearish"
    a = atr(highs, lows, closes, period)
    direction = "bullish"
    prev_upper = prev_lower = None
    for i in range(1, n):
        hl2 = (highs[i] + lows[i]) / 2
        basic_upper = hl2 + mult * a
        basic_lower = hl2 - mult * a
        final_upper = basic_upper if prev_upper is None else (
            basic_upper if (basic_upper < prev_upper or closes[i - 1] > prev_upper) else prev_upper)
        final_lower = basic_lower if prev_lower is None else (
            basic_lower if (basic_lower > prev_lower or closes[i - 1] < prev_lower) else prev_lower)
        if closes[i] > final_upper:
            direction = "bullish"
        elif closes[i] < final_lower:
            direction = "bearish"
        prev_upper, prev_lower = final_upper, final_lower
    return direction


# ---------------------------------------------------------------------------
# Session VWAP
# ---------------------------------------------------------------------------
def vwap(highs: List[float], lows: List[float], closes: List[float], volumes: List[float]) -> float:
    n = min(len(highs), len(lows), len(closes), len(volumes))
    if n < 1:
        return 0.0
    pv = vol = 0.0
    for i in range(n):
        tp = (highs[i] + lows[i] + closes[i]) / 3
        v = max(0.0, float(volumes[i]))
        pv += tp * v
        vol += v
    if vol <= 0:
        return round(sma(closes, n), 2)
    return round(pv / vol, 2)


# ---------------------------------------------------------------------------
# Black-Scholes price + implied-volatility solver (for IV from live premium)
# ---------------------------------------------------------------------------
def _norm_cdf(x: float) -> float:
    return 0.5 * (1 + math.erf(x / math.sqrt(2)))


def bs_price(opt_type: str, s: float, k: float, t: float, r: float, sigma: float) -> float:
    t = max(t, 1 / 3650)
    sigma = max(sigma, 1e-6)
    q = sigma * math.sqrt(t)
    d1 = (math.log(s / k) + (r + sigma * sigma / 2) * t) / q
    d2 = d1 - q
    disc = math.exp(-r * t)
    if opt_type.upper() == "PE":
        price = k * disc * _norm_cdf(-d2) - s * _norm_cdf(-d1)
    else:
        price = s * _norm_cdf(d1) - k * disc * _norm_cdf(d2)
    return max(0.0, price)


def implied_vol(opt_type: str, market_price: float, s: float, k: float, t: float, r: float = 0.065) -> float:
    """Solve for implied volatility (decimal) via bisection. Returns 0 on failure."""
    if market_price <= 0 or s <= 0 or k <= 0 or t <= 0:
        return 0.0
    lo, hi = 1e-4, 5.0  # 0.01% .. 500%
    # Ensure the target is bracketed.
    if bs_price(opt_type, s, k, t, r, hi) < market_price:
        return 0.0
    for _ in range(100):
        mid = (lo + hi) / 2
        price = bs_price(opt_type, s, k, t, r, mid)
        if abs(price - market_price) < 0.01:
            return round(mid, 4)
        if price > market_price:
            hi = mid
        else:
            lo = mid
    return round((lo + hi) / 2, 4)
