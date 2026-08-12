# `fixtures/` — recorded data, not behaviour

Static inputs only: recorded provider bodies, sample documents that are *not* part of the golden
eval corpus, malicious filenames, SSRF payload lists, prompt-injection samples.

Behaviour lives in `tests/support/`:

| Need | Use |
|---|---|
| A provider streaming over a real socket | `tests/support/fake_provider.py` |
| An ASGI app on a real Uvicorn port | `tests/support/live_server.py` |
| A signed internal request | `tests/support/signing.py` |

## Two rules

**`samples/` is not ours.** The golden eval corpus belongs to `rag-eval-engineer` and is read-only
from here. A corpus edited to make a suite green stops being a measurement.

**Cassettes cover non-streaming shapes only.** `vcrpy` replays a body, not its chunk boundaries, and
the chunk boundaries are the thing the streaming tests exist to check. Error bodies, 402/429
discrimination and usage payloads are fair game; a recorded token stream is not — that is what the
transcript in `fake_provider.py` is for, with real sleeps, CRLF frames and a split mid-codepoint.
