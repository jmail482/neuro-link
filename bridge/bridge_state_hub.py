"""
bridge_state_hub.py — Zeus bridge control plane
HTTP + WebSocket state hub with optional Redis backing.
Port: 8784

Purpose:
- expose current local bridge stack state
- broadcast failover / health events over WebSocket
- optionally persist state + pub/sub through Redis if REDIS_URL is set
"""

import asyncio
import json
import os
import time
from typing import Any, Dict, List, Optional, Set

from fastapi import FastAPI, HTTPException, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel

try:
    import redis  # type: ignore
except Exception:
    redis = None

app = FastAPI(title="Zeus Bridge State Hub", version="1.0.0")
app.add_middleware(CORSMiddleware, allow_origins=["*"], allow_methods=["*"], allow_headers=["*"])

REDIS_URL = os.getenv("REDIS_URL", "").strip()
REDIS_KEY = os.getenv("ZEUS_BRIDGE_STATE_KEY", "zeus:bridge:state")
REDIS_CHANNEL = os.getenv("ZEUS_BRIDGE_EVENTS_CHANNEL", "zeus:bridge:events")

_ws_clients: Set[WebSocket] = set()
_redis_client = None
_pubsub_task: Optional[asyncio.Task] = None

state: Dict[str, Any] = {
    "updated_at": None,
    "active_public_target": None,
    "preferred_local_target": "http://127.0.0.1:8785",
    "components": {
        "state_hub": {"port": 8784, "status": "configured"},
        "triadapter": {"port": 8785, "status": "configured"},
        "router": {"port": 8786, "status": "configured"},
        "bridge_a": {"port": 8787, "status": "configured"},
        "bridge_b": {"port": 8790, "status": "configured"},
        "memory": {"port": 8771, "status": "external"},
        "bus": {"port": 8799, "status": "external"},
    },
    "notes": [
        "Tunnel target should ultimately be http://127.0.0.1:8785",
        "Redis is optional. Hub works in memory if REDIS_URL is unset.",
    ],
}

class EventReq(BaseModel):
    type: str
    payload: Dict[str, Any] = {}

class ActiveReq(BaseModel):
    active_public_target: Optional[str] = None
    preferred_local_target: Optional[str] = None
    components: Optional[Dict[str, Any]] = None

def now_iso() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())

def get_redis_client():
    global _redis_client
    if _redis_client is not None:
        return _redis_client
    if not REDIS_URL or redis is None:
        return None
    try:
        _redis_client = redis.from_url(REDIS_URL, decode_responses=True)
        _redis_client.ping()
        return _redis_client
    except Exception:
        _redis_client = None
        return None

def save_state():
    client = get_redis_client()
    if client is not None:
        client.set(REDIS_KEY, json.dumps(state))

def load_state():
    client = get_redis_client()
    if client is not None:
        raw = client.get(REDIS_KEY)
        if raw:
            try:
                loaded = json.loads(raw)
                state.update(loaded)
            except Exception:
                pass

async def broadcast(message: Dict[str, Any]):
    dead: List[WebSocket] = []
    for ws in list(_ws_clients):
        try:
            await ws.send_json(message)
        except Exception:
            dead.append(ws)
    for ws in dead:
        _ws_clients.discard(ws)

    client = get_redis_client()
    if client is not None:
        try:
            client.publish(REDIS_CHANNEL, json.dumps(message))
        except Exception:
            pass

async def redis_listener():
    client = get_redis_client()
    if client is None:
        return
    pubsub = client.pubsub()
    pubsub.subscribe(REDIS_CHANNEL)
    while True:
        try:
            msg = pubsub.get_message(ignore_subscribe_messages=True, timeout=1)
            if msg and msg.get("data"):
                try:
                    payload = json.loads(msg["data"])
                    dead: List[WebSocket] = []
                    for ws in list(_ws_clients):
                        try:
                            await ws.send_json(payload)
                        except Exception:
                            dead.append(ws)
                    for ws in dead:
                        _ws_clients.discard(ws)
                except Exception:
                    pass
        except Exception:
            await asyncio.sleep(1)
        await asyncio.sleep(0.2)

@app.on_event("startup")
async def startup():
    global _pubsub_task
    load_state()
    state["updated_at"] = now_iso()
    save_state()
    if get_redis_client() is not None and _pubsub_task is None:
        _pubsub_task = asyncio.create_task(redis_listener())

@app.get("/health")
def health():
    return {
        "status": "ok",
        "service": "bridge-state-hub",
        "version": "1.0.0",
        "redis_enabled": bool(get_redis_client()),
        "ws_clients": len(_ws_clients),
        "updated_at": state.get("updated_at"),
    }

@app.get("/state")
def get_state():
    return state

@app.post("/state/active")
async def set_active(req: ActiveReq):
    if req.active_public_target is not None:
        state["active_public_target"] = req.active_public_target
    if req.preferred_local_target is not None:
        state["preferred_local_target"] = req.preferred_local_target
    if req.components:
        state["components"].update(req.components)
    state["updated_at"] = now_iso()
    save_state()
    msg = {"type": "state.updated", "payload": state, "ts": state["updated_at"]}
    await broadcast(msg)
    return {"ok": True, "state": state}

@app.post("/event")
async def post_event(req: EventReq):
    message = {"type": req.type, "payload": req.payload, "ts": now_iso()}
    await broadcast(message)
    return {"ok": True, "message": message}

@app.websocket("/ws")
async def websocket_endpoint(ws: WebSocket):
    await ws.accept()
    _ws_clients.add(ws)
    await ws.send_json({"type": "hello", "payload": state, "ts": now_iso()})
    try:
        while True:
            raw = await ws.receive_text()
            try:
                data = json.loads(raw)
            except Exception:
                data = {"type": "client.message", "payload": {"raw": raw}}
            data.setdefault("ts", now_iso())
            await broadcast(data)
    except WebSocketDisconnect:
        _ws_clients.discard(ws)
    except Exception:
        _ws_clients.discard(ws)

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="127.0.0.1", port=8784)
