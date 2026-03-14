"""
bridge_router.py — local sticky failover router for Zeus bridges
Primary backend: 127.0.0.1:8787
Secondary backend: 127.0.0.1:8790
Expose this on 127.0.0.1:8786 and point the tunnel at it.
"""

import time
from typing import Optional

import requests
from fastapi import FastAPI, HTTPException, Request, Response
from pydantic import BaseModel

app = FastAPI(title="Zeus Bridge Router", version="1.0.0")

BACKENDS = [
    "http://127.0.0.1:8787",
    "http://127.0.0.1:8790",
]

_active_idx = 0
_state = {
    ep: {"alive": False, "last_ok": None, "last_error": None, "fail_count": 0}
    for ep in BACKENDS
}

HOP_BY_HOP = {
    "connection", "keep-alive", "proxy-authenticate", "proxy-authorization",
    "te", "trailers", "transfer-encoding", "upgrade", "host", "content-length"
}

class SwitchReq(BaseModel):
    endpoint: str

def probe(ep: str) -> bool:
    try:
        r = requests.get(f"{ep}/health", timeout=2)
        ok = r.status_code == 200
        if ok:
            _state[ep]["alive"] = True
            _state[ep]["last_ok"] = time.strftime("%Y-%m-%d %H:%M:%S")
            _state[ep]["last_error"] = None
            _state[ep]["fail_count"] = 0
            return True
        _state[ep]["alive"] = False
        _state[ep]["last_error"] = f"HTTP {r.status_code}"
        _state[ep]["fail_count"] += 1
        return False
    except Exception as e:
        _state[ep]["alive"] = False
        _state[ep]["last_error"] = str(e)
        _state[ep]["fail_count"] += 1
        return False

def current_backend() -> Optional[str]:
    global _active_idx
    active = BACKENDS[_active_idx]
    if probe(active):
        return active
    for i, ep in enumerate(BACKENDS):
        if i == _active_idx:
            continue
        if probe(ep):
            _active_idx = i
            return ep
    return None

def filtered_headers(headers):
    return {k: v for k, v in headers.items() if k.lower() not in HOP_BY_HOP}

@app.get("/health")
def health():
    active = current_backend()
    return {
        "status": "ok" if active else "degraded",
        "router": "up",
        "active_backend": active,
        "backends": BACKENDS,
        "state": _state,
        "version": "1.0.0"
    }

@app.get("/active-endpoint")
def active_endpoint():
    active = current_backend()
    return {
        "active": active,
        "index": _active_idx if active else None,
        "backends": BACKENDS,
        "state": _state
    }

@app.post("/failover")
def failover():
    active = current_backend()
    if not active:
        raise HTTPException(502, "No backend available")
    return {"active": active, "index": _active_idx}

@app.post("/switch-endpoint")
def switch_endpoint(req: SwitchReq):
    global _active_idx
    if req.endpoint not in BACKENDS:
        raise HTTPException(400, f"Unknown backend: {req.endpoint}")
    _active_idx = BACKENDS.index(req.endpoint)
    return {"switched_to": req.endpoint, "index": _active_idx}

@app.api_route("/{path:path}", methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS", "HEAD"])
async def proxy(path: str, request: Request):
    backend = current_backend()
    if not backend:
        raise HTTPException(502, "No backend available")

    url = f"{backend}/{path}"
    params = dict(request.query_params)
    body = await request.body()
    headers = filtered_headers(request.headers)

    try:
        resp = requests.request(
            method=request.method,
            url=url,
            params=params,
            data=body,
            headers=headers,
            timeout=30,
        )
        response_headers = filtered_headers(resp.headers)
        return Response(content=resp.content, status_code=resp.status_code, headers=response_headers, media_type=resp.headers.get("content-type"))
    except Exception as e:
        _state[backend]["alive"] = False
        _state[backend]["last_error"] = str(e)
        _state[backend]["fail_count"] += 1

        fallback = current_backend()
        if not fallback or fallback == backend:
            raise HTTPException(502, f"Backend request failed: {e}")

        retry_url = f"{fallback}/{path}"
        resp = requests.request(
            method=request.method,
            url=retry_url,
            params=params,
            data=body,
            headers=headers,
            timeout=30,
        )
        response_headers = filtered_headers(resp.headers)
        return Response(content=resp.content, status_code=resp.status_code, headers=response_headers, media_type=resp.headers.get("content-type"))

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8786)
