"""
FastAPI adapter server.

Exposes the endpoints the F&O Signal Pro plugin's "Custom REST endpoint"
provider expects:

    GET /?instrument=NIFTY        -> plugin-schema JSON snapshot (live)
    GET /health                   -> liveness probe

Auth: if ADAPTER_SECRET is set, requests must send it as either
    Authorization: Bearer <secret>   or   X-API-Key: <secret>
(the plugin sends both automatically from its "Data API Key" setting).

Run:  uvicorn app:app --host 0.0.0.0 --port 8080
"""

from __future__ import annotations

import time
from fastapi import FastAPI, Request, Query
from fastapi.responses import JSONResponse

import config
from store import Store, Refresher
from angel_client import AngelClient

app = FastAPI(title="F&O Signal Pro — Angel One Adapter", version="1.0.0")

STORE = Store()
CLIENT = AngelClient()
STATE = {"logged_in": False, "started_at": time.time(), "error": None}


@app.on_event("startup")
def _startup():
    missing = config.missing_credentials()
    if missing:
        STATE["error"] = "Missing env vars: " + ", ".join(missing)
        return
    try:
        CLIENT.login()
        STATE["logged_in"] = True
        Refresher(CLIENT, STORE, config.TRACK_INSTRUMENTS).start()
    except Exception as e:  # pragma: no cover
        STATE["error"] = "Login/refresher failed: %s" % e


def _authorized(request: Request) -> bool:
    if not config.ADAPTER_SECRET:
        return True  # no secret configured -> open (not recommended)
    auth = request.headers.get("authorization", "")
    if auth.lower().startswith("bearer "):
        if auth[7:].strip() == config.ADAPTER_SECRET:
            return True
    if request.headers.get("x-api-key", "").strip() == config.ADAPTER_SECRET:
        return True
    # Allow ?key= as a convenience fallback.
    if request.query_params.get("key", "") == config.ADAPTER_SECRET:
        return True
    return False


@app.get("/health")
def health():
    return {
        "ok": True,
        "service": "fnosp-angelone-adapter",
        "logged_in": STATE["logged_in"],
        "tracking": config.TRACK_INSTRUMENTS,
        "uptime_sec": int(time.time() - STATE["started_at"]),
        "error": STATE["error"],
    }


@app.get("/")
def signal(request: Request, instrument: str = Query("NIFTY")):
    if not _authorized(request):
        return JSONResponse({"error": "Unauthorized"}, status_code=401)
    if STATE["error"] and not STATE["logged_in"]:
        return JSONResponse({"error": STATE["error"]}, status_code=503)

    inst = (instrument or "NIFTY").upper()
    snap = STORE.get(inst)
    if not snap:
        # Not warmed up yet (or an unsupported symbol).
        return JSONResponse(
            {"error": "No live snapshot yet for %s. Warming up or outside market hours." % inst},
            status_code=503,
        )
    return JSONResponse(snap)


if __name__ == "__main__":
    import uvicorn
    uvicorn.run("app:app", host=config.HOST, port=config.PORT, reload=False)
