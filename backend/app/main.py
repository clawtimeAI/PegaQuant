from __future__ import annotations

import asyncio

from fastapi import FastAPI, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware

from app.api.routes import api_router
from app.database import SessionLocal
from app.settings import settings
from app.services.kline_stream_service import KlineStreamService


app = FastAPI(title="PegaQuant API")

app.add_middleware(
    CORSMiddleware,
    allow_origins=[o.strip() for o in settings.cors_origins.split(",") if o.strip()],
    allow_origin_regex=settings.cors_origin_regex,
    allow_credentials=False,
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(api_router)


@app.on_event("startup")
async def on_startup():
    svc = KlineStreamService(session_factory=SessionLocal)
    app.state.kline_stream_service = svc
    asyncio.create_task(svc.start())


@app.on_event("shutdown")
async def on_shutdown():
    svc: KlineStreamService | None = getattr(app.state, "kline_stream_service", None)
    if svc is not None:
        await svc.stop()


@app.websocket("/ws/klines")
async def ws_klines(ws: WebSocket):
    await ws.accept()
    svc: KlineStreamService = app.state.kline_stream_service
    client = await svc.register(ws)
    try:
        while True:
            obj = await ws.receive_json()
            t = obj.get("type")
            if t == "subscribe_kline":
                symbols = obj.get("symbols") or []
                intervals = obj.get("intervals") or []
                groups = await svc.subscribe(client, symbols=list(symbols), intervals=list(intervals))
                client.try_send({"type": "subscribed", "groups": groups})
                await svc.push_snapshots(client, symbols=list(symbols), intervals=list(intervals))
            elif t == "unsubscribe_kline":
                symbols = obj.get("symbols") or []
                intervals = obj.get("intervals") or []
                groups = await svc.unsubscribe(client, symbols=list(symbols), intervals=list(intervals))
                client.try_send({"type": "unsubscribed", "groups": groups})
            else:
                client.try_send({"type": "error", "message": "unknown message type"})
    except WebSocketDisconnect:
        await svc.unregister(client)
    except Exception:
        await svc.unregister(client)


@app.get("/health")
def health():
    return {"ok": True}
