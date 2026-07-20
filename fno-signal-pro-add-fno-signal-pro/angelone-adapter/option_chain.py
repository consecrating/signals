"""
Build option-chain analytics (PCR, Max Pain, IV) from Angel One NFO data.

Inputs:
  - spot: underlying spot price
  - instruments: nearest-expiry option rows [{token, strike, opt_type, expiry}, ...]
  - market: {token: full-market-data row} (needs opnInterest, ltp)

IV is solved from the ATM CE/PE premiums via Black-Scholes (implied_vol).
"""

from __future__ import annotations

import datetime as dt
from typing import Dict, List

import indicators


def _days_to_expiry(expiry: str) -> float:
    try:
        d = dt.datetime.strptime(expiry, "%d%b%Y").date()
        days = (d - dt.date.today()).days
        return max(days, 1)
    except (ValueError, TypeError):
        return 7.0


def _ltp(row: dict) -> float:
    if not row:
        return 0.0
    for k in ("ltp", "lastTradedPrice", "last_traded_price"):
        if k in row and row[k] is not None:
            try:
                return float(row[k])
            except (TypeError, ValueError):
                pass
    return 0.0


def _oi(row: dict) -> float:
    if not row:
        return 0.0
    for k in ("opnInterest", "openInterest", "oi"):
        if k in row and row[k] is not None:
            try:
                return float(row[k])
            except (TypeError, ValueError):
                pass
    return 0.0


def compute_max_pain(strike_oi: Dict[float, Dict[str, float]]) -> float:
    if not strike_oi:
        return 0.0
    strikes = sorted(strike_oi.keys())
    best_strike, best_pain = strikes[0], float("inf")
    for expiry_price in strikes:
        pain = 0.0
        for k, oi in strike_oi.items():
            ce = oi.get("ce", 0.0)
            pe = oi.get("pe", 0.0)
            if expiry_price > k:
                pain += (expiry_price - k) * ce
            if expiry_price < k:
                pain += (k - expiry_price) * pe
        if pain < best_pain:
            best_pain, best_strike = pain, expiry_price
    return float(best_strike)


def build(spot: float, instruments: List[dict], market: Dict[str, dict],
          risk_free: float = 0.065) -> dict:
    result = {
        "pcr": 1.0,
        "max_pain": round(spot) if spot else 0.0,
        "iv": 0.0,
        "call_oi_chg": 0,
        "put_oi_chg": 0,
        "ok": False,
    }
    if not spot or not instruments:
        return result

    tot_ce_oi = tot_pe_oi = 0.0
    strike_oi: Dict[float, Dict[str, float]] = {}
    expiry = instruments[0].get("expiry", "")
    t = _days_to_expiry(expiry) / 365.0

    # Find the ATM strike (nearest to spot).
    strikes = sorted({r["strike"] for r in instruments})
    if not strikes:
        return result
    atm = min(strikes, key=lambda k: abs(k - spot))

    atm_ce_ltp = atm_pe_ltp = 0.0

    for r in instruments:
        row = market.get(r["token"])
        oi = _oi(row)
        strike = r["strike"]
        strike_oi.setdefault(strike, {})
        if r["opt_type"] == "CE":
            tot_ce_oi += oi
            strike_oi[strike]["ce"] = oi
            if strike == atm:
                atm_ce_ltp = _ltp(row)
        else:
            tot_pe_oi += oi
            strike_oi[strike]["pe"] = oi
            if strike == atm:
                atm_pe_ltp = _ltp(row)

    pcr = round(tot_pe_oi / tot_ce_oi, 2) if tot_ce_oi > 0 else 1.0
    max_pain = compute_max_pain(strike_oi)

    # IV: average of ATM CE and PE implied vols (percent).
    ivs = []
    if atm_ce_ltp > 0:
        iv_ce = indicators.implied_vol("CE", atm_ce_ltp, spot, atm, t, risk_free)
        if iv_ce > 0:
            ivs.append(iv_ce)
    if atm_pe_ltp > 0:
        iv_pe = indicators.implied_vol("PE", atm_pe_ltp, spot, atm, t, risk_free)
        if iv_pe > 0:
            ivs.append(iv_pe)
    iv_pct = round((sum(ivs) / len(ivs)) * 100, 1) if ivs else 0.0

    result.update({
        "pcr": pcr,
        "max_pain": max_pain if max_pain > 0 else round(spot),
        "iv": iv_pct,
        "atm": atm,
        "expiry": expiry,
        "tot_ce_oi": tot_ce_oi,
        "tot_pe_oi": tot_pe_oi,
        "ok": True,
    })
    return result
