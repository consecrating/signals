"""
Configuration loaded from environment variables (or a .env file).

NEVER hardcode credentials. Copy .env.example to .env and fill in your values,
or set these as real environment variables in your host / container.
"""

from __future__ import annotations

import os

try:
    from dotenv import load_dotenv  # optional
    load_dotenv()
except Exception:  # pragma: no cover
    pass


def _get(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


def _int(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, str(default)))
    except (TypeError, ValueError):
        return default


# --- Angel One SmartAPI credentials (required) ---
API_KEY = _get("ANGEL_API_KEY")
CLIENT_CODE = _get("ANGEL_CLIENT_CODE")
MPIN = _get("ANGEL_MPIN")          # your login MPIN (or password)
TOTP_SECRET = _get("ANGEL_TOTP_SECRET")  # the base32 secret behind your authenticator QR

# --- Adapter access secret (shared with the plugin's "Data API Key") ---
# The plugin sends this as `Authorization: Bearer <key>` and `X-API-Key: <key>`.
ADAPTER_SECRET = _get("ADAPTER_SECRET")

# --- Refresh cadence ---
INDICATOR_REFRESH_SEC = _int("INDICATOR_REFRESH_SEC", 60)   # candles + indicators
OPTIONCHAIN_REFRESH_SEC = _int("OPTIONCHAIN_REFRESH_SEC", 30)  # PCR/OI/Max Pain/IV
CANDLE_INTERVAL = _get("CANDLE_INTERVAL", "FIFTEEN_MINUTE")  # SmartAPI interval

# --- Server ---
HOST = _get("HOST", "0.0.0.0")
PORT = _int("PORT", 8080)

# Risk-free rate used for implied-vol solving.
RISK_FREE_RATE = float(_get("RISK_FREE_RATE", "0.065") or "0.065")

# Optional manual token overrides for index spot (exch_seg NSE), if auto-resolve fails.
# Comma-separated "SYMBOL:TOKEN" pairs, e.g. "NIFTY:26000,BANKNIFTY:26009".
INDEX_TOKEN_OVERRIDES = _get("INDEX_TOKEN_OVERRIDES")

# Which index instruments to stream/track.
TRACK_INSTRUMENTS = [s.strip().upper() for s in
                     _get("TRACK_INSTRUMENTS", "NIFTY,BANKNIFTY,FINNIFTY,MIDCPNIFTY").split(",")
                     if s.strip()]


def missing_credentials() -> list:
    missing = []
    for name, val in (
        ("ANGEL_API_KEY", API_KEY),
        ("ANGEL_CLIENT_CODE", CLIENT_CODE),
        ("ANGEL_MPIN", MPIN),
        ("ANGEL_TOTP_SECRET", TOTP_SECRET),
    ):
        if not val:
            missing.append(name)
    return missing


def index_token_overrides() -> dict:
    out = {}
    if INDEX_TOKEN_OVERRIDES:
        for pair in INDEX_TOKEN_OVERRIDES.split(","):
            if ":" in pair:
                sym, tok = pair.split(":", 1)
                out[sym.strip().upper()] = tok.strip()
    return out
