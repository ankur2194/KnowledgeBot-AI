"""Bind an ASGI app to a **real socket**, because nothing in-process can stand in for it.

``httpx.ASGITransport`` collects every ``http.response.body`` message into a list and returns
``b"".join(...)`` as one chunk, and its ``receive()`` yields ``http.disconnect`` only *after*
``response_complete`` is set. ``starlette.testclient.TestClient`` does the same through a
``BytesIO``. So both of them:

* deliver the whole SSE body as a single chunk, which makes every inter-event timing
  assertion vacuous and every framing bug invisible; and
* **cannot deliver a mid-stream disconnect at all**, which is the exact event the
  cancellation path exists to handle.

A streaming test written over either one passes against an implementation that streams
nothing and never notices a hangup — which is precisely the bug it was written to catch. It
is the Python twin of Laravel's ``Http::fake()`` and Playwright's ``route.fulfill()``.

So: a real Uvicorn, on a real ephemeral port, for the app under test *and* for the fake
provider it calls.
"""

from __future__ import annotations

import asyncio
import contextlib
import socket
from collections.abc import AsyncIterator
from typing import Any

__all__ = ["serve"]


@contextlib.asynccontextmanager
async def serve(app: Any, *, log_level: str = "warning") -> AsyncIterator[str]:
    """Run ``app`` on ``127.0.0.1:<ephemeral>`` and yield its base URL.

    The listening socket is created here and handed to Uvicorn, rather than being bound,
    read for its port, closed, and re-bound by Uvicorn from the port number. That
    bind-close-rebind dance leaves a window in which another process on a busy CI runner can
    take the port, and the resulting ``address already in use`` reads as an unrelated flake.

    Startup is awaited on ``server.started`` rather than slept for. The lifespan loads models
    and opens pools, so any fixed sleep is either too short on a cold CI runner or wasted on
    every subsequent run.
    """
    import uvicorn

    sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
    sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
    sock.bind(("127.0.0.1", 0))
    sock.listen(128)
    port = sock.getsockname()[1]

    config = uvicorn.Config(
        app,
        log_level=log_level,
        # Uvicorn's default 5 s keeps a torn-down stream's socket alive past the test that
        # cut it, and the next test then reads bytes it did not send.
        timeout_keep_alive=1,
        access_log=False,
    )
    server = uvicorn.Server(config)
    task = asyncio.create_task(server.serve(sockets=[sock]))
    try:
        while not server.started:
            if task.done():  # a failed bind or a lifespan error, surfaced instead of hanging
                await task
            await asyncio.sleep(0.01)
        yield f"http://127.0.0.1:{port}"
    finally:
        server.should_exit = True
        with contextlib.suppress(asyncio.TimeoutError):
            await asyncio.wait_for(task, timeout=10)
        if not task.done():
            task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await task
        sock.close()
